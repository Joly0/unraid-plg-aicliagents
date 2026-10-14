<?php
/**
 * <module_context>
 *     <name>ConvertOfferNotifyService</name>
 *     <description>#496 (docs/specs/CONVERT_LAYERED_TO_PLAIN_FOLDERS.md R13): on plugin
 *     install or update, tell the person ONCE that one or more layered homes sit on a
 *     disk that is not a USB stick and can change to plain folders. One Unraid
 *     notification (importance warning) whose link opens the conversion dialog for the
 *     first such home. A marker on the flash holds the last set of homes, so the
 *     notification comes again only when that set changes, never on every boot.</description>
 *     <dependencies>FileStorage (canConvertHome), UtilityService (users), AtomicWriteService</dependencies>
 *     <constraints>Read only: it mounts and changes nothing in storage. Best effort:
 *     run() never throws. plan() is pure, so a test needs no notify script and no
 *     /boot. The notify command and the marker path are injectable.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class ConvertOfferNotifyService
{
    public const MARKER_PATH   = '/boot/config/plugins/unraid-aicliagents/convert_offer_notified.json';
    public const NOTIFY_SCRIPT = '/usr/local/emhttp/plugins/dynamix/scripts/notify';
    /** The Manager page (Menu="Utilities" lives under Settings), plus the deep link the Storage tab reads. */
    public const PAGE_LINK     = '/Settings/AICliAgentsManager';

    /**
     * Decide whether to notify. Pure.
     *
     * @param list<string>      $homes    users whose home can change to plain folders now
     * @param list<string>|null $notified the set in the marker, or null when there is no marker
     * @return array{send:bool,write:bool,homes:list<string>,subject:string,description:string,link:string}
     */
    public static function plan(array $homes, ?array $notified): array
    {
        $homes = self::normalise($homes);
        $prev  = $notified === null ? null : self::normalise($notified);
        $changed = $prev === null ? true : ($prev !== $homes);
        $n = count($homes);
        $send = $changed && $n > 0;
        return [
            'send'  => $send,
            // An empty set is recorded too (when it differs), so a home that becomes
            // convertible again later is announced again.
            'write' => $changed && ($n > 0 || $prev !== null),
            'homes' => $homes,
            'subject' => 'AICliAgents: homes can move to plain folders',
            'description' => $n . ' home' . ($n === 1 ? '' : 's') . ' can move to plain folders: no more tidy-ups.',
            'link' => $n > 0 ? self::PAGE_LINK . '#aicli-convert=home:' . $homes[0] : self::PAGE_LINK,
        ];
    }

    /** The notify command line for a plan. Pure. */
    public static function command(array $plan, string $script = self::NOTIFY_SCRIPT): string
    {
        return escapeshellcmd($script)
            . " -e 'AICliAgents'"
            . ' -s ' . escapeshellarg((string)$plan['subject'])
            . ' -d ' . escapeshellarg((string)$plan['description'])
            . " -i 'warning'"
            . ' -l ' . escapeshellarg((string)$plan['link']);
    }

    /** The set stored in the marker, or null when the marker is missing or unreadable. */
    public static function readMarker(string $path): ?array
    {
        if (!is_file($path)) return null;
        $d = json_decode((string)@file_get_contents($path), true);
        if (!is_array($d) || !isset($d['homes']) || !is_array($d['homes'])) return null;
        return array_values(array_map('strval', $d['homes']));
    }

    /** Users whose home can change to plain folders now. Read only. */
    public static function convertibleHomes(?callable $canConvert = null): array
    {
        require_once __DIR__ . '/FileStorage.php';
        require_once __DIR__ . '/UtilityService.php';
        $canConvert = $canConvert ?? static function (string $u): bool { return FileStorage::canConvertHome($u); };
        $users = array_unique(array_merge(['root'], (array)UtilityService::getUnraidUsers()));
        $out = [];
        foreach ($users as $u) {
            $u = (string)$u;
            if (preg_match('/^[A-Za-z0-9._-]+$/', $u) !== 1) continue;
            if ($canConvert($u)) $out[] = $u;
        }
        return self::normalise($out);
    }

    /**
     * Run once: compute the set, compare it with the marker, send at most one
     * notification, then write the marker. Never throws.
     *
     * @param list<string>|null $homes  the convertible homes (null = compute them)
     * @param callable|null     $exec   runs the notify command line (test seam)
     * @return array{sent:bool,homes:list<string>}
     */
    public static function run(?array $homes = null, string $markerPath = self::MARKER_PATH,
                               string $notifyScript = self::NOTIFY_SCRIPT, ?callable $exec = null): array
    {
        try {
            $homes = $homes ?? self::convertibleHomes();
            $plan = self::plan($homes, self::readMarker($markerPath));
            $sent = false;
            if ($plan['send']) {
                if (!is_file($notifyScript)) {
                    // No notify subsystem: leave the marker as it is, so the next install tries again.
                    return ['sent' => false, 'homes' => $plan['homes']];
                }
                $cmd = self::command($plan, $notifyScript);
                if ($exec !== null) {
                    $exec($cmd);
                } else {
                    // nosemgrep: php.lang.security.exec-use.exec-use
                    @exec($cmd . ' 2>/dev/null');
                }
                $sent = true;
            }
            if ($plan['write']) {
                require_once __DIR__ . '/AtomicWriteService.php';
                AtomicWriteService::writeJson($markerPath, ['homes' => $plan['homes'], 'at' => gmdate('Y-m-d\TH:i:s\Z')]);
            }
            return ['sent' => $sent, 'homes' => $plan['homes']];
        } catch (\Throwable $e) {
            return ['sent' => false, 'homes' => []];
        }
    }

    /** @param array<mixed> $homes @return list<string> sorted, unique, valid names */
    private static function normalise(array $homes): array
    {
        $out = [];
        foreach ($homes as $h) {
            $h = (string)$h;
            if (preg_match('/^[A-Za-z0-9._-]+$/', $h) === 1) $out[$h] = true;
        }
        $out = array_map('strval', array_keys($out));
        sort($out, SORT_STRING);
        // root first: it is the home most people have, so the link opens that one.
        if (($i = array_search('root', $out, true)) !== false && $i !== 0) {
            array_splice($out, $i, 1);
            array_unshift($out, 'root');
        }
        return array_values($out);
    }
}
