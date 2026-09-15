#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_root="$repo_root/plugin"
version="$(awk -F': ' '/^ \* Version:/{print $2; exit}' "$plugin_root/subscription.php" | tr -d '\r')"
output_path="${1:-$repo_root/dist/ashbi-subscriptions-$version.zip}"
if [[ "$output_path" != /* ]]; then
  output_path="$repo_root/$output_path"
fi
staging_root="$(mktemp -d)"

cleanup() {
  rm -rf "$staging_root"
}
trap cleanup EXIT

mkdir -p "$(dirname "$output_path")" "$staging_root/subscription"
rsync -a \
  --exclude '.DS_Store' \
  --exclude 'tests/' \
	--exclude 'assets/images/logo.png' \
	--exclude 'assets/images/logo-title.svg' \
	--exclude 'assets/images/icons/subscription-20.png' \
	--exclude 'assets/images/icons/subscription-20-gray.png' \
  "$plugin_root/" "$staging_root/subscription/"

if find "$staging_root/subscription" -path '*/tests/*' -print -quit | grep -q .; then
  printf 'Release package unexpectedly contains tests.\n' >&2
  exit 1
fi

(
  cd "$staging_root"
  zip -qr release.zip subscription
)

mv -f "$staging_root/release.zip" "$output_path"

printf '%s\n' "$output_path"
