#!/bin/bash
# layer_mount_guard.sh — #566: what op_mount does when ONE layer of a stack will
# not mount. Spec: docs/specs/HOME_STORAGE_LIFECYCLE.md, section "2026-10-17 — a
# layer that will not mount".
#
# The rule, in short:
#   - Every failure names the layer and gives the reason (lifecycle event
#     layer_mount_failed, with the mount error text).
#   - A home layer that is zero bytes or has no squashfs header, and that is the
#     NEWEST layer left in the stack, with at least one older layer below it and
#     of kind delta, is moved (never deleted) to <persist>/.bad/ and removed from
#     the manifest. The rest of the stack mounts. Only that layer's changes are
#     lost, and the person is told (event home_layer_quarantined + a notification).
#   - Any other failure halts the mount and names the layer. An older layer in the
#     middle of the stack is never skipped: the layers above it depend on it.
#
# Sourced by storage_ops.sh. Needs lifecycle_log (resolve_paths.sh). The manifest
# writer (manifest_write.sh) is optional: without it the manifest keeps the entry.

# _lmg_json_str <text> — print text escaped for use inside a JSON string.
_lmg_json_str() {
    printf '%s' "${1:-}" | tr '\n\r\t' '   ' | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' | cut -c1-300
}

# _layer_sqsh_problem <file> — print why the file cannot be a squashfs layer and
# return 0; print nothing and return 1 when it looks like a squashfs image.
#   zero_bytes          the file is empty (or missing)
#   no_squashfs_header  the first four bytes are not "hsqs" (the squashfs magic)
_layer_sqsh_problem() {
    local f="${1:-}" magic
    if [ ! -s "$f" ]; then
        printf 'zero_bytes'
        return 0
    fi
    magic="$(head -c 4 "$f" 2>/dev/null | tr -d '\0')"
    if [ "$magic" != "hsqs" ]; then
        printf 'no_squashfs_header'
        return 0
    fi
    return 1
}

# _layer_quarantine_allowed <type> <reason> <lowers_so_far> <layers_below> <layer_name>
# Return 0 when the damaged layer may be set aside and the rest mounted.
_layer_quarantine_allowed() {
    local type="${1:-}" reason="${2:-}" lowers="${3:-}" below="${4:-0}" name="${5:-}"
    [ "$type" = "home" ] || return 1
    case "$reason" in zero_bytes|no_squashfs_header) : ;; *) return 1 ;; esac
    [ -z "$lowers" ] || return 1                 # a newer layer is already in the stack
    [ "$below" -ge 1 ] 2>/dev/null || return 1   # nothing below: it is the whole home
    case "$name" in *_consolidated_*) return 1 ;; esac   # a base holds the whole home
    return 0
}

# _layer_quarantine <type> <id> <persist> <file> <reason>
# Move the layer to <persist>/.bad/ (never delete), drop it from the manifest,
# log an event and tell the person. Return 0 when the file was moved.
_layer_quarantine() {
    local type="$1" id="$2" persist="$3" f="$4" reason="$5"
    local name bad dest n=0
    name="$(basename "$f")"
    bad="${persist%/}/.bad"
    mkdir -p "$bad" 2>/dev/null || return 1
    dest="$bad/$name"
    while [ -e "$dest" ]; do n=$((n + 1)); dest="$bad/$name.$n"; done
    mv -f "$f" "$dest" 2>/dev/null || return 1
    if declare -f manifest_remove_layer >/dev/null 2>&1; then
        manifest_remove_layer "$type" "$id" "$name" \
            || lifecycle_log "warn" "mount_stack" "layer_quarantine_manifest_unchanged" \
                "{\"type\":\"$type\",\"id\":\"$id\",\"layer\":\"$name\"}" 2>/dev/null || true
    fi
    lifecycle_log "critical" "mount_stack" "home_layer_quarantined" \
        "{\"type\":\"$type\",\"id\":\"$id\",\"layer\":\"$name\",\"reason\":\"$reason\",\"moved_to\":\"$(_lmg_json_str "$dest")\"}" 2>/dev/null || true
    if [ -x "${AICLI_NOTIFY_BIN:-/usr/local/emhttp/plugins/dynamix/scripts/notify}" ]; then
        "${AICLI_NOTIFY_BIN:-/usr/local/emhttp/plugins/dynamix/scripts/notify}" -i warning \
            -s "AICliAgents: a saved layer of your home was damaged" \
            -d "The newest saved layer of home $id ($name) is damaged ($reason). It was moved to $bad and your home started without it. Changes saved only in that layer are not in the home now." \
            2>/dev/null || true
    fi
    return 0
}

# _layer_mount_failed <type> <id> <persist> <file> <mount_error> <lowers_so_far> <layers_below>
# Decide what a failed layer mount means. Prints a one-line message for the log.
# Return 0 = the layer was set aside, go on with the next layer.
# Return 1 = halt: the caller cleans up its loop mounts and exits 1.
_layer_mount_failed() {
    local type="$1" id="$2" persist="$3" f="$4" err="$5" lowers="$6" below="$7"
    local name reason action
    name="$(basename "$f")"
    reason="$(_layer_sqsh_problem "$f" || true)"
    [ -n "$reason" ] || reason="mount_error"
    if _layer_quarantine_allowed "$type" "$reason" "$lowers" "$below" "$name" \
        && _layer_quarantine "$type" "$id" "$persist" "$f" "$reason"; then
        printf 'Layer %s of %s/%s is damaged (%s). It was moved to %s/.bad and the rest of the home mounts without it.' \
            "$name" "$type" "$id" "$reason" "${persist%/}"
        return 0
    fi
    action="halt"
    lifecycle_log "error" "mount_stack" "layer_mount_failed" \
        "{\"type\":\"$type\",\"id\":\"$id\",\"layer\":\"$name\",\"reason\":\"$reason\",\"error\":\"$(_lmg_json_str "$err")\",\"layers_below\":$below,\"action\":\"$action\"}" 2>/dev/null || true
    printf 'Layer %s of %s/%s cannot be mounted (%s%s). The mount stops here. The file is not changed.%s' \
        "$name" "$type" "$id" "$reason" "${err:+: $err}" \
        "$([ "$below" -ge 1 ] 2>/dev/null && [ -n "$lowers" ] && printf ' Layers above it depend on it.')"
    return 1
}
