<?php
/**
 * <module_context>
 * Description: The "Clear caches" action of the Boot disk space card
 *   (docs/specs/BOOT_DISK_SPACE.md R5, #537). It registers
 *   aicliBootSpace.actions['clear_caches'] = function (part) {...}. The dialog names the
 *   size it frees, and it runs the AJAX action clear_home_caches for the home of the line.
 *   The server refuses while a workspace of that home runs.
 *   `part` carries: user (the home), and optionally bytes and on_boot_bytes (the sizes the
 *   card showed). The dialog reads the size of the last background measurement
 *   (get_home_cache_status, a read) and shows its age (#552). When the numbers are older than
 *   10 minutes or missing, the dialog offers Measure now. It never measures itself.
 * Dependencies: jQuery, SweetAlert, CommonLogging.php (aicliAjax), escapeHtml
 *   (ManagerStorageScripts.php).
 * Constraints: Atomic UI fragment.
 * </module_context>
 */
?>
<script>
(function () {
    window.aicliBootSpace = window.aicliBootSpace || { actions: {} };

    // Pure: "340 MB" / "1.2 GB". Used by the dialog texts.
    function hcSize(bytes) {
        var b = Math.max(0, Number(bytes) || 0);
        if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' GB';
        return Math.max(b > 0 ? 1 : 0, Math.round(b / 1048576)) + ' MB';
    }

    // Pure: the text of the confirm. `freed` is the size the action removes.
    function hcStamp(st) {
        return (window.aicliBootSpace && typeof window.aicliBootSpace.stampHtml === 'function') ? window.aicliBootSpace.stampHtml(st) : '';
    }

    function hcConfirmText(user, freed, location, st) {
        var where = location === 'ram' ? 'in RAM' : (location === 'pool' ? 'on the pool' : 'on the boot disk');
        return '<div style="text-align:left; font-size:12px; line-height:1.6;">' +
            '<div style="margin-bottom:8px;">This empties the cache folders of the home of <b>' + escapeHtml(user) + '</b> (' + escapeHtml(where) + ').</div>' +
            '<ul style="margin:0 0 0 18px; padding:0;">' +
                '<li>It frees <b>' + escapeHtml(hcSize(freed)) + '</b>.</li>' +
                '<li>A cache holds files that a tool downloads again. Nothing else is removed. Installed Claude Code plugins stay.</li>' +
                '<li>It works only while no workspace of this home runs.</li>' +
            '</ul>' + (st ? hcStamp(st) : '') + '</div>';
    }
    window.aicliHomeCacheDialogText = hcConfirmText;   // used by the tests and the card

    function hcDone(freed) {
        if (typeof window.swal === 'function') {
            swal({ title: 'Caches cleared', text: 'Freed ' + hcSize(freed) + '.', type: 'success', timer: 2500, showConfirmButton: false });
        }
        if (window.aicliBootSpace && typeof window.aicliBootSpace.refresh === 'function') window.aicliBootSpace.refresh();
        else if (typeof refreshStats === 'function') refreshStats();
    }

    function hcFail(message) {
        var text = message || 'The caches could not be cleared.';
        if (typeof window.swal === 'function') swal('Not cleared', text, 'error');
        else window.alert(text);
    }

    window.aicliBootSpace.actions['clear_caches'] = function (part) {
        var user = String((part && (part.user || part.home)) || 'root');
        aicliAjax('get_home_cache_status', { user: user }, function (st) {
            if (window.aicliBootSpace) window.aicliBootSpace.reopen = function () { window.aicliBootSpace.actions['clear_caches'](part); };
            if (st && st.status === 'ok' && st.has_data === false) {
                // Nothing is measured yet: offer one background measure, then open this dialog again.
                if (typeof window.swal === 'function') {
                    swal({ title: 'Clear caches?', text: '<div style="text-align:left; font-size:12px;">The size of the caches is not measured yet. Press Measure now.' + hcStamp(st) + '</div>', html: true, type: 'info' });
                } else {
                    hcFail('The size of the caches is not measured yet.');
                }
                return;
            }
            if (!st || st.location === 'none') {
                hcFail('This home is not a plain-folder home.');
                return;
            }
            var freed = (Number(st.bytes) || 0) + (Number(st.on_boot_bytes) || 0);
            if (typeof window.swal !== 'function') {
                if (window.confirm('Clear the caches of ' + user + '? It frees ' + hcSize(freed) + '.')) run();
                return;
            }
            swal({
                title: 'Clear caches?',
                text: hcConfirmText(user, freed, st.location, st),
                html: true,
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Clear caches',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#b35900',
                showLoaderOnConfirm: true,
                closeOnConfirm: false
            }, function (confirmed) {
                if (confirmed) run();
            });
        });

        function run() {
            aicliAjax('clear_home_caches', { user: user }, function (r) {
                if (r && r.status === 'ok') hcDone(r.freed_bytes);
                else hcFail(r && r.message);
            }).fail(function () { hcFail('The request failed. Check the Debug Console.'); });
        }
    };
})();
</script>
