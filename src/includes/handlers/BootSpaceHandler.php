<?php
/**
 * <module_context>
 *     <name>BootSpaceHandler</name>
 *     <description>AJAX handler for the Boot disk space card (docs/specs/BOOT_DISK_SPACE.md R1, #538).
 *     get_boot_space (read-only): returns the last measurement and starts a new one when it is
 *     older than 10 minutes or missing. measure_boot_space (POST): starts a measure now.
 *     remove_leftover (POST): removes one item of the Left over line.</description>
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
            default:                   return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions(): array
    {
        return ['get_boot_space', 'measure_boot_space', 'remove_leftover'];
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
        $started = BootSpaceService::startMeasure($launcher);
        return ['status' => 'ok', 'measuring' => $started || BootSpaceService::isMeasuring(), 'started' => $started];
    }

    public static function removeLeftover(?string $method = null, ?string $key = null, array $seams = []): array
    {
        if (!self::isPost($method)) return ['status' => 'error', 'message' => 'This action needs a POST request.'];
        $key = trim($key ?? (string)($_REQUEST['key'] ?? ''));
        aicli_log("AJAX Request: Remove left-over item $key", AICLI_LOG_INFO);
        $r = BootSpaceService::removeLeftover($key, $seams);
        if (($r['status'] ?? '') === 'ok' && !isset($seams['no_refresh'])) {
            // The card shows the new numbers at once: ask for a fresh measure.
            BootSpaceService::startMeasure($seams['launcher'] ?? null);
        }
        return $r;
    }
}
