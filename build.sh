#!/bin/bash

set -e
shopt -s nullglob

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$DIR/module"
TMP="/tmp/prestashop/scanpay"

# Get the verison number from package.json
VERSION=$(node -p "require('$DIR/package.json').version")
echo -e "Building version: \033[0;31m$VERSION\033[0m\n"

if [ -d "$TMP" ]; then
    rm -rf "${TMP:?}/"*
    echo "Contents of $TMP have been removed."
else
    mkdir -p "$TMP"
fi

# Copy static files to the build directory
rsync -am "$SRC/" "$TMP/"

# Convert SASS to CSS
#"$DIR/node_modules/.bin/sass" --style compressed --no-source-map --verbose "$SRC/public/css/":"$BUILD/public/css/"

# Compile TypeScript to JavaScript (+minify)
#for file in "$SRC/public/js/"*.ts; do
#    echo "Compiling $file"
#    "$DIR/node_modules/.bin/esbuild" --bundle --minify "$file" --outfile="$BUILD/public/js/$(basename "$file" .ts).js"
#done

# Insert the version number into the files
for file in $(find "$TMP" -type f \( -name "*.php" -o -name "*.js" \)); do
    if grep -q "{{ VERSION }}" "$file"; then
        sed -i "s/{{ VERSION }}/$VERSION/g" "$file"
    fi
done

# Create a zip file
cd "$TMP"
cd ..
zip -r "$DIR/prestashop-scanpay-$VERSION.zip" scanpay

# Create a zip file for the test environment
for file in $(find "$TMP" -type f -name "*.php"); do
    mtime=$(stat -c %y "$file")
    sed -i 's/dashboard\.scanpay\.dk/dashboard\.scanpay\.dev/' "$file"
    sed -i 's/api\.scanpay\.dk/api\.scanpay\.dev/g' "$file"
    touch -d "$mtime" "$file"
done

zip -r "$DIR/test-$VERSION.zip" scanpay
