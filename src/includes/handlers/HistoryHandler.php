<?php
/**
 * <module_context>
 *     <name>HistoryHandler</name>
 *     <description>AJAX handler for old conversation history
 *     (docs/specs/BOOT_DISK_SPACE.md R6, #539). history_measure: sizes per agent
 *     and what a move would pick. history_move and history_bring_back: queue a
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

require_once __DIR__ . '/../services/HistoryStoreService.php';
require_once __DIR__ . '/../services/HistoryMoveService.php';
require_once __DIR__ . '/../services/HistoryJobService.php';

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
        if (preg_match('/^[A-Za-z0-9._-]+$/', $user) !== 1) return ['invalid_user', $user];
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

    private static function measure(): array
    {
        [$err, $user] = self::user();
        if ($err) return ['status' => 'error', 'message' => $err];
        $home = self::homeDir($user);
        $days = max(1, min(3650, (int)($_REQUEST['days'] ?? 90)));
        $dest = self::destination();
        $measure = HistoryStoreService::measure($home);
        $stores = [];
        foreach (HistoryStoreService::stores() as $agentId => $st) {
            $m = $measure[$agentId];
            $stores[] = [
                'id' => $agentId, 'label' => $st['label'], 'kind' => $st['kind'], 'verified' => $st['verified'],
                'note' => $st['note'], 'source' => $st['source'],
                'bytes' => $m['bytes'], 'files' => $m['files'], 'sessions' => $m['sessions'],
                'index_bytes' => $m['index_bytes'], 'older_than' => $m['older_than'], 'partial' => $m['partial'],
            ];
        }
        $agent = (string)($_REQUEST['agent'] ?? '');
        $selection = null;
        if ($agent !== '') {
            $ids = $agent === 'all'
                ? array_keys(array_filter(HistoryStoreService::stores(), static function ($s) { return $s['kind'] === 'files'; }))
                : (HistoryStoreService::isMovable($agent) ? [$agent] : []);
            $selection = HistoryStoreService::summarize($home, $ids, $days);
        }
        $mounted = $dest !== '' && HistoryMoveService::destinationMounted($dest);
        return [
            'status' => 'ok', 'user' => $user, 'stores' => $stores, 'days' => $days, 'selection' => $selection,
            'dest' => $dest, 'dest_mounted' => $mounted,
            'moved' => $dest !== '' && $mounted ? HistoryMoveService::movedSummary($dest, $user) : [],
            'pools' => HistoryMoveService::mountedPools(),
            'measured_at' => time(),
        ];
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
