#!/usr/bin/env bash
set -euo pipefail

plugin_dir="${ASHBI_INTEGRATION_PLUGIN_DIR:-plugin}"
if [[ "$plugin_dir" != plugin && "$plugin_dir" != subscription ]]; then
	printf 'ASHBI_INTEGRATION_PLUGIN_DIR must be plugin or subscription.\n' >&2
	exit 2
fi
plugin_status_prefix="\"plugin\":\"${plugin_dir}\\\\/subscription\",\"status\":"

cookie_jar="$(mktemp)"
callback_cookie_jar="$(mktemp)"
trap 'rm -f "$cookie_jar" "$callback_cookie_jar"' EXIT
hpos_mode="${ASHBI_PLAYGROUND_HPOS_MODE:-}"
if [[ -n "$hpos_mode" && "$hpos_mode" != "on" && "$hpos_mode" != "off" ]]; then
	printf 'ASHBI_PLAYGROUND_HPOS_MODE must be on or off when set.\n' >&2
	exit 2
fi

login_fixture_user() {
	local jar="$1"
	curl --silent --show-error --max-time 30 --location --cookie "$jar" --cookie-jar "$jar" \
		--data 'log=admin&pwd=password&wp-submit=Log+In&redirect_to=http%3A%2F%2Flocalhost%3A8888%2Fwp-admin%2F&testcookie=1' \
		http://localhost:8888/wp-login.php >/dev/null
}

# The deterministic blueprint fixes WP_HOME to localhost. Seed only that
# canonical host so blueprint activation cannot refresh the login state while
# the worker is still converging.
curl --silent --show-error --max-time 30 --location --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
	--output /dev/null http://localhost:8888/

# Readiness uses the core plugin REST controller instead of scraping
# plugins.php. The admin dashboard is a stable authenticated surface and
# embeds the current REST nonce; the REST response gives exact plugin status
# without WooCommerce's onboarding redirect.
dependencies_ready=false
activation_attempted=false
login_attempted=false
rest_nonce=''
plugins_json=''
for attempt in $(seq 1 60); do
	admin_html="$(curl --silent --show-error --max-time 30 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
		http://localhost:8888/wp-admin/index.php || true)"
	rest_nonce="$(printf '%s' "$admin_html" | sed -n 's/.*wpApiSettings = .*"nonce":"\([^"]*\)".*/\1/p' | head -1)"
	if [[ -z "$rest_nonce" && "$login_attempted" != true ]]; then
		login_fixture_user "$cookie_jar"
		login_attempted=true
		admin_html="$(curl --silent --show-error --max-time 30 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
			http://localhost:8888/wp-admin/index.php || true)"
		rest_nonce="$(printf '%s' "$admin_html" | sed -n 's/.*wpApiSettings = .*"nonce":"\([^"]*\)".*/\1/p' | head -1)"
	fi
	if [[ -n "$rest_nonce" ]]; then
		plugins_json="$(curl --silent --show-error --max-time 30 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
			-H "X-WP-Nonce: $rest_nonce" http://localhost:8888/wp-json/wp/v2/plugins || true)"

		if [[ "$activation_attempted" != true ]] && \
			printf '%s' "$plugins_json" | grep --quiet "${plugin_status_prefix}\"inactive\""; then
			curl --silent --show-error --max-time 30 --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
				-H "X-WP-Nonce: $rest_nonce" \
				--data 'status=active' \
				"http://localhost:8888/wp-json/wp/v2/plugins/${plugin_dir}/subscription" >/dev/null || true
			activation_attempted=true
		fi

		if printf '%s' "$plugins_json" | grep --extended-regexp --quiet '"plugin":"woocommerce(\.latest-stable)?\\/woocommerce","status":"active"' && \
			printf '%s' "$plugins_json" | grep --extended-regexp --quiet '"plugin":"woocommerce-gateway-stripe(\.latest-stable)?\\/woocommerce-gateway-stripe","status":"active"' && \
			printf '%s' "$plugins_json" | grep --quiet "${plugin_status_prefix}\"active\""; then
			dependencies_ready=true
			break
		fi
	fi
	sleep 1
done

if [[ "$dependencies_ready" != true ]]; then
	printf 'Integration prerequisites did not become active before timeout.\n' >&2
	exit 1
fi

# Start the callback phase with a fresh auto-login session. Blueprint
# activation can invalidate the readiness session even after plugin status is
# active, and a nonce captured before that transition is intentionally rejected.
curl --silent --show-error --max-time 30 --location --cookie-jar "$callback_cookie_jar" \
	--output /dev/null http://localhost:8888/

# Refresh the dashboard nonce after all blueprint activation writes have
# settled.
for attempt in $(seq 1 10); do
	admin_html="$(curl --silent --show-error --max-time 30 --cookie "$callback_cookie_jar" --cookie-jar "$callback_cookie_jar" \
		http://localhost:8888/wp-admin/index.php || true)"
	rest_nonce="$(printf '%s' "$admin_html" | sed -n 's/.*wpApiSettings = .*"nonce":"\([^"]*\)".*/\1/p' | head -1)"
	if [[ -z "$rest_nonce" && "$attempt" == 1 ]]; then
		login_fixture_user "$callback_cookie_jar"
		admin_html="$(curl --silent --show-error --max-time 30 --cookie "$callback_cookie_jar" --cookie-jar "$callback_cookie_jar" \
			http://localhost:8888/wp-admin/index.php || true)"
		rest_nonce="$(printf '%s' "$admin_html" | sed -n 's/.*wpApiSettings = .*"nonce":"\([^"]*\)".*/\1/p' | head -1)"
	fi
	if [[ -n "$rest_nonce" ]]; then
		break
	fi
	sleep 1
done

if [[ -z "$rest_nonce" ]]; then
	printf 'Authenticated REST nonce was not available after plugin activation.\n' >&2
	exit 1
fi

integration_endpoint="http://localhost:8888/wp-json/ashbi-local/v1/security-boundaries"
if [[ -n "$hpos_mode" ]]; then
	integration_endpoint="${integration_endpoint}?ashbi_hpos=${hpos_mode}"
fi

result=''
for request_attempt in $(seq 1 10); do
	result="$(curl --silent --show-error --max-time 30 --cookie "$callback_cookie_jar" --cookie-jar "$callback_cookie_jar" \
		-H "X-WP-Nonce: $rest_nonce" \
		--write-out $'\n%{http_code}' \
		"$integration_endpoint" || true)"
	status="${result##*$'\n'}"
	response="${result%$'\n'*}"
	if [[ "$status" == "200" || "$status" != "403" ]]; then
		break
	fi
	admin_html="$(curl --silent --show-error --max-time 30 --cookie "$callback_cookie_jar" --cookie-jar "$callback_cookie_jar" \
		http://localhost:8888/wp-admin/index.php || true)"
	rest_nonce="$(printf '%s' "$admin_html" | sed -n 's/.*wpApiSettings = .*"nonce":"\([^"]*\)".*/\1/p' | head -1)"
	sleep 1
done

if [[ "$status" != "200" ]]; then
	printf 'Integration endpoint returned HTTP %s. Response prefix:\n' "$status" >&2
	printf '%s\n' "$response" | sed -n '1,24p' >&2
	exit 1
fi

if ! printf '%s' "$response" | grep --quiet '"success":true'; then
	printf 'Integration endpoint returned an unexpected response:\n' >&2
	printf '%s\n' "$response" | sed -n '1,24p' >&2
	exit 1
fi

printf '%s\n' "$response"
