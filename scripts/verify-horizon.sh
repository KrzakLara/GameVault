#!/usr/bin/env sh
# Live Redis/Horizon verification on the isolated E2E stack (run after scripts/e2e.sh, or it
# starts the stack itself). Exercises the real queues with real workers; nothing is faked.
#
#   1. Horizon is running and every configured queue has an active supervisor with processes.
#   2. Fulfillment queue: a real order is delivered by a Horizon worker.
#   3. Notifications queue + outbox: the delivery e-mail is published and actually sent.
#   4. Retry configuration: with a simulated supplier outage the job is retried on its backoff
#      schedule and finally fails the order (key back in stock).
#   5. Failed jobs are observable (failed_jobs table and Horizon's failed-job list).
#   6. Horizon's dashboard and API refuse guests (admin/customer checks: Playwright 12 and 16).
#   7. No plaintext key appears in any log, failed job or Horizon job payload.
#
# Takes about 4 minutes, mostly waiting out the real retry backoff (5+15+45+135 s).
set -eu
cd "$(dirname "$0")/.."
# Git Bash on Windows rewrites absolute arguments (/dev/null, /scripts/x.js) into Windows paths
# before docker sees them; this turns that off. No effect elsewhere.
export MSYS_NO_PATHCONV=1

compose() { docker compose --profile e2e "$@"; }

if ! compose ps --status running --services 2>/dev/null | grep -qx e2e-horizon; then
    echo "==> E2E stack is not running: starting it (database is rebuilt from fixtures)"
    E2E_NO_TESTS=1 scripts/e2e.sh
fi

echo "==> Horizon dashboard and API refuse guests"
for path in /horizon /horizon/api/stats /horizon/api/jobs/failed; do
    code=$(compose exec -T e2e-app curl -s -o /dev/null -w '%{http_code}' "http://localhost:8000$path")
    echo "    GET $path -> $code"
    [ "$code" = "403" ] || { echo "FAIL: expected 403 for a guest" >&2; exit 1; }
done

echo "==> Live queue checks"
status=0
compose exec -T e2e-app php artisan gamevault:verify-live || status=$?

echo "==> Scanning logs, failed jobs and Horizon payloads for plaintext keys"
compose logs --no-color e2e-app e2e-horizon e2e-scheduler > storage/logs/e2e/containers.log 2>&1 || true
compose exec -T e2e-app php artisan gamevault:scan-key-leaks storage/logs/e2e || status=$?

exit "$status"
