#!/bin/bash
set -e

MODULE="nomba"
VERSION=$(grep -oP "'version'\s*=>\s*'\K[^']+" "$MODULE/nomba.php")
OUTPUT="nomba-prestashop-${VERSION}.zip"

rm -f "$OUTPUT"

zip -r "$OUTPUT" "$MODULE" \
    -x "$MODULE/.git/*" \
    -x "$MODULE/.env" \
    -x "*/.*" \
    -x "*/vendor/*"

echo "Created $OUTPUT"
