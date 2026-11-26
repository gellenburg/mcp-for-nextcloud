<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Cloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

/** Bounded, non-mutating metadata reader. Embedded text is untrusted file content, never instructions. */
final class EmbeddedImageMetadata {
	private const MAX_FIELD = 16384;
	private const MAX_PACKET = 262144;
	private const MAX_FIELDS = 64;

	/** @return array<string,mixed> */
	public static function extract(string $bytes): array {
		$out = [];
		if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
			self::png($bytes, $out);
		} elseif (str_starts_with($bytes, "\xff\xd8")) {
			self::jpeg($bytes, $out);
		}
		return $out;
	}

	private static function add(array &$out, string $key, string $value): void {
		if (count($out) >= self::MAX_FIELDS && !isset($out[$key])) { return; }
		$value = trim(mb_scrub(substr($value, 0, self::MAX_FIELD), 'UTF-8'), "\x00 \t\r\n");
		if ($value !== '' && !isset($out[$key])) { $out[$key] = $value; }
	}

	private static function png(string $bytes, array &$out): void {
		$end = strlen($bytes);
		for ($at = 8; $at + 12 <= $end;) {
			$len = unpack('N', $bytes, $at)[1];
			if ($len > $end - $at - 12) { break; }
			$type = substr($bytes, $at + 4, 4);
			if ($type === 'IEND') { break; }
			if ($len <= self::MAX_PACKET && in_array($type, ['tEXt','zTXt','iTXt','eXIf'], true)) {
				$data = substr($bytes, $at + 8, $len);
				// Ignore corrupted metadata chunks rather than returning damaged content.
				if (hash('crc32b', $type . $data, true) === substr($bytes, $at + 8 + $len, 4)) {
					if ($type === 'eXIf') { self::tiff($data, $out); }
					else { self::pngText($type, $data, $out); }
				}
			}
			$at += $len + 12;
		}
	}

	private static function pngText(string $type, string $data, array &$out): void {
		$split = strpos($data, "\0");
		if ($split === false || $split < 1 || $split > 79) { return; }
		$key = mb_convert_encoding(substr($data, 0, $split), 'UTF-8', 'ISO-8859-1');
		$value = substr($data, $split + 1);
		if ($type === 'zTXt') {
			if ($value === '' || $value[0] !== "\0") { return; }
			$value = @gzuncompress(substr($value, 1), self::MAX_PACKET);
		} elseif ($type === 'iTXt') {
			if (strlen($value) < 4 || !in_array(ord($value[0]), [0,1], true) || $value[1] !== "\0") { return; }
			$compressed = $value[0] === "\1";
			$fields = explode("\0", substr($value, 2), 3);
			if (count($fields) !== 3) { return; }
			$value = $compressed ? @gzuncompress($fields[2], self::MAX_PACKET) : $fields[2];
		}
		if (!is_string($value)) { return; }
		if ($type !== 'iTXt') { $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1'); }
		if ($key === 'XML:com.adobe.xmp') { self::xmp($value, $out); return; }
		self::add($out, 'PNG:' . $key, $value);
	}

	private static function jpeg(string $bytes, array &$out): void {
		$end = strlen($bytes);
		for ($at = 2; $at < $end;) {
			if ($bytes[$at++] !== "\xff") { break; }
			while ($at < $end && $bytes[$at] === "\xff") { $at++; }
			if ($at >= $end) { break; }
			$marker = ord($bytes[$at++]);
			if ($marker === 0xda || $marker === 0xd9) { break; }
			if ($marker === 1 || ($marker >= 0xd0 && $marker <= 0xd7)) { continue; }
			if ($at + 2 > $end) { break; }
			$len = unpack('n', $bytes, $at)[1];
			if ($len < 2 || $len > $end - $at) { break; }
			$data = substr($bytes, $at + 2, $len - 2);
			if ($marker === 0xe1 && str_starts_with($data, "Exif\0\0")) {
				self::tiff(substr($data, 6), $out);
			} elseif ($marker === 0xe1 && str_starts_with($data, "http://ns.adobe.com/xap/1.0/\0")) {
				self::xmp(substr($data, strlen("http://ns.adobe.com/xap/1.0/\0")), $out);
			} elseif ($marker === 0xed && function_exists('iptcparse')) {
				$tags = @iptcparse($data);
				if (is_array($tags)) {
					foreach (['2#005'=>'ObjectName','2#025'=>'Keywords','2#105'=>'Headline','2#120'=>'Caption'] as $tag => $name) {
						if (isset($tags[$tag]) && is_array($tags[$tag])) {
							self::add($out, 'IPTC:' . $name, implode("\n", array_slice($tags[$tag], 0, 32)));
						}
					}
				}
			} elseif ($marker === 0xfe) {
				self::add($out, 'JPEG:Comment', $data);
			}
			$at += $len;
		}
	}

	private static function xmp(string $xml, array &$out): void {
		if (strlen($xml) > self::MAX_PACKET || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false || !class_exists(\DOMDocument::class)) { return; }
		$doc = new \DOMDocument();
		if (!@$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) { return; }
		$xpath = new \DOMXPath($doc);
		$xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
		$xpath->registerNamespace('photoshop', 'http://ns.adobe.com/photoshop/1.0/');
		$xpath->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
		foreach (['dc:title','dc:description','dc:subject','photoshop:Headline','photoshop:CaptionWriter','photoshop:Instructions'] as $field) {
			$values = [];
			foreach ($xpath->query('//' . $field . '|//@' . $field) ?: [] as $node) {
				$items = $node instanceof \DOMElement ? $xpath->query('.//rdf:li', $node) : false;
				if ($items !== false && $items->length > 0) {
					foreach ($items as $item) {
						$values[] = substr($item->textContent, 0, self::MAX_FIELD);
						if (count($values) >= 32) { break; }
					}
				} else { $values[] = substr($node->textContent, 0, self::MAX_FIELD); }
				if (count($values) >= 32) { break; }
			}
			self::add($out, 'XMP:' . $field, implode("\n", array_unique($values)));
		}
	}

	private static function tiff(string $data, array &$out): void {
		$size = strlen($data);
		if ($size < 8 || !in_array(substr($data, 0, 2), ['II','MM'], true)) { return; }
		$little = substr($data, 0, 2) === 'II';
		$u16 = static fn (int $at): int => unpack($little ? 'v' : 'n', $data, $at)[1];
		$u32 = static fn (int $at): int => unpack($little ? 'V' : 'N', $data, $at)[1];
		if ($u16(2) !== 42) { return; }
		$queue = [$u32(4)]; $seen = [];
		$tags = [0x010e=>'ImageDescription',0x013b=>'Artist',0x8298=>'Copyright',0x9286=>'UserComment',0x9c9b=>'XPTitle',0x9c9c=>'XPComment',0x9c9d=>'XPAuthor',0x9c9e=>'XPKeywords',0x9c9f=>'XPSubject'];
		while ($queue !== [] && count($seen) < 4) {
			$offset = array_shift($queue);
			if (isset($seen[$offset]) || $offset < 8 || $offset > $size - 2) { continue; }
			$seen[$offset] = true;
			$count = $u16($offset);
			if ($count > 1024 || $count > intdiv($size - $offset - 2, 12)) { continue; }
			for ($i = 0; $i < $count; $i++) {
				$at = $offset + 2 + $i * 12;
				$tag = $u16($at); $type = $u16($at + 2); $n = $u32($at + 4);
				if ($tag === 0x8769 && $type === 4 && $n === 1) { $queue[] = $u32($at + 8); continue; }
				if (!isset($tags[$tag]) || !in_array($type, [1,2,7], true) || $n < 1 || $n > self::MAX_PACKET) { continue; }
				$start = $n <= 4 ? $at + 8 : $u32($at + 8);
				if ($start > $size - $n) { continue; }
				$v = substr($data, $start, $n);
				if ($tag >= 0x9c9b && $tag <= 0x9c9f) { $v = mb_convert_encoding($v, 'UTF-8', 'UTF-16LE'); }
				elseif ($tag === 0x9286) {
					$encoding = substr($v, 0, 8); $v = substr($v, 8);
					if ($encoding === "UNICODE\0") { $v = mb_convert_encoding($v, 'UTF-8', $little ? 'UTF-16LE' : 'UTF-16BE'); }
				}
				self::add($out, 'EXIF:' . $tags[$tag], $v);
			}
		}
	}
}
