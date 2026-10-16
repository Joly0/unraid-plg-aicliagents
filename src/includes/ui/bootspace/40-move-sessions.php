<?php
/**
 * <module_context>
 * Description: Boot disk space tool "Move old sessions" and "Bring back"
 *   (docs/specs/BOOT_DISK_SPACE.md R6, #539). Registers
 *   aicliBootSpace.actions['move_sessions'] and
 *   aicliBootSpace.actions['bring_back_sessions']. Each is a swal dialog.
 *   The server side is HistoryHandler (history_measure, history_move,
 *   history_bring_back). #552: history_measure answers from the last background measurement
 *   (a read). The dialog shows its age and a Measure now button when it is older than 10
 *   minutes or missing. The count of old sessions is computed here from the figures of the
 *   answer; no request is sent when the age or the agent changes. The dialog never measures.
 * Dependencies: jQuery, SweetAlert (swal), aicliAjax (CommonLogging.php),
 *   window.aicliBootSpace (ManagerBootSpaceScripts.php).
 * Constraints: Atomic fragment. The action gets the line object `part`; it reads
 *   part.user (default 'root') and part.agent (optional, preselects an agent).
 * </module_context>
 */
?>
<script>
(function () {
    'use strict';
    var reg = window.aicliBootSpace = window.aicliBootSpace || { actions: {} };
    var WARNING = 'Moved sessions cannot be resumed, and are not reachable while the array is stopped.';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function human(b) {
        b = Number(b) || 0;
        var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
        while (b >= 1024 && i < 4) { b /= 1024; i++; }
        return (i === 0 ? Math.round(b) : b.toFixed(1)) + ' ' + u[i];
    }
    function liftSwal() {
        // Lift the dialog above the open Activity tray (same class as the home conversion).
        if (!document.getElementById('aicli-swal-above-tray-css')) {
            var st = document.createElement('style');
            st.id = 'aicli-swal-above-tray-css';
            st.textContent = 'body.aicli-swal-above-tray .sweet-overlay { z-index: 1000010 !important; }'
                + 'body.aicli-swal-above-tray .sweet-alert { z-index: 1000011 !important; }';
            document.head.appendChild(st);
        }
        document.body.classList.add('aicli-swal-above-tray');
    }
    function unlift() { document.body.classList.remove('aicli-swal-above-tray'); }
    function fail(msg) {
        if (typeof window.swal === 'function') swal('Not done', msg || 'Unknown error. Check the Debug Console.', 'error');
        else window.alert(msg || 'Not done.');
    }

    /** Options of the agent select. Database agents are greyed with the reason. */
    function agentOptions(stores, selected, onlyWithMoved, moved) {
        var html = '', total = 0;
        stores.forEach(function (s) {
            if (onlyWithMoved) {
                if (!moved[s.id]) return;
                html += '<option value="' + esc(s.id) + '"' + (s.id === selected ? ' selected' : '') + '>'
                    + esc(s.label) + ' (' + moved[s.id].files + ' files, ' + human(moved[s.id].bytes) + ')</option>';
                return;
            }
            if (s.kind === 'files') {
                total += s.bytes;
                html += '<option value="' + esc(s.id) + '"' + (s.id === selected ? ' selected' : '') + '>'
                    + esc(s.label) + ' (' + human(s.bytes) + ')</option>';
            } else {
                html += '<option value="' + esc(s.id) + '" disabled title="' + esc(s.note) + '">'
                    + esc(s.label) + ' (' + human(s.bytes) + ') - history is in a database, cannot move</option>';
            }
        });
        return html;
    }

    function stamp(r) {
        return (typeof reg.stampHtml === 'function') ? reg.stampHtml(r) : '';
    }

    /** The sessions older than `days` for one agent id, or for 'all'. Null when this age was not measured. */
    function olderThan(stores, agent, days, ages) {
        if ((ages || []).map(Number).indexOf(Number(days)) < 0) return null;
        var t = { sessions: 0, files: 0, bytes: 0 };
        stores.forEach(function (s) {
            if (s.kind !== 'files' || (agent !== 'all' && s.id !== agent)) return;
            var b = (s.older_than || {})[String(days)] || (s.older_than || {})[days];
            if (!b) return;
            t.sessions += b.sessions; t.files += b.files; t.bytes += b.bytes;
        });
        return t;
    }

    reg.actions['move_sessions'] = function (part) {
        part = part || {};
        var user = part.user || 'root';
        if (typeof window.swal !== 'function') { fail('This page cannot show the dialog.'); return; }
        aicliAjax('history_measure', { user: user }, function (m) {
            if (!m || m.status !== 'ok') { fail(m && m.message); return; }
            reg.reopen = function () { reg.actions['move_sessions'](part); };
            liftSwal();
            var pools = m.pools || [];
            var dest = m.dest || '';
            var poolPath = '', folder = '';
            pools.forEach(function (p) {
                if (dest.indexOf(p.path + '/') === 0 || dest === p.path) {
                    poolPath = p.path; folder = dest.substring(p.path.length + 1);
                }
            });
            if (!poolPath && pools.length) { poolPath = pools[0].path; folder = 'aicli-history'; }
            var poolOpts = pools.map(function (p) {
                return '<option value="' + esc(p.path) + '"' + (p.path === poolPath ? ' selected' : '') + '>'
                    + esc(p.path) + ' (' + human(p.free_bytes) + ' free)</option>';
            }).join('');
            var noPool = !pools.length;
            var html =
                '<div style="text-align:left; font-size:12px; line-height:1.6;" data-testid="move-sessions-dialog">' +
                '<div style="margin-bottom:8px;">Move old conversation sessions of <b>' + esc(user) + '</b> out of the home to a folder on a pool. ' +
                'This frees space on the boot disk. Nothing is deleted: each file is copied, checked, and only then removed from the home.</div>' +
                '<label style="display:block;">Agent</label>' +
                '<select id="aicli-hist-agent" style="width:100%; margin-bottom:6px;">' +
                    '<option value="all">All agents that keep one file for each session</option>' +
                    agentOptions(m.stores, part.agent, false, null) + '</select>' +
                '<label style="display:block;">Older than (days)</label>' +
                '<input id="aicli-hist-days" type="number" min="1" max="3650" value="90" style="width:100px; margin-bottom:6px;">' +
                '<label style="display:block;">Folder for moved sessions</label>' +
                (noPool
                    ? '<div style="color:#c0392b; margin-bottom:6px;">The array is stopped. Start the array, then try again.</div>'
                    : '<div style="display:flex; gap:6px; margin-bottom:6px;">' +
                      '<select id="aicli-hist-pool" style="flex:1;">' + poolOpts + '</select>' +
                      '<input id="aicli-hist-folder" type="text" value="' + esc(folder) + '" style="flex:1;" placeholder="folder name"></div>') +
                '<div id="aicli-hist-summary" style="margin:8px 0; font-weight:600;"></div>' + stamp(m) +
                '<div style="padding:8px; border:1px solid #b35900; border-radius:4px;">' + esc(WARNING) + '</div>' +
                '</div>';
            swal({
                title: 'Move old sessions',
                text: html, html: true, type: 'warning',
                showCancelButton: true, confirmButtonText: 'Move sessions', cancelButtonText: 'Cancel',
                confirmButtonColor: '#b35900', closeOnConfirm: false, showLoaderOnConfirm: true
            }, function (ok) {
                if (!ok) { unlift(); return; }
                var d = destValue();
                if (!d) { fail('Choose a folder for the moved sessions.'); return; }
                aicliAjax('history_move', {
                    user: user, agent: $('#aicli-hist-agent').val() || 'all',
                    days: parseInt($('#aicli-hist-days').val(), 10) || 90, dest: d
                }, function (r) {
                    unlift();
                    if (r && r.status === 'queued') {
                        swal({ title: 'Move queued', text: r.message || 'Watch the activity tray for progress.', type: 'info', timer: 3000, showConfirmButton: false });
                        if (typeof window.refreshStats === 'function') window.refreshStats();   // the job starts a fresh measurement when it ends (#552)
                    } else {
                        fail(r && r.message);
                    }
                });
            });

            function destValue() {
                var p = $('#aicli-hist-pool').val(), f = String($('#aicli-hist-folder').val() || '').replace(/^\/+|\/+$/g, '');
                return (p && f) ? (p + '/' + f) : '';
            }
            // The count comes from the figures of the answer (ages of 30, 90 and 180 days). No request.
            function refreshSummary() {
                var agent = $('#aicli-hist-agent').val() || 'all';
                var days = parseInt($('#aicli-hist-days').val(), 10) || 90;
                var t = olderThan(m.stores, agent, days, m.ages);
                var text;
                if (!m.has_data) text = 'The sessions are not measured yet. Press Measure now.';
                else if (t === null) text = 'Counts exist for 30, 90 and 180 days. Pick one of them to see the count. The move works for any age.';
                else text = t.sessions + ' sessions (' + t.files + ' files, ' + human(t.bytes) + ') are older than ' + days + ' days and can move.';
                $('#aicli-hist-summary').text(text);
            }
            $(document).off('.aicliHist').on('input.aicliHist change.aicliHist', '#aicli-hist-agent, #aicli-hist-days', refreshSummary);
            refreshSummary();
        });
    };

    reg.actions['bring_back_sessions'] = function (part) {
        part = part || {};
        var user = part.user || 'root';
        if (typeof window.swal !== 'function') { fail('This page cannot show the dialog.'); return; }
        aicliAjax('history_measure', { user: user }, function (m) {
            if (!m || m.status !== 'ok') { fail(m && m.message); return; }
            var moved = m.moved || {};
            var ids = Object.keys(moved);
            if (!m.dest) { fail('No folder for moved sessions is set.'); return; }
            if (!m.dest_mounted) { fail('The array is stopped. Start the array, then try again.'); return; }
            if (!ids.length) { fail('No moved sessions are in ' + m.dest + '.'); return; }
            liftSwal();
            var files = 0, bytes = 0;
            ids.forEach(function (id) { files += moved[id].files; bytes += moved[id].bytes; });
            var html =
                '<div style="text-align:left; font-size:12px; line-height:1.6;" data-testid="bring-back-dialog">' +
                '<div style="margin-bottom:8px;">Copy moved sessions of <b>' + esc(user) + '</b> from <b>' + esc(m.dest) + '</b> back into the home. ' +
                'Each file is checked after the copy. A file that already exists in the home is never replaced.</div>' +
                '<label style="display:block;">Agent</label>' +
                '<select id="aicli-hist-back-agent" style="width:100%; margin-bottom:6px;">' +
                    '<option value="all">All agents (' + files + ' files, ' + human(bytes) + ')</option>' +
                    agentOptions(m.stores, '', true, moved) + '</select>' +
                '<div>The files need ' + esc(human(bytes)) + ' of space on the boot disk.</div></div>';
            swal({
                title: 'Bring back sessions', text: html, html: true, type: 'info',
                showCancelButton: true, confirmButtonText: 'Bring back', cancelButtonText: 'Cancel',
                closeOnConfirm: false, showLoaderOnConfirm: true
            }, function (ok) {
                if (!ok) { unlift(); return; }
                aicliAjax('history_bring_back', { user: user, agent: $('#aicli-hist-back-agent').val() || 'all' }, function (r) {
                    unlift();
                    if (r && r.status === 'queued') {
                        swal({ title: 'Queued', text: r.message || 'Watch the activity tray for progress.', type: 'info', timer: 3000, showConfirmButton: false });
                        if (typeof window.refreshStats === 'function') window.refreshStats();   // the job starts a fresh measurement when it ends (#552)
                    } else {
                        fail(r && r.message);
                    }
                });
            });
        });
    };
})();
</script>
