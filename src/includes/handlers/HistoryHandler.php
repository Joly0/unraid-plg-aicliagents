<?php
/**
 * <module_context>
 *     <name>HistoryHandler</name>
 *     <description>AJAX handler for old conversation history
 *     (docs/specs/BOOT_DISK_SPACE.md R6, #539). history_measure: sizes per agent
 *     and what a move would pick, read from the last background measurement (#552). history_move and history_bring_back: queue a
 *     supervisor job (ops historymove, historyback) with Activity tray steps.</description>
 *     <dependencies>HistoryStoreService, HistoryMoveService, HistoryJobService,
 *     SupervisorService, ActivityService, ConfigService.</dependencies>
 *     <constraints>CSRF is checked once by the AJAX dispatcher. A move needs a
 *     destination on a mounted pool; with the array stopped it refuses with
 *     "The array is stopped". The user comes from the request and must match
 *     [A-Za-z0-9._-]+.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\HistoryJobService;
use AICliAgents\Services\HistoryMoveService;
use AICliAgents\Services\HistoryStoreService;
use AICliAgents\Services\BootSpaceService;

require_once __DIR__ . '/../services/HistoryStoreService.php';
require_once __DIR__ . '/../services/HistoryMoveService.php';
require_once __DIR__ . '/../services/HistoryJobService.php';
require_once __DIR__ . '/../services/BootSpaceService.php';

class HistoryHandler
{
    public static function handle($action, $id): ?array
    {
        switch ($action) {
            case 'history_measure':    return self::measure();          // read-only
            case 'history_move':       return self::queue(HistoryJobService::OP_MOVE);
            case 'history_bring_back': return self::queue(HistoryJobService::OP_BACK);
            default:                   return null;
        }
    }

    /** Actions handled by this handler. */
    public static function actions(): array
    {
        return ['history_measure', 'history_move', 'history_bring_back'];
    }

    /** @return array{0:?string,1:string} [error|null, user] */
    private static function user(): array
    {
        $user = trim((string)($_REQUEST['user'] ?? ''));
        if ($user === '' || $user === '0') $user = 'root';
        // R-138: a home id never starts with a dot, so "." and ".." cannot name the store base.
        if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]*$/D', $user) !== 1) return ['invalid_user', $user];
        return [null, $user];
    }

    private static function homeDir(string $user): string
    {
        $env = getenv('AICLI_HISTORY_HOME_DIR');
        if ($env !== false && $env !== '') return rtrim($env, '/');
        require_once __DIR__ . '/../services/UtilityService.php';
        return \AICliAgents\Services\UtilityService::getWorkDir($user) . '/home';
    }

    private static function destination(): string
    {
        $given = trim((string)($_REQUEST['dest'] ?? ''));
        $stored = (string)(getAICliConfig()['history_move_path'] ?? '');
        return $given !== '' ? HistoryMoveService::normaliseSetting($given, '') : $stored;
    }

    /**
     * history_measure (read-only). #552: the figures come from the last background measurement
     * (.boot_space.json). This request walks no folder and measures nothing. The answer carries
     * `measured_at`, `age`, `stale`, `has_data` and `measuring`. The count of sessions that a
     * move would pick (`selection`) exists for the ages 30, 90 and 180 days (`ages`); for any
     * other age it is null. The count is approximate: the move itself skips sessions in use.
     */
    private static function measure(): array
    {
        [$err, $user] = self::user();
        if ($err) return ['status' => 'error', 'message' => $err];
        $days = max(1, min(3650, (int)($_REQUEST['days'] ?? 90)));
        $dest = self::destination();
        $data = BootSpaceService::read();
        $rows = BootSpaceService::cachedHistory($user, $data) ?? [];
        $ages = is_array($data['history_ages'] ?? null) ? array_map('intval', $data['history_ages']) : BootSpaceService::HISTORY_AGES;
        $stores = [];
        foreach (HistoryStoreService::stores() as $agentId => $st) {
            $m = $rows[$agentId] ?? [];
            $stores[] = [
                'id' => $agentId, 'label' => $st['label'], 'kind' => $st['kind'], 'verified' => $st['verified'],
                'note' => $st['note'], 'source' => $st['source'],
                'bytes' => (int)($m['bytes'] ?? 0), 'files' => (int)($m['files'] ?? 0), 'sessions' => (int)($m['sessions'] ?? 0),
                'index_bytes' => (int)($m['index_bytes'] ?? 0), 'older_than' => $m['older_than'] ?? new \stdClass(),
                'partial' => (bool)($m['partial'] ?? false),
            ];
        }
        $agent = (string)($_REQUEST['agent'] ?? '');
        $selection = null;
        if ($agent !== '' && $rows && in_array($days, $ages, true)) {
            $ids = $agent === 'all'
                ? array_keys(array_filter(HistoryStoreService::stores(), static function ($s) { return $s['kind'] === 'files'; }))
                : (HistoryStoreService::isMovable($agent) ? [$agent] : []);
            $selection = ['files' => 0, 'bytes' => 0, 'sessions' => 0, 'agents' => []];
            foreach ($ids as $id) {
                $b = $rows[$id]['older_than'][$days] ?? $rows[$id]['older_than'][(string)$days] ?? ['files' => 0, 'bytes' => 0, 'sessions' => 0];
                $one = ['files' => (int)$b['files'], 'bytes' => (int)$b['bytes'], 'sessions' => (int)$b['sessions']];
                $selection['agents'][$id] = $one;
                foreach ($one as $k => $v) $selection[$k] += $v;
            }
        }
        $mounted = $dest !== '' && HistoryMoveService::destinationMounted($dest);
        return [
            'status' => 'ok', 'user' => $user, 'stores' => $stores, 'days' => $days, 'ages' => $ages, 'selection' => $selection,
            'dest' => $dest, 'dest_mounted' => $mounted,
            'moved' => $dest !== '' && $mounted ? HistoryMoveService::movedSummary($dest, $user) : [],
            'pools' => HistoryMoveService::mountedPools(),
        ] + BootSpaceService::meta($data) + ['has_user' => $rows !== []];
    }

    private static function queue(string $op): array
    {
        [$err, $user] = self::user();
        if ($err) return ['status' => 'error', 'message' => $err];
        $agent = trim((string)($_REQUEST['agent'] ?? 'all'));
        if ($agent === '') $agent = 'all';
        $stores = HistoryStoreService::stores();
        if ($agent !== 'all' && !isset($stores[$agent])) {
            return ['status' => 'error', 'message' => 'Unknown agent.'];
        }
        if ($agent !== 'all' && $op === HistoryJobService::OP_MOVE && !HistoryStoreService::isMovable($agent)) {
            return ['status' => 'error', 'message' => 'This agent keeps its history in a database, so its sessions cannot move.'];
        }
        $days = (int)($_REQUEST['days'] ?? 90);
        if ($op === HistoryJobService::OP_MOVE && ($days < 1 || $days > 3650)) {
            return ['status' => 'error', 'message' => 'Enter an age between 1 and 3650 days.'];
        }
        $dest = self::destination();
        if ($dest === '') {
            return ['status' => 'error', 'message' => 'Choose a folder for the moved sessions first.'];
        }
        if (!HistoryMoveService::destinationMounted($dest)) {
            return ['status' => 'error', 'message' => HistoryMoveService::REFUSE_ARRAY_STOPPED];
        }
        // Remember the folder when the person typed a new one.
        if (trim((string)($_REQUEST['dest'] ?? '')) !== '' && $dest !== (string)(getAICliConfig()['history_move_path'] ?? '')) {
            saveAICliConfig(['history_move_path' => $dest], false);
        }

        require_once __DIR__ . '/../services/SupervisorService.php';
        require_once __DIR__ . '/../services/ActivityService.php';
        $jobId = \AICliAgents\Services\SupervisorService::generateJobId($op, 'home', $user);
        $ok = HistoryJobService::writeOptions($jobId, [
            'op' => $op, 'user' => $user, 'agent' => $agent, 'days' => $days, 'dest' => $dest, 'home' => self::homeDir($user),
        ]);
        if (!$ok) return ['status' => 'error', 'message' => 'Could not prepare the job. Try again.'];
        if (!\AICliAgents\Services\SupervisorService::enqueue('home', $user, $op, 'user_history', 5, $jobId)) {
            HistoryJobService::deleteOptions($jobId);
            return ['status' => 'error', 'message' => 'Could not queue the job. Try again.'];
        }
        $label = $op === HistoryJobService::OP_BACK
            ? "Bringing back old sessions for $user"
            : "Moving old sessions of $user to " . basename($dest);
        \AICliAgents\Services\ActivityService::register("storage_job_$jobId", 'storage', $label, [
            'step' => 'Queued', 'progress' => 5, 'meta' => ['jobId' => $jobId, 'cancel' => 'none'],
        ]);
        \AICliAgents\Services\SupervisorService::wake();
        return ['status' => 'queued', 'job_id' => $jobId, 'message' => 'Queued. Watch the activity tray for progress.'];
    }
}
