#!/bin/bash
# AICliAgents: command line for the cache folders of plain-folder homes
# (BOOT_DISK_SPACE.md R5). Used by the PHP service and by the Unraid event hooks.
#
#   home-cache.sh status <user>        JSON: location, path, bytes, cap_bytes, on_boot_bytes
#   home-cache.sh clear <user>         empty the cache folders; prints {"status":"ok","freed_bytes":N}
#                                      exit 3 (status busy) while a process uses the home
#   home-cache.sh reapply              apply the current settings to every mounted plain home
#   home-cache.sh repoint-ram          array stop: move pool cache binds to RAM
#
# Test seams: AICLI_HOME_CACHE_BASE, AICLI_PERSIST_PATH (replaces the home storage path).

_HC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd)"
# shellcheck source=resolve_paths.sh
source "$_HC_DIR/resolve_paths.sh" 2>/dev/null || true
# shellcheck source=common.sh
source "$_HC_DIR/common.sh" 2>/dev/null || { echo '{"status":"error","message":"common.sh missing"}'; exit 1; }

_hc_persist() {
    if [ -n "${AICLI_PERSIST_PATH:-}" ]; then printf '%s\n' "$AICLI_PERSIST_PATH"; return 0; fi
    home_persist_path "$1"
}

_hc_user_ok() { case "${1:-}" in ''|*[!A-Za-z0-9._-]*) return 1 ;; esac; }

case "${1:-}" in
    status)
        _hc_user_ok "${2:-}" || { echo '{"status":"error","message":"invalid user"}'; exit 64; }
        home_cache_status_json "$2" "$(_hc_persist "$2")" ;;
    clear)
        _hc_user_ok "${2:-}" || { echo '{"status":"error","message":"invalid user"}'; exit 64; }
        freed="$(home_cache_clear "$2" "$(_hc_persist "$2")")"; rc=$?
        if [ "$rc" -eq 3 ]; then
            echo '{"status":"busy","message":"A workspace of this home is running."}'; exit 3
        fi
        printf '{"status":"ok","freed_bytes":%s}\n' "${freed:-0}" ;;
    reapply)
        home_cache_reapply_all; echo '{"status":"ok"}' ;;
    repoint-ram)
        home_cache_repoint_ram_all; echo '{"status":"ok"}' ;;
    *)
        echo "usage: home-cache.sh <status|clear> <user> | reapply | repoint-ram" >&2; exit 64 ;;
esac
