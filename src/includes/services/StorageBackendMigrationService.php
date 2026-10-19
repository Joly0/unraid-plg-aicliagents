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
        return ($e !== false && $e !== '') ? $e : SecretPaths::stateRoot() . '/.backend_migrate.lock';
    }

    /**
     * Shared by agent auto-launch and this migration. Auto-launch takes a
     * shared lock; migration takes the exclusive lock. This makes the two
     * operations mutually exclusive without stopping the supervisor.
     *
     * @return resource|false
     */
    public static function acquireLaunchGuard(bool $exclusive)
    {
        $lockFile = self::lockPath();
        @mkdir(dirname($lockFile), 0755, true);
        $lock = @fopen($lockFile, 'c');
        if ($lock === false) return false;
        $mode = $exclusive ? LOCK_EX : LOCK_SH;
        if (!@flock($lock, $mode | LOCK_NB)) { @fclose($lock); return false; }
        return $lock;
    }

    public static function releaseLaunchGuard($lock): void
    {
        if (!is_resource($lock)) return;
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }

    /** True while a queued or active global migration owns the storage policy. */
    public static function isMigrationInProgress(): bool
    {
        return in_array((string)(self::readStatus()['status'] ?? ''), ['queued', 'running'], true);
    }

    /** Reserve the global engine change, then start its worker without holding the page open. */
    public static function startAsync(string $target, bool $acknowledgeWear = false, ?array $config = null, array $seams = []): array
    {
        $config = $config ?? ConfigService::getConfig();
        $check = $seams['preflight'] ?? self::preflight($target, $config);
        if (($check['status'] ?? '') !== 'ok') return $check;
        $target = (string)$check['target'];
        if (!empty($check['requires_acknowledgement']) && !$acknowledgeWear) {
            return ['status' => 'error', 'message' => 'Plain-directory mode on removable/USB storage requires an explicit wear acknowledgement.'];
        }

        $entities = $seams['entities'] ?? self::entities($config);
        $from = StorageBackendPolicyService::normalizeMode($config['storage_backend_mode'] ?? 'layering');
        $lock = self::acquireLaunchGuard(true);
        if ($lock === false) {
            return ['status' => 'error', 'message' => 'A storage-engine migration or workspace start is already running. Wait for it to finish, then try again.'];
        }
        try {
            if ((self::readStatus()['status'] ?? '') === 'queued') {
                return ['status' => 'error', 'message' => 'A storage-engine migration is already queued. Wait for it to finish, then try again.'];
            }
            $busy = self::busyEntities($entities);
            if ($busy !== []) {
                return ['status' => 'error', 'message' => 'Storage work is active for ' . implode(', ', $busy) . '. Wait for it to finish, then try the engine change again.'];
            }

            $state = [
                'status' => 'queued', 'from' => $from, 'target' => $target,
                'started_at' => time(), 'updated_at' => time(), 'completed' => 0,
                'total' => count($entities), 'current' => '', 'phase' => 'queued',
                'message' => 'The migration is queued. Workspaces cannot open until it finishes.', 'worker_pid' => 0,
            ];
            if (!self::writeStatus($state)) {
                return ['status' => 'error', 'message' => 'The migration status could not be saved. No storage was changed.'];
            }
            // The queued state now refuses new starts and storage actions. Free
            // the reservation before spawning so the worker can take the
            // exclusive lock on its first attempt.
            self::releaseLaunchGuard($lock);
            $lock = false;
            $spawn = $seams['spawn'] ?? static function (string $mode, bool $ack): int {
                require_once __DIR__ . '/UtilityService.php';
                $script = dirname(__DIR__, 2) . '/scripts/storage-backend-migrate.php';
                return UtilityService::spawnDetached(
                    [PHP_BINARY, $script, $mode, $ack ? '1' : '0'],
                    self::statusPath() . '.log',
                    true
                );
            };
            try {
                $pid = (int)$spawn($target, $acknowledgeWear);
            } catch (\Throwable $e) {
                $state['status'] = 'error';
                $state['phase'] = 'error';
                $state['message'] = 'The migration worker did not start. No storage was changed.';
                $state['updated_at'] = time();
                self::writeStatus($state);
                return ['status' => 'error', 'message' => $state['message']];
            }
            if ($pid < 1) {
                $state['status'] = 'error';
                $state['phase'] = 'error';
                $state['message'] = 'The migration worker did not start. No storage was changed.';
                $state['updated_at'] = time();
                self::writeStatus($state);
                return ['status' => 'error', 'message' => $state['message']];
            }
            $current = self::readStatus();
            if (($current['status'] ?? '') === 'queued') {
                $state['worker_pid'] = $pid;
                $state['updated_at'] = time();
                self::writeStatus($state);
            }
            return ['status' => 'accepted', 'message' => 'Migration started. Workspaces stay closed until it finishes.', 'worker_pid' => $pid];
        } finally {
            self::releaseLaunchGuard($lock);
        }
    }

    /** Run the accepted worker job and record a failure that happened before conversion began. */
    public static function runQueuedMigration(string $target, bool $acknowledgeWear): array
    {
        try {
            $result = self::migrate($target, $acknowledgeWear, ConfigService::getConfig());
        } catch (\Throwable $e) {
            $state = self::readStatus();
            if (($state['status'] ?? '') === 'queued') {
                $state['status'] = 'error';
                $state['phase'] = 'error';
                $state['message'] = 'The migration worker stopped before conversion began. Check the Debug Console for details.';
                $state['updated_at'] = time();
                self::writeStatus($state);
            }
            throw $e;
        }
        $state = self::readStatus();
        if (($state['status'] ?? '') === 'queued') {
            $state['status'] = ($result['status'] ?? '') === 'ok' ? 'complete' : 'error';
            $state['phase'] = $state['status'];
            $state['message'] = (string)($result['message'] ?? 'The migration did not start.');
            $state['updated_at'] = time();
            self::writeStatus($state);
        }
        return $result;
    }

    /** Probe every per-entity bake lock before any session closes or conversion begins. */
    private static function busyEntities(array $entities): array
    {
        $dir = getenv('AICLI_BACKEND_ENTITY_LOCK_DIR');
        if ($dir === false || $dir === '') $dir = '/var/run';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $busy = [];
        foreach ($entities as $entity) {
            $type = (string)($entity['type'] ?? '');
            $id = (string)($entity['id'] ?? '');
            if (!in_array($type, ['agent', 'home'], true) || $id === '') continue;
            $safeId = preg_replace('/[^A-Za-z0-9_-]/', '_', $id);
            $path = rtrim($dir, '/') . '/aicli-bake-' . $type . '-' . $safeId . '.lock';
            $fh = @fopen($path, 'c');
            if ($fh === false || !@flock($fh, LOCK_EX | LOCK_NB)) {
                if (is_resource($fh)) @fclose($fh);
                $busy[] = $type . '/' . $id;
                continue;
            }
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
        return $busy;
    }

    private static function statusPath(): string
    {
        $override = getenv('AICLI_BACKEND_MIGRATE_STATUS');
        return ($override !== false && $override !== '') ? $override : SecretPaths::stateRoot() . '/backend-migration-status.json';
    }

    /** Current page-independent migration status. */
    public static function status(): array
    {
        $status = self::readStatus();
        if (($status['status'] ?? '') === 'queued') {
            require_once __DIR__ . '/UtilityService.php';
        }
        if (($status['status'] ?? '') === 'queued'
            && time() - (int)($status['updated_at'] ?? 0) > 60
            && !UtilityService::isPidRunning((int)($status['worker_pid'] ?? 0))) {
            $status['status'] = 'interrupted';
            $status['message'] = 'The migration worker stopped before conversion began. Check storage before you try again.';
            $status['updated_at'] = time();
            self::writeStatus($status);
        }
        if (($status['status'] ?? '') === 'running') {
            $probe = self::acquireLaunchGuard(false);
            if ($probe !== false) {
                self::releaseLaunchGuard($probe);
                $status['status'] = 'interrupted';
                $status['message'] = 'The migration stopped before it finished. Check storage before you try again.';
                $status['updated_at'] = time();
                self::writeStatus($status);
            }
        }
        return $status;
    }

    private static function readStatus(): array
    {
        $json = @file_get_contents(self::statusPath());
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) ? $data : ['status' => 'idle'];
    }

    private static function writeStatus(array $status): bool
    {
        $path = self::statusPath();
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($status, JSON_UNESCAPED_SLASHES);
        if ($json !== false && @file_put_contents($tmp, $json, LOCK_EX) !== false && @rename($tmp, $path)) return true;
        @unlink($tmp);
        return false;
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

        $lock = self::acquireLaunchGuard(true);
        if ($lock === false) {
            return ['status' => 'error', 'message' => 'A storage-engine migration is already running. Wait for it to finish, then try again.', 'migrated' => []];
        }
        $result = null;
        try {
            $busy = self::busyEntities($entities);
            if ($busy !== []) {
                $message = 'Storage work is active for ' . implode(', ', $busy) . '. Wait for it to finish, then try the engine change again.';
                self::writeStatus([
                    'status' => 'error', 'phase' => 'preflight', 'from' => $from, 'target' => $target,
                    'started_at' => time(), 'updated_at' => time(), 'completed' => 0,
                    'total' => count($entities), 'current' => $busy[0], 'message' => $message,
                ]);
                $result = ['status' => 'error', 'message' => $message, 'migrated' => []];
            } else {
                $state = [
                    'status' => 'running', 'from' => $from, 'target' => $target,
                    'started_at' => time(), 'updated_at' => time(), 'completed' => 0,
                    'total' => count($entities), 'current' => '', 'phase' => 'converting', 'message' => 'Preparing migration',
                ];
                self::writeStatus($state);
                $result = self::migrateLocked($from, $target, $entities, $seams, $save, $state);
                $state['status'] = $result['status'] === 'ok' ? 'complete' : 'error';
                $state['phase'] = $state['status'];
                $state['current'] = '';
                $state['message'] = (string)($result['message'] ?? 'Migration finished.');
                $state['updated_at'] = time();
                self::writeStatus($state);
            }
        } finally {
            self::releaseLaunchGuard($lock);
        }
        if (!empty($result['resume_after_migration'])) {
            if (isset($seams['resume']) && is_callable($seams['resume'])) {
                ($seams['resume'])();
            } elseif (class_exists(AutoLaunchService::class)) {
                AutoLaunchService::launchAllPending('storage_backend_migration');
            }
        }
        unset($result['resume_after_migration']);
        return $result;
    }

    /** @param array<int,array{type:string,id:string}> $entities */
    private static function migrateLocked(string $from, string $target, array $entities, array $seams, callable $save, array &$state): array
    {
        $convert = $seams['convert'] ?? static fn(string $type, string $id, string $mode, bool $direct): array => FileStorage::migrateBackend($type, $id, $mode, $direct);
        $wrap = $seams['wrap'] ?? static fn(string $f, string $t, callable $work) => FileStorage::migrateBackendPolicy($f, $t, $work);
        $migrated = [];
        $failure = null;
        $rollbackFailed = [];
        $result = $wrap($from, $target, function () use ($entities, $target, $from, $seams, $convert, &$migrated, &$failure, &$rollbackFailed, &$state): bool {
            // No workspace may keep writing while the authority of its storage
            // engine is changing. The shell operation still refuses a busy mount.
            if (isset($seams['evict'])) {
                ($seams['evict'])(false);
            } else {
                if (class_exists(TerminalService::class) && method_exists(TerminalService::class, 'captureResumeForShutdown')) {
                    TerminalService::captureResumeForShutdown(null, 60);
                }
                ProcessManager::evictAllForStorageMigration();
            }
            foreach ($entities as $entity) {
                $state['current'] = $entity['type'] . '/' . $entity['id'];
                $state['message'] = 'Converting ' . $state['current'];
                $state['updated_at'] = time();
                self::writeStatus($state);
                $r = $convert($entity['type'], $entity['id'], $target, $target === StorageBackendPolicyService::PASSTHROUGH);
                if ((int)($r['exit'] ?? 1) !== 0) {
                    $failure = $entity['type'] . '/' . $entity['id'] . ': ' . (($r['reason'] ?? '') ?: 'storage conversion failed');
                    // Convert the entities that already moved back, newest first.
                    foreach (array_reverse($migrated) as $done) {
                        $state['phase'] = 'rolling_back';
                        $state['current'] = $done;
                        $state['message'] = 'Restoring ' . $done . ' to ' . StorageBackendPolicyService::label($from);
                        $state['updated_at'] = time();
                        self::writeStatus($state);
                        [$t, $i] = explode('/', $done, 2);
                        $b = $convert($t, $i, $from, $from === StorageBackendPolicyService::PASSTHROUGH);
                        if ((int)($b['exit'] ?? 1) !== 0) $rollbackFailed[] = $done;
                        else $state['completed'] = max(0, (int)$state['completed'] - 1);
                        $state['updated_at'] = time();
                        self::writeStatus($state);
                    }
                    return false;
                }
                $migrated[] = $entity['type'] . '/' . $entity['id'];
                $state['completed'] = count($migrated);
                $state['updated_at'] = time();
                self::writeStatus($state);
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
            return ['status' => 'error', 'message' => $message, 'migrated' => $rollbackFailed, 'resume_after_migration' => $failure !== null && $rollbackFailed === []];
        }
        if (!$save($target)) {
            return ['status' => 'error', 'message' => 'Storage data migrated, but the global policy could not be saved. Re-open Settings and retry.', 'migrated' => $migrated];
        }
        return ['status' => 'ok', 'message' => 'Migrated ' . count($migrated) . ' storage entities to ' . StorageBackendPolicyService::label($target) . '.', 'migrated' => $migrated, 'resume_after_migration' => true];
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
