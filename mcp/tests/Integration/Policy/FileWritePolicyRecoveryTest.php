<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Server;

/** Legacy configuration must remain intact in the real core store for rollback. */
final class FileWritePolicyRecoveryTest extends IntegrationTestCase {
	private bool $seeded = false;

	/** Seed an integer at the otherwise absent policy key in this disposable fixture only. */
	protected function setUp(): void {
		parent::setUp();
		$config = Server::get(IAppConfig::class);
		$config->clearCache(false);
		self::assertFalse($config->hasKey('mcp', FileWritePolicy::KEY, null));
		$config->setValueInt('mcp', FileWritePolicy::KEY, 7, false);
		$this->seeded = true;
	}

	/** Restore the fixture's absence after committing/rolling back the official transaction. */
	protected function tearDown(): void {
		parent::tearDown();
		if ($this->seeded) {
			$config = Server::get(IAppConfig::class);
			$config->deleteKey('mcp', FileWritePolicy::KEY);
			$config->clearCache(false);
		}
	}

	/** Saving through the legacy API does not touch the wrongly typed core value. */
	public function testLegacySavePreservesWrongCoreType(): void {
		$config = Server::get(IAppConfig::class);
		$policy = new FileWritePolicy($config, $this->official());
		self::assertSame(FileWritePolicy::defaults(), $policy->save('allowlist', ['txt'], false));
		self::assertSame(FileWritePolicy::defaults(), $policy->current());
		self::assertFalse($this->official()->inTransaction());
		$config->clearCache(false);
		self::assertSame(7, $config->getValueInt('mcp', FileWritePolicy::KEY, 0, false));
	}

	/** Native-only admission does not begin, commit or roll back an obsolete policy transaction. */
	public function testLegacySaveNeverStartsDatabaseTransaction(): void {
		$proxy = $this->createMock(IDBConnection::class);
		$proxy->expects(self::never())->method('beginTransaction');
		$proxy->expects(self::never())->method('commit');
		$proxy->expects(self::never())->method('rollBack');
		$config = Server::get(IAppConfig::class);
		$policy = new FileWritePolicy($config, $proxy);
		self::assertSame(FileWritePolicy::defaults(), $policy->save('allowlist', ['txt'], false));
		self::assertSame(FileWritePolicy::defaults(), $policy->current());
		$config->clearCache(false);
		self::assertSame(7, $config->getValueInt('mcp', FileWritePolicy::KEY, 0, false));
	}
}
