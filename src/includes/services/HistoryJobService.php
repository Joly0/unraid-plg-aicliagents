<?php
/**
 * <module_context>
 * Description: The supervisor job for old conversation history (ops
 *   `historymove` and `historyback`; docs/specs/BOOT_DISK_SPACE.md R6, #539).
 *   The AJAX handler writes an options file, then queues the job. The
 *   supervisor starts scripts/storage/history-move.php, which calls run().
 *   run() moves or brings back the sessions and writes a progress file and a
 *   result file. SupervisorService reads those two files for the Activity tray.
 * Dependencies: HistoryStoreService, HistoryMoveService.
 * Constraints: No plugin config here: the options file carries everything. State
 *   files live in /tmp/unraid-aicliagents/history (AICLI_HISTORY_STATE_DIR in tests).
 * </module_context>
 *
 * Op names are lower-case letters only, because SupervisorService::enqueue()
 * removes every other character. The spec calls them history_move and
 * history_bring_back.
 */

namespace AICliAgents\Services;

class HistoryJobService
{
    public const OP_MOVE = 'historymove';
    public const OP_BACK = 'historyback';

    public static function isHistoryOp(string $op): bool
    {
        return $op === self::OP_MOVE || $op === self::OP_BACK;
    }

    public static function stateDir(): string
    {
        $env = getenv('AICLI_HISTORY_STATE_DIR');
        return ($env !== false && $env !== '') ? rtrim($env, '/') : '/tmp/unraid-aicliagents/history';
    }

    private static function path(string $jobId, string $suffix): string
    {
        return self::stateDir() . '/' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $jobId) . '.' . $suffix;
    }

    /**
     * @param array{op:string,user:string,agent:string,days:int,dest:string,home:string} $opts
     */
    public static function writeOptions(string $jobId, array $opts): bool
    {
        return self::writeJson(self::path($jobId, 'opts.json'), $opts);
    }

    /** @return array<string,mixed> */
    public static function readOptions(string $jobId): array
    {
        return self::readJson(self::path($jobId, 'opts.json'));
    }

    public static function deleteOptions(string $jobId): void
    {
        @unlink(self::path($jobId, 'opts.json'));
    }

    /** @param array<string,mixed> $p */
    public static function writeProgress(string $jobId, array $p): void
    {
        self::writeJson(self::path($jobId, 'progress.json'), $p + ['ts' => time()]);
    }

    /** @return array<string,mixed> */
    public static function readProgress(string $jobId): array
    {
        $p = self::readJson(self::path($jobId, 'progress.json'));
        return $p;
    }

    /** @param array<string,mixed> $r */
    public static function writeResult(string $jobId, array $r): void
    {
        self::writeJson(self::path($jobId, 'result.json'), $r);
    }

    /** @return array<string,mixed> */
    public static function readResult(string $jobId): array
    {
        return self::readJson(self::path($jobId, 'result.json'));
    }

    /** Tray step text for a running job. */
    public static function runningStep(string $op, string $jobId): string
    {
        $p = self::readProgress($jobId);
        if (!$p) return 'Checking the sessions';
        $done = (int)($p['done'] ?? 0);
        $total = (int)($p['total'] ?? 0);
        $verb = $op === self::OP_BACK ? 'Bringing back sessions' : 'Moving sessions';
        if ($total <= 0) return 'Checking the sessions';
        return sprintf('%s: %d of %d files (%s)', $verb, $done, $total, self::human((int)($p['bytes'] ?? 0)));
    }

    /** Tray percentage for a running job (10 to 95). */
    public static function runningProgress(string $jobId): int
    {
        $p = self::readProgress($jobId);
        $total = (int)($p['total'] ?? 0);
        if ($total <= 0) return 10;
        return max(10, min(95, 10 + (int)round(85 * (int)($p['done'] ?? 0) / $total)));
    }

    /** One plain sentence for the finished job. */
    public static function doneStep(string $jobId): string
    {
        $r = self::readResult($jobId);
        if (!$r) return '';
        $skipped = count((array)($r['skipped'] ?? []));
        if (($r['op'] ?? '') === self::OP_BACK) {
            $s = sprintf('Brought back %d files (%s).', (int)($r['restored'] ?? 0), self::human((int)($r['bytes'] ?? 0)));
        } else {
            $s = sprintf('Moved %d files (%s) out of the home.', (int)($r['moved'] ?? 0), self::human((int)($r['bytes'] ?? 0)));
        }
        if ($skipped > 0) $s .= sprintf(' %d files stayed (in use, changed, or already there).', $skipped);
        return $s;
    }

    /** The reason a failed job wrote; '' when none. */
    public static function failureReason(string $jobId): string
    {
        $r = self::readResult($jobId);
        return mb_substr(trim((string)($r['message'] ?? '')), 0, 200);
    }

    /** Remove the state files of a finished job after the tray has read them. */
    public static function cleanup(string $jobId): void
    {
        foreach (['opts.json', 'progress.json', 'result.json'] as $s) @unlink(self::path($jobId, $s));
    }

    /**
     * Run a queued job. Returns the process exit code: 0 done, 1 refused or failed.
     * @param array<string,callable> $seams test seams passed to HistoryMoveService
     */
    public static function run(string $jobId, array $seams = []): int
    {
        $o = self::readOptions($jobId);
        if (!$o || empty($o['op']) || empty($o['user']) || empty($o['dest']) || empty($o['home'])) {
            self::writeResult($jobId, ['op' => $o['op'] ?? '', 'status' => 'error', 'message' => 'The job has no options.', 'skipped' => []]);
            return 1;
        }
        $seams['progress'] = static function (array $p) use ($jobId): void { HistoryJobService::writeProgress($jobId, $p); };
        self::writeProgress($jobId, ['phase' => 'checking', 'done' => 0, 'total' => 0, 'bytes' => 0]);
        $agents = ((string)($o['agent'] ?? '') === 'all')
            ? array_keys(array_filter(HistoryStoreService::stores(), static function ($s) { return $s['kind'] === 'files'; }))
            : [(string)$o['agent']];

        try {
            if ($o['op'] === self::OP_BACK) {
                $res = HistoryMoveService::bringBack((string)$o['home'], (string)$o['user'], (string)($o['agent'] ?: 'all'), (string)$o['dest'], $seams);
                $res['op'] = self::OP_BACK;
            } else {
                $res = ['op' => self::OP_MOVE, 'status' => 'ok', 'message' => '', 'moved' => 0, 'bytes' => 0, 'skipped' => []];
                foreach ($agents as $agentId) {
                    $r = HistoryMoveService::move((string)$o['home'], (string)$o['user'], $agentId, (int)$o['days'], (string)$o['dest'], $seams);
                    if ($r['status'] !== 'ok') { $res['status'] = 'error'; $res['message'] = $r['message']; break; }
                    $res['moved'] += $r['moved'];
                    $res['bytes'] += $r['bytes'];
                    $res['skipped'] = array_merge($res['skipped'], $r['skipped']);
                }
            }
        } catch (\Throwable $e) {
            LogService::log('Job ' . $jobId . ' stopped: ' . $e->getMessage(), LogService::LOG_ERROR, 'HistoryJob');
            $res = ['op' => (string)$o['op'], 'status' => 'error', 'message' => 'The job stopped with an error. Details are in the Debug Console.', 'skipped' => []];
        }
        self::writeResult($jobId, $res);
        return $res['status'] === 'ok' ? 0 : 1;
    }

    public static function human(int $bytes): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float)$bytes;
        while ($v >= 1024 && $i < 4) { $v /= 1024; $i++; }
        return ($i === 0 ? (string)(int)$v : number_format($v, 1)) . ' ' . $u[$i];
    }

    /** @param array<string,mixed> $data */
    private static function writeJson(string $path, array $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, json_encode($data)) === false) return false;
        return @rename($tmp, $path);
    }

    /** @return array<string,mixed> */
    private static function readJson(string $path): array
    {
        $raw = @file_get_contents($path);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) ? $d : [];
    }
}
