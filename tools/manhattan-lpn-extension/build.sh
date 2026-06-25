#!/usr/bin/env bash
# Baut das Store-Paket (dist/lpn-helfer.zip) zum Hochladen in den Chrome Web Store.
set -euo pipefail
cd "$(dirname "$0")"
node icons/make-icons.js >/dev/null
rm -rf dist && mkdir -p dist
zip -r -X dist/lpn-helfer.zip \
  manifest.json src \
  icons/icon16.png icons/icon48.png icons/icon128.png >/dev/null
echo "✓ dist/lpn-helfer.zip ($(du -h dist/lpn-helfer.zip | cut -f1))"
