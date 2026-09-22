#!/usr/bin/env bash
set -euo pipefail

pid_file="/tmp/ashbi-subscriptions-playground.pid"

if [[ -s "$pid_file" ]]; then
	playground_pid="$(cat "$pid_file")"
	if kill -0 "$playground_pid" 2>/dev/null; then
		kill "$playground_pid"
		for attempt in $(seq 1 10); do
			if ! kill -0 "$playground_pid" 2>/dev/null; then
				break
			fi
			sleep 1
		done
	fi
	: > "$pid_file"
	printf 'Stopped WordPress Playground.\n'
	exit 0
fi

if command -v wp-env >/dev/null 2>&1; then
	exec wp-env stop
fi

printf 'No managed WordPress Playground process was found; no stop action was taken.\n'
