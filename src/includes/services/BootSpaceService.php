<?php
/**
 * <module_context>
 * Description: The Boot disk space breakdown (docs/specs/BOOT_DISK_SPACE.md R1, #538).
 *   It measures where the space of the boot disk goes and writes the answer to
 *   /tmp/unraid-aicliagents/.boot_space.json. The Storage tab card shows that file.
 *   Sizes are allocated bytes (du -x -s -B1), so compression is counted.
 *   A measure runs in the background (src/scripts/storage/boot-space.php), never in a page
 *   request. get_boot_space reads the file and starts a new measure when the file is older
 *   than 10 minutes or missing. removeLeftover() removes one item of the "Left over" line.
 * Dependencies: ConfigService, AgentStorageService, AgentVersionCleanupService, HomeCacheService,
 *   HistoryStoreService, HistoryMoveService, FileStorage (home layers), UtilityService.
 * Constraints: Never measures in the page request. removeLeftover() removes only items that the
 *   last measurement listed under "Left over", re-checks right before it removes, and never
 *   touches a path outside the persistence folder. Every seam is optional; production code
 *   passes none.
 * </module_context>
 */
namespace AICliAgents\Services;

require_once __DIR__ . '/HistoryStoreService.php';
require_once __DIR__ . '/HistoryMoveService.php';

class BootSpaceService
{
    /** A measurement older than this (seconds) is replaced by a new one. */
    public const MAX_AGE = 600;
    /** A lock file younger than this (seconds) means a measure is running. */
    public const LOCK_TTL = 900;
    /** The longest one du call may run (seconds). */
    private const DU_TIMEOUT = 120;
    /** Same patterns as _smoke_is_test_id in tests/smoke/_common.sh. */
    public const TEST_ID_PATTERNS = ['smktest_*', 'smoketest_*', 'smokeuser*', 'smoke*', 'it[0-9]*', 'soak*', 'realuser*[0-9]*'];

    // ---- places ------------------------------------------------------------------

    public static function stateFile(): string
    {
        $e = getenv('AICLI_BOOT_SPACE_FILE');
        return ($e !== false && $e !== '') ? $e : '/tmp/unraid-aicliagents/.boot_space.json';
    }

    private static function lockFile(): string
    {
        return self::stateFile() . '.lock';
    }

    public static function persistPath(): string
    {
        $e = getenv('AICLI_BOOT_SPACE_PERSIST');
        if ($e !== false && $e !== '') return rtrim($e, '/');
        return rtrim(AgentStorageService::persistPath(), '/');
    }

    private static function retiredListPath(): string
    {
        $e = getenv('AICLI_RETIRED_LIST');
        return ($e !== false && $e !== '') ? $e : '/boot/config/plugins/unraid-aicliagents/retired_dirs.list';
    }

    /** The plugin's folder on the boot disk. */
    public static function pluginDir(): string
    {
        $e = getenv('AICLI_BOOT_SPACE_PLUGIN_DIR');
        return ($e !== false && $e !== '') ? rtrim($e, '/') : '/boot/config/plugins/unraid-aicliagents';
    }

    // ---- pure helpers -------------------------------------------------------------

    /** True for the ids that the smoke tests create. */
    public static function isTestId(string $id): bool
    {
        if ($id === '' || strpbrk($id, '/') !== false || $id[0] === '.') return false;
        foreach (self::TEST_ID_PATTERNS as $p) {
            if (fnmatch($p, $id)) return true;
        }
        return false;
    }

    /** True when $path is $root or lies inside it (lexical check; no `..` part allowed). */
    public static function isInside(string $path, string $root): bool
    {
        $root = rtrim($root, '/');
        if ($root === '' || $path === '' || $path[0] !== '/') return false;
        if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1) return false;
        return $path === $root || strpos($path, $root . '/') === 0;
    }

    /** Sort parts: biggest first. Equal sizes keep their order. */
    public static function sortParts(array $parts): array
    {
        $idx = 0;
        foreach ($parts as &$p) { $p['_i'] = $idx++; }
        unset($p);
        usort($parts, static function ($a, $b) {
            return ($b['bytes'] <=> $a['bytes']) ?: ($a['_i'] <=> $b['_i']);
        });
        foreach ($parts as &$p) { unset($p['_i']); }
        unset($p);
        return $parts;
    }

    private static function action(string $id, string $label, bool $enabled = true, string $reason = ''): array
    {
        return ['id' => $id, 'label' => $label, 'enabled' => $enabled, 'reason' => $enabled ? '' : $reason];
    }

    /** Plain reason texts for a convert offer that is not made. */
    public static function convertReason(string $reason): string
    {
        $map = [
            'usb_boot' => 'Agents on a USB boot stick keep compressed layers.',
            'keep_agents_compressed' => 'Keep agents compressed is on (Configuration tab).',
            'no_layered_agents' => 'Every agent already uses plain folders.',
            'not_boot_disk' => 'The agents are not on the boot disk.',
        ];
        return $map[$reason] ?? 'Not offered on this system.';
    }

    // ---- measuring -----------------------------------------------------------------

    /** Allocated bytes of a file or folder (du -x -s -B1). 0 when it is missing or du fails. */
    public static function duBytes(string $path): int
    {
        if (!file_exists($path) && !is_link($path)) return 0;
        static $prefix = null;
        if ($prefix === null) {
            $prefix = 'timeout ' . self::DU_TIMEOUT . ' nice -n 19 ';
            $io = trim((string)@shell_exec('command -v ionice 2>/dev/null'));
            if ($io !== '') $prefix .= 'ionice -c3 ';
        }
        $out = (string)@shell_exec($prefix . 'du -x -s -B1 -- ' . escapeshellarg($path) . ' 2>/dev/null');
        return preg_match('/^(\d+)\s/', $out, $m) === 1 ? (int)$m[1] : 0;
    }

    /** Size, use, free space, mount and file system of the disk that holds $path. */
    public static function diskInfo(string $path): array
    {
        $size = (int)@disk_total_space($path);
        $free = (int)@disk_free_space($path);
        $mount = ''; $fstype = '';
        $best = -1;
        foreach (preg_split('/\R/', (string)@file_get_contents('/proc/mounts')) ?: [] as $line) {
            $f = preg_split('/\s+/', $line);
            if (count($f) < 3) continue;
            $mp = str_replace('\040', ' ', $f[1]);
            if ($mp === '/' || $path === $mp || strpos($path, rtrim($mp, '/') . '/') === 0) {
                if (strlen($mp) > $best) { $best = strlen($mp); $mount = $mp; $fstype = $f[2]; }
            }
        }
        $ratio = 1.0;
        try { $ratio = (float)AgentStorageService::compressRatio($path); } catch (\Throwable $e) { /* keep 1.0 */ }
        $used = max(0, $size - $free);
        return ['size' => $size, 'used' => $used, 'free' => $free, 'mount' => $mount, 'fstype' => $fstype,
                'ratio' => $size > 0 ? round($used / $size, 4) : 0.0, 'compress_ratio' => $ratio];
    }

    /**
     * Where the data sits: the homes, and the left-over items.
     * @return array{layered:string[],plain:string[],unused_layers:string[]}
     */
    public static function discoverHomes(string $persist, string $configUser): array
    {
        $layered = [];
        foreach (glob($persist . '/home_*.sqsh') ?: [] as $f) {
            if (preg_match('/^home_([A-Za-z0-9._-]+?)_(?:delta|consolidated|v\d+_vol\d+|vol\d+)/', basename($f), $m) === 1) {
                $layered[$m[1]] = true;
            }
        }
        $plain = [];
        foreach (glob($persist . '/passthrough/homes/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $d) {
            $id = basename($d);
            if (self::isTestId($id) || $id[0] === '.') continue;
            $plain[$id] = true;
        }
        // The home of the configured user is always in use.
        $inUse = $plain + [$configUser => true];
        $usedLayers = []; $unused = [];
        foreach (array_keys($layered) as $u) {
            if (isset($inUse[$u])) $usedLayers[] = $u; else $unused[] = $u;
        }
        sort($usedLayers); sort($unused);
        // A user with layers AND a plain folder counts as plain (the layers wait in .graduated/ or are old).
        $plainUsers = array_keys($plain);
        sort($plainUsers);
        return ['layered' => array_values(array_diff($usedLayers, $plainUsers)), 'plain' => $plainUsers, 'unused_layers' => $unused];
    }

    /**
     * The "Left over" items: [{key,label,bytes,path}]. The path is absolute and inside $persist.
     * Keys: graduated:<name>, retired:<hash>, homelayers:<user>, testhome:<id>.
     */
    public static function leftOverItems(string $persist, array $homes, callable $du, ?string $retiredList = null): array
    {
        $items = [];
        foreach (glob($persist . '/.graduated/*', GLOB_NOSORT) ?: [] as $p) {
            $name = basename($p);
            if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $name) !== 1) continue;
            $items[] = ['key' => 'graduated:' . $name, 'label' => 'Old layers of a converted home or agent (' . $name . ')',
                        'bytes' => $du($p), 'path' => $p];
        }
        $list = $retiredList ?? self::retiredListPath();
        $seen = [];
        if (is_file($list)) {
            foreach (preg_split('/\R/', (string)@file_get_contents($list)) ?: [] as $d) {
                $d = trim($d);
                if ($d === '' || isset($seen[$d]) || !self::isInside($d, $persist) || !is_dir($d)) continue;
                $seen[$d] = true;
                $items[] = ['key' => 'retired:' . substr(sha1($d), 0, 12),
                            'label' => 'Retired layers (' . basename($d) . ')', 'bytes' => $du($d), 'path' => $d];
            }
        }
        foreach ($homes['unused_layers'] as $u) {
            $files = glob($persist . '/home_' . $u . '_*.sqsh') ?: [];
            $b = 0; foreach ($files as $f) $b += $du($f);
            $items[] = ['key' => 'homelayers:' . $u, 'label' => 'Saved layers of the home "' . $u . '" (no workspace uses it)',
                        'bytes' => $b, 'path' => $persist . '/home_' . $u . '_*.sqsh'];
        }
        foreach (glob($persist . '/passthrough/homes/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $d) {
            $id = basename($d);
            if (!self::isTestId($id)) continue;
            $items[] = ['key' => 'testhome:' . $id, 'label' => 'Test home ' . $id, 'bytes' => $du($d), 'path' => $d];
        }
        return $items;
    }

    /**
     * Measure everything. Slow: du on several GB. Run it in the background only.
     * $seams (tests): persist, plugin_dir, du (callable path→bytes), config_user, disk (array),
     * agent_sizes (array), old_versions (array), convert (array), cache_status (callable user→array),
     * history (callable homeDir→array), moved (callable user→array), retired_list, now (int).
     */
    public static function measure(array $seams = []): array
    {
        $persist = rtrim((string)($seams['persist'] ?? self::persistPath()), '/');
        $pluginDir = rtrim((string)($seams['plugin_dir'] ?? self::pluginDir()), '/');
        $du = $seams['du'] ?? [self::class, 'duBytes'];
        $now = (int)($seams['now'] ?? time());
        $configUser = (string)($seams['config_user'] ?? (ConfigService::getConfig()['user'] ?? 'root'));
        if ($configUser === '') $configUser = 'root';

        $disk = $seams['disk'] ?? self::diskInfo($persist);
        $homes = self::discoverHomes($persist, $configUser);
        $parts = [];
        $insideTotal = 0;   // bytes of the parts that lie inside the plugin folder

        // ---- homes ----
        foreach ($homes['layered'] as $u) {
            $files = glob($persist . '/home_' . $u . '_*.sqsh') ?: [];
            $b = 0; foreach ($files as $f) $b += (int)$du($f);
            $b += (int)$du($persist . '/_upper/homes/' . $u);
            $insideTotal += $b;
            $parts[] = ['key' => 'home:' . $u . ':layers', 'group' => 'home', 'user' => $u,
                'label' => 'Home ' . $u . ': saved layers', 'bytes' => $b,
                'note' => 'Saved layers and the writable layer of the home.',
                'action' => self::action('tidy_up', 'Close all and tidy up')];
        }
        foreach ($homes['plain'] as $u) {
            $home = $persist . '/passthrough/homes/' . $u;
            $total = (int)$du($home);
            $histBytes = 0;
            try {
                $m = isset($seams['history']) ? ($seams['history'])($home) : HistoryStoreService::measure($home);
                foreach ($m as $row) $histBytes += (int)($row['bytes'] ?? 0);
            } catch (\Throwable $e) { $histBytes = 0; }
            $cache = ['location' => 'none', 'on_boot_bytes' => 0, 'bytes' => 0];
            try {
                $cache = isset($seams['cache_status']) ? ($seams['cache_status'])($u) : HomeCacheService::status($u);
            } catch (\Throwable $e) { /* keep the empty answer */ }
            $cacheBoot = (int)($cache['on_boot_bytes'] ?? 0);
            if (($cache['location'] ?? '') === 'boot') $cacheBoot = max($cacheBoot, (int)($cache['bytes'] ?? 0));
            $histBytes = min($histBytes, $total);
            $cacheBoot = min($cacheBoot, max(0, $total - $histBytes));
            $other = max(0, $total - $histBytes - $cacheBoot);
            $insideTotal += $total;

            $hist = ['key' => 'home:' . $u . ':history', 'group' => 'home', 'user' => $u,
                'label' => 'Home ' . $u . ': conversation history', 'bytes' => $histBytes,
                'action' => self::action('move_sessions', 'Move old sessions…')];
            try {
                $dest = (string)(ConfigService::getConfig()['history_move_path'] ?? '');
                $moved = isset($seams['moved']) ? ($seams['moved'])($u)
                    : ($dest !== '' && HistoryMoveService::destinationMounted($dest) ? HistoryMoveService::movedSummary($dest, $u) : []);
                if ($moved) $hist['extra_actions'] = [self::action('bring_back_sessions', 'Bring back…')];
            } catch (\Throwable $e) { /* no extra action */ }
            $parts[] = $hist;

            $loc = (string)($cache['location'] ?? 'none');
            $cacheNote = '';
            if ($loc === 'ram') $cacheNote = 'The cache lives in RAM (' . self::human((int)($cache['bytes'] ?? 0)) . ' used now). Only content still on the boot disk is counted here.';
            elseif ($loc === 'pool') $cacheNote = 'The cache lives on a pool (' . self::human((int)($cache['bytes'] ?? 0)) . ' used now). Only content still on the boot disk is counted here.';
            $cacheAction = $loc === 'none'
                ? self::action('clear_caches', 'Clear caches', false, 'This home does not use plain folders.')
                : self::action('clear_caches', 'Clear caches');
            $parts[] = ['key' => 'home:' . $u . ':caches', 'group' => 'home', 'user' => $u,
                'label' => 'Home ' . $u . ': caches', 'bytes' => $cacheBoot, 'note' => $cacheNote,
                'cache_location' => $loc, 'action' => $cacheAction, 'setting_link' => 'home_cache_location'];
            $parts[] = ['key' => 'home:' . $u . ':other', 'group' => 'home', 'user' => $u,
                'label' => 'Home ' . $u . ': other', 'bytes' => $other,
                'note' => 'Your own files and agent settings.', 'action' => null];
        }

        // ---- agents ----
        $sizes = $seams['agent_sizes'] ?? AgentStorageService::agentSizes($persist);
        $cur = 0;
        foreach ($sizes as $s) $cur += (int)($s['current_bytes'] ?? 0);
        $old = $seams['old_versions'] ?? AgentVersionCleanupService::oldVersions(null);
        $oldBytes = (int)($old['total_bytes'] ?? 0);
        $est = $seams['convert'] ?? AgentStorageService::convertEstimate();
        $convAction = !empty($est['offered'])
            ? self::action('convert_agents', 'Convert to plain folders')
            : self::action('convert_agents', 'Convert to plain folders', false, self::convertReason((string)($est['reason'] ?? '')));
        $insideTotal += $cur + $oldBytes;
        $names = [];
        foreach ($sizes as $s) $names[] = ($s['name'] ?? $s['id']) . ' (' . (($s['storage'] ?? '') === 'layers' ? 'layers' : 'plain folders') . ')';
        $parts[] = ['key' => 'agents:current', 'group' => 'agents', 'label' => 'Agents: current versions', 'bytes' => $cur,
            'note' => $names ? implode(', ', $names) : '', 'action' => $convAction];
        $parts[] = ['key' => 'agents:old', 'group' => 'agents', 'label' => 'Agents: old versions', 'bytes' => $oldBytes,
            'items' => count($old['items'] ?? []),
            'action' => $oldBytes > 0 ? self::action('remove_old_versions', 'Remove old versions')
                : self::action('remove_old_versions', 'Remove old versions', false, 'No old version to remove.')];

        // ---- left over ----
        $items = self::leftOverItems($persist, $homes, $du, $seams['retired_list'] ?? null);
        $lo = 0; foreach ($items as $it) $lo += $it['bytes'];
        $insideTotal += $lo;
        $parts[] = ['key' => 'leftover', 'group' => 'leftover', 'label' => 'Left over', 'bytes' => $lo,
            'items' => array_map(static function ($it) { return ['key' => $it['key'], 'label' => $it['label'], 'bytes' => $it['bytes']]; }, $items),
            'action' => $items ? self::action('remove_leftover', 'Remove')
                : self::action('remove_leftover', 'Remove', false, 'Nothing is left over.')];

        // ---- plugin files: the rest of the plugin folder ----
        $pluginTotal = (int)$du($pluginDir);
        $persistInPlugin = self::isInside($persist, $pluginDir);
        $rest = $persistInPlugin ? max(0, $pluginTotal - $insideTotal) : $pluginTotal;
        $parts[] = ['key' => 'plugin', 'group' => 'plugin', 'label' => 'Plugin files', 'bytes' => $rest,
            'note' => 'Program files, saved copies of earlier versions, settings and state.', 'action' => null];

        return ['measured_at' => $now, 'disk' => $disk, 'parts' => self::sortParts($parts)];
    }

    public static function human(int $b): string
    {
        if ($b >= 1073741824) return number_format($b / 1073741824, 1) . ' GB';
        return max($b > 0 ? 1 : 0, (int)round($b / 1048576)) . ' MB';
    }

    // ---- the file ------------------------------------------------------------------

    /** Write the file atomically (temp file in the same folder, then rename). */
    public static function write(array $data): bool
    {
        $file = self::stateFile();
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp = $file . '.tmp.' . getmypid();
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false || @file_put_contents($tmp, $json) === false) { @unlink($tmp); return false; }
        if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
        return true;
    }

    /** The last measurement, or null when there is none or the file is damaged. */
    public static function read(): ?array
    {
        $raw = @file_get_contents(self::stateFile());
        if ($raw === false || $raw === '') return null;
        $j = json_decode($raw, true);
        return (is_array($j) && isset($j['measured_at'], $j['parts'])) ? $j : null;
    }

    public static function isStale(?array $data, ?int $now = null): bool
    {
        if ($data === null) return true;
        return ($now ?? time()) - (int)$data['measured_at'] >= self::MAX_AGE;
    }

    public static function isMeasuring(?int $now = null): bool
    {
        $m = @filemtime(self::lockFile());
        return $m !== false && ($now ?? time()) - $m < self::LOCK_TTL;
    }

    /**
     * Run a measure now, in this process, under the lock. Returns false when another measure
     * runs. Called by the background script, and by tests.
     */
    public static function run(array $seams = []): bool
    {
        $lock = self::lockFile();
        $dir = dirname($lock);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $fh = @fopen($lock, 'c');
        if ($fh === false) return false;
        if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; }
        try {
            @ftruncate($fh, 0); fwrite($fh, (string)getmypid()); fflush($fh); @touch($lock);
            $data = self::measure($seams);
            self::write($data);
        } finally {
            flock($fh, LOCK_UN); fclose($fh);
            @unlink($lock);
        }
        return true;
    }

    /**
     * Start a measure in the background. Does nothing when one runs. A test replaces the
     * launcher with $launcher (callable, gets the argv array).
     */
    public static function startMeasure(?callable $launcher = null): bool
    {
        if (self::isMeasuring()) return false;
        $script = dirname(__DIR__, 2) . '/scripts/storage/boot-space.php';
        $argv = ['timeout', '900', 'php', '-d', 'display_errors=0', $script];
        if (!is_dir(dirname(self::lockFile()))) @mkdir(dirname(self::lockFile()), 0755, true);
        @touch(self::lockFile());   // mark it at once, so a second request does not start a second one
        if ($launcher !== null) { $launcher($argv); return true; }
        require_once __DIR__ . '/UtilityService.php';
        UtilityService::spawnDetached($argv);
        return true;
    }

    /**
     * The answer of get_boot_space: the file, `measuring`, and an `age` in seconds.
     * Starts a measure when the file is stale or missing.
     */
    public static function status(?callable $launcher = null, ?int $now = null): array
    {
        $now = $now ?? time();
        $data = self::read();
        $started = false;
        if (self::isStale($data, $now)) $started = self::startMeasure($launcher);
        $measuring = $started || self::isMeasuring($now);
        $out = $data ?? ['measured_at' => 0, 'disk' => null, 'parts' => []];
        $out['measuring'] = $measuring;
        $out['has_data'] = $data !== null;
        $out['age'] = $data !== null ? max(0, $now - (int)$data['measured_at']) : null;
        return $out;
    }

    // ---- removing a left-over item ---------------------------------------------------

    /**
     * Remove one item of the "Left over" line. Only an item that the last measurement lists is
     * accepted. The item is looked up again right before it is removed.
     *
     * $seams (tests): persist, du, in_use (callable path→bool), delete_home (callable user→array{ok,message}),
     * retired_list, data (the last measurement instead of the file).
     * @return array{status:string,message?:string,freed_bytes?:int}
     */
    public static function removeLeftover(string $key, array $seams = []): array
    {
        if (preg_match('/^(graduated|retired|homelayers|testhome):[A-Za-z0-9._-]{1,128}$/', $key) !== 1) {
            return ['status' => 'error', 'message' => 'This item is not valid.'];
        }
        $data = $seams['data'] ?? self::read();
        $listed = false;
        foreach (($data['parts'] ?? []) as $p) {
            if (($p['key'] ?? '') !== 'leftover') continue;
            foreach (($p['items'] ?? []) as $it) {
                if (($it['key'] ?? '') === $key) { $listed = true; break 2; }
            }
        }
        if (!$listed) {
            return ['status' => 'error', 'message' => 'This item is not in the last measurement. Measure again, then try once more.'];
        }

        $persist = rtrim((string)($seams['persist'] ?? self::persistPath()), '/');
        $du = $seams['du'] ?? [self::class, 'duBytes'];
        $inUse = $seams['in_use'] ?? [self::class, 'pathInUse'];
        $configUser = (string)($seams['config_user'] ?? (ConfigService::getConfig()['user'] ?? 'root'));
        if ($configUser === '') $configUser = 'root';

        // Re-check right before removal: find the item again with the live rules.
        $homes = self::discoverHomes($persist, $configUser);
        $live = self::leftOverItems($persist, $homes, static function () { return 0; }, $seams['retired_list'] ?? null);
        $item = null;
        foreach ($live as $it) { if ($it['key'] === $key) { $item = $it; break; } }
        if ($item === null) {
            return ['status' => 'error', 'message' => 'This item is no longer left over, so nothing was removed.'];
        }

        [$type, $name] = explode(':', $key, 2);
        $freed = 0;
        if ($type === 'homelayers') {
            $files = glob($persist . '/home_' . $name . '_*.sqsh') ?: [];
            foreach ($files as $f) { if (!self::isInside($f, $persist)) return ['status' => 'error', 'message' => 'A path is outside the persistence folder.']; }
            if (isset($seams['delete_home'])) {
                $r = ($seams['delete_home'])($name);
            } else {
                require_once __DIR__ . '/FileStorage.php';
                $r = FileStorage::deleteHomeEntity($name);
            }
            $ok = !empty($r['ok']);
            foreach ($files as $f) $freed += (int)$du($f);
            if ($ok) { self::logRemoved($key, $freed); return ['status' => 'ok', 'freed_bytes' => $freed]; }
            return ['status' => 'error', 'message' => (string)($r['message'] ?? 'The layers could not be removed.')];
        }

        $path = $item['path'];
        if (!self::isInside($path, $persist) || $path === $persist) {
            return ['status' => 'error', 'message' => 'The item lies outside the persistence folder, so it was not removed.'];
        }
        $real = realpath($path);
        $realRoot = realpath($persist);
        if ($real === false || $realRoot === false || !self::isInside($real, $realRoot) || $real === $realRoot) {
            return ['status' => 'error', 'message' => 'The item lies outside the persistence folder, so it was not removed.'];
        }
        if ($type === 'testhome' && !self::isTestId($name)) {
            return ['status' => 'error', 'message' => 'This is not a test home.'];
        }
        if ($inUse($path)) {
            return ['status' => 'error', 'message' => 'The item is in use (a mount or a process holds it), so it was not removed.'];
        }
        $freed = (int)$du($path);
        $out = []; $rc = 0;
        @exec('rm -rf -- ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
        if (file_exists($path)) {
            return ['status' => 'error', 'message' => 'The item could not be removed completely.'];
        }
        self::logRemoved($key, $freed);
        return ['status' => 'ok', 'freed_bytes' => $freed];
    }

    private static function logRemoved(string $key, int $freed): void
    {
        try {
            LifecycleLogService::log(LifecycleLogService::LEVEL_INFO, 'storage', 'boot_space_leftover_removed',
                ['key' => $key, 'freed_bytes' => $freed]);
        } catch (\Throwable $e) { /* a log must never break the action */ }
    }

    /** True when a mount lists the path, or a process has it as its working folder. */
    public static function pathInUse(string $path): bool
    {
        $real = realpath($path) ?: $path;
        foreach (preg_split('/\R/', (string)@file_get_contents('/proc/mounts')) ?: [] as $line) {
            $f = preg_split('/\s+/', $line);
            if (count($f) < 2) continue;
            $mp = str_replace('\040', ' ', $f[1]);
            if ($mp === $real || strpos($mp, $real . '/') === 0 || $f[0] === $real || strpos($f[0], $real . '/') === 0) return true;
        }
        foreach (glob('/proc/[0-9]*/cwd', GLOB_NOSORT) ?: [] as $c) {
            $t = @readlink($c);
            if ($t !== false && ($t === $real || strpos($t, $real . '/') === 0)) return true;
        }
        return false;
    }
}
