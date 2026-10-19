#!/usr/bin/env php
<?php
/** Detached worker for the all-entity storage-engine migration. */

require_once dirname(__DIR__) . '/includes/AICliAgentsManager.php';

use AICliAgents\Services\LogService;
use AICliAgents\Services\StorageBackendMigrationService;

$target = (string)($argv[1] ?? '');
$acknowledgeWear = (string)($argv[2] ?? '0') === '1';
if (!in_array($target, ['layering', 'passthrough'], true)) {
    aicli_log('Storage backend worker refused an invalid target.', AICLI_LOG_ERROR, 'StorageBackendMigration');
    exit(2);
}

try {
    $result = StorageBackendMigrationService::runQueuedMigration($target, $acknowledgeWear);
    if (($result['status'] ?? '') !== 'ok') {
        aicli_log('Storage backend migration worker finished with an error: ' . (string)($result['message'] ?? 'unknown error'), AICLI_LOG_ERROR, 'StorageBackendMigration');
        exit(1);
    }
} catch (Throwable $e) {
    aicli_log('Storage backend migration worker failed: ' . $e->getMessage(), AICLI_LOG_ERROR, 'StorageBackendMigration');
    exit(1);
}
