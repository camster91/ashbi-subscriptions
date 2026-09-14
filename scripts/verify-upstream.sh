#!/usr/bin/env bash
set -euo pipefail

readonly VERSION="2.0.0"
readonly EXPECTED_SHA256="232e0eb4bfb5535d1ddcb4de19aba2d60f2461b14f5af1c1354919faa9842a90"
readonly DOWNLOAD_URL="https://downloads.wordpress.org/plugin/subscription.${VERSION}.zip"
readonly IMPORT_COMMIT="5508935"

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work_dir="$(mktemp -d "${TMPDIR:-/tmp}/ashbi-upstream-verify.XXXXXX")"
trap 'rm -rf "$work_dir"' EXIT

curl --fail --silent --show-error --location "$DOWNLOAD_URL" \
  --output "$work_dir/subscription.zip"

actual_sha256="$(shasum -a 256 "$work_dir/subscription.zip" | awk '{print $1}')"
if [[ "$actual_sha256" != "$EXPECTED_SHA256" ]]; then
  printf 'SHA-256 mismatch: expected %s, got %s\n' "$EXPECTED_SHA256" "$actual_sha256" >&2
  exit 1
fi

unzip -q "$work_dir/subscription.zip" -d "$work_dir/extracted"

mkdir -p "$work_dir/imported"
git -C "$repo_root" archive "${IMPORT_COMMIT}:plugin" | tar -xf - -C "$work_dir/imported"

diff -ruN \
  --exclude='.DS_Store' \
  "$work_dir/extracted/subscription" \
  "$work_dir/imported"

printf 'Verified subscription %s (%s) against import commit %s.\n' \
	"$VERSION" "$actual_sha256" "$IMPORT_COMMIT"
