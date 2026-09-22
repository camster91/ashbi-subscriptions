#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d)"
input_archive="${1:-}"

cleanup() {
  rm -rf "$test_root"
}
trap cleanup EXIT

if [[ -n "$input_archive" ]]; then
  archive_dir="$(cd "$(dirname "$input_archive")" && pwd)"
  archive="$archive_dir/$(basename "$input_archive")"
  if [[ ! -f "$archive" ]]; then
    printf 'Release archive does not exist: %s\n' "$archive" >&2
    exit 2
  fi
else
  archive="$test_root/ashbi-subscriptions.zip"
  bash "$repo_root/scripts/build-release.sh" "$archive" >/dev/null
fi

bash "$repo_root/scripts/build-release-evidence.sh" "$archive" "$test_root/release" >/dev/null
bash "$repo_root/scripts/verify-release-evidence.sh" "$archive" "$test_root/release" >/dev/null

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

if grep -Eq '^subscription/assets/images/(integrations|other_plugins)/' "$test_root/contents.txt"; then
  printf 'Release package contains third-party integration logo assets.\n' >&2
  exit 1
fi

archive_sha256="$(awk '{print $1}' "$test_root/release.sha256")"
if [[ -z "$archive_sha256" ]] || ! grep -q "^archive_sha256=$archive_sha256$" "$test_root/release.manifest"; then
  printf 'Release evidence does not match the archive checksum.\n' >&2
  exit 1
fi

grep -Eq '^source_commit=[0-9a-f]{40}$' "$test_root/release.manifest"
grep -Eq '^source_branch=.+$' "$test_root/release.manifest"
grep -Eq '^dirty_tree=(yes|no)$' "$test_root/release.manifest"
grep -Eq '^dirty_patch_sha256=([0-9a-f]{64}|none)$' "$test_root/release.manifest"
grep -Eq '^dirty_status_sha256=([0-9a-f]{64}|none)$' "$test_root/release.manifest"
grep -Eq '^source_date_epoch=[0-9]+$' "$test_root/release.manifest"
grep -Eq '^php_version=.+$' "$test_root/release.manifest"
grep -Eq '^build_script_sha256=[0-9a-f]{64}$' "$test_root/release.manifest"
grep -Eq '^evidence_script_sha256=[0-9a-f]{64}$' "$test_root/release.manifest"

grep -q '  subscription/subscription.php$' "$test_root/release.manifest"
if grep -q '  subscription/tests/' "$test_root/release.manifest"; then
  printf 'Release evidence contains integration tests.\n' >&2
  exit 1
fi

printf 'Release package checks passed.\n'
