#!/usr/bin/env bash
# Run PHPUnit on the LIVE working tree in a throwaway container, with no rsync and no shared runner
# volume, so several agents can run tests at once. Usage: tools/phpunit-local.sh <TestClassOrFile> [phpunit args]
# vendor/ comes read-only from the CI runner volume. Fast loop only: it is not a gate result.
set -euo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
VENDOR=/mnt/cache/appdata/runner_aicliagents/vendor
[ -x "$VENDOR/bin/phpunit" ] || { echo "no phpunit in $VENDOR" >&2; exit 2; }
T="${1:?test class name or file}"; shift || true
ARGS=(--filter "$T")
[ -f "$T" ] && ARGS=("$T")
exec docker run --rm -i -v "$REPO:/work" -v "$VENDOR:/work/vendor:ro" -w /work aicli-ci/php:8.4-cli \
  php -d memory_limit=1G vendor/bin/phpunit --colors=never --do-not-cache-result "${ARGS[@]}" "$@"
