#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_version="$(awk -F': ' '/^ \* Version:/{print $2; exit}' "$repo_root/plugin/subscription.php" | tr -d '\r')"
archive="${1:-$repo_root/dist/ashbi-subscriptions-$plugin_version.zip}"
if [[ "$archive" != /* ]]; then
	archive="$repo_root/$archive"
fi

if [[ ! -f "$archive" ]]; then
	mkdir -p "$(dirname "$archive")"
	bash "$repo_root/scripts/build-release.sh" "$archive" >/dev/null
fi

# Validate the exact candidate archive before mounting it into the disposable site.
bash "$repo_root/scripts/test-release-package.sh" "$archive" >/dev/null

test_root="$(mktemp -d)"
playground_started=false
cleanup() {
	if [[ "$playground_started" == true ]]; then
		ASHBI_PLAYGROUND_PLUGIN_DIR="$test_root/subscription" \
		ASHBI_PLAYGROUND_INTEGRATION_DIR="$repo_root/plugin/tests/integration" \
			bash "$repo_root/scripts/stop-playground.sh" >/dev/null || true
	fi
	rm -rf "$test_root"
}
trap cleanup EXIT

if curl --silent --max-time 2 --output /dev/null http://localhost:8888/; then
	printf 'Port 8888 is already serving a site; refusing to test the package on an unknown runtime.\n' >&2
	exit 2
fi

unzip -q "$archive" -d "$test_root"
if [[ ! -f "$test_root/subscription/subscription.php" ]]; then
	printf 'Release archive did not extract a legacy-compatible subscription root.\n' >&2
	exit 1
fi

ASHBI_PLAYGROUND_PLUGIN_DIR="$test_root/subscription" \
ASHBI_PLAYGROUND_INTEGRATION_DIR="$repo_root/plugin/tests/integration" \
	bash "$repo_root/scripts/start-playground.sh"
playground_started=true

ASHBI_PLAYGROUND_PLUGIN_DIR="$test_root/subscription" \
ASHBI_PLAYGROUND_INTEGRATION_DIR="$repo_root/plugin/tests/integration" \
	ASHBI_PLAYGROUND_HPOS_MODE="${ASHBI_PLAYGROUND_HPOS_MODE:-}" \
	bash "$repo_root/scripts/run-integration.sh"

printf 'Packaged release runtime checks passed for %s.\n' "$(basename "$archive")"
