#!/bin/bash
# watch-agent-sessions.sh — print one line whenever a workspace's tmux session ends, restarts or appears (Forgejo #433).
# Read-only: it lists sessions and reads the debug log; it never signals or starts anything.
# Test workspaces (ids that start e2e, smk, smoke or it<digit>: the gate and the smoke run make and remove them) are skipped,
# so a line means a REAL workspace. WATCH_ALL=1 reports every one.
# Usage: tools/watch-agent-sessions.sh [interval-seconds]    (default 15)    — run it under a monitor; each line is an event.
INTERVAL="${1:-15}"
TMUX_BIN=/usr/local/emhttp/plugins/unraid-aicliagents/.runtime/bin/tmux
BASE=/tmp/unraid-aicliagents/tmux
LOG=/tmp/unraid-aicliagents/debug.log
declare -A prev
snapshot() {
    local d id c
    for d in "$BASE"/s-*; do
        [ -d "$d" ] || continue
        id="${d##*/s-}"
        if [ -z "${WATCH_ALL:-}" ] && [[ "$id" =~ ^(e2e|smk|smoke|it[0-9]) ]]; then continue; fi
        c="$(env -u TMUX -u TMUX_PANE TMUX_TMPDIR="$d" "$TMUX_BIN" list-sessions -F '#{session_created}' 2>/dev/null | head -n 1)"
        echo "$id ${c:-none}"
    done
}
first=1
while true; do
    declare -A cur=()
    while read -r id c; do cur[$id]="$c"; done < <(snapshot)
    if [ "$first" -eq 0 ]; then
        for id in "${!cur[@]}"; do
            if [ "${prev[$id]:-}" != "${cur[$id]}" ]; then
                if [ "${cur[$id]}" = "none" ]; then kind="SESSION ENDED"
                elif [ -z "${prev[$id]:-}" ] || [ "${prev[$id]}" = "none" ]; then kind="SESSION STARTED"
                else kind="SESSION RESTARTED"; fi
                echo "$(date '+%H:%M:%S') $kind workspace=$id | log: $(grep -a "TmuxLifecycle\|ended with no\|not found before attach" "$LOG" 2>/dev/null | grep -a "$id" | tail -n 1 | cut -c1-200)"
            fi
        done
        for id in "${!prev[@]}"; do [ -z "${cur[$id]:-}" ] && echo "$(date '+%H:%M:%S') WORKSPACE FOLDER GONE workspace=$id"; done
    else
        echo "$(date '+%H:%M:%S') watching ${#cur[@]} workspace tmux servers"
    fi
    prev=(); for id in "${!cur[@]}"; do prev[$id]="${cur[$id]}"; done
    first=0
    sleep "$INTERVAL"
done
