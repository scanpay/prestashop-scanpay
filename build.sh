#!/bin/bash
set -euo pipefail
shopt -s nullglob

for cmd in node rsync zip sed grep stat touch; do
    command -v $cmd >/dev/null || { echo "Missing required tool: $cmd" >&2; exit 1; }
done

# Paths
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$DIR/module"
TMP="/tmp/prestashop/scanpay"
ZIP_PROD="$DIR/prestashop-scanpay"
ZIP_TEST="$DIR/test"

# Version
VERSION="$(node -p "require('$DIR/package.json').version")"
echo -e "Building version: \033[0;31m$VERSION\033[0m"

# Prepare build dir
rm -rf "$TMP"
mkdir -p "$TMP"

# Copy static files
rsync -am --quiet "$SRC/" "$TMP/"

# Replace version placeholders
find "$TMP" -type f \( -name "*.php" -o -name "*.js" \) -print0 |
while IFS= read -r -d '' file; do
    if grep -q "{{ VERSION }}" "$file"; then
        sed -i "s/{{ VERSION }}/$VERSION/g" "$file"
    fi
done

# Production zip
cd "$(dirname "$TMP")"
zip -qr "$ZIP_PROD-$VERSION.zip" "$(basename "$TMP")"
echo "Production zip created at $ZIP_PROD-$VERSION.zip"

# Test build: replace domains, preserve mtimes
find "$TMP" -type f -name "*.php" -print0 |
while IFS= read -r -d '' file; do
    mtime=$(stat -c %y "$file")
    sed -i 's/dashboard\.scanpay\.dk/dashboard.scanpay.dev/g' "$file"
    sed -i 's/api\.scanpay\.dk/api.scanpay.dev/g' "$file"
    touch -d "$mtime" "$file"
done

zip -qr "$ZIP_TEST-$VERSION.zip" "$(basename "$TMP")"
echo "Test zip created at $ZIP_TEST-$VERSION.zip"

# Cleanup
rm -rf "$TMP"
