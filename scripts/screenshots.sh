#!/usr/bin/env sh
# Captures docs/screenshots/*.png from the real application on a freshly seeded E2E stack.
set -eu
cd "$(dirname "$0")/.."
# Git Bash on Windows rewrites absolute arguments (/dev/null, /scripts/x.js) into Windows paths
# before docker sees them; this turns that off. No effect elsewhere.
export MSYS_NO_PATHCONV=1
mkdir -p docs/screenshots
E2E_NO_TESTS=1 scripts/e2e.sh
status=0
docker compose --profile e2e run --rm -T playwright npx playwright test -c playwright.screenshots.config.ts || status=$?
if [ "${E2E_KEEP_RUNNING:-0}" != "1" ]; then
    docker compose --profile e2e stop e2e-app e2e-horizon e2e-scheduler cheapshark-stub > /dev/null
fi
exit "$status"
