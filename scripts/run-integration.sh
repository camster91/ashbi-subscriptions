#!/usr/bin/env bash
set -euo pipefail

cookie_jar="$(mktemp)"
trap 'rm -f "$cookie_jar"' EXIT

curl --silent --show-error --location --cookie-jar "$cookie_jar" http://localhost:8888/ >/dev/null
plugins_html="$(curl --silent --show-error --cookie "$cookie_jar" http://localhost:8888/wp-admin/plugins.php)"
activation_path="$(printf '%s' "$plugins_html" | grep -o 'plugins.php?action=activate&amp;plugin=plugin%2Fsubscription.php[^"<]*' | head -1 | sed 's/&amp;/\&/g' || true)"
if [[ -n "$activation_path" ]]; then
  curl --silent --show-error --location --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "http://localhost:8888/wp-admin/$activation_path" >/dev/null
fi
result="$(curl --silent --show-error --location --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
  --write-out $'\n%{http_code}' 'http://localhost:8888/wp-admin/admin-post.php?action=ashbi_security_boundaries')"
status="${result##*$'\n'}"
response="${result%$'\n'*}"
printf '%s\n' "$response"
[[ "$status" == "200" ]]
printf '%s' "$response" | grep --quiet '"success":true'
