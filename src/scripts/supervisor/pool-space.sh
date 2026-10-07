#!/bin/bash
# pool-space.sh — warn early when the file system that holds the persistence
# folder (the boot disk) fills up (Forgejo #393,
# docs/specs/HARD_STOP_RESILIENCE.md R9).
#
# Sourced by aicli-supervisor.sh. The supervisor calls _pool_space_tick on
# every tick. The function acts only every 300 seconds:
#   - At or above AICLI_POOL_WARN_PCT (default 85): it sends ONE warning per
#     6 hours that names the free space and the biggest users, and it keeps
#     one Activity tray entry (pool_space_<mount>, status waiting).
#   - Below AICLI_POOL_CLEAR_PCT (default 80): it finishes the tray entry.
#   - Between the two limits: it changes nothing (hysteresis).
# Every step is best effort. The function always returns 0.
#
# Test overrides (environment): AICLI_POOL_PERSIST_PATH, AICLI_POOL_STATE_DIR,
# AICLI_POOL_NOW (epoch seconds), AICLI_POOL_DF_CMD (replaces df),
# AICLI_POOL_WARN_PCT, AICLI_POOL_CLEAR_PCT, AICLI_POOL_INTERVAL.

_POOL_SPACE_LAST=0   # epoch of the last check in this process

# _pool_space_php <open|refresh|close> <op> <label> — make or finish the tray
# entry. Best effort. Skipped in a sandboxed test run (AICLI_JOBS_DIR set),
# without php, or without the manager include. A test replaces this function.
_pool_space_php() {
    [ -z "${AICLI_JOBS_DIR:-}" ] || return 0
    local inc="${AICLI_MANAGER_PHP:-/usr/local/emhttp/plugins/unraid-aicliagents/src/includes/AICliAgentsManager.php}"
    [ -f "$inc" ] || return 0
    command -v php >/dev/null 2>&1 || return 0
    (
        # Do not pass the supervisor lock to the php child.
        if [ -n "${SUP_LOCK_FD:-}" ]; then
            exec {SUP_LOCK_FD}>&-
        fi
        timeout 20 php -d display_errors=0 -r '
            require_once $argv[1];
            \AICliAgents\Services\EventActor::$override = ["type" => "system"];
            $op = $argv[3];
            if ($argv[2] === "close") {
                \AICliAgents\Services\ActivityService::finish($op);
            } else {
                \AICliAgents\Services\ActivityService::register($op, "storage", $argv[4],
                    ["meta" => ["entity" => "pool/" . $op]]);
                \AICliAgents\Services\ActivityService::wait($op, "Waiting for free space");
            }' -- "$inc" "$1" "$2" "${3:-}" >/dev/null 2>&1
    ) || true
}

# _pool_space_dir_mb <path>... — on-disk size in MB of all given existing
# paths together ("" when none exists or du fails).
_pool_space_dir_mb() {
    local out
    [ "$#" -gt 0 ] || return 0
    out="$(timeout 60 du -smc -- "$@" 2>/dev/null | tail -n 1 | awk '{print $1}')"
    case "$out" in ''|*[!0-9]*) return 0 ;; esac
    printf '%s\n' "$out"
}

# _pool_space_users <persist dir> — one line, the biggest users, largest first,
# like "writable layer 4200 MB, home layers 900 MB". Empty when unknown.
_pool_space_users() {
    local p="$1" mb lines="" f
    local -a files
    if [ -d "$p/_upper" ]; then
        mb="$(_pool_space_dir_mb "$p/_upper")"
        [ -z "$mb" ] || lines+="$mb|the writable layer"$'\n'
    fi
    files=(); for f in "$p"/home_*.sqsh; do [ -e "$f" ] && files+=("$f"); done
    if [ "${#files[@]}" -gt 0 ]; then
        mb="$(_pool_space_dir_mb "${files[@]}")"
        [ -z "$mb" ] || lines+="$mb|the home layers"$'\n'
    fi
    files=(); for f in "$p"/agent_*.sqsh; do [ -e "$f" ] && files+=("$f"); done
    if [ "${#files[@]}" -gt 0 ]; then
        mb="$(_pool_space_dir_mb "${files[@]}")"
        [ -z "$mb" ] || lines+="$mb|the agent layers"$'\n'
    fi
    if [ -d "$p/passthrough" ]; then
        mb="$(_pool_space_dir_mb "$p/passthrough")"
        [ -z "$mb" ] || lines+="$mb|the uncompressed agents"$'\n'
    fi
    [ -n "$lines" ] || return 0
    printf '%s' "$lines" | sort -t'|' -k1,1nr | awk -F'|' 'NF==2 { printf "%s%s %s MB", (n++ ? ", " : ""), $2, $1 } END { print "" }'
}

_pool_space_tick() {
    local persist="${AICLI_POOL_PERSIST_PATH:-}"
    if [ -z "$persist" ]; then
        if declare -f home_persist_path >/dev/null 2>&1; then
            persist="$(home_persist_path 2>/dev/null)"
        fi
        [ -n "$persist" ] || persist="/boot/config/plugins/unraid-aicliagents/persistence"
    fi
    local state="${AICLI_POOL_STATE_DIR:-/tmp/unraid-aicliagents}"
    local interval="${AICLI_POOL_INTERVAL:-300}"
    local warn="${AICLI_POOL_WARN_PCT:-85}" clear="${AICLI_POOL_CLEAR_PCT:-80}"
    local now line size used avail mount pct free_mb safe op marker last_pct users msg label d
    now="${AICLI_POOL_NOW:-$(date +%s)}"
    case "$interval" in ''|*[!0-9]*) interval=300 ;; esac
    case "$warn" in ''|*[!0-9]*) warn=85 ;; esac
    case "$clear" in ''|*[!0-9]*) clear=80 ;; esac
    [ $(( now - _POOL_SPACE_LAST )) -ge "$interval" ] || return 0
    _POOL_SPACE_LAST="$now"

    mkdir -p "$state" 2>/dev/null || return 0
    # df -P -m: Filesystem 1M-blocks Used Available Capacity Mounted-on
    if [ -n "${AICLI_POOL_DF_CMD:-}" ]; then
        line="$($AICLI_POOL_DF_CMD "$persist" 2>/dev/null | awk 'NR==2')" || return 0
    else
        line="$(df -P -m "$persist" 2>/dev/null | awk 'NR==2')" || return 0
    fi
    [ -n "$line" ] || return 0
    read -r _ size used avail _ mount <<< "$line"
    case "$size" in ''|*[!0-9]*) return 0 ;; esac
    case "$used" in ''|*[!0-9]*) return 0 ;; esac
    case "$avail" in ''|*[!0-9]*) return 0 ;; esac
    [ "$size" -gt 0 ] || return 0
    free_mb="$avail"
    pct=$(( (used * 100 + size / 2) / size ))
    [ -n "$mount" ] || mount="boot"
    safe="${mount//[^a-zA-Z0-9_-]/_}"
    op="pool_space_${safe}"
    marker="$state/.${op}.tray"

    if [ "$pct" -ge "$warn" ]; then
        users="$(_pool_space_users "$persist")"
        msg="The boot disk is ${pct}% full and only ${free_mb} MB is free."
        [ -z "$users" ] || msg+=" The biggest users are: ${users}."
        msg+=" The Close all and tidy up notice in the Activity tray merges the saved layers and frees space."
        # One warning per 6 hours. Our own stamp makes this testable; the
        # supervisor rate key is a second guard.
        d="$state/.${op}.notified"
        last_pct=0; [ -f "$d" ] && read -r last_pct < "$d" 2>/dev/null
        case "$last_pct" in ''|*[!0-9]*) last_pct=0 ;; esac
        if [ $(( now - last_pct )) -ge 21600 ]; then
            echo "$now" > "$d" 2>/dev/null || true
            if declare -f _supervisor_notify >/dev/null 2>&1; then
                _supervisor_notify "warning" "AICliAgents: the boot disk is ${pct}% full" "$msg" "$op" 21600 || true
            fi
        fi
        # Tray entry: made once, text refreshed when the percent moves 2+ points.
        label="The boot disk is ${pct}% full (${free_mb} MB free)."
        [ -z "$users" ] || label+=" Biggest users: ${users}."
        label+=" Close all and tidy up merges the layers."
        last_pct=""; [ -f "$marker" ] && read -r last_pct < "$marker" 2>/dev/null
        case "$last_pct" in
            ''|*[!0-9]*)
                echo "$pct" > "$marker" 2>/dev/null || true
                _pool_space_php open "$op" "$label" || true ;;
            *)
                d=$(( pct - last_pct )); [ "$d" -ge 0 ] || d=$(( -d ))
                if [ "$d" -ge 2 ]; then
                    echo "$pct" > "$marker" 2>/dev/null || true
                    _pool_space_php refresh "$op" "$label" || true
                fi ;;
        esac
    elif [ "$pct" -lt "$clear" ]; then
        if [ -f "$marker" ]; then
            rm -f "$marker" 2>/dev/null || true
            rm -f "$state/.${op}.notified" 2>/dev/null || true
            _pool_space_php close "$op" "" || true
        fi
    fi
    return 0
}
