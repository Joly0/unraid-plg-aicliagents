#!/bin/bash
# Dynamically sourced by generated session wrappers after every child exit.
# Keeping this outside the generated snapshot lets a hot-swapped plugin improve
# crash diagnostics for already-running workspaces without restarting agents.
aicli_dynamic_record_agent_exit() {
    local _attempt="$1" _rc="$2" _started="$3" _ended _termination="exit"
    _ended=$(date +%s)
    last_launch_duration=$((_ended - _started))
    last_launch_rc="$_rc"
    if [ "$_rc" -ge 129 ] && [ "$_rc" -le 192 ]; then
        local _signal=$((_rc - 128)) _signal_name
        _signal_name=$(kill -l "$_signal" 2>/dev/null || printf 'UNKNOWN')
        _termination="signal ${_signal} (${_signal_name})"
    fi
    log_aicli "INFO" 2 "Agent process ended: attempt=$_attempt rc=$_rc termination=$_termination duration=${last_launch_duration}s"
    perf_log run.exit "rc=$_rc"
    if [ "$_rc" -ne 0 ]; then _aicli_capture_crash "$_attempt" "$_rc"; fi
    # WORKSPACE_LIFECYCLE_EVENTS.md: tell every open tab the agent process
    # inside this session just ended. $BRIDGE_SCRIPT and $ID are set by the
    # sourcing wrapper (aicli-shell.sh) before this function is ever called.
    # Best-effort: a missing bridge (e.g. mid-uninstall) must never break the
    # relaunch loop this function runs inside.
    if [ -n "${BRIDGE_SCRIPT:-}" ] && [ -n "${ID:-}" ] && [ -f "$BRIDGE_SCRIPT" ]; then
        php "$BRIDGE_SCRIPT" workspace_exited "$ID" "$_rc" 2>/dev/null || true
        # HARD_STOP_RESILIENCE.md R4: separate call, so the exit event line above is unchanged.
        # A failed start counts: under 3 s, or non-zero before 60 s (the PHP side decides and
        # alerts for Relay actors only). Three failed exits in a row are a launcher give-up.
        if [ "${last_launch_duration:-999}" -lt 3 ] || { [ "$_rc" -ne 0 ] && [ "${last_launch_duration:-999}" -lt 60 ]; }; then
            php "$BRIDGE_SCRIPT" actor_exit_immediate "$ID" "$_rc" "$last_launch_duration" 2>/dev/null || true
        fi
    fi
}
