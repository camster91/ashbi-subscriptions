#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
pid_file="/tmp/ashbi-subscriptions-playground.pid"
log_file="/tmp/ashbi-subscriptions-playground.log"
plugin_dir="${ASHBI_PLAYGROUND_PLUGIN_DIR:-$repo_root/plugin}"
integration_dir="${ASHBI_PLAYGROUND_INTEGRATION_DIR:-$repo_root/plugin/tests/integration}"
node_bin="${ASHBI_PLAYGROUND_NODE_BIN:-node}"

# The macOS arm64 Playground dependency currently has no Node 26 native
# fs-ext build. Prefer the locally installed supported Node 22 runtime when
# available, while leaving CI and other hosts on their normal `node` binary.
if [[ "$node_bin" == node && -x "/opt/homebrew/opt/node@22/bin/node" ]]; then
	node_bin="/opt/homebrew/opt/node@22/bin/node"
fi

if [[ ! -d "$plugin_dir" || ! -d "$integration_dir" ]]; then
	printf 'Playground mount directories are unavailable.\n' >&2
	exit 2
fi

if [[ -s "$pid_file" ]]; then
	playground_pid="$(cat "$pid_file")"
	if kill -0 "$playground_pid" 2>/dev/null; then
		printf 'WordPress Playground is already running on port 8888 (PID %s).\n' "$playground_pid"
		exit 0
	fi
	: > "$pid_file"
fi

"$node_bin" "${repo_root}/scripts/launch-playground.mjs" "$repo_root" "$pid_file" "$log_file" "$plugin_dir" "$integration_dir"
playground_pid="$(cat "$pid_file")"

for attempt in $(seq 1 60); do
	if curl --silent --show-error --max-time 2 --output /dev/null http://localhost:8888/; then
		printf 'WordPress development site started at http://localhost:8888\n'
		exit 0
	fi
	sleep 1
done

printf 'WordPress Playground did not become ready. Log:\n' >&2
sed -n '1,80p' "$log_file" >&2
exit 1
