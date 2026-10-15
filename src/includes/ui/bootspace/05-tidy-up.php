<?php
/**
 * <module_context>
 * Description: The "Close all and tidy up" action of the Boot disk space card
 *   (docs/specs/BOOT_DISK_SPACE.md R1). It registers aicliBootSpace.actions['tidy_up'].
 *   It starts the layer merge that the home card of the Storage tab starts today
 *   (consolidateStorage('home', <user>) in ManagerStorageScripts.php), with the same
 *   confirm and the same list of open workspaces. `part` carries: user (the home).
 * Dependencies: ManagerStorageScripts.php (consolidateStorage), SweetAlert.
 * Constraints: Atomic UI fragment. It has no merge code of its own.
 * </module_context>
 */
?>
<script>
(function () {
    window.aicliBootSpace = window.aicliBootSpace || { actions: {} };
    window.aicliBootSpace.actions['tidy_up'] = function (part) {
        var user = String((part && (part.user || part.home)) || 'root');
        if (typeof window.consolidateStorage !== 'function') {
            if (typeof window.swal === 'function') swal('Not available', 'The tidy-up is not available on this page.', 'info');
            return;
        }
        window.consolidateStorage('home', user);
        // The merge runs as a job. Measure again so that the card shows the result soon.
        setTimeout(function () {
            if (window.aicliBootSpace && typeof window.aicliBootSpace.refresh === 'function') window.aicliBootSpace.refresh();
        }, 4000);
    };
})();
</script>
