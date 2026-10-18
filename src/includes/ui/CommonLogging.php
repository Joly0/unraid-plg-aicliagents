<?php
/**
 * <module_context>
 * Description: Shared JavaScript logging + AJAX helpers for AICliAgents.
 * Dependencies: AICliAjax.php?action=log, $config (the page's settings; perf_trace sets window.AICLI_PERF_TRACE).
 * Constraints: Atomic UI fragment (< 130 lines). aicliAjax() is the canonical
 * jQuery AJAX wrapper (R-06): it stamps every call with an X-Aicli-Trace id so
 * server/shell log lines are grep-joinable per request. PLUGIN_EVENT_LEDGER_
 * AND_SUBSCRIPTIONS.md (2026-09-11) R2: it also sends X-AICli-Client (a
 * per-tab id, sessionStorage) and X-AICli-Device (an optional per-browser
 * label, localStorage — set from Settings > Session) on every call, so a
 * Manager click is attributed the same way a browser AJAX call from the SPA
 * is (ui-build/src/lib/clientIdentity.ts — same storage keys, a fetch-based
 * wrapper there instead of jQuery).
 * </module_context>
 */
?>
<script>
// NOTEBOOK_HANG_OBSERVABILITY.md R12: the browser counter summaries are written to the server log only
// while the setting perf_trace is on (Debug Console tab). Read when the page loads.
window.AICLI_PERF_TRACE = <?= (((string)(($config['perf_trace'] ?? '0'))) === '1') ? 'true' : 'false' ?>;
// R-06 trace correlation: 4-hex per-page-load prefix + 4-hex per-call suffix
// = an 8-char [a-z0-9] id the server adopts verbatim (validated server-side).
// The shared prefix groups all calls from one page visit; the suffix separates
// individual requests.
window._aicliTracePrefix = window._aicliTracePrefix ||
    ('0000' + Math.floor(Math.random() * 0xffff).toString(16)).slice(-4);
function aicliTraceId() {
    return window._aicliTracePrefix +
        ('0000' + Math.floor(Math.random() * 0xffff).toString(16)).slice(-4);
}

// PLUGIN_EVENT_LEDGER_AND_SUBSCRIPTIONS.md (2026-09-11) R2: same storage keys
// as ui-build/src/lib/clientIdentity.ts, so a Manager click and an SPA click
// are attributed the same way. Never throws — a browser that blocks storage
// still gets a usable (if unstable) id.
function aicliClientId() {
    try {
        var id = sessionStorage.getItem('aicli_client_id');
        if (!id) {
            id = (Math.random().toString(16) + Math.random().toString(16)).replace(/[^a-f0-9]/g, '').slice(0, 8);
            sessionStorage.setItem('aicli_client_id', id);
        }
        return id;
    } catch (e) { return ''; }
}
function aicliDeviceLabel() {
    try { return localStorage.getItem('aicli_device_label') || ''; } catch (e) { return ''; }
}

// R-176: the actions the server accepts as POST only. Keep equal to ValidationService::POST_ONLY_ACTIONS.
window.AICLI_POST_ONLY_ACTIONS = <?= json_encode(\AICliAgents\Services\ValidationService::POST_ONLY_ACTIONS) ?>;

/**
 * R-175: why a request failed, in a sentence a person can read.
 * @param {Object} xhr The jqXHR of the failed request.
 * @param {string} status jQuery's text status (timeout, abort, parsererror, error).
 * @param {string} err jQuery's error text (the HTTP status text).
 */
function aicliAjaxReason(xhr, status, err) {
    if (status === 'timeout') return 'The request timed out.';
    if (status === 'abort') return 'The request was cancelled.';
    if (status === 'parsererror') return 'The server sent an answer the page could not read. The login may have ended. Reload the page.';
    if (xhr && xhr.status) return 'The server answered HTTP ' + xhr.status + (err ? ' ' + err : '') + '.';
    return 'The browser could not connect to the server.';
}

var _aicliAjaxNoticeAt = 0;
/**
 * R-175: what a failed request does when its caller has no failure handler of its own.
 * A dialog that waits on the request (its confirm button is disabled by the loader) is
 * replaced by an error dialog, so it never spins for ever. Any other failure shows a small
 * notice, at most one every 20 seconds, so a poll against a stopped server is not noisy.
 * A caller with its own failure handler may call this to show the same message.
 */
function aicliAjaxDefaultFail(xhr, status, err) {
    if (status === 'abort') return;
    var reason = aicliAjaxReason(xhr, status, err);
    console.warn('[AICliAgents] Request failed:', reason);
    var waiting = document.querySelector('.sweet-alert.visible button.confirm:disabled');
    if (waiting && typeof window.swal === 'function') {
        window.swal('Could not reach the server', reason, 'error');
        return;
    }
    var now = Date.now();
    if (now - _aicliAjaxNoticeAt < 20000) return;
    _aicliAjaxNoticeAt = now;
    var box = document.getElementById('aicli-ajax-notice');
    if (!box) {
        box = document.createElement('div');
        box.id = 'aicli-ajax-notice';
        box.setAttribute('role', 'alert');
        box.style.cssText = 'position:fixed; right:16px; bottom:16px; max-width:360px; padding:10px 14px; background:#7a1f1f; color:#fff; border-radius:4px; font-size:12px; z-index:10002;';
        document.body.appendChild(box);
    }
    box.textContent = 'Could not reach the server. ' + reason;
    box.style.display = 'block';
    setTimeout(function () { box.style.display = 'none'; }, 8000);
}

/**
 * R-175: give a jqXHR a default failure path. When the caller attaches no failure handler
 * (.fail, .catch or a two-argument .then), aicliAjaxDefaultFail runs. A caller that attaches
 * its own handler owns the failure. Returns the same jqXHR.
 */
function aicliAjaxWatch(xhr) {
    var handled = false;
    var origFail = xhr.fail;
    var origThen = xhr.then;
    xhr.fail = function () { handled = true; return origFail.apply(this, arguments); };
    if (typeof xhr.catch === 'function') {
        var origCatch = xhr.catch;
        xhr.catch = function () { handled = true; return origCatch.apply(this, arguments); };
    }
    xhr.then = function (ok, bad) { if (bad) handled = true; return origThen.apply(this, arguments); };
    origFail.call(xhr, function (x, s, e) { if (!handled) aicliAjaxDefaultFail(x, s, e); });
    return xhr;
}

/**
 * Canonical AJAX helper (R-06): GET to AICliAjax.php with the csrf token and
 * fresh X-Aicli-Trace/X-AICli-Client/X-AICli-Device headers. Returns the
 * jqXHR (use .done/.fail), so existing `$.getJSON(url, cb)` call sites
 * convert as `aicliAjax(action, params, cb)`. A failed request shows its
 * reason through aicliAjaxDefaultFail unless the caller attaches a .fail (R-175).
 * @param {string} action  AJAX action name.
 * @param {Object} [params] Extra query params (values URL-encoded here).
 * @param {Function} [done] Optional success callback (parsed JSON).
 */
function aicliAjax(action, params, done) {
    const token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
    const headers = { 'X-Aicli-Trace': aicliTraceId(), 'X-AICli-Client': aicliClientId() };
    const label = aicliDeviceLabel();
    if (label) headers['X-AICli-Device'] = label;
    // R-176: an action that changes storage goes as POST, with the token in the body (see
    // ValidationService::POST_ONLY_ACTIONS; the server refuses these actions as GET).
    const post = AICLI_POST_ONLY_ACTIONS.indexOf(action) !== -1;
    const xhr = post
        ? $.ajax({
            url: '/plugins/unraid-aicliagents/AICliAjax.php',
            method: 'POST',
            data: Object.assign({ action: action, csrf_token: token }, params || {}),
            dataType: 'json',
            headers: headers
        })
        : $.ajax({
            url: '/plugins/unraid-aicliagents/AICliAjax.php?' + $.param(Object.assign({ action: action, csrf_token: token }, params || {})),
            dataType: 'json',
            headers: headers
        });
    if (done) xhr.done(done);
    return aicliAjaxWatch(xhr);
}

/**
 * Sends a log message from the client to the server debug.log.
 * @param {string} msg The message to log.
 * @param {number} level The log level (0=ERROR, 1=WARN, 2=INFO, 3=DEBUG).
 * @param {string} context The component context (e.g., [ManagerStorage]).
 */
function aicli_log_to_server(msg, level = 2, context = "Frontend") {
    const token = typeof csrf !== 'undefined' ? csrf : (window.csrf_token || '');
    $.ajax({
        url: '/plugins/unraid-aicliagents/AICliAjax.php?action=log&csrf_token=' + token,
        method: 'POST',
        headers: { 'X-Aicli-Trace': aicliTraceId() },
        data: {
            message: msg,
            level: level,
            context: context,
            csrf_token: token
        }
    }).fail(function() {
        console.error("[AICliAgents] Failed to send log to server:", msg);
    });
}

// Intercept window errors. SOURCE FILTER (2026-05-13): only report errors that
// originate from our own JS — drop anything sourced from Unraid's WebGUI bundle
// (e.g. /webGui/javascript/dynamix.js) or from cross-origin scripts. Background:
// Unraid 7.3.0 introduced a `ReferenceError: Can't find variable: codeAZ` in its
// minified dynamix.js that fires dozens of times per page load on every plugin's
// page (it's an upstream Unraid regression, not ours). Without this filter our
// debug.log fills with hundreds of identical noise lines per session, drowning
// the signal from real plugin issues. We still console.error everything so a
// developer with DevTools open sees the full picture; we just don't ship the
// Unraid-internal noise upstream to our log file.
window.onerror = function(msg, url, lineNo, columnNo, error) {
    const detail = msg + " at " + url + ":" + lineNo + ":" + columnNo;
    console.error("[AICliAgents] Frontend Error:", detail);
    const u = String(url || '');
    // Match the WebGUI base regardless of host (myunraid.net subdomain hash, LAN IP, …).
    const isUnraidInternal = /\/webGui\//.test(u);
    // Cross-origin / opaque errors (browser strips url to "") aren't actionable from a plugin.
    const isOpaque = u === '' || u === 'null' || u === 'undefined';
    if (isUnraidInternal || isOpaque) {
        return false;
    }
    aicli_log_to_server("JS ERROR: " + detail, 0); // 0 = ERROR
    return false;
};

// Log high-impact user actions
console.log("[AICliAgents] Client-side logging initialized.");
</script>
