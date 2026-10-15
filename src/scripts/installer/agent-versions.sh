#!/bin/bash
# agent-versions.sh — list and remove the old versions of agents
# (docs/specs/BOOT_DISK_SPACE.md R4, epic #534).
#
# Usage:
#   agent-versions.sh list   <agent|all> [--persist <path>] [--keep-rollback 0|1]
#   agent-versions.sh remove <agent|all> [--persist <path>] [--keep-rollback 0|1]
#
# Output is one tab-separated line per fact:
#   ITEM <agent> <kind> <bytes> <path>     an old version the clean-up removes
#   SKIP <agent> <path> <reason>           a version that stays, and why
#   LOG  <agent> <text>                    (remove only) a clean-up summary
# The keep rules live in generation.sh. This script only calls them.
# `remove` takes each agent's lock without waiting and never removes a version
# in use. Exit 0 ok, 64 usage error.

set -uo pipefail
_AV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd)"
# shellcheck source=/dev/null
source "$_AV_DIR/../storage/resolve_paths.sh" 2>/dev/null || true
# shellcheck source=/dev/null
source "$_AV_DIR/../storage/manifest_write.sh" 2>/dev/null || true
# shellcheck source=/dev/null
source "$_AV_DIR/generation.sh" 2>/dev/null || { echo "generation.sh missing" >&2; exit 64; }

VERB="${1:-}"; TARGET="${2:-}"; shift 2 2>/dev/null || true
PERSIST=""; KEEP_RB=""
while [ $# -gt 0 ]; do
    case "$1" in
        --persist) PERSIST="${2:-}"; shift 2 ;;
        --keep-rollback) KEEP_RB="${2:-}"; shift 2 ;;
        *) echo "unknown flag $1" >&2; exit 64 ;;
    esac
done
case "$VERB" in list|remove) : ;; *) echo "usage: agent-versions.sh list|remove <agent|all> [--persist P] [--keep-rollback 0|1]" >&2; exit 64 ;; esac
[ -n "$TARGET" ] || { echo "agent id or all required" >&2; exit 64; }
[ -n "$PERSIST" ] || PERSIST="$(agent_persist_path 2>/dev/null || true)"
[ -n "$PERSIST" ] || { echo "no persist path" >&2; exit 64; }
[ -n "$KEEP_RB" ] || KEEP_RB="$(aicli_keep_previous_version)"

# Every agent that has versions: plain folders, layers or rollback copies.
_av_agents() {
    local d f bn id
    for d in "$PERSIST/passthrough/agents/.versions"/*/ "$PERSIST/aicli-rollback"/*/; do
        [ -d "$d" ] || continue
        id="$(basename -- "${d%/}")"; case "$id" in .*) continue ;; esac
        printf '%s\n' "$id"
    done
    for f in "$PERSIST"/agent_*_*.sqsh; do
        [ -f "$f" ] || continue
        bn="$(basename -- "$f")"; id="${bn#agent_}"
        id="$(printf '%s' "$id" | sed -E 's/_(delta|consolidated)_[0-9]{10}_.*$//; t; s/_[^_]*$//')"
        [ -n "$id" ] && printf '%s\n' "$id"
    done
}
if [ "$TARGET" = "all" ]; then AGENTS="$(_av_agents | sort -u)"; else
    case "$TARGET" in */*|.*|'') echo "bad agent id" >&2; exit 64 ;; esac
    AGENTS="$TARGET"
fi

_av_list() {
    local id="$1" kind bytes path
    while IFS=$'\t' read -r kind bytes path; do
        [ -n "$kind" ] || continue
        printf 'ITEM\t%s\t%s\t%s\t%s\n' "$id" "$kind" "$bytes" "$path"
    done < <(aicli_agent_old_versions "$id" "$PERSIST" 0 "$KEEP_RB")
    while IFS=$'\t' read -r path reason; do
        [ -n "$path" ] || continue
        printf 'SKIP\t%s\t%s\t%s\n' "$id" "$path" "$reason"
    done < <(aicli_agent_in_use_versions "$id" "$PERSIST")
}

while IFS= read -r id; do
    [ -n "$id" ] || continue
    if [ "$VERB" = "list" ]; then
        _av_list "$id"
    else
        # The skips are read BEFORE the removal: afterwards a version that
        # was in use is still in use, but the list of what went is gone.
        _av_list "$id" | grep -P '^SKIP\t' || true
        while IFS= read -r line; do
            [ -n "$line" ] && printf 'LOG\t%s\t%s\n' "$id" "$line"
        done < <(aicli_agent_remove_old_versions "$id" "$PERSIST" "$KEEP_RB" 2>&1)
    fi
done <<< "$AGENTS"
exit 0
