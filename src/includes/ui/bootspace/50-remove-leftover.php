<?php
/**
 * <module_context>
 * Description: The "Remove" action of the Left over line on the Boot disk space card
 *   (docs/specs/BOOT_DISK_SPACE.md R1). It registers aicliBootSpace.actions['remove_leftover'].
 *   The confirm names every item and the space it frees. Then it removes the items one by one
 *   with the POST action remove_leftover (the server checks each item again).
 *   `part` carries: items [{key, label, bytes}].
 * Dependencies: jQuery, SweetAlert, 00-card.php (aicliBootSpace.post, aicliBootSpace.refresh).
 * Constraints: Atomic UI fragment. Never removes an item that the server did not list.
 * </module_context>
 */
?>
<script>
(function () {
    var reg = window.aicliBootSpace = window.aicliBootSpace || { actions: {} };

    function esc(s) {
        if (typeof window.escapeHtml === 'function') return window.escapeHtml(String(s == null ? '' : s));
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }
    function human(b) {
        b = Math.max(0, Number(b) || 0);
        if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' GB';
        return Math.max(b > 0 ? 1 : 0, Math.round(b / 1048576)) + ' MB';
    }

    // Pure: the text of the confirm.
    function confirmHtml(items, lockedCount) {
        var total = 0;
        var li = (items || []).map(function (it) {
            total += Number(it.bytes) || 0;
            return '<li>' + esc(it.label) + ' &mdash; <b>' + human(it.bytes) + '</b></li>';
        }).join('');
        var locked = lockedCount > 0
            ? '<div style="margin:8px 0;">' + lockedCount + (lockedCount === 1 ? ' archived copy is' : ' archived copies are') + ' still needed to finish or put back a conversion. They stay in place.</div>'
            : '';
        return '<div style="text-align:left; font-size:12px; line-height:1.6;" data-testid="remove-leftover-dialog">' +
            '<div style="margin-bottom:8px;">These items are left over and no workspace uses them. They are removed for good:</div>' +
            '<ul style="margin:0 0 8px 18px; padding:0;">' + li + '</ul>' + locked +
            '<div>This frees <b>' + human(total) + '</b> on the boot disk.</div></div>';
    }
    reg.removeLeftoverHtml = confirmHtml;   // test seam

    function fail(msg) {
        if (typeof window.swal === 'function') swal('Not removed', msg || 'The items could not be removed.', 'error');
    }

    reg.actions['remove_leftover'] = function (part) {
        var allItems = (part && part.items) || [];
        var items = allItems.filter(function (it) { return !it.locked; });
        var lockedCount = allItems.length - items.length;
        if (!items.length) { fail(lockedCount ? 'A conversion still needs this archive to finish or put back the storage.' : 'Nothing is left over.'); return; }
        if (typeof window.swal !== 'function') { fail('This page cannot show the dialog.'); return; }
        swal({
            title: 'Remove left-over items?',
            text: confirmHtml(items, lockedCount),
            html: true,
            type: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Remove',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#b35900',
            showLoaderOnConfirm: true,
            closeOnConfirm: false
        }, function (confirmed) {
            if (!confirmed) return;
            var freed = 0, errors = [], i = 0;
            (function next() {
                if (i >= items.length) {
                    if (errors.length) fail(errors.join(' '));
                    else swal({ title: 'Removed', text: 'Freed ' + human(freed) + '.', type: 'success', timer: 2500, showConfirmButton: false });
                    if (typeof reg.refresh === 'function') reg.refresh();
                    return;
                }
                var it = items[i++];
                reg.post('remove_leftover', { key: it.key }, function (r) {
                    if (r && r.status === 'ok') freed += Number(r.freed_bytes) || 0;
                    else errors.push((it.label || it.key) + ': ' + ((r && r.message) || 'failed.'));
                    next();
                }).fail(function () { errors.push((it.label || it.key) + ': the request failed.'); next(); });
            })();
        });
    };
})();
</script>
