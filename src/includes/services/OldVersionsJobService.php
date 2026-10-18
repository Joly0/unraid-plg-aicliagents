<?php
/**
 * <module_context>
 * Description: The supervisor job "Remove old versions" (op `removeold`; docs/specs/BOOT_DISK_SPACE.md
 *   R4 and "Implementation notes (#552)"). The web request writes an options file and queues the job.
 *   The supervisor starts scripts/storage/remove-old-versions.php, which calls run(). run() removes
 *   the old versions (AgentVersionCleanupService::removeOldVersions), writes a progress file and a
 *   result file. The runner script then asks for a fresh background measurement. SupervisorService
 *   reads the two files for the Activity tray.
 * Dependencies: AgentVersionCleanupService, BootSpaceService.
 * Constraints: The request never removes and never measures. State files live in
 *   /tmp/unraid-aicliagents/old-versions (AICLI_OLD_VERSIONS_STATE_DIR in tests). The op name has
 *   lower-case letters only, because SupervisorService::enqueue() removes every other character.
 * </module_context>
 */

namespace AICliAgents\Services;

class OldVersionsJobService
{
    public const OP = 'removeold';

    public static function isOp(string $op): bool
    {
        return $op === self::OP;
    }

    public static function stateDir(): string
    {
        $env = getenv('AICLI_OLD_VERSIONS_STATE_DIR');
        return ($env !== false && $env !== '') ? rtrim($env, '/') : '/tmp/unraid-aicliagents/old-versions';
    }

    private static function path(string $jobId, string $suffix): string
    {
        return self::stateDir() . '/' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $jobId) . '.' . $suffix;
    }

    /** @param array<string,mixed> $opts {agent:string} */
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
        return ($p['phase'] ?? '') === 'removing' ? 'Removing old versions' : 'Checking the old versions';
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
        $n = (int)($r['removed'] ?? 0);
        $kept = (int)($r['skipped'] ?? 0);
        $s = sprintf('Removed %d old item(s) and freed %s.', $n, BootSpaceService::human((int)($r['freed_bytes'] ?? 0)));
        if ($kept > 0) $s .= sprintf(' %d item(s) stayed (in use, or busy).', $kept);
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
     * Run a queued job. Returns the process exit code: 0 done, 1 failed.
     * The runner script (remove-old-versions.php) asks for a fresh background measurement after run().
     * @param array<string,callable> $seams test seams: remove (fn(string $agent): array)
     */
    public static function run(string $jobId, array $seams = []): int
    {
        $o = self::readOptions($jobId);
        $agent = (string)($o['agent'] ?? '');
        if ($agent === '' || !AgentVersionCleanupService::isValidTarget($agent)) {
            self::writeResult($jobId, ['status' => 'error', 'message' => 'The job has no valid agent.']);
            return 1;
        }
        self::writeProgress($jobId, ['phase' => 'checking']);
        try {
            self::writeProgress($jobId, ['phase' => 'removing']);
            $res = isset($seams['remove']) ? ($seams['remove'])($agent) : AgentVersionCleanupService::removeOldVersions($agent);
            // #621: a script that failed or timed out ends the job as FAILED, with the numbers of what did go.
            $failed = ($res['status'] ?? 'ok') === 'error';
            $out = [
                'status' => $failed ? 'error' : 'ok', 'agent' => $agent,
                'freed_bytes' => (int)($res['freed_bytes'] ?? 0),
                'removed' => count((array)($res['removed'] ?? [])),
                'skipped' => count((array)($res['skipped'] ?? [])),
            ];
            if ($failed) $out['message'] = (string)($res['message'] ?? 'The clean-up did not finish.');
            self::writeResult($jobId, $out);
            $code = $failed ? 1 : 0;
        } catch (\Throwable $e) {
            LogService::log('Job ' . $jobId . ' stopped: ' . $e->getMessage(), LogService::LOG_ERROR, 'OldVersionsJob');
            self::writeResult($jobId, ['status' => 'error', 'message' => 'The job stopped with an error. Details are in the Debug Console.']);
            $code = 1;
        }
        return $code;
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
