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
    private const CONVERSION_RETRY_LIMIT = 6;
    private const CONVERSION_RETRY_DELAY_SECONDS = 5;
    private const INSTALL_WAIT_TIMEOUT_SECONDS = 1200;

    public static function preflight(string $target, ?array $config = null, array $seams = []): array
    {
        $config = $config ?? ConfigService::getConfig();
        $target = StorageBackendPolicyService::normalizeMode($target);
        $verdicts = $seams['verdicts'] ?? StorageBackendPolicyService::targetVerdicts($config);
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
        $entities = $seams['entities'] ?? self::entities($config);
        $capacity = self::capacityPreflight($target, $config, $verdicts, $entities, $seams);
        if (($capacity['status'] ?? '') !== 'ok') {
            return $capacity + ['target' => $target, 'targets' => $verdicts];
        }
        $message = $target === StorageBackendPolicyService::PASSTHROUGH
            ? 'All installed agents and homes will be converted to plain directories. Existing sessions will be closed safely, old SquashFS data will be retained in the migration archive, and new installs can activate side by side.'
            : 'All installed agents and homes will be converted to SquashFS layers with writable zram state. Existing plain directories will be archived only after the layer and manifest are verified.';
        if ($usb !== []) {
            $message .= ' The probe identified removable/USB-backed storage (' . implode(', ', $usb) . '); SquashFS + zram is recommended to reduce wear.';
        }
        $message .= ' The migration needs about ' . BootSpaceService::human((int)$capacity['capacity']['forward_bytes'])
            . ' for the new storage and ' . BootSpaceService::human((int)$capacity['capacity']['rollback_bytes'])
            . ' for rollback copies. It must leave ' . BootSpaceService::human((int)$capacity['capacity']['reserve_bytes']) . ' free.';
        return [
            'status' => 'ok',
            'target' => $target,
            'current' => StorageBackendPolicyService::normalizeMode($config['storage_backend_mode'] ?? 'layering'),
            'targets' => $verdicts,
            'requires_acknowledgement' => $usb !== [],
            'message' => $message,
            'capacity' => $capacity['capacity'],
        ];
    }

    /**
     * Check all new and rollback copies against one fresh measurement and live free space.
     * The old representation stays in place until the new one is verified, so it does not
     * free space for this check. Tests may supply snapshot, entities and disk seams.
     */
    private static function capacityPreflight(string $target, array $config, array $verdicts, array $entities, array $seams): array
    {
        $now = (int)($seams['now'] ?? time());
        $snapshot = $seams['snapshot'] ?? BootSpaceService::read();
        if (!is_array($snapshot) || BootSpaceService::isStale($snapshot, $now) || !array_key_exists('partial', $snapshot) || !empty($snapshot['partial'])) {
            return ['status' => 'error', 'message' => 'The Boot disk space report is missing, old or incomplete. Refresh that report before you try the engine change.'];
        }
        $agentPath = trim((string)($verdicts['agent']['path'] ?? ''));
        if ($agentPath === '') $agentPath = trim((string)($config['agent_storage_path'] ?? '')) ?: StoragePathResolver::pluginBase();
        $agentPath = rtrim($agentPath, '/');
        $homePath = rtrim((string)($verdicts['home']['path'] ?? StoragePathResolver::homePersistPathFromConfig($config)), '/');
        $measuredAgentPath = rtrim((string)($snapshot['persist'] ?? ''), '/');
        $measuredHomePath = rtrim((string)($snapshot['home_persist'] ?? ''), '/');
        if (($measuredAgentPath !== '' && $measuredAgentPath !== $agentPath)
            || ($measuredHomePath !== '' && $measuredHomePath !== $homePath)
            || (($measuredAgentPath === '' || $measuredHomePath === '') && $agentPath !== $homePath)) {
            return ['status' => 'error', 'message' => 'The Boot disk space report does not match both current storage paths. Refresh that report before you try the engine change.'];
        }

        $agents = [];
        foreach (($snapshot['agents'] ?? []) as $row) {
            if (is_array($row) && isset($row['id'])) $agents[(string)$row['id']] = $row;
        }
        $plainAgentEstimates = [];
        foreach (($snapshot['convert']['agents'] ?? []) as $row) {
            if (is_array($row) && isset($row['id'], $row['plain_bytes'])) $plainAgentEstimates[(string)$row['id']] = $row;
        }
        $homeParts = [];
        foreach (($snapshot['parts'] ?? []) as $part) {
            if (!is_array($part) || !isset($part['key'], $part['bytes'])) continue;
            $homeParts[(string)$part['key']] = max(0, (int)$part['bytes']);
        }

        $diskFor = $seams['disk'] ?? static function (string $path): array {
            $stat = @stat($path);
            return [
                'device' => $stat === false ? null : (string)($stat['dev'] ?? ''),
                'total' => (int)@disk_total_space($path),
                'free' => (int)@disk_free_space($path),
            ];
        };
        $groups = [];
        $forwardTotal = 0;
        $rollbackTotal = 0;
        foreach ($entities as $entity) {
            $type = (string)($entity['type'] ?? '');
            $id = (string)($entity['id'] ?? '');
            if (!in_array($type, ['agent', 'home'], true) || $id === '') continue;
            $forwardBytes = 0;
            $rollbackBytes = 0;
            if ($type === 'agent') {
                $row = $agents[$id] ?? null;
                // A complete report has scanned both persistence roots. An installed
                // agent with no measured row has no saved files to copy.
                if (!is_array($row)) continue;
                $storage = (string)($row['storage'] ?? '');
                if ($target === StorageBackendPolicyService::PASSTHROUGH && $storage === 'layers') {
                    $estimate = $plainAgentEstimates[$id] ?? null;
                    $forwardBytes = is_array($estimate)
                        ? max(0, (int)$estimate['plain_bytes'])
                        : (int)ceil(max(0, (int)($row['current_bytes'] ?? 0) + (int)($row['previous_bytes'] ?? 0)) * AgentStorageService::UNPACKED_RATIO);
                    $rollbackBytes = max(
                        max(0, (int)($row['current_bytes'] ?? 0) + (int)($row['previous_bytes'] ?? 0)),
                        (int)ceil($forwardBytes / AgentStorageService::UNPACKED_RATIO)
                    );
                } elseif ($target === StorageBackendPolicyService::LAYERING && $storage === 'plain') {
                    // The SquashFS output can approach the plain folder size. Use that
                    // full size for the new layer and again for a plain rollback copy.
                    $forwardBytes = max(0, (int)($row['current_bytes'] ?? 0));
                    $rollbackBytes = $forwardBytes;
                } elseif (!in_array($storage, ['layers', 'plain'], true)) {
                    return ['status' => 'error', 'message' => 'The Boot disk space report cannot identify the storage for agent ' . $id . '. Refresh that report before you try the engine change.'];
                }
            } else {
                $layerKey = 'home:' . $id . ':layers';
                $plainKeys = ['home:' . $id . ':history', 'home:' . $id . ':caches', 'home:' . $id . ':other'];
                if (array_key_exists($layerKey, $homeParts)) {
                    if ($target === StorageBackendPolicyService::PASSTHROUGH) {
                        // Layer files hold compressed content. The measured part uses allocated
                        // bytes, so the established 5.8 expansion estimate is conservative here.
                        $forwardBytes = (int)ceil($homeParts[$layerKey] * AgentStorageService::UNPACKED_RATIO);
                        $rollbackBytes = max($homeParts[$layerKey], (int)ceil($forwardBytes / AgentStorageService::UNPACKED_RATIO));
                    }
                } elseif (array_reduce($plainKeys, static fn(bool $found, string $key): bool => $found || array_key_exists($key, $homeParts), false)) {
                    if ($target === StorageBackendPolicyService::LAYERING) {
                        // A SquashFS layer may be close to the plain source size. The
                        // migration also needs a fresh plain copy if it must roll back.
                        $forwardBytes = array_sum(array_map(static fn(string $key): int => $homeParts[$key] ?? 0, $plainKeys));
                        $rollbackBytes = $forwardBytes;
                    }
                } else {
                    // Homes with no layer or plain-folder part have no saved files to copy.
                    continue;
                }
            }
            if ($forwardBytes === 0 && $rollbackBytes === 0) continue;
            $path = (string)($verdicts[$type]['path'] ?? ($type === 'agent' ? ($config['agent_storage_path'] ?? '') : ($config['home_storage_path'] ?? '')));
            if ($path === '') return ['status' => 'error', 'message' => 'The storage path for ' . $type . '/' . $id . ' is not available for the space check.'];
            $disk = $diskFor($path);
            $device = (string)($disk['device'] ?? '');
            $total = (int)($disk['total'] ?? 0);
            $free = (int)($disk['free'] ?? 0);
            if ($device === '' || $total <= 0 || $free < 0) {
                return ['status' => 'error', 'message' => 'Free space for ' . $type . '/' . $id . ' could not be checked. No storage was changed.'];
            }
            $key = $device;
            if (!isset($groups[$key])) $groups[$key] = ['total' => $total, 'free' => $free, 'forward' => 0, 'rollback' => 0];
            $groups[$key]['forward'] += $forwardBytes;
            $groups[$key]['rollback'] += $rollbackBytes;
            $forwardTotal += $forwardBytes;
            $rollbackTotal += $rollbackBytes;
        }

        $reserveTotal = 0;
        foreach ($groups as $group) {
            $reserve = max(AgentStorageService::RESERVE_BYTES, intdiv((int)$group['total'], AgentStorageService::RESERVE_DIVISOR));
            $reserveTotal += $reserve;
            $write = (int)$group['forward'] + (int)$group['rollback'];
            if ((int)$group['free'] < $write + $reserve) {
                $need = $write + $reserve;
                return [
                    'status' => 'error',
                    'message' => 'The migration needs about ' . BootSpaceService::human((int)$group['forward'])
                        . ' for new storage and ' . BootSpaceService::human((int)$group['rollback'])
                        . ' for rollback copies, plus ' . BootSpaceService::human($reserve) . ' free. It needs '
                        . BootSpaceService::human($need) . ' free, but only ' . BootSpaceService::human((int)$group['free'])
                        . ' is available. Free space, then try again. No storage was changed.',
                    'capacity' => ['forward_bytes' => (int)$group['forward'], 'rollback_bytes' => (int)$group['rollback'],
                        'write_bytes' => $write, 'reserve_bytes' => $reserve,
                        'required_bytes' => $need, 'available_bytes' => (int)$group['free'], 'fits' => false],
                ];
            }
        }
        return ['status' => 'ok', 'capacity' => [
            'forward_bytes' => $forwardTotal, 'rollback_bytes' => $rollbackTotal,
            'write_bytes' => $forwardTotal + $rollbackTotal, 'reserve_bytes' => $reserveTotal,
            'required_bytes' => $forwardTotal + $rollbackTotal + $reserveTotal,
            'available_bytes' => array_sum(array_map(static fn(array $g): int => (int)$g['free'], $groups)),
            'fits' => true,
        ]];
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

    /** Plain server refusal shared by agent install, removal and upgrade paths. */
    public static function migrationRefusal(): ?array
    {
        if (!self::isMigrationInProgress()) return null;
        return [
            'status' => 'error',
            'reason' => 'storage_migration',
            'message' => 'Wait for the storage migration to finish before installing, removing, or upgrading agents.',
        ];
    }

    /** Save a refusal so both status views retain the reason a migration did not start. */
    private static function recordPreflightFailure(array $check, string $target, array $config, array $entities): array
    {
        $now = time();
        $state = [
            'status' => 'error', 'phase' => 'preflight',
            'from' => StorageBackendPolicyService::normalizeMode($config['storage_backend_mode'] ?? 'layering'),
            'target' => StorageBackendPolicyService::normalizeMode($target),
            'started_at' => $now, 'updated_at' => $now, 'completed' => 0,
            'total' => count($entities), 'current' => '',
            'message' => (string)($check['message'] ?? 'The migration could not start. No storage was changed.'),
        ];
        if (isset($check['capacity']) && is_array($check['capacity'])) $state['capacity'] = $check['capacity'];
        self::writeStatus($state);
        return $check;
    }

    /** Reserve the shared migration right, then start the worker without holding the page open. */
    public static function startAsync(string $target, bool $acknowledgeWear = false, ?array $config = null, array $seams = []): array
    {
        $config = $config ?? ConfigService::getConfig();
        require_once __DIR__ . '/FileStorage.php';
        require_once __DIR__ . '/StoragePathMoveJobService.php';
        $migrationLock = FileStorage::acquireMigrationLock();
        if ($migrationLock === null) {
            return ['status' => 'error', 'message' => 'Another storage migration is already running. Wait for it to finish, then try again.'];
        }
        try {
            $conflict = self::pathMoveConflictMessage();
            if ($conflict !== null) return ['status' => 'error', 'message' => $conflict];
            return self::startAsyncWithMigrationLock($target, $acknowledgeWear, $config, $seams, $migrationLock);
        } finally {
            FileStorage::releaseMigrationLock($migrationLock);
        }
    }

    /** @param resource|null $migrationLock */
    private static function startAsyncWithMigrationLock(string $target, bool $acknowledgeWear, array $config, array $seams, &$migrationLock): array
    {
        $check = $seams['preflight'] ?? self::preflight($target, $config, $seams);
        if (($check['status'] ?? '') !== 'ok') {
            $entities = $seams['entities'] ?? self::entities($config);
            return self::recordPreflightFailure($check, $target, $config, $entities);
        }
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
            $activeInstalls = self::activeInstallIds($seams);
            $busy = self::busyEntities($entities);
            if ($busy !== [] && $activeInstalls === []) {
                return ['status' => 'error', 'message' => 'Storage work is active for ' . implode(', ', $busy) . '. Wait for it to finish, then try the engine change again.'];
            }

            $waitingForInstalls = $activeInstalls !== [];
            $state = [
                'status' => 'queued', 'from' => $from, 'target' => $target,
                'started_at' => time(), 'updated_at' => time(), 'completed' => 0,
                'total' => count($entities), 'current' => $waitingForInstalls ? implode(', ', $activeInstalls) : '',
                'phase' => $waitingForInstalls ? 'waiting_for_installs' : 'queued',
                'message' => $waitingForInstalls
                    ? 'The migration is queued and waiting for agent installation to finish: ' . implode(', ', $activeInstalls) . '.'
                    : 'The migration is queued. Workspaces cannot open until it finishes.',
                'worker_pid' => 0,
                'entities' => self::entityJournal($entities, $from, $target),
            ];
            if (!self::writeStatus($state)) {
                return ['status' => 'error', 'message' => 'The migration status could not be saved. No storage was changed.'];
            }
            // The queued state now refuses path moves. Free the shared lock
            // before spawning so the worker can take it on its first attempt.
            FileStorage::releaseMigrationLock($migrationLock);
            $migrationLock = null;
            // The launch guard also blocks workspace starts until the worker
            // records that it owns the storage policy.
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

    /** Refuse the engine change while a path move is queued or still has its marker. */
    private static function pathMoveConflictMessage(): ?string
    {
        if (FileStorage::isMigrationInProgress()) {
            return 'A storage path move is still running. Wait for it to finish before changing the storage engine.';
        }
        if (StoragePathMoveJobService::hasPending()) {
            return 'A storage path move is queued. Wait for it to finish before changing the storage engine.';
        }
        return null;
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
        require_once __DIR__ . '/FileStorage.php';
        require_once __DIR__ . '/StoragePathMoveJobService.php';
        $migrationLock = FileStorage::acquireMigrationLock();
        if ($migrationLock === null) {
            return ['status' => 'error', 'message' => 'Another storage migration is already running. Wait for it to finish, then try again.', 'migrated' => []];
        }
        try {
            $conflict = self::pathMoveConflictMessage();
            if ($conflict !== null) return ['status' => 'error', 'message' => $conflict, 'migrated' => []];
            return self::migrateWithMigrationLock($target, $acknowledgeWear, $config, $seams, $migrationLock);
        } finally {
            FileStorage::releaseMigrationLock($migrationLock);
        }
    }

    /** @param resource|null $migrationLock */
    private static function migrateWithMigrationLock(string $target, bool $acknowledgeWear, array $config, array $seams, &$migrationLock): array
    {
        $check = $seams['preflight'] ?? self::preflight($target, $config, $seams);
        if (($check['status'] ?? '') !== 'ok') {
            $entities = $seams['entities'] ?? self::entities($config);
            return self::recordPreflightFailure($check, $target, $config, $entities);
        }
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
            $state = [
                'status' => 'running', 'from' => $from, 'target' => $target,
                'started_at' => time(), 'updated_at' => time(), 'completed' => 0,
                'total' => count($entities), 'current' => '', 'phase' => 'waiting_for_installs',
                'message' => 'Checking for agent installations in progress.',
                'entities' => self::entityJournal($entities, $from, $target),
            ];
            self::writeStatus($state);

            $now = $seams['now'] ?? static fn(): int => time();
            $installWait = $seams['install_wait'] ?? static function (int $seconds): void { sleep($seconds); };
            $waitStartedAt = $now();
            $activeInstalls = self::activeInstallIds($seams);
            while ($activeInstalls !== []) {
                if ($now() - $waitStartedAt >= self::INSTALL_WAIT_TIMEOUT_SECONDS) {
                    $message = 'An agent installation did not finish in time. No storage was changed. Try the migration again.';
                    $state['status'] = 'error';
                    $state['phase'] = 'error';
                    $state['current'] = '';
                    $state['message'] = $message;
                    $state['updated_at'] = $now();
                    self::writeStatus($state);
                    $result = ['status' => 'error', 'message' => $message, 'migrated' => []];
                    break;
                }
                $state['phase'] = 'waiting_for_installs';
                $state['current'] = implode(', ', $activeInstalls);
                $state['message'] = 'Waiting for agent installation to finish: ' . implode(', ', $activeInstalls) . '.';
                $state['updated_at'] = $now();
                self::writeStatus($state);
                $installWait(self::CONVERSION_RETRY_DELAY_SECONDS);
                $activeInstalls = self::activeInstallIds($seams);
            }

            if ($result === null) {
                $state['phase'] = 'converting';
                $state['current'] = '';
                $state['message'] = 'Preparing migration';
                $state['updated_at'] = $now();
                self::writeStatus($state);
                $busy = self::busyEntities($entities);
                if ($busy !== []) {
                    $message = 'Storage work is active for ' . implode(', ', $busy) . '. Wait for it to finish, then try the engine change again.';
                    $state['status'] = 'error';
                    $state['phase'] = 'preflight';
                    $state['current'] = $busy[0];
                    $state['message'] = $message;
                    $state['updated_at'] = $now();
                    self::writeStatus($state);
                    $result = ['status' => 'error', 'message' => $message, 'migrated' => []];
                } else {
                    $result = self::migrateLocked($from, $target, $entities, $seams, $save, $state);
                    $state['status'] = $result['status'] === 'ok' ? 'complete' : 'error';
                    $state['phase'] = $state['status'];
                    $state['current'] = '';
                    $state['message'] = (string)($result['message'] ?? 'Migration finished.');
                    $state['updated_at'] = time();
                    self::writeStatus($state);
                }
            }
        } finally {
            self::releaseLaunchGuard($lock);
        }
        FileStorage::releaseMigrationLock($migrationLock);
        $migrationLock = null;
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

    /** @return list<string> active install ids from the tray and live worker processes */
    private static function activeInstallIds(array $seams): array
    {
        if (isset($seams['active_installs']) && is_callable($seams['active_installs'])) {
            $ids = (array)($seams['active_installs'])();
        } else {
            $ids = [];
            if (class_exists(ActivityService::class)) {
                foreach (ActivityService::listAll() as $entry) {
                    $opId = (string)($entry['opId'] ?? '');
                    if (in_array((string)($entry['status'] ?? ''), ['running', 'stalled'], true)
                        && preg_match('/^install_([A-Za-z0-9._-]{1,128})$/', $opId, $m)) {
                        $ids[] = $m[1];
                    }
                }
            }
            if (class_exists(PendingAgentUpgradeService::class)
                && method_exists(PendingAgentUpgradeService::class, 'runningInstallIds')) {
                $ids = array_merge($ids, PendingAgentUpgradeService::runningInstallIds());
            }
        }
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn(string $id): bool => $id !== '')));
        sort($ids);
        return $ids;
    }

    /** @param array<int,array{type:string,id:string}> $entities */
    private static function migrateLocked(string $from, string $target, array $entities, array $seams, callable $save, array &$state): array
    {
        $convert = $seams['convert'] ?? static fn(string $type, string $id, string $mode, bool $direct): array => FileStorage::migrateBackend($type, $id, $mode, $direct);
        $wrap = $seams['wrap'] ?? static fn(string $f, string $t, callable $work) => FileStorage::migrateBackendPolicy($f, $t, $work);
        $retryWait = $seams['retry_wait'] ?? static function (int $seconds): void { sleep($seconds); };
        $migrated = [];
        $failure = null;
        $rollbackFailed = [];
        $result = $wrap($from, $target, function () use ($entities, $target, $from, $seams, $convert, $retryWait, &$migrated, &$failure, &$rollbackFailed, &$state): bool {
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
            $processed = 0;
            foreach ($entities as $entity) {
                $key = $entity['type'] . '/' . $entity['id'];
                $state['current'] = $key;
                $state['message'] = 'Converting ' . $state['current'];
                $state['entities'][$key]['status'] = 'converting';
                $state['updated_at'] = time();
                self::writeStatus($state);
                $retry = 0;
                do {
                    $r = $convert($entity['type'], $entity['id'], $target, $target === StorageBackendPolicyService::PASSTHROUGH);
                    $exit = (int)($r['exit'] ?? 1);
                    if ($exit !== 2 || $retry >= self::CONVERSION_RETRY_LIMIT) break;
                    $retry++;
                    // Exit 2 means another storage operation still owns the
                    // entity. Keep the migration active and retry that entity.
                    $state['entities'][$key]['status'] = 'waiting';
                    $state['entities'][$key]['retry'] = $retry;
                    $state['message'] = 'Waiting for storage to become available for ' . $key . ' (retry ' . $retry . ' of ' . self::CONVERSION_RETRY_LIMIT . ').';
                    $state['updated_at'] = time();
                    self::writeStatus($state);
                    $retryWait(self::CONVERSION_RETRY_DELAY_SECONDS);
                    $state['entities'][$key]['status'] = 'converting';
                    $state['message'] = 'Converting ' . $key;
                    $state['updated_at'] = time();
                    self::writeStatus($state);
                } while (true);
                $changed = !empty($r['changed']);
                $state['entities'][$key]['exit'] = $exit;
                $state['entities'][$key]['changed'] = $changed;
                if ($exit !== 0) {
                    $state['entities'][$key]['status'] = 'failed';
                    $diagnostic = self::cleanDiagnostic((string)($r['diagnostic'] ?? ''));
                    $reason = self::conversionFailureReason((string)($r['reason'] ?? ''), $diagnostic);
                    $failure = $entity['type'] . '/' . $entity['id'] . ': ' . $reason;
                    self::logConversionFailure($entity, $exit, (string)($r['reason'] ?? ''), $diagnostic, $target);
                    // Convert the entities that already moved back, newest first.
                    if ($changed) $migrated[] = $key;
                    foreach (array_reverse($migrated) as $done) {
                        $state['phase'] = 'rolling_back';
                        $state['current'] = $done;
                        $state['message'] = 'Restoring ' . $done . ' to ' . StorageBackendPolicyService::label($from);
                        $state['entities'][$done]['status'] = 'rolling_back';
                        $state['updated_at'] = time();
                        self::writeStatus($state);
                        [$t, $i] = explode('/', $done, 2);
                        $b = $convert($t, $i, $from, $from === StorageBackendPolicyService::PASSTHROUGH, true);
                        if ((int)($b['exit'] ?? 1) !== 0) {
                            $rollbackFailed[] = $done;
                            $state['entities'][$done]['status'] = 'rollback_failed';
                        } else {
                            $state['completed'] = max(0, (int)$state['completed'] - 1);
                            $state['entities'][$done]['status'] = 'rolled_back';
                        }
                        $state['updated_at'] = time();
                        self::writeStatus($state);
                    }
                    return false;
                }
                $state['entities'][$key]['status'] = $changed ? 'converted' : 'unchanged';
                if ($changed) $migrated[] = $key;
                $processed++;
                $state['completed'] = $processed;
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

    /** Keep the source engine and conversion outcome for each entity in the saved migration state. */
    private static function entityJournal(array $entities, string $from, string $target): array
    {
        $journal = [];
        foreach ($entities as $entity) {
            $key = (string)($entity['type'] ?? '') . '/' . (string)($entity['id'] ?? '');
            $journal[$key] = ['from' => $from, 'target' => $target, 'status' => 'pending', 'changed' => null];
        }
        return $journal;
    }

    /** Return a short reason that fits the saved status and Settings page. */
    private static function conversionFailureReason(string $reason, string $diagnostic): string
    {
        $text = strtolower($reason . ' ' . $diagnostic);
        if (preg_match('/no space left|insufficient space|not enough (?:free )?space|disk full|no_room|too_small|space .* needed/', $text)) {
            return 'not enough free space on the storage disk';
        }
        if (preg_match('/busy|in use|lock|already running|mount .*busy/', $text)) {
            return 'storage is in use';
        }
        if (str_contains($text, 'backend_migration_deferred')) {
            return 'conversion was deferred. Check the Debug Console for details.';
        }
        if (str_contains($text, 'space_estimate_unavailable')) {
            return 'free space for the new layer could not be checked';
        }
        if (str_contains($text, 'pt_dir_occupied')) {
            return 'the plain folder already contains data';
        }
        $plainReason = trim(self::cleanDiagnostic($reason));
        if (strlen($plainReason) > 20 && preg_match('/[\s.]/', $plainReason)) {
            return strlen($plainReason) > 180 ? substr($plainReason, 0, 177) . '...' : $plainReason;
        }
        return 'storage conversion failed. Check the Debug Console for details.';
    }

    /** Keep control characters and oversized shell output out of status and log records. */
    private static function cleanDiagnostic(string $diagnostic): string
    {
        $diagnostic = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $diagnostic);
        $lines = preg_split('/\r\n|\r|\n/', $diagnostic) ?: [];
        $diagnostic = trim(implode("\n", array_slice($lines, -20)));
        return strlen($diagnostic) > 2000 ? substr($diagnostic, -2000) : $diagnostic;
    }

    /** Keep the shell cause in both durable lifecycle history and the Debug Console. */
    private static function logConversionFailure(array $entity, int $exit, string $reason, string $diagnostic, string $target): void
    {
        $reason = substr(self::cleanDiagnostic($reason), 0, 256);
        $payload = [
            'entity' => (string)($entity['type'] ?? '') . '/' . (string)($entity['id'] ?? ''),
            'target' => $target,
            'exit' => $exit,
            'reason' => $reason,
            'diagnostic' => $diagnostic,
        ];
        if (class_exists(LifecycleLogService::class)) {
            LifecycleLogService::log(LifecycleLogService::LEVEL_ERROR, 'backend_migration', 'conversion_failed', $payload);
        }
        if (class_exists(LogService::class)) {
            $detail = $diagnostic !== '' ? $diagnostic : ($reason !== '' ? $reason : 'no diagnostic output');
            LogService::log('Storage migration failed for ' . $payload['entity'] . ' (exit ' . $exit . '): ' . $detail,
                LogService::LOG_ERROR, 'StorageBackendMigrationService');
        }
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
