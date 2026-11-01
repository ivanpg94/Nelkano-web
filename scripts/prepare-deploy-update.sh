#!/usr/bin/env bash
set -Eeuo pipefail
# One-time transition from older checkouts. No network, commits or deployment.
# Usage: bash prepare-deploy-update.sh REPOSITORY COMMIT BACKUP_DIRECTORY
test "$#" = 3
cd -- "$1"
test -z "$(git rev-parse --show-prefix)"
test "$(git branch --show-current)" = main
revision="$2"
[[ "$revision" =~ ^[a-f0-9]{40}$ ]]
git cat-file -e "$revision^{commit}"
git merge-base --is-ancestor HEAD "$revision"
git diff --cached --quiet || { echo 'Stop: the index contains changes to review.' >&2; exit 1; }
repo="$(pwd -P)"
test -d "$3"
backup="$(cd -- "$3" && pwd -P)/checkout-state"
case "$backup/" in "$repo/"*) echo 'Backup must be outside the checkout.' >&2; exit 1;; esac
test ! -e "$backup"

runtime() {
  case "$1" in
    public_html/error_log|public_html/error_log.*|release-artifacts/*.exe|release-artifacts/*.msi|release-artifacts/*.zip|'private/nelkano-releases/Nelkano SDK Studio 1.0.0-beta-windows.zip')
      ! git cat-file -e "$revision:$1" 2>/dev/null;;
    *) return 1;;
  esac
}
same_text() {
  local ref="$1" path="$2"
  case "$path" in
    *.php|*.cjs|*.js|*.mjs|*.json|*.yml|*.yaml|*.ini|*.md|*.txt|*.sh|*.twig|.gitattributes|.gitignore|private/.htaccess|public_html/.htaccess) ;;
    *) return 1;;
  esac
  git cat-file -e "$ref:$path" 2>/dev/null || return 1
  cmp -s <(sed 's/\r$//' -- "$path") <(git show "$ref:$path" | sed 's/\r$//')
}
sources=(); generated=(); rejected=()
while IFS= read -r -d '' entry; do
  state="${entry:0:2}"; path="${entry:3}"
  if [[ "$state" = *R* || "$state" = *C* ]]; then echo 'Stop: renamed paths require review.' >&2; exit 1; fi
  if [[ -L "$path" || ! -f "$path" ]]; then rejected+=("$path"); continue; fi
  if runtime "$path"; then generated+=("$path")
  elif [[ "$state" = ' M' ]] && { same_text HEAD "$path" || same_text "$revision" "$path"; }; then sources+=("$path")
  else rejected+=("$path"); fi
done < <(git status --porcelain=v1 -z --untracked-files=all)
if (( ${#rejected[@]} )); then
  printf 'Stop: source changes are not contained in the prepared commit; preserve/review them first:\n' >&2
  printf '  %s\n' "${rejected[@]}" >&2
  exit 1
fi
# Preserve even unchanged files that the incoming tracking migration will remove.
for path in public_html/error_log 'release-artifacts/Nelkano 1.0.0-beta-steamos.zip' 'release-artifacts/Nelkano 1.0.0-beta-windows.msi' 'release-artifacts/Nelkano SDK Studio 1.0.0-beta-windows.zip' 'private/nelkano-releases/Nelkano SDK Studio 1.0.0-beta-windows.zip'; do
  if [[ -f "$path" && ! -L "$path" ]] && runtime "$path"; then generated+=("$path"); fi
done
mkdir -p -- "$backup/files"
git rev-parse HEAD > "$backup/revision.txt"
git diff --no-ext-diff > "$backup/source.patch"
for path in "${generated[@]}" "${sources[@]}"; do
  mkdir -p -- "$backup/files/$(dirname -- "$path")"
  cp -p -- "$path" "$backup/files/$path"
  cmp -s -- "$path" "$backup/files/$path"
done
original="$(git rev-parse HEAD)"
restore_preserved() {
  local code=$?
  trap - EXIT
  for path in "${generated[@]}"; do
    if [[ ! -e "$path" ]]; then mkdir -p -- "$(dirname -- "$path")"; cp -p -- "$backup/files/$path" "$path"; fi
  done
  if (( code != 0 )); then
    if [[ "$(git rev-parse HEAD)" = "$original" ]]; then
      for path in "${sources[@]}"; do cp -p -- "$backup/files/$path" "$path"; done
      for path in "${generated[@]}"; do cp -p -- "$backup/files/$path" "$path"; done
    fi
    printf 'Update stopped; preserved files are in %s\n' "$backup" >&2
  fi
  exit "$code"
}
trap restore_preserved EXIT
for path in "${generated[@]}"; do
  if git cat-file -e "HEAD:$path" 2>/dev/null; then git show "HEAD:$path" > "$path"; else rm -- "$path"; fi
done
for path in "${sources[@]}"; do git show "HEAD:$path" > "$path"; done
git merge --ff-only "$revision"
test "$(git rev-parse HEAD)" = "$revision"
for path in "${generated[@]}"; do
  if [[ ! -e "$path" ]]; then mkdir -p -- "$(dirname -- "$path")"; cp -p -- "$backup/files/$path" "$path"; fi
  git check-ignore -q -- "$path"
done
test -z "$(git status --porcelain --untracked-files=normal)"
printf 'Checkout updated and clean. Existing runtime copies preserved; backup: %s\n' "$backup"
