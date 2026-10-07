#!/usr/bin/env bash
# Run the Notebook Playwright specs against the live box (.4), bracketed by the notebook canary.
# Safety rules: docs/specs/NOTEBOOK_TESTING.md and ui-build/tests/e2e/notebook/README.md.
# Usage: tools/notebook-e2e-live.sh [chromium|iphone-14]   (default chromium)
# Needs: the runner volume already synced (run `../ci/run build` first), ci/ci.secrets.conf for the login.
# Exit: the Playwright exit code, or 37 when the canary finds a changed real notebook.
set -uo pipefail
PROJECT="${1:-chromium}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
CI_DIR="$(cd "$HERE/../ci" && pwd)"
VOL=/mnt/cache/appdata/runner_aicliagents
IMG="${CI_PW_IMAGE:-mcr.microsoft.com/playwright:v1.59.1-jammy}"
SERVER=192.168.1.4
KEY=/root/.ssh/aicli_e2e_ed25519
SNAP="$(mktemp /tmp/nb-canary.XXXXXX.json)"
trap 'rm -f "$SNAP"; rm -f "$VOL/ui-build/tests/e2e/.auth/storageState.json"' EXIT
[ -f "$VOL/ui-build/tests/e2e/notebook/helpers.ts" ] || { echo "runner volume has no notebook specs: run ../ci/run build first" >&2; exit 2; }
# The e2e container's own SSH key (never the host root key), as ci/run does.
[ -f "$KEY" ] || ssh-keygen -q -t ed25519 -N '' -C aicli-e2e-runner -f "$KEY"
grep -q ' aicli-e2e-runner$' /root/.ssh/authorized_keys || \
  printf 'from="172.16.0.0/12,127.0.0.1,%s",no-agent-forwarding,no-port-forwarding,no-X11-forwarding %s\n' "$SERVER" "$(cat "$KEY.pub")" >> /root/.ssh/authorized_keys
# The login. The state file is a credential: removed again on exit.
set +x
# shellcheck disable=SC1091
. "$CI_DIR/ci.secrets.conf"
if [ ! -f "$VOL/ui-build/tests/e2e/.auth/storageState.json" ]; then
  docker run --rm -v "$VOL:/work" -w /work -e AICLI_TEST_SERVER="http://$SERVER" -e AICLI_TEST_USER="$AICLI_TEST_USER" -e AICLI_TEST_PASS="$AICLI_TEST_PASS" \
    "$IMG" bash /work/.ci/runners/auth-bootstrap.sh || { echo "auth bootstrap failed" >&2; exit 3; }
fi
python3 "$HERE/tests/lib/notebook-canary.py" snapshot "$SNAP" || exit 4
docker run --rm -v "$VOL:/work" -w /work/ui-build -v "$KEY:/root/.ssh/id_ed25519:ro" \
  -e AICLI_TEST_SERVER="http://$SERVER" -e AICLI_SSH_HOST="$SERVER" -e AICLI_E2E_REQUIRE_SSH=1 -e CI=1 -e NOTEBOOK_E2E=1 \
  "$IMG" bash -c 'npx playwright test '"${NB_SPEC:-tests/e2e/notebook}"' --project='"$PROJECT"' --reporter=list --workers=1'
RC=$?
python3 "$HERE/tests/lib/notebook-canary.py" verify "$SNAP" || { echo "FAIL: a real notebook changed (exit 37)" >&2; exit 37; }
exit "$RC"
