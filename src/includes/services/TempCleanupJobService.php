<?php
/**
 * <module_context>
 * Description: The supervisor job "Clean up temporary files of closed sessions" (op `cleantmp`;
 *   docs/specs/AGENT_TEMP_FOLDER.md R5). The web request only queues the job. The supervisor starts
 *   scripts/storage/clean-agent-tmp.php, which calls run(). run() removes the temporary folders of
 *   sessions that no longer run (AgentTempService::cleanup) and writes a progress file and a result
 *   file. SupervisorService reads the two files for the Activity tray.
 * Dependencies: AgentTempService, BootSpaceService.
 * Constraints: The request never removes and never measures. State files live in
 *   /tmp/unraid-aicliagents/tmp-clean (AICLI_TMP_CLEAN_STATE_DIR in tests). The op name has
 *   lower-case letters only, because SupervisorService::enqueue() removes every other character.
 * </module_context>
 */

namespace AICliAgents\Services;

class TempCleanupJobService
{
    public const OP = 'cleantmp';

    public static function isOp(string $op): bool
    {
        return $op === self::OP;
    }

    public static function stateDir(): string
    {
        $env = getenv('AICLI_TMP_CLEAN_STATE_DIR');
        return ($env !== false && $env !== '') ? rtrim($env, '/') : '/tmp/unraid-aicliagents/tmp-clean';
    }

    private static function path(string $jobId, string $suffix): string
    {
        return self::stateDir() . '/' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $jobId) . '.' . $suffix;
    }

    /** @param array<string,mixed> $p */
    public static function writeProgress(string $jobId, array $p): void
    {
        self::writeJson(self::path($jobId, 'progress.json'), $p + ['ts' => time()]);
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
    public static function runningStep(string $jobId): string
    {
        $p = self::readJson(self::path($jobId, 'progress.json'));
        return ($p['phase'] ?? '') === 'removing' ? 'Removing temporary files of closed sessions' : 'Looking for closed sessions';
    }

    /** Tray percentage for a running job. */
    public static function runningProgress(string $jobId): int
    {
        $p = self::readJson(self::path($jobId, 'progress.json'));
        return ($p['phase'] ?? '') === 'removing' ? 50 : 15;
    }

    /** One plain sentence for the finished job ('' when the result is missing). */
    public static function doneStep(string $jobId): string
    {
        $r = self::readResult($jobId);
        if (!$r) return '';
        $s = sprintf('Removed the temporary files of %d closed session(s) and freed %s.', (int)($r['removed'] ?? 0), BootSpaceService::human((int)($r['freed_bytes'] ?? 0)));
        $kept = (int)($r['skipped'] ?? 0);
        if ($kept > 0) $s .= sprintf(' %d folder(s) stayed (a session uses them, or they are busy).', $kept);
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
        foreach (['progress.json', 'result.json'] as $s) @unlink(self::path($jobId, $s));
    }

    /**
     * Run a queued job. Returns the process exit code: 0 done, 1 failed.
     * @param array<string,callable> $seams test seams: cleanup (fn(): array)
     */
    public static function run(string $jobId, array $seams = []): int
    {
        self::writeProgress($jobId, ['phase' => 'looking']);
        try {
            self::writeProgress($jobId, ['phase' => 'removing']);
            $res = isset($seams['cleanup']) ? ($seams['cleanup'])() : AgentTempService::cleanup();
            self::writeResult($jobId, [
                'status' => 'ok',
                'freed_bytes' => (int)($res['freed_bytes'] ?? 0),
                'removed' => (int)($res['removed'] ?? 0),
                'skipped' => (int)($res['skipped'] ?? 0),
            ]);
            return 0;
        } catch (\Throwable $e) {
            LogService::log('Job ' . $jobId . ' stopped: ' . $e->getMessage(), LogService::LOG_ERROR, 'TempCleanupJob');
            self::writeResult($jobId, ['status' => 'error', 'message' => 'The job stopped with an error. Details are in the Debug Console.']);
            return 1;
        }
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
