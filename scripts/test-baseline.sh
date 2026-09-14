#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

php tests/compatibility-contract.php

while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done < <(find plugin tools tests -type f -name '*.php' -print0)

printf 'PHP syntax checks passed.\n'
