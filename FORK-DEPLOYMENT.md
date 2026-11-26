<!--
SPDX-FileCopyrightText: 2026 MCP for Cloud contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Fork deployment

The fork retains the native Nextcloud app ID `mcp`, database schema and grants. It is not a separate application or an ExApp. The fork version is 1.4.1, based on upstream 1.4.0.

## Build and validation

From the repository root on a machine with PHP, Composer, zip and unzip:

    cd mcp
    composer install
    vendor/bin/phpunit
    sh scripts/package-appdrop.sh

The packaging script runs the upstream production archive builder and checker first, then produces `mcp/build/mcp-1.4.1-appdrop.zip` with a single mcp/ root, production dependencies and explicit directory entries. It does not include the repository checkout or development dependencies.

Do not install this development branch until the relevant unit/integration tests pass. When upgrading an existing 1.4.0 installation, retain an AppDrop backup and keep the current configuration. Do not treat an upstream update as a fork update.

## Tool behavior

- Catalog writes execute under the active grants and native resource permissions. Omit confirmation fields for a direct call. Explicit `confirm: false` requests a preview. `confirm_shared: false` excludes shared effects.
- Existing state receipts and ETags remain valid preconditions. State changes can abort a call; no automatic mutation retry is performed.
- Client approval controls are independent of the server and are not changed by the fork.
- `files_read` with mode download uses the read grant and returns only a five-minute, single-use original-byte download capability. No upload capability is issued.
- `files_read` with mode metadata returns bounded embedded PNG/JPEG metadata as untrusted data. It does not rewrite the image or invoke OCR.
