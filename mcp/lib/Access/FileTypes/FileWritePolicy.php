<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\FileTypes;

use InvalidArgumentException;
use OCA\Mcp\Access\FileTypes\FileTypeMessages;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Exceptions\AppConfigTypeConflictException;

/** Reads the current global MCP file-name policy and saves one canonical, versioned value. */
final class FileWritePolicy {
	public const APP = 'mcp';
	public const KEY = 'file_write_policy';
	public const MAX_EXTENSIONS = 128;

	/**
	 * @param IAppConfig $config Official Nextcloud app configuration, with no process cache here.
	 * @param IDBConnection $db The same official connection used by app configuration.
	 */
	public function __construct(private IAppConfig $config, private IDBConnection $db) {
	}

	/** @return array{version:int, mode:string, extensions:list<string>, allowExtensionless:bool} Factory policy. */
	public static function defaults(): array {
		// All extensions, including extensionless and dotfile names. Nextcloud still validates names.
		return ['version' => 1, 'mode' => 'off', 'extensions' => [], 'allowExtensionless' => true];
	}

	/** This private fork never layers a filename allowlist over native Nextcloud admission. */
	public function current(): array {
		return self::defaults();
	}

	/** Legacy settings calls remain compatible, but cannot re-enable an app-specific allowlist.
	 * Existing stored upstream configuration is deliberately preserved for rollback.
	 */
	public function save(string $mode, string|array $extensions, bool $allowExtensionless): array {
		return self::defaults();
	}
}
