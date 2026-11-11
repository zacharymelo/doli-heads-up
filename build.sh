#!/usr/bin/env bash
# Build an installable Dolibarr module zip: headsup-X.Y.Z.zip
# The zip must have "headsup/" at its top level, not "module/".
set -euo pipefail

MODULE=headsup
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

VERSION="$(grep -oE "version[[:space:]]*=[[:space:]]*'[0-9.]+'" "$ROOT/module/core/modules/modHeadsup.class.php" | grep -oE "[0-9.]+" | head -1)"
if [ -z "$VERSION" ]; then
	echo "Could not read version from the module descriptor" >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$MODULE"
cp -R "$ROOT/module/." "$STAGE/$MODULE/"
cp "$ROOT/README.md" "$STAGE/$MODULE/README.md"
find "$STAGE/$MODULE" -name '.DS_Store' -delete

OUT="$ROOT/$MODULE-$VERSION.zip"
rm -f "$OUT"
(cd "$STAGE" && zip -qr "$OUT" "$MODULE")
echo "Built $OUT"
