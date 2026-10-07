#!/bin/bash
# agent-state-preflight.sh — deterministic repair of an agent's own state files
# at every workspace start. Sourced by the launcher (aicli-shell.sh) before the
# agent runs. Spec: docs/specs/AGENT_STATE_PREFLIGHT.md
#
# Why: after a crash or a hard stop, a SQLite file in the home can be damaged
# (Codex: "database disk image is malformed"). OpenCode can also be asked to
# resume a session that its own store does not hold ("Session not found"). In
# both cases the agent exits at once and the workspace shows "Agent Exited
# Immediately". These functions find that state and repair it BEFORE the agent
# starts. They never delete: a damaged file is moved to a quarantine folder.
#
# Every function is quiet on success, never exits the caller, and never returns
# non-zero in a way that stops the launch.

AICLI_PREFLIGHT_QUICK_CHECK_TIMEOUT="${AICLI_PREFLIGHT_QUICK_CHECK_TIMEOUT:-90}"
AICLI_PREFLIGHT_RECOVER_TIMEOUT="${AICLI_PREFLIGHT_RECOVER_TIMEOUT:-300}"
AICLI_PREFLIGHT_KEEP_QUARANTINE="${AICLI_PREFLIGHT_KEEP_QUARANTINE:-3}"

_pf_log() {
    # $1 level, $2 message. Uses the launcher's log_aicli when it exists.
    if declare -f log_aicli >/dev/null 2>&1; then log_aicli "$1" 1 "$2"; else printf '[preflight] %s: %s\n' "$1" "$2" >&2; fi
}

# aicli_sqlite_state FILE -> prints ok | corrupt | unknown
#   ok       quick_check answered "ok"
#   corrupt  SQLite says the file is malformed or not a database
#   unknown  sqlite3 is missing, the file is locked, or the check timed out
aicli_sqlite_state() {
    local f="$1" out rc
    command -v sqlite3 >/dev/null 2>&1 || { echo unknown; return 0; }
    [ -s "$f" ] || { echo ok; return 0; }          # empty or missing: the agent creates it
    out="$(timeout "$AICLI_PREFLIGHT_QUICK_CHECK_TIMEOUT" sqlite3 -readonly "$f" 'PRAGMA quick_check(1);' 2>&1)"; rc=$?
    if [ "$rc" -eq 124 ]; then echo unknown; return 0; fi
    case "$out" in
        ok) echo ok ;;
        *"database is locked"*|*"busy"*) echo unknown ;;
        *) echo corrupt ;;
    esac
}

# True when a process other than this launcher holds the file open.
_pf_file_held() {
    if [ -z "${AICLI_PREFLIGHT_NO_FUSER:-}" ] && command -v fuser >/dev/null 2>&1; then   # NO_FUSER forces the /proc path (tests)
        fuser -s "$1" 2>/dev/null
        return $?
    fi
    # No fuser (a minimal host or a test container): look for an open descriptor in /proc, so a file that another
    # process holds is never moved aside just because a tool is missing.
    local real fd
    real="$(readlink -f "$1" 2>/dev/null)" || return 1
    for fd in /proc/[0-9]*/fd/*; do
        [ "$(readlink "$fd" 2>/dev/null)" = "$real" ] && return 0
    done
    return 1
}

# aicli_sqlite_repair AGENT FILE — move a damaged database to quarantine, then
# try to rebuild it from the readable rows. Prints a one-line result.
aicli_sqlite_repair() {
    local agent="$1" f="$2" home="${HOME_DIR:-$HOME}" ts qdir tmp uv base
    base="$(basename "$f")"
    ts="$(date -u +%Y%m%dT%H%M%SZ)"
    qdir="$home/.aicli/quarantine/$agent/$ts"
    mkdir -p "$qdir" 2>/dev/null || { _pf_log WARN "preflight: cannot create $qdir; leaving $base alone"; return 1; }
    tmp="$f.recovering.$$"
    rm -f "$tmp" 2>/dev/null
    uv="$(timeout 10 sqlite3 -readonly "$f" 'PRAGMA user_version;' 2>/dev/null | head -1)"

    local recovered=0
    if command -v sqlite3 >/dev/null 2>&1; then
        # .recover reads every page it can, even from a damaged file.
        if timeout "$AICLI_PREFLIGHT_RECOVER_TIMEOUT" sh -c 'sqlite3 -readonly "$1" ".recover" | sqlite3 "$2"' _ "$f" "$tmp" >/dev/null 2>&1 \
           && [ -s "$tmp" ] && [ "$(aicli_sqlite_state "$tmp")" = "ok" ]; then
            [ -n "$uv" ] && sqlite3 "$tmp" "PRAGMA user_version=$uv;" >/dev/null 2>&1
            # .recover parks orphan pages in a lost_and_found table; the agent must not see it.
            sqlite3 "$tmp" 'DROP TABLE IF EXISTS lost_and_found;' >/dev/null 2>&1
            recovered=1
        fi
    fi

    local e
    for e in "" -wal -shm; do [ -e "$f$e" ] && mv -f "$f$e" "$qdir/" 2>/dev/null; done
    if [ "$recovered" -eq 1 ] && mv -f "$tmp" "$f" 2>/dev/null; then
        _pf_log WARN "preflight: $agent database $base was damaged; rebuilt it from its readable rows. Original kept in $qdir"
        echo "rebuilt"
    else
        rm -f "$tmp" 2>/dev/null
        _pf_log WARN "preflight: $agent database $base was damaged and could not be rebuilt; moved to $qdir. The agent creates a new one."
        echo "quarantined"
    fi

    # Keep only the newest few quarantine folders for this agent.
    local old
    for old in $(ls -1dt "$home/.aicli/quarantine/$agent"/*/ 2>/dev/null | tail -n +"$((AICLI_PREFLIGHT_KEEP_QUARANTINE + 1))"); do
        rm -rf -- "$old" 2>/dev/null
    done
    return 0
}

# aicli_sqlite_preflight AGENT LABEL FILE... — check each file, repair a damaged
# one. A file that another process holds open is skipped (never move a live DB).
# Sets AICLI_PREFLIGHT_NOTICE to a user-facing sentence when it repaired anything.
aicli_sqlite_preflight() {
    local agent="$1" label="$2"; shift 2
    local f st res
    AICLI_PREFLIGHT_NOTICE=""
    for f in "$@"; do
        [ -f "$f" ] || continue
        st="$(aicli_sqlite_state "$f")"
        [ "$st" = "corrupt" ] || continue
        if _pf_file_held "$f"; then
            _pf_log WARN "preflight: $agent database $(basename "$f") looks damaged but another process has it open; not touching it"
            continue
        fi
        res="$(aicli_sqlite_repair "$agent" "$f")"
        AICLI_PREFLIGHT_NOTICE="${AICLI_PREFLIGHT_NOTICE}${AICLI_PREFLIGHT_NOTICE:+ }$label file $(basename "$f") was damaged and was ${res}."
    done
    return 0
}

# aicli_agent_sqlite_files AGENTID HOMEDIR — prints, one per line, the SQLite files
# that agent keeps in the home. Pure: it only expands globs (nullglob semantics),
# never errors, and prints nothing for an agent with no SQLite file or an unknown id.
# codex-cli and opencode are listed for completeness; the launcher keeps its own
# calls for them (opencode uses the store of the launch, not the shared one).
aicli_agent_sqlite_files() {
    local id="$1" h="${2%/}" f had_ng=0
    [ -n "$id" ] && [ -n "$h" ] || return 0
    shopt -q nullglob && had_ng=1
    shopt -s nullglob
    case "$id" in
        goose)           set -- "$h"/.local/share/goose/sessions/sessions.db ;;
        kilocode)        set -- "$h"/.local/share/kilo/kilo.db "$h"/.local/share/kilo/session-export.db ;;
        gh-copilot)      set -- "$h"/.copilot/session-store.db ;;
        grok-build)      set -- "$h"/.grok/worktrees.db "$h"/.grok/sessions/session_search.sqlite ;;
        antigravity-cli) set -- "$h"/.gemini/antigravity-cli/conversation_summaries.db "$h"/.gemini/antigravity-cli/conversations/*.db ;;
        codex-cli)       set -- "$h"/.codex/*.sqlite ;;
        *)               set -- ;;
    esac
    for f in "$@"; do [ -f "$f" ] && printf '%s\n' "$f"; done
    [ "$had_ng" -eq 1 ] || shopt -u nullglob
    return 0
}

# aicli_agent_preflight AGENTID HOMEDIR LABEL — check and repair every SQLite file
# the agent keeps (see aicli_agent_sqlite_files). Same rules as aicli_sqlite_preflight:
# a file another process holds open is never moved. Sets AICLI_PREFLIGHT_NOTICE.
aicli_agent_preflight() {
    local id="$1" h="$2" label="$3" files=() line
    AICLI_PREFLIGHT_NOTICE=""
    while IFS= read -r line; do [ -n "$line" ] && files+=("$line"); done < <(aicli_agent_sqlite_files "$id" "$h")
    [ "${#files[@]}" -gt 0 ] || return 0
    aicli_sqlite_preflight "$id" "$label" "${files[@]}"
    return 0
}

# aicli_opencode_resume_guard DB CHATID DIR... — prints the chat id to resume.
#   - the id is in this store            -> the same id
#   - not in this store, store has a top-level session for the workspace
#                                         -> the newest such session id
#   - nothing usable                      -> _fresh_ (start a new session)
# An empty, "none" or "_fresh_" id is returned unchanged. The DIR arguments are
# the workspace paths the store may know (e.g. the /mnt/user and /mnt/cache form).
aicli_opencode_resume_guard() {
    local db="$1" chat="$2"; shift 2
    case "$chat" in ""|none|_fresh_) printf '%s' "$chat"; return 0 ;; esac
    command -v sqlite3 >/dev/null 2>&1 || { printf '%s' "$chat"; return 0; }
    [ -s "$db" ] || { printf '%s' "$chat"; return 0; }
    case "$chat" in *[!A-Za-z0-9_-]*) printf '%s' "$chat"; return 0 ;; esac   # not an id we can quote safely

    local has
    has="$(timeout 30 sqlite3 -readonly "$db" "SELECT count(*) FROM session WHERE id='$chat';" 2>/dev/null)"
    case "$has" in
        1) printf '%s' "$chat"; return 0 ;;
        0) : ;;
        *) printf '%s' "$chat"; return 0 ;;   # could not tell (locked, schema differs): do not change the choice
    esac

    local d in_list=""
    for d in "$@"; do
        [ -n "$d" ] || continue
        d="${d%/}"
        case "$d" in *"'"*) continue ;; esac
        in_list="${in_list}${in_list:+,}'$d'"
    done
    local pick=""
    if [ -n "$in_list" ]; then
        pick="$(timeout 30 sqlite3 -readonly "$db" "SELECT id FROM session WHERE directory IN ($in_list) AND (parent_id IS NULL OR parent_id='') AND time_archived IS NULL ORDER BY time_updated DESC LIMIT 1;" 2>/dev/null | head -1)"
    fi
    case "$pick" in
        ses_*) _pf_log WARN "preflight: OpenCode session $chat is not in this workspace's store; resuming its newest session $pick instead"; printf '%s' "$pick" ;;
        *)     _pf_log WARN "preflight: OpenCode session $chat is not in this workspace's store and the store has no other session for it; starting a new session"; printf '%s' "_fresh_" ;;
    esac
    return 0
}
