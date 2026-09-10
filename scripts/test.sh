#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
cd "$ROOT_DIR"

npm run audit:production

php -l commonsbooking-extended.php >/dev/null
php -l uninstall.php >/dev/null
while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null
done < <(find src -type f -name '*.php' -print0 | sort -z)

php tests/run.php
php tests/booking-groups-test.php
php tests/booking-user-filter-test.php
php tests/qr-check-interface-test.php

printf '%s\n' 'Plugin checks passed.'
