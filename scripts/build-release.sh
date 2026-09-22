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
source_date_epoch="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct)}"

if [[ ! "$source_date_epoch" =~ ^[0-9]+$ ]]; then
  printf 'SOURCE_DATE_EPOCH must be a Unix timestamp.\n' >&2
  exit 1
fi

# ZIP stores filesystem mtimes and extra attributes. Normalize both so the
# same source tree and SOURCE_DATE_EPOCH produce byte-identical artifacts.
touch_timestamp="$(date -u -r "$source_date_epoch" '+%Y%m%d%H%M.%S' 2>/dev/null || date -u -d "@$source_date_epoch" '+%Y%m%d%H%M.%S')"

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
	--exclude 'assets/images/integrations/' \
	--exclude 'assets/images/other_plugins/' \
	--exclude 'assets/images/subscrpt-ads.png' \
	--exclude 'assets/images/woocommerce.png' \
	--exclude 'assets/images/icons/crown.svg' \
  "$plugin_root/" "$staging_root/subscription/"

find "$staging_root/subscription" -exec touch -t "$touch_timestamp" {} +

if find "$staging_root/subscription" -path '*/tests/*' -print -quit | grep -q .; then
  printf 'Release package unexpectedly contains tests.\n' >&2
  exit 1
fi

(
  cd "$staging_root"
  LC_ALL=C find subscription -type f -print | LC_ALL=C sort | zip -q -X release.zip -@
)

mv -f "$staging_root/release.zip" "$output_path"

printf '%s\n' "$output_path"
