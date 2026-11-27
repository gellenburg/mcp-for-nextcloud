<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Extraction;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Extraction\FileReadLimit;
use OCA\Mcp\Modules\Files\Extraction\TextExtractor;
use OCA\Mcp\Modules\Files\Presentation\ReadingMessages;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Platform\Php\MemoryLimit;
use OCP\Files\File;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/** The file ceiling follows the administrator; a zip entry stays at 20 MiB. */
final class TextExtractorLimitTest extends TestCase {
	/**
	 * @param int|null $mib Configured ceiling in MiB, null keeps the default.
	 * @param string $memoryLimit Directive the reader sees, independent of this test process.
	 * @return TextExtractor Reader using the real ceiling calculation with the supplied directive.
	 */
	private function extractor(?int $mib, string $memoryLimit = '-1'): TextExtractor {
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(fn () => tempnam(sys_get_temp_dir(), 'mcp'));
		$stored = new InMemoryConfig();
		if ($mib !== null) {
			$stored->app['mcp'][FileReadLimit::MAX_BYTES_KEY] = (string)($mib * 1024 * 1024);
		}
		$memory = $this->createMock(MemoryLimit::class);
		$memory->method('value')->willReturn($memoryLimit);
		return new TextExtractor($temp, new FileReadLimit($stored->mock($this), $memory));
	}

	/** @param int $size size the node reports, in bytes */
	private function file(int $size, string $name = 'note.txt', string $body = "hello\n", string $mime = 'text/plain'): File {
		$stream = fopen('php://temp', 'w+');
		fwrite($stream, $body);
		rewind($stream);
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn($size);
		$file->method('getName')->willReturn($name);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('fopen')->willReturn($stream);
		return $file;
	}

	public function testReadHasNoApplicationDefaultByteCeiling(): void {
		$extractor = $this->extractor(null);
		$this->assertSame(FileReadLimit::NATIVE_ONLY, $extractor->maxFileBytes());
		$this->assertSame("hello\n", $extractor->readText($this->file(TextExtractor::MAX_BYTES + 1)));
	}

	public function testARaisedLimitReadsAFileTheOldConstantRefused(): void {
		$extractor = $this->extractor(40);
		$this->assertSame(FileReadLimit::NATIVE_ONLY, $extractor->maxFileBytes());
		$this->assertSame("hello\n", $extractor->readText($this->file(TextExtractor::MAX_BYTES + 1)));
	}

	public function testLegacyConfiguredLimitIsIgnored(): void {
		$extractor = $this->extractor(1);
		$this->assertSame("hello\n", $extractor->readText($this->file(40 * 1024 * 1024 + 1)));
	}

	public function testValidDocxEntryExceedsFormerZipEntryCeiling(): void {
		$extractor = $this->extractor(40);
		$text = str_repeat('A', FileReadLimit::ZIP_ENTRY_MAX_BYTES + 1);
		$docx = self::zip('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:t>' . $text . '</w:t></w:p></w:document>');
		$this->assertSame($text, $extractor->extractBytes($docx, 'large.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
	}

	/** 128M used to shrink the default to 16 MiB. The default, and a file between 16 and 20 MiB, stay readable. */
	public function testMemoryDirectiveIsNotConvertedToAnArbitraryFileLimit(): void {
		$extractor = $this->extractor(null, '128M');
		$this->assertSame(FileReadLimit::NATIVE_ONLY, $extractor->maxFileBytes());
		$this->assertSame("hello\n", $extractor->readText($this->file(25 * 1024 * 1024)));
	}

	/** readText of a PDF asks for the stored ceiling once and passes that same value into the decode allowance. */
	public function testPdfExtractionDoesNotReadLegacyFileLimitSetting(): void {
		$config = $this->createMock(\OCP\IConfig::class);
		$config->expects($this->never())->method('getAppValue');
		$temp = $this->createMock(ITempManager::class);
		$extractor = new TextExtractor($temp, new FileReadLimit($config));
		$pdf = \OCA\Mcp\Tests\Unit\Tools\BrokenTextSamples::pdf();
		$this->assertNotSame('', $extractor->readText($this->file(strlen($pdf), 'a.pdf', $pdf, 'application/pdf')));
	}

	private static function zip(string $entry, string $content): string {
		$path = tempnam(sys_get_temp_dir(), 'mcpzip');
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		$zip->addFromString($entry, $content);
		$zip->close();
		$bytes = (string)file_get_contents($path);
		unlink($path);
		return $bytes;
	}
}
