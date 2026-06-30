#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MODULE_DIR="$(basename "$SCRIPT_DIR")"
PARENT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PARENT_DIR"

VERSION=$(grep -oP '\$this->version\s*=\s*'\''\K[^'\'']+' "$MODULE_DIR/$MODULE_DIR.php")
OUTPUT="$MODULE_DIR/nomba-prestashop-${VERSION}.zip"

rm -f "$OUTPUT"

zip -r "$OUTPUT" "$MODULE_DIR" \
    -x "$MODULE_DIR/.git/*" \
    -x "$MODULE_DIR/.env" \
    -x "*/.*" \
    -x "*/vendor/*"

echo "Created $OUTPUT"
