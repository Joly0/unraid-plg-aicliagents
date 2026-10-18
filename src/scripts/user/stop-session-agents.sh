#!/bin/bash
# Stop processes started by an agent workspace before the shutdown bake.
# Agents share AICLI_SESSION_ID with their child processes, regardless of runtime.

_stop_session_agent_pids() {
    local proc_root="${AICLI_STOP_PROC_ROOT:-/proc}" environ pid cmdline exe stat rest state candidates
    candidates=$(grep -aEl 'AICLI_SESSION_ID=[A-Za-z0-9_.-]+' "$proc_root"/[0-9]*/environ 2>/dev/null || true)
    for environ in $candidates; do
        pid=${environ%/environ}
        pid=${pid##*/}
        [[ $pid =~ ^[0-9]+$ ]] || continue
        [ "$pid" -gt 1 ] 2>/dev/null || continue
        [ "$pid" = "$$" ] && continue
        cat "$environ" 2>/dev/null | tr '\0' '\n' | grep -Eq '^AICLI_SESSION_ID=[A-Za-z0-9_.-]+$' || continue

        cmdline=$(cat "$proc_root/$pid/cmdline" 2>/dev/null | tr '\0' ' ')
        case "$cmdline" in
            *tmux*|*ttyd*|*runuser*|*aicli-run-*|*aicli-shell*) continue ;;
        esac

        exe=$(readlink "$proc_root/$pid/exe" 2>/dev/null)
        case "$exe" in
            */qemu*|*/libvirt*|*/virsh|*/kvm|*/systemd|*/init|/sbin/init) continue ;;
        esac

        stat=$(cat "$proc_root/$pid/stat" 2>/dev/null) || continue
        rest=${stat##*) }
        state=${rest%% *}
        [ "$state" = Z ] && continue
        printf '%s\n' "$pid"
    done
}

signal_session_agent_processes() {
    local pid
    while IFS= read -r pid; do
        [ -n "$pid" ] || continue
        kill -TERM "$pid" >/dev/null 2>&1 || true
    done < <(_stop_session_agent_pids)
}

wait_session_agent_processes() {
    local wait="${1:-5}" waited=0
    while [ "$waited" -lt "$wait" ]; do
        _stop_session_agent_pids | grep -q . || return 0
        sleep 1
        waited=$((waited + 1))
    done
}

kill_session_agent_processes() {
    local pid
    while IFS= read -r pid; do
        [ -n "$pid" ] || continue
        kill -KILL "$pid" >/dev/null 2>&1 || true
    done < <(_stop_session_agent_pids)
}

stop_session_agent_processes() {
    local wait="${1:-5}"
    signal_session_agent_processes
    wait_session_agent_processes "$wait"
    kill_session_agent_processes
}
