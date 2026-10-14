<?php
/**
 * #496 (docs/specs/CONVERT_LAYERED_TO_PLAIN_FOLDERS.md R13): run once by
 * finalize.sh on plugin install or update. When one or more layered homes sit
 * on a disk that is not a USB stick, send ONE Unraid notification that links to
 * the conversion dialog. A marker on the flash keeps it from repeating until the
 * set of such homes changes. Read only, best effort: it always exits 0, so it
 * can never fail the install.
 *
 * A script FILE, not an inline `php -r`, for the same reason as
 * sync-backup-cron.php: bash would mangle the namespace backslashes.
 */

try {
    require_once dirname(__DIR__) . '/includes/AICliAgentsManager.php';
    require_once dirname(__DIR__) . '/includes/services/ConvertOfferNotifyService.php';
    \AICliAgents\Services\ConvertOfferNotifyService::run();
} catch (\Throwable $e) {
    // best effort: never fail the install
}
exit(0);
