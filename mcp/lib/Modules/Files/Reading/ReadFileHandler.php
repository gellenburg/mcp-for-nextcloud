<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\Extraction\TextExtractor;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\Images\OcrSupport;

/** Runs the operation that reads visible file text with metadata and optional OCR inside the actor view. */
final class ReadFileHandler implements FileTextListing {
	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
	 * @param ReadableFile $readable visible file resolver
	 * @param NodeAccessInfo $accessInfo node access metadata formatter
	 * @param TextExtractor $extractor document text extractor
	 * @param OcrSupport $ocr image text extraction support
	 * @param FileTextResult $texts text and metadata response formatter
	 */
	public function __construct(
		private FilesCallContext $context,
		private ReadableFile $readable,
		private NodeAccessInfo $accessInfo,
		private TextExtractor $extractor,
		private OcrSupport $ocr,
		private FileTextResult $texts,
		private ?\OCA\Mcp\Modules\Files\Checkout\CheckoutService $checkout = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->context->run($userId, fn (Folder $root): array => $this->read($root, $userId, $arguments['path'], $arguments['mode'] ?? 'text', $arguments['offset'] ?? null, $arguments['max_chars'] ?? null));
	}

	/**
	 * The text stays in content[0] exactly as it always was; content[1] carries the metadata the client
	 * needs to place the text and to know who owns the file.
	 *
	 * @return array{content: list<array{type:string, text:string}>}
	 * @param Folder $root actor folder used to resolve relative paths
	 * @param string $userId authenticated actor identifier
	 * @param string $path user-relative file path
	 */
	private function read(Folder $root, string $userId, string $path, string $mode = 'text', ?int $offset = null, ?int $maxChars = null): array {
		$path = PathGuard::normalize($path);
		$file = $this->readable->get($root, $path);
		$meta = ['path' => $path, 'etag' => (string)$file->getEtag(), 'size' => (int)$file->getSize(),
			'mime' => (string)$file->getMimetype(), 'access' => $this->accessInfo->describe($file, $userId)];
		if ($mode === 'download') {
			if ($this->checkout === null) {
				throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Read-only download service unavailable.');
			}
			return \OCA\Mcp\Mcp\Tools\Results\ToolResult::json($this->checkout->issueRead($userId, $file, $path, $meta['access']));
		}
		if ($mode === 'metadata') {
			$bytes = $this->extractor->read($file);
			if (!str_starts_with($bytes, "\x89PNG\r\n\x1a\n") && !str_starts_with($bytes, "\xff\xd8")) {
				throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Embedded metadata mode supports PNG and JPEG content; use download for other formats.');
			}
			$embedded = \OCA\Mcp\Modules\Files\Images\EmbeddedImageMetadata::extract($bytes);
			return \OCA\Mcp\Mcp\Tools\Results\ToolResult::json($meta + ['embedded_metadata' => $embedded]);
		}
		$scannable = OcrSupport::canLackText($file->getName(), (string)$file->getMimetype());
		$isImage = $scannable && str_starts_with(strtolower((string)$file->getMimetype()), 'image/');
		$text = $isImage ? '' : $this->extractor->readText($file);
		if ($scannable && trim($text) === '') {
			$notice = $this->ocr->noTextNotice();
			return $this->texts->render($notice, $meta + ['text_layer' => false, 'ocr_active' => $this->ocr->isActive(), 'notice' => $notice]);
		}
		// No implicit output cap. Explicit character windows are a client choice, not a file admission limit.
		if ($offset !== null || $maxChars !== null) {
			$total = mb_strlen($text, 'UTF-8');
			$start = min($offset ?? 0, $total);
			$length = min($maxChars ?? ($total - $start), $total - $start);
			$text = mb_substr($text, $start, $length, 'UTF-8');
			$next = $start + $length;
			$meta += ['offset' => $start, 'returned_chars' => $length, 'total_chars' => $total,
				'next_offset' => $next < $total ? $next : null];
		}
		return $this->texts->render(
			$text,
			$meta,
		);
	}
}
