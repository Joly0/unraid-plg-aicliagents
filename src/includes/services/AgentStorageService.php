<?php
/**
 * <module_context>
 *   <name>AgentStorageService</name>
 *   <description>BOOT_DISK_SPACE.md R3: how each agent is stored (compressed layers or
 *   plain folders), what it costs on the boot disk, and what the "Convert agents to
 *   plain folders" dialog shows before it changes anything.</description>
 *   <dependencies>ConfigService, StoragePathResolver, FileStorage, LayerManifestService, AgentRegistry</dependencies>
 *   <constraints>Static methods only. Read-only: it measures and decides, it never moves a file.
 *   The decision functions are the PHP twins of agent_storage_choice and agent_convert_fits
 *   in src/scripts/storage/detect_backend.sh.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class AgentStorageService
{
    /** After a conversion at least this many bytes must stay free ... */
    public const RESERVE_BYTES = 2147483648;
    /** ... and at least this share of the whole disk (one tenth). */
    public const RESERVE_DIVISOR = 10;
    /**
     * When an agent is not mounted its unpacked size cannot be measured. A layer is
     * a compressed image: measured on prod02 (2026-10-16) 770 MB of layers held
     * 4.5 GB of files, so the estimate multiplies the layer size by 5.
     */
    public const UNPACKED_FALLBACK_FACTOR = 5;

    /**
     * PHP twin of agent_storage_choice (detect_backend.sh). 'layers' or 'plain'.
     * An engine policy of 'passthrough' is plain everywhere; an internal boot is
     * plain unless the person keeps agents compressed; anything else is layers.
     */
    public static function storageChoice(string $enginePolicy, bool $keepCompressed, string $deviceClass): string
    {
        if ($enginePolicy === 'passthrough') return 'plain';
        if ($deviceClass === 'internal' && !$keepCompressed) return 'plain';
        return 'layers';
    }

    /**
     * PHP twin of agent_convert_fits (detect_backend.sh): 'ok', or 'no_room:<bytes>'
     * with the bytes that are missing. The reserve is the larger of 2 GiB and one
     * tenth of the disk.
     */
    public static function convertFits(int $freeBytes, int $diskBytes, int $extraBytes): string
    {
        $reserve = max(self::RESERVE_BYTES, intdiv(max(0, $diskBytes), self::RESERVE_DIVISOR));
        $after = $freeBytes - max(0, $extraBytes);
        return $after >= $reserve ? 'ok' : 'no_room:' . ($reserve - $after);
    }

    /** Where the agents live on disk (the agent persistence folder). */
    public static function persistPath(): string
    {
        require_once __DIR__ . '/StoragePathResolver.php';
        return StoragePathResolver::agentPersistPath();
    }

    /**
     * The device class of the agent persistence folder for the R3 rule:
     * 'internal' (an internal boot disk), 'usb' (a USB boot stick) or 'other'
     * (a pool or an array disk). Read from the storage probe (mount_class).
     */
    public static function deviceClass(string $persist): string
    {
        require_once __DIR__ . '/FileStorage.php';
        $class = (string)(FileStorage::probeTarget($persist)['mount_class'] ?? '');
        if ($class === 'boot_internal') return 'internal';
        if ($class === 'boot_usb') return 'usb';
        return 'other';
    }

    /**
     * Split a list of layer file names into the layers of the generation in service
     * (the newest stack, down to its newest base) and the older ones (the previous
     * generation and its rollback copies). Pure.
     *
     * @param string[] $names basenames of agent_<id>_*.sqsh in any order
     * @return array{current:string[],previous:string[]}
     */
    public static function splitLayers(array $names): array
    {
        require_once __DIR__ . '/LayerManifestService.php';
        $sorted = array_values($names);
        usort($sorted, static fn(string $a, string $b): int
            => strcmp(LayerManifestService::layerSortKey($b), LayerManifestService::layerSortKey($a)));
        $current = LayerManifestService::agentStack($sorted);
        return ['current' => $current, 'previous' => array_values(array_diff($sorted, $current))];
    }

    /** On-disk (allocated) bytes of one file: blocks, so compression and sparse files count right. */
    private static function allocatedBytes(string $file): int
    {
        $st = @stat($file);
        if ($st === false) return 0;
        $blocks = (int)($st['blocks'] ?? 0);
        return $blocks > 0 ? $blocks * 512 : (int)($st['size'] ?? 0);
    }

    /** Allocated bytes of a folder tree (du -x -s -B1, 30 s cap). 0 when it cannot be read. */
    private static function treeBytes(string $dir, ?callable $du = null): int
    {
        if ($du !== null) return (int)$du($dir);
        if (!is_dir($dir)) return 0;
        // nosemgrep: php.lang.security.exec-use.exec-use
        $out = (string)@shell_exec('timeout 30 du -x -s -B1 ' . escapeshellarg($dir) . ' 2>/dev/null');
        return (int)trim((string)strtok($out, "\t "));
    }

    /**
     * Every installed agent with its storage kind and what it uses on the boot disk.
     * The Storage tab used to list only agents that have .sqsh files; a plain-folder
     * agent was missing. Both kinds are listed here.
     *
     * - layered: current = the layers of the generation in service, previous = the
     *   older layers; both are allocated bytes of the files.
     * - plain: current = the active version folder (the stable link's target),
     *   previous = every other version folder under .versions/<id>.
     *
     * @param string|null   $persist the agent persistence folder (default: the configured one)
     * @param callable|null $du      fn(string $dir): int  allocated bytes of a folder (test seam)
     * @param callable|null $fileBytes fn(string $file): int  allocated bytes of a layer file (test seam)
     * @return array<int,array{id:string,name:string,storage:string,current_bytes:int,previous_bytes:int}>
     */
    public static function agentSizes(?string $persist = null, ?callable $du = null, ?callable $fileBytes = null): array
    {
        $persist = rtrim($persist ?? self::persistPath(), '/');
        $out = [];

        // Layered agents: group the layer files by agent id.
        $byId = [];
        foreach (glob($persist . '/agent_*.sqsh') ?: [] as $file) {
            $bn = basename($file);
            if (preg_match('/^agent_([a-z0-9][a-z0-9-]*)_(?:delta|consolidated|v\d+_vol\d+|vol\d+)/', $bn, $m)) {
                $byId[$m[1]][] = $bn;
            }
        }
        foreach ($byId as $id => $names) {
            $split = self::splitLayers($names);
            $sum = static function (array $list) use ($persist, $fileBytes): int {
                $n = 0;
                foreach ($list as $bn) {
                    $n += $fileBytes !== null ? (int)$fileBytes($persist . '/' . $bn) : self::allocatedBytes($persist . '/' . $bn);
                }
                return $n;
            };
            $out[$id] = ['id' => $id, 'name' => self::agentName($id), 'storage' => 'layers',
                'current_bytes' => $sum($split['current']), 'previous_bytes' => $sum($split['previous'])];
        }

        // Plain-folder agents: a stable link or folder at passthrough/agents/<id>.
        $ptRoot = $persist . '/passthrough/agents';
        foreach (glob($ptRoot . '/*', GLOB_NOSORT) ?: [] as $entry) {
            $id = basename($entry);
            if ($id === '' || $id[0] === '.' || isset($out[$id])) continue;
            if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $id)) continue;
            $active = null;
            if (is_link($entry)) {
                $link = (string)readlink($entry);
                if (preg_match('#^\.versions/' . preg_quote($id, '#') . '/([^/]+)$#', $link, $lm)) $active = $lm[1];
            }
            $versions = $ptRoot . '/.versions/' . $id;
            $current = 0; $previous = 0;
            if ($active !== null) {
                $current = self::treeBytes($versions . '/' . $active, $du);
                foreach (glob($versions . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $dir) {
                    if (basename($dir) === $active) continue;
                    $previous += self::treeBytes($dir, $du);
                }
            } elseif (is_dir($entry)) {
                $current = self::treeBytes($entry, $du);
                foreach (glob($versions . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $dir) {
                    $previous += self::treeBytes($dir, $du);
                }
            } else {
                continue;
            }
            $out[$id] = ['id' => $id, 'name' => self::agentName($id), 'storage' => 'plain',
                'current_bytes' => $current, 'previous_bytes' => $previous];
        }
        ksort($out);
        return array_values($out);
    }

    /** The name a person sees for an agent ("Claude Code"), else the id. */
    private static function agentName(string $id): string
    {
        try {
            require_once __DIR__ . '/AgentRegistry.php';
            $reg = AgentRegistry::getRegistry();
            $name = is_array($reg[$id] ?? null) ? trim((string)($reg[$id]['name'] ?? '')) : '';
            return $name !== '' ? $name : $id;
        } catch (\Throwable $e) {
            return $id;
        }
    }

    /**
     * The compression ratio of the file system that holds $path: ZFS gives its
     * compressratio (1.81 for 1.81x), every other file system 1.0. Never below 1.0.
     */
    public static function compressRatio(string $path): float
    {
        // nosemgrep: php.lang.security.exec-use.exec-use
        $fst = trim((string)@shell_exec('findmnt -no FSTYPE -T ' . escapeshellarg($path) . ' 2>/dev/null | head -1'));
        if ($fst !== 'zfs') return 1.0;
        // nosemgrep: php.lang.security.exec-use.exec-use
        $src = trim((string)@shell_exec('findmnt -no SOURCE -T ' . escapeshellarg($path) . ' 2>/dev/null | head -1'));
        if ($src === '' || !preg_match('#^[A-Za-z0-9._/:-]+$#', $src)) return 1.0;
        // nosemgrep: php.lang.security.exec-use.exec-use
        $raw = trim((string)@shell_exec('zfs get -H -p -o value compressratio ' . escapeshellarg($src) . ' 2>/dev/null'));
        $ratio = (float)rtrim($raw, 'x');
        return $ratio >= 1.0 ? $ratio : 1.0;
    }

    /**
     * The unpacked size of one layered agent: the files its mounted view holds.
     * A mounted view is measured with du (apparent size) and the answer is kept in
     * RAM until a layer changes. An agent that is not mounted gets the layer size
     * times UNPACKED_FALLBACK_FACTOR, and the caller is told it is an estimate.
     *
     * @return array{bytes:int,estimated:bool}
     */
    private static function unpackedBytes(string $id, int $layerFileBytes, string $sig): array
    {
        $cache = (getenv('AICLI_TMP_BASE') ?: '/tmp/unraid-aicliagents') . '/.agent_unpacked_' . preg_replace('/[^a-z0-9-]/', '_', $id) . '.json';
        $c = json_decode((string)@file_get_contents($cache), true);
        if (is_array($c) && ($c['sig'] ?? '') === $sig && (int)($c['bytes'] ?? 0) > 0) {
            return ['bytes' => (int)$c['bytes'], 'estimated' => false];
        }
        require_once __DIR__ . '/AgentRegistry.php';
        require_once __DIR__ . '/StorageMountService.php';
        $view = AgentRegistry::agentPath($id);
        $real = @realpath($view);
        if ($real !== false && StorageMountService::isMounted($real)) {
            // nosemgrep: php.lang.security.exec-use.exec-use
            $out = (string)@shell_exec('timeout 60 du -x -s -b ' . escapeshellarg($real) . ' 2>/dev/null');
            $bytes = (int)trim((string)strtok($out, "\t "));
            if ($bytes > 0) {
                @file_put_contents($cache, json_encode(['sig' => $sig, 'bytes' => $bytes]));
                return ['bytes' => $bytes, 'estimated' => false];
            }
        }
        return ['bytes' => $layerFileBytes * self::UNPACKED_FALLBACK_FACTOR, 'estimated' => true];
    }

    /**
     * The numbers of the "Convert agents to plain folders" dialog, and whether the
     * dialog is offered at all.
     *
     * Offered only on an internal boot disk, while "Keep agents compressed" is off,
     * and when at least one agent is layered. For each layered agent: its size now
     * (its layers on disk), its estimated size as plain folders (the unpacked size
     * divided by the boot file system's compression ratio), the total extra space,
     * and whether the free space after the conversion keeps 10% of the disk and 2 GiB.
     *
     * `retained_bytes` is the space the old layers still use for the retention
     * window (graduated_retention_days) after the conversion: they move to
     * .graduated/ and are removed later, or at once from the card's Left over line.
     *
     * @param array<string,mixed>|null $facts test seam: persist, device_class, keep, policy,
     *        disk_bytes, free_bytes, ratio, agents[] ({id,name,now_bytes,unpacked_bytes})
     * @return array{offered:bool,reason:string,disk_bytes:int,free_bytes:int,ratio:float,agents:array<int,array{id:string,name:string,now_bytes:int,plain_bytes:int}>,extra_bytes:int,fits:string,retained_bytes:int,estimated:bool}
     */
    public static function convertEstimate(?array $facts = null): array
    {
        $facts = $facts ?? [];
        $persist = (string)($facts['persist'] ?? self::persistPath());
        $class   = (string)($facts['device_class'] ?? self::deviceClass($persist));
        $keep    = array_key_exists('keep', $facts)
            ? (bool)$facts['keep']
            : (string)(ConfigService::getConfig()['keep_agents_compressed'] ?? '0') === '1';
        $disk  = (int)($facts['disk_bytes'] ?? (int)@disk_total_space($persist));
        $free  = (int)($facts['free_bytes'] ?? (int)@disk_free_space($persist));
        $ratio = (float)($facts['ratio'] ?? self::compressRatio($persist));
        if ($ratio < 1.0) $ratio = 1.0;

        $result = ['offered' => false, 'reason' => '', 'disk_bytes' => $disk, 'free_bytes' => $free,
            'ratio' => $ratio, 'agents' => [], 'extra_bytes' => 0, 'fits' => 'ok',
            'retained_bytes' => 0, 'estimated' => false];

        if ($class === 'usb') { $result['reason'] = 'usb_boot'; return $result; }
        if ($class !== 'internal') { $result['reason'] = 'not_boot_disk'; return $result; }
        if ($keep) { $result['reason'] = 'keep_agents_compressed'; return $result; }

        $rows = [];
        $estimated = false;
        if (isset($facts['agents']) && is_array($facts['agents'])) {
            foreach ($facts['agents'] as $a) {
                $rows[] = ['id' => (string)$a['id'], 'name' => (string)($a['name'] ?? $a['id']),
                    'now_bytes' => (int)$a['now_bytes'], 'unpacked_bytes' => (int)$a['unpacked_bytes']];
            }
        } else {
            foreach (self::agentSizes($persist) as $s) {
                if ($s['storage'] !== 'layers') continue;
                $layerFiles = glob(rtrim($persist, '/') . '/agent_' . $s['id'] . '_*.sqsh') ?: [];
                $sigParts = [];
                $apparent = 0;
                foreach ($layerFiles as $f) {
                    $st = @stat($f);
                    $sigParts[] = basename($f) . ':' . (int)($st['size'] ?? 0) . ':' . (int)($st['mtime'] ?? 0);
                    $apparent += (int)($st['size'] ?? 0);
                }
                sort($sigParts);
                $u = self::unpackedBytes($s['id'], $apparent, md5(implode('|', $sigParts)));
                if ($u['estimated']) $estimated = true;
                $rows[] = ['id' => $s['id'], 'name' => $s['name'],
                    'now_bytes' => $s['current_bytes'] + $s['previous_bytes'], 'unpacked_bytes' => $u['bytes']];
            }
        }
        if ($rows === []) { $result['reason'] = 'no_layered_agents'; return $result; }

        $extra = 0; $retained = 0;
        foreach ($rows as $r) {
            $plain = (int)ceil($r['unpacked_bytes'] / $ratio);
            $result['agents'][] = ['id' => $r['id'], 'name' => $r['name'],
                'now_bytes' => $r['now_bytes'], 'plain_bytes' => $plain];
            $extra += $plain - $r['now_bytes'];
            $retained += $r['now_bytes'];
        }
        $result['offered'] = true;
        $result['extra_bytes'] = max(0, $extra);
        $result['retained_bytes'] = $retained;
        $result['estimated'] = $estimated;
        $result['fits'] = self::convertFits($free, $disk, $result['extra_bytes']);
        return $result;
    }

    /**
     * Which of the requested agents may be converted now: each must be layered, and the
     * dialog must be offered. The caller already holds the estimate.
     *
     * @param string[]|'all' $requested agent ids, or the word "all"
     * @param array<string,mixed> $estimate the result of convertEstimate()
     * @return array{agents:string[],refused:array<string,string>}
     */
    public static function selectConvertible($requested, array $estimate): array
    {
        $layered = array_map(static fn(array $a): string => (string)$a['id'], $estimate['agents'] ?? []);
        if ($requested === 'all') {
            return ['agents' => $layered, 'refused' => []];
        }
        $agents = []; $refused = [];
        foreach ((array)$requested as $id) {
            $id = (string)$id;
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id)) { $refused[$id] = 'invalid_id'; continue; }
            if (!in_array($id, $layered, true)) { $refused[$id] = 'not_layered'; continue; }
            $agents[] = $id;
        }
        return ['agents' => array_values(array_unique($agents)), 'refused' => $refused];
    }
}
