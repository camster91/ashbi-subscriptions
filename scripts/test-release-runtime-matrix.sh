#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_version="$(awk -F': ' '/^ \* Version:/{print $2; exit}' "$repo_root/plugin/subscription.php" | tr -d '\r')"
archive="${1:-$repo_root/dist/ashbi-subscriptions-$plugin_version.zip}"

for hpos_mode in off on; do
	printf 'Running packaged disposable runtime with HPOS %s.\n' "$hpos_mode"
	ASHBI_PLAYGROUND_HPOS_MODE="$hpos_mode" \
		bash "$repo_root/scripts/test-release-runtime.sh" "$archive"
done

printf 'Packaged release HPOS on/off matrix passed for %s.\n' "$(basename "$archive")"
