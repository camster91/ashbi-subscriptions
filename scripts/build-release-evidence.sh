#!/usr/bin/env bash
set -euo pipefail

archive="${1:-}"
if [[ -z "$archive" || ! -f "$archive" ]]; then
  printf 'Usage: %s PATH_TO_RELEASE_ZIP [EVIDENCE_PREFIX]\n' "$0" >&2
  exit 2
fi

archive_dir="$(cd "$(dirname "$archive")" && pwd)"
archive_path="$archive_dir/$(basename "$archive")"
prefix="${2:-${archive_path%.zip}}"
checksum_path="${prefix}.sha256"
manifest_path="${prefix}.manifest"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

source_date_epoch="${SOURCE_DATE_EPOCH:-$(git -C "$repo_root" log -1 --format=%ct 2>/dev/null || true)}"
if [[ ! "$source_date_epoch" =~ ^[0-9]+$ ]]; then
  printf 'SOURCE_DATE_EPOCH or a repository commit timestamp is required.\n' >&2
  exit 2
fi

source_commit="$(git -C "$repo_root" rev-parse HEAD 2>/dev/null || true)"
if [[ ! "$source_commit" =~ ^[0-9a-f]{40}$ ]]; then
  printf 'A full source commit is required for release evidence.\n' >&2
  exit 2
fi
source_branch="$(git -C "$repo_root" branch --show-current 2>/dev/null || true)"
source_branch="${source_branch:-detached-or-unavailable}"
if [[ -n "$(git -C "$repo_root" status --porcelain 2>/dev/null)" ]]; then
  dirty_tree='yes'
else
  dirty_tree='no'
fi

if command -v sha256sum >/dev/null 2>&1; then
  hash_stream() {
    sha256sum | awk '{print $1}'
  }
else
  hash_stream() {
    shasum -a 256 | awk '{print $1}'
  }
fi

if [[ "$dirty_tree" == 'yes' ]]; then
  # The patch hash covers tracked staged/unstaged content; the status hash
  # covers the complete path boundary, including untracked files, without
  # copying customer data or secrets into the evidence manifest.
  dirty_patch_sha256="$(git -C "$repo_root" diff --binary --no-ext-diff HEAD -- . ':!dist/*' | hash_stream)"
  dirty_status_sha256="$(git -C "$repo_root" status --porcelain=v1 | hash_stream)"
else
  dirty_patch_sha256='none'
  dirty_status_sha256='none'
fi

plugin_version="$(awk -F': ' '/^ \* Version:/{print $2; exit}' "$repo_root/plugin/subscription.php" | tr -d '\r')"
if [[ -z "$plugin_version" ]]; then
  printf 'The plugin version could not be identified from the main plugin file.\n' >&2
  exit 2
fi
if ! command -v php >/dev/null 2>&1; then
  printf 'PHP is required to record release tool identity.\n' >&2
  exit 2
fi
php_version="$(php -r 'echo PHP_VERSION;' 2>/dev/null)"
if [[ -z "$php_version" ]]; then
  printf 'The PHP runtime version could not be identified.\n' >&2
  exit 2
fi

if command -v shasum >/dev/null 2>&1; then
  build_script_sha256="$(shasum -a 256 "$repo_root/scripts/build-release.sh" | awk '{print $1}')"
  evidence_script_sha256="$(shasum -a 256 "$repo_root/scripts/build-release-evidence.sh" | awk '{print $1}')"
else
  build_script_sha256="$(sha256sum "$repo_root/scripts/build-release.sh" | awk '{print $1}')"
  evidence_script_sha256="$(sha256sum "$repo_root/scripts/build-release-evidence.sh" | awk '{print $1}')"
fi

if command -v sha256sum >/dev/null 2>&1; then
  archive_sha256="$(sha256sum "$archive_path" | awk '{print $1}')"
else
  archive_sha256="$(shasum -a 256 "$archive_path" | awk '{print $1}')"
fi

printf '%s  %s\n' "$archive_sha256" "$(basename "$archive_path")" > "$checksum_path"

{
  printf 'archive=%s\n' "$(basename "$archive_path")"
  printf 'archive_sha256=%s\n' "$archive_sha256"
  printf 'source_checkout=%s\n' "$repo_root"
  printf 'source_commit=%s\n' "$source_commit"
  printf 'source_branch=%s\n' "$source_branch"
  printf 'dirty_tree=%s\n' "$dirty_tree"
  printf 'dirty_patch_sha256=%s\n' "$dirty_patch_sha256"
  printf 'dirty_status_sha256=%s\n' "$dirty_status_sha256"
  printf 'plugin_version=%s\n' "$plugin_version"
  printf 'source_date_epoch=%s\n' "$source_date_epoch"
  printf 'php_version=%s\n' "$php_version"
  printf 'build_script_sha256=%s\n' "$build_script_sha256"
  printf 'evidence_script_sha256=%s\n' "$evidence_script_sha256"
  printf 'members_sha256:\n'

  while IFS= read -r member; do
    [[ "$member" == */ ]] && continue
    if command -v sha256sum >/dev/null 2>&1; then
      member_sha256="$(unzip -p "$archive_path" "$member" | sha256sum | awk '{print $1}')"
    else
      member_sha256="$(unzip -p "$archive_path" "$member" | shasum -a 256 | awk '{print $1}')"
    fi
    printf '%s  %s\n' "$member_sha256" "$member"
  done < <(unzip -Z1 "$archive_path" | LC_ALL=C sort)
} > "$manifest_path"

printf '%s\n' "$checksum_path"
printf '%s\n' "$manifest_path"
