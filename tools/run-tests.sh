#!/bin/sh
# Runs the suite inside the rig container; output goes to a file, only the tail is shown.
# usage: tools/run-tests.sh [phpunit args...]   (output file: $OUT, default /tmp/wisso-phpunit.txt inside the container)
set -u
OUT="${OUT:-/tmp/wisso-phpunit.txt}"
cd "$(dirname "$0")/../../rig" || exit 2
MSYS_NO_PATHCONV=1 docker compose exec -T -u www-data app bash -c 'out=$0; cd /var/www/html/custom_apps/wisso && phpunit -c phpunit.xml "$@" > "$out" 2>&1; echo exit=$?' "$OUT" "$@"
MSYS_NO_PATHCONV=1 docker compose exec -T app tail -n 25 "$OUT"
