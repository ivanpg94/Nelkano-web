#!/usr/bin/env bash
set -euo pipefail
mode="${1:---dry-run}"
if [[ "$mode" != "--dry-run" && "$mode" != "--apply" ]]; then
  echo "Usage: bash scripts/import-sdk-book.sh [--dry-run|--apply]" >&2
  exit 2
fi
repo_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd -- "$repo_dir"
test -f content/books/nelkano-sdk.yml
./vendor/bin/drush --root=public_html book:import content/books/nelkano-sdk.yml --dry-run
if [[ "$mode" == "--apply" ]]; then
  ./vendor/bin/drush --root=public_html book:import content/books/nelkano-sdk.yml
  ./vendor/bin/drush --root=public_html cr
fi
