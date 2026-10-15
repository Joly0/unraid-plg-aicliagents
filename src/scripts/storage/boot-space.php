<?php
/**
 * <module_context>
 * Description: Background measure for the Boot disk space card (docs/specs/BOOT_DISK_SPACE.md R1).
 *   Started detached by BootSpaceService::startMeasure(). It measures the parts of the boot disk
 *   and writes /tmp/unraid-aicliagents/.boot_space.json (temp file, then rename).
 *   Usage: php boot-space.php
 * Dependencies: BootSpaceService (through the manager include).
 * Constraints: One run at a time (a lock file). Exit 0 when it measured or another run holds the
 *   lock, 1 on a failure. It reads only; it never changes plugin data.
 * </module_context>
 */
$inc = getenv('AICLI_MANAGER_PHP') ?: dirname(__DIR__, 2) . '/includes/AICliAgentsManager.php';
require_once $inc;

try {
    \AICliAgents\Services\BootSpaceService::run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'boot-space: ' . $e->getMessage() . "\n");
    exit(1);
}
exit(0);
