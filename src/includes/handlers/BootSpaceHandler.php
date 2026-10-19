<?php
/**
 * <module_context>
 *     <name>BootSpaceHandler</name>
 *     <description>AJAX handler for the Boot disk space card (docs/specs/BOOT_DISK_SPACE.md R1, #538).
 *     get_boot_space (read-only): returns the last measurement and starts a new one when it is
 *     older than 10 minutes or missing. measure_boot_space (POST): starts a measure now.
 *     remove_leftover (POST): removes one item of the Left over line.
 *     clean_agent_tmp (POST): queues the supervisor job that removes the temporary folders of closed
 *     agent sessions (AGENT_TEMP_FOLDER.md R5). The request removes nothing and measures nothing.</description>
 *     <dependencies>BootSpaceService.</dependencies>
 *     <constraints>CSRF is checked once by the AJAX dispatcher. The two changing actions refuse
 *     a GET request. A measure never runs inside the request: it starts a detached script.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\BootSpaceService;

require_once __DIR__ . '/../services/BootSpaceService.php';

class BootSpaceHandler
{
    public static function handle($action, $id): ?array
    {
        switch ($action) {
            case 'get_boot_space':     return self::get();
            case 'measure_boot_space': return self::measure();
            case 'remove_leftover':    return self::removeLeftover();
            case 'clean_agent_tmp':    return self::cleanAgentTmp();
            default:                   return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions(): array
    {
        return ['get_boot_space', 'measure_boot_space', 'remove_leftover', 'clean_agent_tmp'];
    }

    /** True for a POST request. $method is a test seam. */
    public static function isPost(?string $method = null): bool
    {
        return strtoupper($method ?? (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    }

    public static function get(?callable $launcher = null): array
    {
        return ['status' => 'ok'] + BootSpaceService::status($launcher);
    }

    public static function measure(?string $method = null, ?callable $launcher = null): array
    {
        if (!self::isPost($method)) return ['status' => 'error', 'message' => 'This action needs a POST request.'];
        if (self::migrationActive()) return self::migrationRefusal();
        $started = BootSpaceService::startMeasure($launcher);
        return ['status' => 'ok', 'measuring' => $started || BootSpaceService::isMeasuring(), 'started' => $started];
    }

    /**
     * AJAX `clean_agent_tmp` (AGENT_TEMP_FOLDER.md R5): queues a supervisor job (op `cleantmp`)
     * with Activity tray steps and returns at once. $seams (tests): enqueue (fn(jobId):bool), no_track.
     */
    public static function cleanAgentTmp(?string $method = null, array $seams = []): array
    {
        if (!self::isPost($method)) return ['status' => 'error', 'message' => 'This action needs a POST request.'];
        if (self::migrationActive()) return self::migrationRefusal();
        aicli_log("AJAX Request: Clean up temporary files of closed sessions", AICLI_LOG_INFO);
        require_once __DIR__ . '/../services/TempCleanupJobService.php';
        $op = \AICliAgents\Services\TempCleanupJobService::OP;
        $jobId = \AICliAgents\Services\SupervisorService::generateJobId($op, 'tmp', 'agent');
        $queued = isset($seams['enqueue'])
            ? (bool)($seams['enqueue'])($jobId)
            : \AICliAgents\Services\SupervisorService::enqueue('tmp', 'agent', $op, 'user_clean_tmp', 5, $jobId);
        if (!$queued) return ['status' => 'error', 'message' => 'Could not queue the job. Try again.'];
        if (!isset($seams['no_track'])) {
            \AICliAgents\Services\ActivityService::register("storage_job_$jobId", 'storage', 'Cleaning up temporary files of closed sessions', [
                'step' => 'Queued', 'progress' => 5, 'meta' => ['jobId' => $jobId, 'cancel' => 'none'],
            ]);
            \AICliAgents\Services\SupervisorService::wake();
        }
        return ['status' => 'queued', 'job_id' => $jobId, 'message' => 'Queued. Watch the activity tray for progress.'];
    }

    public static function removeLeftover(?string $method = null, ?string $key = null, array $seams = []): array
    {
        if (!self::isPost($method)) return ['status' => 'error', 'message' => 'This action needs a POST request.'];
        if (self::migrationActive()) return self::migrationRefusal();
        $key = trim($key ?? (string)($_REQUEST['key'] ?? ''));
        aicli_log("AJAX Request: Remove left-over item $key", AICLI_LOG_INFO);
        $r = BootSpaceService::removeLeftover($key, $seams);
        if (($r['status'] ?? '') === 'ok' && !isset($seams['no_refresh'])) {
            // The card shows the new numbers at once: ask for a fresh measure.
            BootSpaceService::startMeasure($seams['launcher'] ?? null);
        }
        return $r;
    }

    private static function migrationActive(): bool
    {
        require_once __DIR__ . '/../services/StorageBackendMigrationService.php';
        return \AICliAgents\Services\StorageBackendMigrationService::isMigrationInProgress();
    }

    private static function migrationRefusal(): array
    {
        return ['status' => 'error', 'reason' => 'storage_migration', 'message' => 'Wait for the storage migration to finish before starting another storage action.'];
    }
}
