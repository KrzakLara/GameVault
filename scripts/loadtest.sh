#!/usr/bin/env sh
# k6 load tests against the isolated E2E stack ONLY (never a shared or production environment).
# Rebuilds the E2E database, adds the load fixtures (LoadTestSeeder), runs the scenarios in
# loadtest/k6, then checks allocation correctness in the database. Results: loadtest/results/.
#
#   LOAD_RATE=30         storefront arrival rate (requests/s; search adds half as many again)
#   LOAD_DURATION=60s    storefront scenario duration
#   LOAD_BURST_VUS=100   simultaneous checkouts in the burst
#   LOAD_RACE_VUS=60     customers racing for the 10-key title
set -eu
cd "$(dirname "$0")/.."
# Git Bash on Windows rewrites absolute arguments (/dev/null, /scripts/x.js) into Windows paths
# before docker sees them; this turns that off. No effect elsewhere.
export MSYS_NO_PATHCONV=1

compose() { docker compose --profile e2e "$@"; }

mkdir -p loadtest/results
chmod 777 loadtest/results 2> /dev/null || true

E2E_NO_TESTS=1 scripts/e2e.sh
echo "==> Load-test fixtures"
compose exec -T e2e-app php artisan db:seed --class='Database\Seeders\LoadTestSeeder' --force

echo "==> Environment"
{
    echo "date_utc: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    docker info --format 'docker: {{.ServerVersion}} | os: {{.OperatingSystem}} | kernel: {{.KernelVersion}} | cpus: {{.NCPU}} | memory_bytes: {{.MemTotal}}'
    echo "app server: php artisan serve (PHP built-in server), PHP_CLI_SERVER_WORKERS=8, single container, no opcache tuning"
    compose exec -T e2e-app php -r 'echo "php: ", PHP_VERSION, " | opcache: ", (function_exists("opcache_get_status") && opcache_get_status(false) ? "on" : "off"), PHP_EOL;'
    compose exec -T mysql mysql --version
    compose exec -T redis redis-server --version
} | tee loadtest/results/environment.txt

status=0
run() { compose run --rm -T k6 run --quiet "$@" || status=1; }

echo "==> Storefront: browsing + search/filter"
run -e RATE="${LOAD_RATE:-30}" -e DURATION="${LOAD_DURATION:-60s}" storefront.js
echo "==> Checkout burst + order-status polling"
run -e VUS="${LOAD_BURST_VUS:-100}" checkout-burst.js
echo "==> Limited-inventory contention"
run -e VUS="${LOAD_RACE_VUS:-60}" -e STOCK=10 contention.js

echo "==> Database checks"
compose exec -T e2e-app php artisan gamevault:loadtest-report --json=loadtest/results/database.json || status=1

if [ "${E2E_KEEP_RUNNING:-0}" != "1" ]; then
    compose stop e2e-app e2e-horizon e2e-scheduler cheapshark-stub > /dev/null
fi
exit "$status"
