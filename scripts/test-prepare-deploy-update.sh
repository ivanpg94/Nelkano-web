#!/usr/bin/env bash
set -euo pipefail
# Actual local Git repositories. No network, website or deployment is involved.
script="$(cd -- "$(dirname -- "$0")" && pwd -P)/prepare-deploy-update.sh"
test_root="$(cd -- "$(dirname -- "$0")/.." && pwd -P)/outputs/deploy-update"
mkdir -p -- "$test_root"
base="$(mktemp -d "$test_root/fixture-XXXXXX")"
cleanup() {
  local resolved
  resolved="$(cd -- "$base" && pwd -P)"
  case "$resolved/" in "$test_root"/fixture-*/) rm -rf -- "$resolved";; *) echo 'Unsafe fixture cleanup target' >&2; return 1;; esac
}
trap cleanup EXIT
for mode in clean runtime included-source unrelated-source staged-source; do
  repo="$base/$mode/repo"; mkdir -p "$repo" "$base/$mode/backup"
  git -C "$base/$mode" init -q -b main repo
  git -C "$repo" config user.name 'Local deployment fixture'
  git -C "$repo" config user.email 'fixture@nelkano.test'
  git -C "$repo" config core.autocrlf false
  mkdir -p "$repo/public_html" "$repo/release-artifacts"
  printf 'old source\n' > "$repo/config.json"
  printf 'old log\n' > "$repo/public_html/error_log"
  printf 'old artifact\n' > "$repo/release-artifacts/old.msi"
  git -C "$repo" add .; git -C "$repo" commit -qm old
  old="$(git -C "$repo" rev-parse HEAD)"
  printf '/public_html/error_log\n/release-artifacts/*.msi\n' > "$repo/.gitignore"
  printf 'new source\n' > "$repo/config.json"
  git -C "$repo" rm -q -- public_html/error_log release-artifacts/old.msi
  git -C "$repo" add .; git -C "$repo" commit -qm prepared
  next="$(git -C "$repo" rev-parse HEAD)"
  git -C "$repo" reset --hard -q "$old"
  if [[ "$mode" = runtime || "$mode" = included-source ]]; then
    printf 'live log data\n' > "$repo/public_html/error_log"
    printf 'real historic installer\n' > "$repo/release-artifacts/old.msi"
    printf 'new generated installer\n' > "$repo/release-artifacts/new.msi"
  fi
  case "$mode" in
    included-source) printf 'new source\r\n' > "$repo/config.json";;
    unrelated-source) printf 'unpublished server change\n' > "$repo/config.json";;
    staged-source) printf 'staged server change\n' > "$repo/config.json"; git -C "$repo" add config.json;;
  esac
  if bash "$script" "$repo" "$next" "$base/$mode/backup" > "$base/$mode/result.log" 2>&1; then result=0; else result=$?; fi
  if [[ "$mode" = unrelated-source || "$mode" = staged-source ]]; then
    test "$result" != 0
    test "$(git -C "$repo" rev-parse HEAD)" = "$old"
    if [[ "$mode" = unrelated-source ]]; then grep -q 'unpublished server change' "$repo/config.json"; else grep -q 'staged server change' "$repo/config.json"; fi
    test ! -e "$base/$mode/backup/checkout-state"
  else
    if [[ "$result" != 0 ]]; then cat "$base/$mode/result.log"; exit 1; fi
    test "$(git -C "$repo" rev-parse HEAD)" = "$next"
    test -z "$(git -C "$repo" status --porcelain)"
    if [[ "$mode" = runtime || "$mode" = included-source ]]; then
      grep -q 'live log data' "$repo/public_html/error_log"
      grep -q 'real historic installer' "$repo/release-artifacts/old.msi"
      grep -q 'new generated installer' "$repo/release-artifacts/new.msi"
    fi
  fi
  printf 'PASS: %s\n' "$mode"
done
echo 'SUCCESS: 5 actual Git migration scenarios; no remote operations.'
