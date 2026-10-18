<?php
/**
 * Coordinates the global storage-engine migration.  The shell storage
 * component owns each entity's mount, layer and manifest authority; this class
 * owns only policy validation, entity enumeration, session eviction and the
 * final config commit.
 */

namespace AICliAgents\Services;

final class StorageBackendMigrationService
{
    public static function preflight(string $target, ?array $config = null): array
    {
        $config = $config ?? ConfigService::getConfig();
        $target = StorageBackendPolicyService::normalizeMode($target);
        $verdicts = StorageBackendPolicyService::targetVerdicts($config);
        $refused = [];
        $usb = [];
        foreach ($verdicts as $kind => $verdict) {
            if (!empty($verdict['refuse'])) $refused[] = $kind;
            if (!empty($verdict['is_usb_or_removable'])) $usb[] = $kind;
        }
        if ($target === StorageBackendPolicyService::PASSTHROUGH && $refused !== []) {
            return [
                'status' => 'error',
                'message' => 'Plain-directory mode cannot use the refused storage target for: ' . implode(', ', $refused) . '.',
                'target' => $target,
                'targets' => $verdicts,
            ];
        }
        $message = $target === StorageBackendPolicyService::PASSTHROUGH
            ? 'All installed agents and homes will be converted to plain directories. Existing sessions will be closed safely, old SquashFS data will be retained in the migration archive, and new installs can activate side by side.'
            : 'All installed agents and homes will be converted to SquashFS layers with writable zram state. Existing plain directories will be archived only after the layer and manifest are verified.';
        if ($usb !== []) {
            $message .= ' The probe identified removable/USB-backed storage (' . implode(', ', $usb) . '); SquashFS + zram is recommended to reduce wear.';
        }
        return [
            'status' => 'ok',
            'target' => $target,
            'current' => StorageBackendPolicyService::normalizeMode($config['storage_backend_mode'] ?? 'layering'),
            'targets' => $verdicts,
            'requires_acknowledgement' => $usb !== [],
            'message' => $message,
        ];
    }

    /** Lock file of the all-entity engine migration. Tests may override it. */
    private static function lockPath(): string
    {
        $e = getenv('AICLI_BACKEND_MIGRATE_LOCK');
        return ($e !== false && $e !== '') ? $e : '/tmp/unraid-aicliagents/.backend_migrate.lock';
    }

    /**
     * R-122 (#662): one engine migration at a time (a non-blocking lock), and an all-or-nothing
     * result. When an entity fails, the entities that were already converted are converted back,
     * and the global mode is NOT saved, so the state is never a mix of both engines. When the
     * rollback of an entity fails too, the message names it.
     *
     * $seams (tests): preflight (array), entities (array), evict (callable),
     * convert (callable(type,id,mode,forceDirect): array),
     * wrap (callable(from,to,work): object with ->ok and ->error), save (callable(mode): bool).
     */
    public static function migrate(string $target, bool $acknowledgeWear = false, ?array $config = null, array $seams = []): array
    {
        $config = $config ?? ConfigService::getConfig();
        $check = $seams['preflight'] ?? self::preflight($target, $config);
        if (($check['status'] ?? '') !== 'ok') return $check;
        $target = (string)$check['target'];
        if (!empty($check['requires_acknowledgement']) && !$acknowledgeWear) {
            return ['status' => 'error', 'message' => 'Plain-directory mode on removable/USB storage requires an explicit wear acknowledgement.'];
        }
        $save = $seams['save'] ?? static fn(string $mode): bool => (bool)ConfigService::saveConfig(['storage_backend_mode' => $mode], false);
        $from = StorageBackendPolicyService::normalizeMode($config['storage_backend_mode'] ?? 'layering');
        $entities = $seams['entities'] ?? self::entities($config);
        if ($from === $target && $entities === []) {
            $save($target);
            return ['status' => 'ok', 'message' => 'Storage-engine policy saved; there was no entity data to migrate.', 'migrated' => []];
        }

        $lockFile = self::lockPath();
        @mkdir(dirname($lockFile), 0755, true);
        $lock = @fopen($lockFile, 'c');
        if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return ['status' => 'error', 'message' => 'A storage-engine migration is already running. Wait for it to finish, then try again.', 'migrated' => []];
        }
        try {
            return self::migrateLocked($from, $target, $entities, $seams, $save);
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<int,array{type:string,id:string}> $entities */
    private static function migrateLocked(string $from, string $target, array $entities, array $seams, callable $save): array
    {
        $convert = $seams['convert'] ?? static fn(string $type, string $id, string $mode, bool $direct): array => FileStorage::migrateBackend($type, $id, $mode, $direct);
        $wrap = $seams['wrap'] ?? static fn(string $f, string $t, callable $work) => FileStorage::migrateBackendPolicy($f, $t, $work);
        $migrated = [];
        $failure = null;
        $rollbackFailed = [];
        $result = $wrap($from, $target, function () use ($entities, $target, $from, $seams, $convert, &$migrated, &$failure, &$rollbackFailed): bool {
            // No workspace may keep writing while the authority of its storage
            // engine is changing. The shell operation still refuses a busy mount.
            if (isset($seams['evict'])) {
                ($seams['evict'])();
            } else {
                if (class_exists(TerminalService::class) && method_exists(TerminalService::class, 'captureResumeForShutdown')) {
                    TerminalService::captureResumeForShutdown(null, 60);
                }
                ProcessManager::evictAll();
            }
            foreach ($entities as $entity) {
                $r = $convert($entity['type'], $entity['id'], $target, $target === StorageBackendPolicyService::PASSTHROUGH);
                if ((int)($r['exit'] ?? 1) !== 0) {
                    $failure = $entity['type'] . '/' . $entity['id'] . ': ' . (($r['reason'] ?? '') ?: 'storage conversion failed');
                    // Convert the entities that already moved back, newest first.
                    foreach (array_reverse($migrated) as $done) {
                        [$t, $i] = explode('/', $done, 2);
                        $b = $convert($t, $i, $from, $from === StorageBackendPolicyService::PASSTHROUGH);
                        if ((int)($b['exit'] ?? 1) !== 0) $rollbackFailed[] = $done;
                    }
                    return false;
                }
                $migrated[] = $entity['type'] . '/' . $entity['id'];
            }
            return true;
        });
        if (!$result->ok) {
            $message = $failure ?: ($result->error ?: 'Storage-engine migration failed.');
            if ($failure !== null) {
                $message .= $rollbackFailed === []
                    ? ' The entities that were already converted were converted back; the storage engine is unchanged.'
                    : ' These entities could not be converted back and are still in the new engine: ' . implode(', ', $rollbackFailed) . '.';
            }
            return ['status' => 'error', 'message' => $message, 'migrated' => $rollbackFailed];
        }
        if (!$save($target)) {
            return ['status' => 'error', 'message' => 'Storage data migrated, but the global policy could not be saved. Re-open Settings and retry.', 'migrated' => $migrated];
        }
        return ['status' => 'ok', 'message' => 'Migrated ' . count($migrated) . ' storage entities to ' . StorageBackendPolicyService::label($target) . '.', 'migrated' => $migrated];
    }

    /** Enumerate only candidate entities; the shell remains authoritative about presence. */
    public static function entities(array $config): array
    {
        $paths = [
            'agent' => (string)($config['agent_storage_path'] ?? ''),
            'home' => (string)($config['home_storage_path'] ?? ''),
        ];
        $out = [];
        $seen = [];
        $add = static function (string $type, string $id) use (&$out, &$seen): void {
            if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $id)) return;
            $key = $type . '/' . $id;
            if (isset($seen[$key])) return;
            $seen[$key] = true;
            $out[] = ['type' => $type, 'id' => $id];
        };
        foreach (LayerManifestService::getAllEntities() as $entity => $_entry) {
            if (preg_match('#^(agent|home)/([A-Za-z0-9_.-]+)$#', (string)$entity, $m)) $add($m[1], $m[2]);
        }
        foreach ($paths as $type => $path) {
            foreach (glob(rtrim($path, '/') . '/' . $type . '_*.sqsh') ?: [] as $file) {
                if (preg_match('/^' . $type . '_(.+?)_(?:v\d+_)?(?:vol1|delta|consolidated)/', basename($file), $m)) $add($type, $m[1]);
            }
            foreach (glob(rtrim($path, '/') . '/passthrough/' . $type . 's/*') ?: [] as $dir) {
                $id = basename($dir);
                if ($id !== '.versions' && $id !== '.staging' && $id !== '.staging-data') $add($type, $id);
            }
        }
        if (class_exists(AgentRegistry::class)) {
            foreach (AgentRegistry::getRegistry() as $id => $agent) {
                if (!empty($agent['is_installed'])) $add('agent', (string)$id);
            }
        }
        if (class_exists(UtilityService::class)) {
            foreach (UtilityService::getUnraidUsers() as $user) $add('home', (string)$user);
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['type'] . '/' . $a['id'], $b['type'] . '/' . $b['id']));
        return $out;
    }
}
