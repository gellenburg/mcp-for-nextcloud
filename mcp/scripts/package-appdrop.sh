#!/bin/sh
# SPDX-FileCopyrightText: 2026 MCP for Cloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Build an AppDrop-compatible ZIP from the upstream production tarball.
set -eu
cd "$(dirname "$0")/.."
sh scripts/package.sh
version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
archive="$(pwd)/build/mcp-$version.tar.gz"
zipfile="$(pwd)/build/mcp-$version-appdrop.zip"
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
tar -xzf "$archive" -C "$stage"
test -f "$stage/mcp/appinfo/info.xml"
test -f "$stage/mcp/vendor/autoload.php"
rm -f "$zipfile"
# Include directory entries for AppDrop compatibility; do not use zip -D.
(cd "$stage" && zip -q -r "$zipfile" mcp)
unzip -tq "$zipfile"
echo "$zipfile"
