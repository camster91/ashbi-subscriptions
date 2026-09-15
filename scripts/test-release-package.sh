#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d)"

cleanup() {
  rm -rf "$test_root"
}
trap cleanup EXIT

archive="$test_root/ashbi-subscriptions.zip"
bash "$repo_root/scripts/build-release.sh" "$archive" >/dev/null

unzip -tq "$archive" >/dev/null
unzip -Z1 "$archive" > "$test_root/contents.txt"

grep -qx 'subscription/subscription.php' "$test_root/contents.txt"
if grep -q '^subscription/tests/' "$test_root/contents.txt"; then
  printf 'Release package contains integration tests.\n' >&2
  exit 1
fi

if grep -Eq '^subscription/assets/images/(logo(-title)?\.(png|svg)|icons/subscription-20(-gray)?\.png)$' "$test_root/contents.txt"; then
  printf 'Release package contains retired upstream brand assets.\n' >&2
  exit 1
fi

printf 'Release package checks passed.\n'
