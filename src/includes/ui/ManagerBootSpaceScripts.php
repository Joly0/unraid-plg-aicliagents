<?php
/**
 * <module_context>
 * Description: Loader for the Boot disk space tools on the Storage tab
 *   (docs/specs/BOOT_DISK_SPACE.md, epic #534). It defines the registry
 *   window.aicliBootSpace = {actions:{}} and includes every fragment in
 *   bootspace/*.php, in name order. Each fragment registers the dialog of one
 *   tool as aicliBootSpace.actions[<action id>] = function(part) {...}. The Boot
 *   disk space card calls the action of a line by its id.
 * Dependencies: jQuery, SweetAlert, CommonLogging.php (aicliAjax).
 * Constraints: Atomic UI fragment. Loads after ManagerStorageScripts.php.
 * </module_context>
 */
?>
<script>
window.aicliBootSpace = window.aicliBootSpace || { actions: {} };
</script>
<?php
foreach (glob(__DIR__ . '/bootspace/*.php') ?: [] as $aicliBootSpaceFragment) {
    require_once $aicliBootSpaceFragment;
}
unset($aicliBootSpaceFragment);
