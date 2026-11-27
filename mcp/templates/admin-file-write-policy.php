<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/** @var \OCP\IL10N $l */
?>
<div class="section mcp-block" id="mcp-file-write-policy">
	<h2><?php p($l->t('File types writable through MCP')); ?></h2>
	<p><strong>*.*</strong> — all extensions, extensionless names and dotfiles.</p>
	<p class="settings-hint">Nextcloud's native filename validation, permissions and storage policy still apply. PDF-specific extraction and preview require .pdf (case-insensitive).</p>
</div>
