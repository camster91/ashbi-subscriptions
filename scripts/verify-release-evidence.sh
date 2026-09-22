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
if [[ ! -f "$checksum_path" || ! -f "$manifest_path" ]]; then
	printf 'Release evidence files are required: %s and %s\n' "$checksum_path" "$manifest_path" >&2
	exit 2
fi

if command -v sha256sum >/dev/null 2>&1; then
	hash_file() {
		sha256sum "$1" | awk '{print $1}'
	}
	hash_stream() {
		sha256sum | awk '{print $1}'
	}
else
	hash_file() {
		shasum -a 256 "$1" | awk '{print $1}'
	}
	hash_stream() {
		shasum -a 256 | awk '{print $1}'
	}
fi

actual_archive_sha256="$(hash_file "$archive_path")"
recorded_archive_sha256="$(awk -F= '$1 == "archive_sha256" {print $2; exit}' "$manifest_path")"
checksum_archive_sha256="$(awk '{print $1; exit}' "$checksum_path")"
if [[ "$actual_archive_sha256" != "$recorded_archive_sha256" || "$actual_archive_sha256" != "$checksum_archive_sha256" ]]; then
	printf 'Archive checksum does not match release evidence.\n' >&2
	exit 1
fi

test_root="$(mktemp -d)"
cleanup() {
	rm -rf "$test_root"
}
trap cleanup EXIT

unzip -tq "$archive_path" >/dev/null
unzip -Z1 "$archive_path" | LC_ALL=C sort > "$test_root/archive-members"
awk '
	/members_sha256:/ { in_members = 1; next }
	in_members && /^[0-9a-f]{64}  / { sub(/^[0-9a-f]{64}  /, ""); print }
' "$manifest_path" | LC_ALL=C sort > "$test_root/manifest-members"

if ! diff -u "$test_root/archive-members" "$test_root/manifest-members" >/dev/null; then
	printf 'Release manifest members do not match the archive.\n' >&2
	exit 1
fi

while IFS= read -r member; do
	[[ -z "$member" ]] && continue
	if [[ "$member" != subscription/* || "$member" == *'..'* || "$member" == /* ]]; then
		printf 'Release manifest contains an unsafe member path: %s\n' "$member" >&2
		exit 1
	fi
	recorded_member_sha256="$(awk -v wanted="$member" '$0 ~ /^[0-9a-f]{64}  / { line = $0; sub(/^[0-9a-f]{64}  /, "", line); if (line == wanted) { print substr($0, 1, 64); exit } }' "$manifest_path")"
	actual_member_sha256="$(unzip -p "$archive_path" "$member" | hash_stream)"
	if [[ "$actual_member_sha256" != "$recorded_member_sha256" ]]; then
		printf 'Release member checksum mismatch: %s\n' "$member" >&2
		exit 1
	fi
done < "$test_root/archive-members"

printf 'Release evidence verification passed.\n'
