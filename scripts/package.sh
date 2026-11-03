#!/usr/bin/env bash
# Build dist/spa-ai-concierge.zip: installable as-is (Plugins > Add New > Upload, or WP-CLI).
# Contains only runtime files; no vendor/, tests or dev tooling. The plugin's own autoloader
# loads src/ when vendor/ is absent.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="spa-ai-concierge"
BUILD="$ROOT/dist/build"
TARGET="$BUILD/$SLUG"

rm -rf "$BUILD" "$ROOT/dist/$SLUG.zip"
mkdir -p "$TARGET"

cp "$ROOT/$SLUG.php" "$ROOT/uninstall.php" "$ROOT/README.md" "$TARGET/"
cp -R "$ROOT/src" "$ROOT/blocks" "$ROOT/assets" "$ROOT/languages" "$TARGET/"
find "$TARGET" -name '.DS_Store' -delete

# Fail the build on a syntax error.
find "$TARGET" -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

(cd "$BUILD" && zip -qr "$ROOT/dist/$SLUG.zip" "$SLUG")
rm -rf "$BUILD"

echo "Built dist/$SLUG.zip"
unzip -l "$ROOT/dist/$SLUG.zip" | tail -1
