<?php
/**
 * <module_context>
 * Description: The "Clean up temporary files of closed sessions" action of the Boot disk space card
 *   (docs/specs/AGENT_TEMP_FOLDER.md R4 and R5, #554). It registers
 *   aicliBootSpace.actions['clean_agent_tmp'] = function (info) {...} and binds the button of the
 *   warning (.aicli-bs-tmp-act). The dialog names the size it frees and the age of the numbers,
 *   and runs the AJAX action clean_agent_tmp, which only queues a supervisor job. The Activity
 *   tray shows the steps. The dialog never measures: it reads the last measurement (data.agent_tmp)
 *   and offers Measure now when the numbers are old.
 * Dependencies: jQuery, SweetAlert, 00-card.php (aicliBootSpace.data, post, refresh, stampHtml).
 * Constraints: Atomic UI fragment. It removes nothing itself. The server removes only the folders
 *   of sessions that no longer run.
 * </module_context>
 */
?>
<script>
(function () {
    var reg = window.aicliBootSpace = window.aicliBootSpace || { actions: {} };

    // Pure: "340 MB" / "1.2 GB".
    function tmpSize(bytes) {
        var b = Math.max(0, Number(bytes) || 0);
        if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' GB';
        return Math.max(b > 0 ? 1 : 0, Math.round(b / 1048576)) + ' MB';
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Pure: the text of the confirm. `t` is data.agent_tmp, `meta` is the answer with the age.
    function tmpConfirmText(t, meta) {
        var stamp = typeof reg.stampHtml === 'function' ? reg.stampHtml(meta) : '';
        return '<div style="text-align:left; font-size:12px; line-height:1.6;">' +
            '<div style="margin-bottom:8px;">This removes the temporary files of agent sessions that no longer run, from RAM.</div>' +
            '<ul style="margin:0 0 0 18px; padding:0;">' +
                '<li>It frees about <b>' + esc(tmpSize(t && t.closed_bytes)) + '</b> (' + esc(String((t && t.closed_sessions) || 0)) + ' closed sessions).</li>' +
                '<li>It removes files of sessions that ended at least a day ago. A project that is in use now, and any session that changed in the last 24 hours, is never touched.</li>' +
                '<li>Only the temporary folders of agents are removed. Your projects and your home stay.</li>' +
            '</ul>' + stamp + '</div>';
    }
    window.aicliTmpCleanDialogText = tmpConfirmText;   // used by the tests

    function tmpFail(message) {
        var text = message || 'The clean-up could not be queued.';
        if (typeof window.swal === 'function') swal('Not cleaned up', text, 'error');
        else window.alert(text);
    }

    reg.actions['clean_agent_tmp'] = function (info) {
        var meta = reg.data ? (reg.data() || {}) : {};
        reg.reopen = function () { reg.actions['clean_agent_tmp'](info); };
        var t = (meta && meta.agent_tmp) || info || {};
        if (typeof window.swal !== 'function') {
            if (window.confirm('Remove the temporary files of closed sessions? It frees about ' + tmpSize(t.closed_bytes) + '.')) run();
            return;
        }
        swal({
            title: 'Clean up temporary files?',
            text: tmpConfirmText(t, meta),
            html: true,
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Clean up',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#b35900',
            showLoaderOnConfirm: true,
            closeOnConfirm: false
        }, function (confirmed) {
            if (confirmed) run();
        });

        function run() {
            reg.post('clean_agent_tmp', {}, function (r) {
                if (r && (r.status === 'queued' || r.status === 'ok')) {
                    swal({ title: 'Clean-up queued', text: 'Watch the Activity tray for progress.', type: 'success', timer: 2500, showConfirmButton: false });
                } else {
                    tmpFail(r && r.message);
                }
            }).fail(function () { tmpFail('The request failed. Check the Debug Console.'); });
        }
    };

    $(document).on('click', '#aicli-boot-space-card .aicli-bs-tmp-act', function () {
        if (this.getAttribute('aria-disabled') === 'true') return;
        reg.actions['clean_agent_tmp']((reg.data && reg.data() && reg.data().agent_tmp) || {});
    });
})();
</script>
