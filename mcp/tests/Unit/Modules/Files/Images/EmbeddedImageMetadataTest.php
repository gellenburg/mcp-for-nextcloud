<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Cloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Images;

use OCA\Mcp\Modules\Files\Images\EmbeddedImageMetadata;
use PHPUnit\Framework\TestCase;

final class EmbeddedImageMetadataTest extends TestCase {
	private static function chunk(string $type, string $data): string {
		return pack('N', strlen($data)) . $type . $data . hash('crc32b', $type . $data, true);
	}

	public function testPngDescriptionCompressedTextAndXmp(): void {
		$xml = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:dc="http://purl.org/dc/elements/1.1/"><rdf:Description><dc:description><rdf:Alt><rdf:li xml:lang="x-default">Android assembly plate</rdf:li></rdf:Alt></dc:description></rdf:Description></rdf:RDF>';
		$png = "\x89PNG\r\n\x1a\n" . self::chunk('tEXt', "Description\0Plain description")
			. self::chunk('zTXt', "Comment\0\0" . gzcompress('Compressed comment'))
			. self::chunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0" . $xml)
			. self::chunk('IEND', '');
		self::assertSame('Plain description', EmbeddedImageMetadata::extract($png)['PNG:Description']);
		self::assertSame('Compressed comment', EmbeddedImageMetadata::extract($png)['PNG:Comment']);
		self::assertSame('Android assembly plate', EmbeddedImageMetadata::extract($png)['XMP:dc:description']);
	}

	public function testJpegExifDescription(): void {
		$value = "Android\0";
		$tiff = 'II' . pack('vV', 42, 8) . pack('v', 1) . pack('vvVV', 0x010e, 2, strlen($value), 26) . pack('V', 0) . $value;
		$app = "Exif\0\0" . $tiff;
		$jpeg = "\xff\xd8\xff\xe1" . pack('n', strlen($app) + 2) . $app . "\xff\xd9";
		self::assertSame(['EXIF:ImageDescription' => 'Android'], EmbeddedImageMetadata::extract($jpeg));
	}

	/** A differently encoded DTD must not bypass the XML declaration rejection. */
	public function testUtf16DoctypeIsRejected(): void {
		$xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE rdf:RDF [<!ENTITY secret "not metadata">]><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:dc="http://purl.org/dc/elements/1.1/"><rdf:Description><dc:description>&secret;</dc:description></rdf:Description></rdf:RDF>';
		$packet = "\xff\xfe" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');
		$png = "\x89PNG\r\n\x1a\n" . self::chunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0" . $packet);
		self::assertSame([], EmbeddedImageMetadata::extract($png));
	}

	public function testMalformedChunksAndXmlEntitiesAreNotRead(): void {
		$xml = '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>';
		$png = "\x89PNG\r\n\x1a\n" . self::chunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0" . $xml)
			. pack('N', 0x7fffffff) . 'tEXt';
		self::assertSame([], EmbeddedImageMetadata::extract($png));
	}
}
