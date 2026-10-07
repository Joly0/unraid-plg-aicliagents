<?php
/**
 * <module_context>
 *     <name>ActorDownAlertService</name>
 *     <description>Raises an alert when a Relay actor workspace (a server-managed
 *     workspace) cannot start. An actor whose agent fails to start twice within 10
 *     minutes (exit under 3 s, or non-zero exit inside 60 s) is "down": the service speaks one sentence, shows an Activity tray entry
 *     actor_down_&lt;workspaceId&gt; (status failed, the last error line from the crash
 *     forensics file, a recovery hint) and sends one notification per hour. The entry
 *     is removed when the actor runs again. Spec: docs/specs/HARD_STOP_RESILIENCE.md (R4).</description>
 *     <dependencies>ActivityService, UtilityService, ConfigService, VoiceService</dependencies>
 *     <constraints>Best effort: no public method throws. Only Relay actor workspaces
 *     alert; an ordinary workspace is ignored. State is one small JSON file per
 *     workspace in AICLI_ACTOR_DOWN_DIR (default /tmp/unraid-aicliagents/actor-down).
 *     plan() is pure.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class ActorDownAlertService
{
    public const OP_PREFIX = 'actor_down_';
    public const DEFAULT_DIR = '/tmp/unraid-aicliagents/actor-down';
    /** The launcher calls an exit "immediate" when the agent ran for less than this many seconds. */
    public const IMMEDIATE_SECONDS = 3;
    /** Two immediate exits inside this window mean the actor is down. */
    public const WINDOW_SECONDS = 600;
    /** A non-zero exit before the agent ran this long is a failed start (it never became ready). */
    public const STARTUP_WINDOW_SECONDS = 60;
    /** This many failed exits inside the window mean the launcher gave up. */
    public const GIVE_UP_EXITS = 3;
    public const SPOKEN = 'A workspace that other agents depend on could not start. Open the workspace and restart it.';
    public const NOTIFY_INTERVAL_SECONDS = 3600;
    /** The actor must have run this long after the last immediate exit to count as running again. */
    public const HEALTHY_SECONDS = 60;
    public const RECOVERY = 'Open the workspace and press Restart, or close and reopen it.';

    /** Test seam: fn(string $workspaceId): bool. Null means read the Relay actor registry. */
    public static $actorProbe = null;
    /** Test seam: fn(string $message, string $subject): void. Null means the Unraid notification. */
    public static $notifier = null;
    /** Test seam: fn(string $text, array $actorContext): array. Null means VoiceService::speak. */
    public static $speaker = null;

    public static function opId(string $workspaceId): string
    {
        return self::OP_PREFIX . $workspaceId;
    }

    public static function isImmediateExit(int $durationSeconds): bool
    {
        return $durationSeconds < self::IMMEDIATE_SECONDS;
    }

    /**
     * True when an agent exit is a failed start: under 3 s with any code, or a non-zero
     * code before the startup window ended. A long run or a clean exit after 3 s is not.
     */
    public static function isFailedStart(int $durationSeconds, int $exitCode): bool
    {
        if (self::isImmediateExit($durationSeconds)) return true;
        return $exitCode !== 0 && $durationSeconds < self::STARTUP_WINDOW_SECONDS;
    }

    /**
     * Pure decision. $state = ['exits' => int[] (unix times), 'lastNotifyAt' => int].
     * Returns ['down' => bool, 'notify' => bool, 'gaveUp' => bool].
     */
    public static function plan(array $state, int $now): array
    {
        $recent = 0;
        foreach ((array)($state['exits'] ?? []) as $t) {
            $t = (int)$t;
            if ($t <= $now && $now - $t <= self::WINDOW_SECONDS) $recent++;
        }
        $down = $recent >= 2;
        $last = (int)($state['lastNotifyAt'] ?? 0);
        $notify = $down && ($last <= 0 || $now - $last >= self::NOTIFY_INTERVAL_SECONDS);
        return ['down' => $down, 'notify' => $notify, 'gaveUp' => $recent >= self::GIVE_UP_EXITS];
    }

    /**
     * Record an immediate agent exit of one workspace. Does nothing for a workspace
     * that is not a Relay actor. $gaveUp true means the launcher reported a give-up:
     * the actor is down at once, even after a single exit.
     */
    public static function recordExit(string $workspaceId, string $name, int $now, ?string $forensicsPath, bool $gaveUp = false): void
    {
        try {
            $id = self::cleanId($workspaceId);
            if ($id === '' || !self::isActor($id)) return;
            $state = self::readState($id);
            $exits = [];
            foreach ((array)($state['exits'] ?? []) as $t) {
                if ($now - (int)$t <= self::WINDOW_SECONDS) $exits[] = (int)$t;
            }
            $exits[] = $now;
            $state['exits'] = $exits;
            $state['lastExitAt'] = $now;
            $state['name'] = $name !== '' ? $name : (string)($state['name'] ?? $id);

            $plan = self::plan($state, $now);
            if ($gaveUp) {
                $plan['gaveUp'] = true;
                if (!$plan['down']) {
                    $last = (int)($state['lastNotifyAt'] ?? 0);
                    $plan['down'] = true;
                    $plan['notify'] = $last <= 0 || $now - $last >= self::NOTIFY_INTERVAL_SECONDS;
                }
            }
            if ($plan['down']) {
                $label = $state['name'] . ' is down';
                $error = self::lastErrorLine($forensicsPath !== null && $forensicsPath !== '' ? $forensicsPath : self::newestForensics($id));
                $op = self::opId($id);
                if ($plan['gaveUp'] && empty($state['gaveUp'])) {
                    $state['gaveUp'] = true;
                    ActivityService::dismiss($op); // register again below with the give-up text
                }
                if (empty($state['down']) || ActivityService::get($op) === null) {
                    ActivityService::register($op, 'system', $label, ['step' => $plan['gaveUp'] ? 'The launcher gave up after repeated failed starts.' : 'The agent keeps failing to start.']);
                }
                ActivityService::fail($op, $error, self::RECOVERY, ['label' => $label]);
                $state['down'] = true;
                if (empty($state['spoken'])) {
                    $state['spoken'] = $now; // once for each down event, whatever the answer
                    self::say($id, $state['name']);
                }
                if ($plan['notify']) {
                    $state['lastNotifyAt'] = $now;
                    self::send($label . '. ' . $error . ' ' . self::RECOVERY, $label);
                }
            }
            self::writeState($id, $state);
        } catch (\Throwable $e) { /* best effort */ }
    }

    /**
     * Report that a workspace is running. Clears the alert when the agent is alive
     * and the last immediate exit was at least 60 seconds ago. Cheap when the
     * workspace has no state file.
     */
    public static function recordHealthy(string $workspaceId, int $now, bool $agentAlive = true): void
    {
        try {
            $id = self::cleanId($workspaceId);
            if ($id === '' || !is_file(self::statePath($id))) return;
            if (!$agentAlive) return;
            $state = self::readState($id);
            if ($now - (int)($state['lastExitAt'] ?? 0) < self::HEALTHY_SECONDS) return;
            self::clear($id);
        } catch (\Throwable $e) { /* best effort */ }
    }

    /** Remove the state and the tray entry. */
    public static function clear(string $workspaceId): void
    {
        try {
            $id = self::cleanId($workspaceId);
            if ($id === '') return;
            @unlink(self::statePath($id));
            ActivityService::dismiss(self::opId($id));
        } catch (\Throwable $e) { /* best effort */ }
    }

    /** True when this workspace has an alert state (cheap check for the caller). */
    public static function hasState(string $workspaceId): bool
    {
        $id = self::cleanId($workspaceId);
        return $id !== '' && is_file(self::statePath($id));
    }

    /** First non-empty line that contains "Error" or "failed", trimmed to 200 characters. */
    public static function lastErrorLine(?string $forensicsPath): string
    {
        $fallback = 'The agent exited immediately.';
        try {
            if ($forensicsPath === null || $forensicsPath === '' || !is_file($forensicsPath)) return $fallback;
            $text = (string)@file_get_contents($forensicsPath, false, null, 0, 262144);
            foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
                $line = trim(preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $line) ?? $line);
                if ($line === '') continue;
                if (stripos($line, 'Error') !== false || stripos($line, 'failed') !== false) {
                    return mb_substr($line, 0, 200);
                }
            }
        } catch (\Throwable $e) { /* fall through */ }
        return $fallback;
    }

    private static function newestForensics(string $id): ?string
    {
        $dir = getenv('AICLI_CRASH_DIR');
        $dir = ($dir !== false && $dir !== '') ? $dir : '/tmp/unraid-aicliagents';
        $best = null;
        $bestT = 0;
        foreach (glob($dir . '/crash-*-' . $id . '-*.log') ?: [] as $f) {
            $t = (int)@filemtime($f);
            if ($t >= $bestT) { $bestT = $t; $best = $f; }
        }
        return $best;
    }

    private static function isActor(string $id): bool
    {
        if (is_callable(self::$actorProbe)) return (bool)call_user_func(self::$actorProbe, $id);
        $file = ConfigService::getUserStatePath() . '/relay/actors.json';
        $data = is_file($file) ? json_decode((string)@file_get_contents($file), true) : [];
        foreach ((array)($data['actors'] ?? []) as $actor) {
            $sid = is_array($actor) ? (string)($actor['session_id'] ?? '') : (string)$actor;
            if ($sid === $id) return true;
        }
        return false;
    }

    /** Speak one fixed sentence. The switches and the workspace mute are VoiceService's decision. */
    private static function say(string $id, string $name): void
    {
        try {
            $ctx = ['workspaceId' => $id, 'agentId' => '', 'name' => $name];
            if (is_callable(self::$speaker)) { call_user_func(self::$speaker, self::SPOKEN, $ctx); return; }
            VoiceService::speak(self::SPOKEN, $ctx);
        } catch (\Throwable $e) { /* best effort */ }
    }

    private static function send(string $message, string $subject): void
    {
        if (is_callable(self::$notifier)) { call_user_func(self::$notifier, $message, $subject); return; }
        UtilityService::notify($message, $subject);
    }

    private static function cleanId(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? '';
    }

    private static function dir(): string
    {
        $env = getenv('AICLI_ACTOR_DOWN_DIR');
        return ($env !== false && $env !== '') ? $env : self::DEFAULT_DIR;
    }

    private static function statePath(string $id): string
    {
        return self::dir() . '/' . $id . '.json';
    }

    private static function readState(string $id): array
    {
        $p = self::statePath($id);
        $d = is_file($p) ? json_decode((string)@file_get_contents($p), true) : [];
        return is_array($d) ? $d : [];
    }

    private static function writeState(string $id, array $state): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $p = self::statePath($id);
        $tmp = $p . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($state)) !== false) @rename($tmp, $p);
    }
}
