<?php
/**
 * <module_context>
 *     <name>TmuxSocketRecovery</name>
 *     <description>Makes a tmux server that lost its socket file reachable again at
 *     a SEPARATE recovery path, without touching the live server that now owns the
 *     original path. 2026-09-29, see docs/specs/DRAWER_ACTIVE_STATE_RECONCILE.md,
 *     "2026-09-29: unreachable copies".</description>
 *     <dependencies>None (file and signal operations are injectable for tests).</dependencies>
 *     <constraints>Static methods only. Never throws. The caller holds the
 *     session's SessionLaunchLock. The file at the original path is ALWAYS put
 *     back (finally block), also on a timeout, a failed rename or an exception.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

final class TmuxSocketRecovery
{
    /** How long to wait for tmux to create its socket again after SIGUSR1. */
    public const DEFAULT_TIMEOUT_SECONDS = 3.0;

    private const POLL_US = 50000;

    /** R-108: how long to watch for a late SIGUSR1 after the timeout. */
    public const LATE_GRACE_SECONDS = 2.0;

    /**
     * tmux 3.6a, on SIGUSR1: the server calls server_create_socket(), which
     * UNLINKS the file at its original bind path, binds a new socket there and
     * closes the old one (proved on .4 with throw-away servers, 2026-09-29).
     * So a plain SIGUSR1 would take the path away from the live server. This
     * function therefore does, in this order:
     *
     *   1. rename the file at $boundPath (the live server's socket) aside;
     *   2. send SIGUSR1 to the unreachable server;
     *   3. wait (bounded) until a new socket file appears at $boundPath;
     *   4. rename that new file to $recoveryPath;
     *   5. ALWAYS (finally) rename the live file back to $boundPath.
     *
     * A renamed socket file stays connectable: connect() finds the socket by
     * the file's inode, so the live server and its clients do not notice the
     * rename. Only a NEW attach in the short window (well under a second
     * normally, $timeout at most) would fail; the caller's SessionLaunchLock
     * keeps plugin starts and closes of the session out of the window.
     *
     * @param array<string,callable> $ops test seams:
     *   exists(string):bool, isDir(string):bool, mkdir(string):bool,
     *   rmdir(string):bool, rename(string,string):bool, signal(int,int):bool,
     *   sleepUs(int):void, now():float
     * @return array{ok:bool,path?:string,reason?:string,steps:list<string>}
     */
    public static function recover(string $boundPath, int $serverPid, string $recoveryPath,
                                   array $ops = [], float $timeout = self::DEFAULT_TIMEOUT_SECONDS): array
    {
        $op = self::ops($ops);
        $steps = [];
        if ($serverPid <= 1 || $boundPath === '' || $recoveryPath === '' || $boundPath === $recoveryPath) {
            return ['ok' => false, 'reason' => 'bad_arguments', 'steps' => $steps];
        }
        if (($op['exists'])($recoveryPath)) {
            return ['ok' => false, 'reason' => 'recovery_path_in_use', 'steps' => $steps];
        }

        // tmux cannot create its socket when a parent folder is missing (it
        // then keeps running without one). Create the folder for the moment.
        $dir = dirname($boundPath);
        $createdDir = false;
        if (!($op['isDir'])($dir)) {
            if (!($op['mkdir'])($dir)) {
                return ['ok' => false, 'reason' => 'mkdir_failed', 'steps' => $steps];
            }
            $createdDir = true;
            $steps[] = 'mkdir';
        }

        $aside = $boundPath . '.live-' . $serverPid;
        $movedAside = false;
        $result = ['ok' => false, 'reason' => 'exception'];
        // R-108: what the late-signal check needs (inode of the live socket, and a hard link to it).
        $live = ['inode' => false, 'keep' => null];
        try {
            $result = self::attempt($op, $boundPath, $aside, $serverPid, $recoveryPath, $timeout, $steps, $movedAside, $live);
        } catch (\Throwable $e) {
            $steps[] = 'exception';
            $result = ['ok' => false, 'reason' => 'exception'];
        } finally {
            // The live path comes back in every case. rename() replaces a file
            // that tmux created late at $boundPath, so the live server wins.
            if ($movedAside) {
                ($op['rename'])($aside, $boundPath);
                $steps[] = 'restore';
            } elseif ($createdDir && !$result['ok']) {
                ($op['rmdir'])($dir);
            }
        }
        // R-108: after a timeout the slow server may still handle SIGUSR1. It then unlinks
        // the live socket we just put back and binds its own there. Look again for a short
        // time; if the inode at $boundPath changed, that new file is the recovered socket.
        if (!$result['ok'] && ($result['reason'] ?? '') === 'no_socket' && $movedAside && $live['inode'] !== false) {
            $result = self::settleLateSignal($op, $boundPath, $recoveryPath, $live, $steps) ?? $result;
        }
        if ($live['keep'] !== null) ($op['unlink'])($live['keep']);
        return $result + ['steps' => $steps];
    }

    /**
     * Steps 1 to 4 of recover(). Sets $movedAside as soon as the live file is
     * aside, so recover() can put it back whatever happens after.
     *
     * @param array<string,callable> $op
     * @param list<string> $steps
     * @return array{ok:bool,path?:string,reason?:string}
     */
    private static function attempt(array $op, string $boundPath, string $aside, int $serverPid,
                                    string $recoveryPath, float $timeout, array &$steps, bool &$movedAside, array &$live): array
    {
        if (($op['exists'])($boundPath)) {
            if (($op['exists'])($aside) || !($op['rename'])($boundPath, $aside)) {
                // Nothing was signalled: the live path is unchanged.
                return ['ok' => false, 'reason' => 'rename_aside_failed'];
            }
            $movedAside = true;
            $steps[] = 'aside';
            // R-108: remember which file is the live socket, and keep a second name for it.
            $live['inode'] = ($op['inode'])($aside);
            $keep = $aside . '.keep';
            if ($live['inode'] !== false && ($op['link'])($aside, $keep)) $live['keep'] = $keep;
        }

        if (!($op['signal'])($serverPid, defined('SIGUSR1') ? SIGUSR1 : 10)) {
            $steps[] = 'signal_failed';
            return ['ok' => false, 'reason' => 'signal_failed'];
        }
        $steps[] = 'signal';

        $deadline = ($op['now'])() + max(0.0, $timeout);
        $appeared = false;
        while (true) {
            if (($op['exists'])($boundPath)) { $appeared = true; break; }
            if (($op['now'])() >= $deadline) break;
            ($op['sleepUs'])(self::POLL_US);
        }
        if (!$appeared) {
            $steps[] = 'timeout';
            return ['ok' => false, 'reason' => 'no_socket'];
        }
        $steps[] = 'appeared';

        if (!($op['rename'])($boundPath, $recoveryPath)) {
            $steps[] = 'move_recovery_failed';
            return ['ok' => false, 'reason' => 'move_recovery_failed'];
        }
        $steps[] = 'recovered';
        return ['ok' => true, 'path' => $recoveryPath];
    }

    /**
     * R-108: grace period after a timeout. If the file at $boundPath is no longer the live
     * socket (its inode changed), the slow server handled SIGUSR1 late and bound a new socket
     * there. Move that socket to $recoveryPath and put the live socket back from its kept link.
     *
     * @param array<string,callable> $op
     * @param array{inode:int|false,keep:?string} $live
     * @param list<string> $steps
     * @return array{ok:bool,path?:string,reason?:string}|null null when nothing changed
     */
    private static function settleLateSignal(array $op, string $boundPath, string $recoveryPath, array $live, array &$steps): ?array
    {
        $deadline = ($op['now'])() + self::LATE_GRACE_SECONDS;
        while (true) {
            $now = ($op['inode'])($boundPath);
            if ($now !== false && $now !== $live['inode']) {
                if ($live['keep'] === null || ($op['exists'])($recoveryPath)) {
                    // Cannot give the live socket back safely: leave the path alone.
                    $steps[] = 'late_unrestorable';
                    return ['ok' => false, 'reason' => 'late_signal_unrestorable'];
                }
                if (!($op['rename'])($boundPath, $recoveryPath)) {
                    $steps[] = 'late_move_failed';
                    return ['ok' => false, 'reason' => 'move_recovery_failed'];
                }
                ($op['rename'])($live['keep'], $boundPath);
                $steps[] = 'late_recovered';
                return ['ok' => true, 'path' => $recoveryPath];
            }
            if (($op['now'])() >= $deadline) return null;
            ($op['sleepUs'])(self::POLL_US);
        }
    }

    /** @param array<string,callable> $ops @return array<string,callable> */
    private static function ops(array $ops): array
    {
        return $ops + [
            'exists'  => static fn(string $p): bool => file_exists($p) || is_link($p),
            'isDir'   => static fn(string $p): bool => is_dir($p),
            'mkdir'   => static fn(string $p): bool => @mkdir($p, 0700, true),
            'rmdir'   => static fn(string $p): bool => @rmdir($p),
            'rename'  => static fn(string $a, string $b): bool => @rename($a, $b),
            'signal'  => static fn(int $pid, int $sig): bool => function_exists('posix_kill') && @posix_kill($pid, $sig),
            'inode'   => static function (string $p) { clearstatcache(true, $p); $i = @fileinode($p); return $i === false ? false : (int)$i; },
            'link'    => static fn(string $a, string $b): bool => @link($a, $b),
            'unlink'  => static fn(string $p): bool => @unlink($p),
            'sleepUs' => static function (int $us): void { usleep($us); },
            'now'     => static fn(): float => microtime(true),
        ];
    }
}
