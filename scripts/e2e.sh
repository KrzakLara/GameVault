#!/usr/bin/env sh
# Runs the Playwright journeys (e2e/) against the REAL application on the isolated E2E stack
# (docker-compose.yml, profile "e2e"). It never touches development data:
#   - MySQL database gamevault_e2e only; the app refuses any other (app/Support/E2eGuard.php),
#     and e2e:reset checks that BEFORE it runs migrate:fresh;
#   - Redis databases 2 and 3, and only keys with the gamevault_e2e_ prefix are cleared;
#   - its own log files in storage/logs/e2e/.
#
# Usage: scripts/e2e.sh [playwright args...]
#   E2E_SKIP_BUILD=1    reuse the current public/build
#   E2E_KEEP_RUNNING=1  leave the stack up afterwards (http://127.0.0.1:8001)
#   E2E_NO_TESTS=1      only rebuild and start the stack
set -eu
cd "$(dirname "$0")/.."
# Git Bash on Windows rewrites absolute arguments (/dev/null, /scripts/x.js) into Windows paths
# before docker sees them; this turns that off. No effect elsewhere.
export MSYS_NO_PATHCONV=1

compose() { docker compose --profile e2e "$@"; }

if [ -f public/hot ]; then
    echo "public/hot exists (is the Vite dev server running?). Stop it: the E2E app serves the production build." >&2
    exit 1
fi

echo "==> MySQL and Redis"
compose up -d --wait mysql redis

echo "==> Isolated E2E database (idempotent)"
compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" 2>/dev/null -e "CREATE DATABASE IF NOT EXISTS gamevault_e2e; GRANT ALL PRIVILEGES ON gamevault_e2e.* TO '"'"'gamevault'"'"'@'"'"'%'"'"';"'

echo "==> Stopping a previous E2E stack"
compose stop e2e-app e2e-horizon e2e-scheduler cheapshark-stub > /dev/null 2>&1 || true

echo "==> Clearing E2E Redis keys (prefix gamevault_e2e_, databases 2 and 3)"
for db in 2 3; do
    compose exec -T redis sh -c "redis-cli -n $db --scan --pattern 'gamevault_e2e_*' | xargs -r redis-cli -n $db DEL > /dev/null"
done

if [ "${E2E_SKIP_BUILD:-0}" != "1" ]; then
    echo "==> Production frontend build"
    compose run --rm --no-deps -T app npm run build > /dev/null
fi

echo "==> Rebuilding the E2E database from deterministic fixtures"
mkdir -p storage/logs/e2e
rm -f storage/logs/e2e/*.log
compose run --rm -T e2e-app php artisan e2e:reset

echo "==> Starting the E2E stack"
compose up -d --wait e2e-app e2e-horizon e2e-scheduler cheapshark-stub

status=0
if [ "${E2E_NO_TESTS:-0}" = "1" ]; then
    echo "==> Stack is up (E2E_NO_TESTS=1: Playwright skipped)"
    exit 0
fi

echo "==> Playwright"
compose run --rm -T playwright npx playwright test "$@" || status=$?

compose logs --no-color e2e-app e2e-horizon e2e-scheduler > storage/logs/e2e/containers.log 2>&1 || true

if [ "${E2E_KEEP_RUNNING:-0}" != "1" ]; then
    compose stop e2e-app e2e-horizon e2e-scheduler cheapshark-stub > /dev/null
fi

exit "$status"
