<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\FileTypes;

use InvalidArgumentException;
use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Native-only admission is immutable; legacy settings are retained for rollback, never consulted. */
final class FileWritePolicyTest extends TestCase {
	public function testTheEffectivePolicyIsAlwaysUnrestricted(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects(self::never())->method('hasKey');
		$config->expects(self::never())->method('getValueString');
		$config->expects(self::never())->method('clearCache');
		$policy = new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class));
		self::assertSame(['version' => 1, 'mode' => 'off', 'extensions' => [], 'allowExtensionless' => true], $policy->current());
	}

	/** @return iterable<string, array{string}> Former restrictive or corrupt persisted values. */
	public static function legacySettings(): iterable {
		foreach (['', 'null', '[]', 'broken', '{"version":1,"mode":"allowlist","extensions":["txt"],"allowExtensionless":false}'] as $i => $raw) {
			yield 'legacy-' . $i => [$raw];
		}
	}

	#[DataProvider('legacySettings')]
	public function testLegacyValuesCannotRestrictTheFork(string $raw): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($raw);
		$config->expects(self::never())->method('getValueString');
		$config->expects(self::never())->method('setValueString');
		$config->expects(self::never())->method('deleteKey');
		$policy = new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class));
		self::assertSame(FileWritePolicy::defaults(), $policy->current());
		self::assertSame(FileWritePolicy::defaults(), $policy->save('allowlist', ['txt'], false));
		self::assertSame(FileWritePolicy::defaults(), $policy->current());
	}

	public function testUnavailableLegacyConfigurationCannotBlockNativeFileAccess(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willThrowException(new \RuntimeException('legacy setting unavailable'));
		$config->expects(self::never())->method('getValueString');
		$policy = new FileWritePolicy($config, $this->createMock(\OCP\IDBConnection::class));
		self::assertSame('off', $policy->current()['mode']);
	}
}
