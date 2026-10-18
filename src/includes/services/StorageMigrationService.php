<?php
/**
 * <module_context>
 *     <name>StorageMigrationService</name>
 *     <description>Resizing and migration of AICliAgents storage volumes.</description>
 *     <dependencies>LogService, ConfigService, StorageMountService, UtilityService, TaskService</dependencies>
 *     <constraints>Under 150 lines. Delegates complex operations to shell scripts.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class StorageMigrationService {
    /**
     * Consolidates layers for an entity (Agent or Home).
     */
    public static function consolidateEntity($type, $id) {
        LogService::log("Triggering consolidation for $type: $id", LogService::LOG_INFO, "StorageMigrationService");
        return StorageMountService::consolidate($type, $id);
    }

    /**
     * Legacy expand/shrink are no longer needed for SquashFS.
     * These now return true or perform consolidation if appropriate.
     */
    public static function expandStorage($inc = "256M") {
        LogService::log("Expansion requested. SquashFS uses dynamic ZRAM, no expansion needed.", LogService::LOG_INFO, "StorageMigrationService");
        return true;
    }

    public static function expandHomeStorage($user, $inc = "128M") {
        LogService::log("Expansion requested for $user. SquashFS uses dynamic ZRAM.", LogService::LOG_INFO, "StorageMigrationService");
        return true;
    }

    public static function shrinkStorage() {
        return self::consolidateEntity('agent', 'all'); // Placeholder
    }

    public static function shrinkHomeStorage($user) {
        return self::consolidateEntity('home', $user);
    }

    /**
     * #585: the one rule for a wipe target. The type is agent or home. The id is
     * one path-safe name: no slash, no glob character, not "." or "..". An agent
     * id also has to match the agent id shape. Used by the handler AND by
     * nuclearRebuild(), so a direct caller is refused as well.
     */
    public static function isValidWipeTarget($type, $id): bool {
        if (!is_string($type) || !is_string($id)) return false;
        if ($type === 'agent') return (bool)preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id);
        if ($type === 'home')  return (bool)preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}$/', $id);
        return false;
    }

    /**
     * #585: does this layer file belong to EXACTLY this entity? The old glob
     * "{type}_{id}_*" also took the layers of a longer id that starts with the
     * same text plus an underscore (home "a" matched "home_a_b_..."). The part
     * after the id must start with a layer kind (consolidated, delta, vol, v<N>).
     */
    public static function layerFileBelongsTo(string $file, string $type, string $id): bool {
        $name = basename($file);
        $prefix = "{$type}_{$id}_";
        if (strncmp($name, $prefix, strlen($prefix)) !== 0) return false;
        return (bool)preg_match('/^(consolidated|delta|vol\d*|v\d+)([_.]|$)/', substr($name, strlen($prefix)));
    }

    /** @return string[] the layer files of exactly this entity under $persistPath */
    private static function entityLayerFiles(string $persistPath, string $type, string $id): array {
        $files = [];
        foreach ((array)glob(rtrim($persistPath, '/') . "/{$type}_*.sqsh") as $f) {
            if (self::layerFileBelongsTo($f, $type, $id)) $files[] = $f;
        }
        return $files;
    }

    public static function nuclearRebuild($type, $id) {
        if (!self::isValidWipeTarget($type, $id)) {
            LogService::log("Wipe refused: invalid storage type or id.", LogService::LOG_WARN, "StorageMigrationService");
            return false;
        }
        $config = ConfigService::getConfig();
        $persistPath = ($type === 'home')
            ? ($config['home_storage_path']  ?? "/boot/config/plugins/unraid-aicliagents")
            : ($config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents");

        $flashOld = 0;
        foreach (self::entityLayerFiles($persistPath, $type, $id) as $f) $flashOld += (int)@filesize($f);
        
        $upperDir = "/tmp/unraid-aicliagents/zram_upper/{$type}s/{$id}/upper";
        $ramOld = 0;
        if (is_dir($upperDir)) {
            $io = shell_exec("du -sm " . escapeshellarg($upperDir) . " 2>/dev/null | cut -f1");
            $ramOld = (int)trim($io);
        }

        LogService::log("Initiating nuclear wipe sequence for $type $id...", LogService::LOG_WARN, "StorageMigrationService");
        
        // 1. Unmount
        // SIDE_BY_SIDE_AGENT_INSTALLS.md Phase 1: route the agent-mount arm through
        // AgentRegistry::agentPath() instead of repeating the literal AGENT_BASE path.
        $mnt = ($type === 'agent') ? AgentRegistry::agentPath($id) : UtilityService::getWorkDir($id) . "/home";
        // #373: name the uppers to wipe BEFORE the unmount, while the kernel
        // still shows the live mount's own upperdir/workdir.
        $wipeDirs = self::rebuildWipeDirs((string)$type, (string)$id, (string)$persistPath, $mnt);
        // #512: a real unmount only. A busy mount stops the wipe: deleting its
        // layers would leave their space held by the kernel until a reboot,
        // and deleting its upper would remove files a session still uses.
        if (!StorageMountService::unmountReal($mnt)) {
            LogService::log("Wipe of $type $id stopped: $mnt is still in use. Close its sessions, then try again.", LogService::LOG_WARN, "StorageMigrationService");
            return false;
        }

        // 2. Wipe Flash
        foreach (self::entityLayerFiles($persistPath, $type, $id) as $f) @unlink($f);

        // 3. Wipe the upper(s): zram base plus, for a home, the live disk upper.
        foreach ($wipeDirs as $dir) {
            // #585: defence in depth. A wipe dir must end in this entity's own
            // name, so no odd input can point rm -rf at a parent folder.
            if (basename($dir) !== (string)$id) continue;
            if (is_dir($dir)) exec("rm -rf " . escapeshellarg($dir));
        }

        // #585: the layers are gone, so the manifest must not still list them
        // (the next boot sweep would report total_loss).
        LayerManifestService::removeEntity("$type/$id");

        $flashMB = round($flashOld / 1024 / 1024, 2);
        LogService::log("Successfully wiped all storage for $id. Reclaimed $flashMB MB of Flash space and $ramOld MB of RAM.", LogService::LOG_INFO, "StorageMigrationService");
        
        return true;
    }

    /**
     * #373 (docs/specs/HOME_STORAGE_LIFECYCLE.md "the live mount wins"): the
     * directories a nuclear rebuild must remove. Always the zram base of the
     * entity. For a home also the live mount's own upperdir and workdir (they
     * sit on disk when the home runs with a disk upper) and the policy disk
     * upper/work for the persist path. A live path is used only when it has the
     * shape of an upper/work dir of THIS entity, so an odd mount option can
     * never make the rebuild delete an unrelated folder. Pure: the mount table
     * and zram base are arguments.
     *
     * @return string[]
     */
    public static function rebuildWipeDirs(
        string $type,
        string $id,
        string $persistPath,
        string $mnt,
        ?string $mounts = null,
        string $zramBase = StoragePathResolver::ZRAM_BASE
    ): array {
        $dirs = [rtrim($zramBase, '/') . "/{$type}s/$id"];
        if ($type !== 'home') return $dirs;
        $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $id) ?: 'unknown';
        if ($mounts === null) {
            $mounts = is_readable('/proc/mounts') ? (string)@file_get_contents('/proc/mounts') : '';
        }
        foreach (['upperdir' => '_upper', 'workdir' => '_work'] as $opt => $dirName) {
            $live = StorageMountService::overlayOptionAt($mounts, rtrim($mnt, '/'), $opt);
            if ($live !== null && preg_match('#/' . $dirName . '/homes/' . preg_quote($safe, '#') . '/?$#', $live)) {
                $dirs[] = rtrim($live, '/');
            }
            if ($persistPath !== '') {
                $dirs[] = rtrim($persistPath, '/') . "/$dirName/homes/$safe";
            }
        }
        return array_values(array_unique($dirs));
    }

    /**
     * Purges all migration artifacts (migrated_legacy_data/, .img.migrated, .migrated folders).
     * D-313: Version-Aware 'Analyze & Rescue' for incorrect SquashFS volumes.
     */
    public static function purgeArtifacts() {
        LogService::log("PURGE: Starting 'Analyze & Rescue' for storage artifacts.", LogService::LOG_WARN, "StorageMigrationService");
        $config = ConfigService::getConfig();
        $persistPath = $config['agent_storage_path'] ?? "/boot/config/plugins/unraid-aicliagents";
        $pluginBase = "/boot/config/plugins/unraid-aicliagents";
        $envPath = "$pluginBase/envs";
        if (!is_dir($envPath)) @mkdir($envPath, 0755, true);

        // 1. Clean up migration artifacts (current + legacy naming conventions)
        $searchPaths = [$persistPath, "$persistPath/persistence", $pluginBase];
        $seen = [];
        foreach ($searchPaths as $path) {
            if (!is_dir($path)) continue;
            $real = realpath($path);
            if (isset($seen[$real])) continue;
            $seen[$real] = true;

            // Current: migrated_legacy_data/ directory
            $legacyDir = "$path/migrated_legacy_data";
            if (is_dir($legacyDir)) {
                LogService::log("Purging migrated_legacy_data/ in $path", LogService::LOG_INFO, "StorageMigrationService");
                exec("rm -rf " . escapeshellarg($legacyDir));
            }
            // Old-style: .img.migrated files and .migrated.* directories
            exec("rm -f " . escapeshellarg($path) . "/*.img.migrated 2>/dev/null");
            exec("rm -rf " . escapeshellarg($path) . "/*.migrated.* 2>/dev/null");
        }

        // 2. Analyze & Rescue misplaced SquashFS files (v44 artifacts)
        $mnt = "/tmp/unraid-aicliagents/mnt/rescue";
        if (!is_dir($mnt)) @mkdir($mnt, 0755, true);

        foreach (glob("$persistPath/home_*.sqsh") as $file) {
            $name = basename($file);
            if (!preg_match('/home_(.*?)_v1_vol1\.sqsh/', $name, $m)) continue;
            $user = $m[1];

            // If it matches a system directory name, it's an artifact from v44 over-eager migration
            $denylist = ['envs', 'persistence', 'pkg-cache', 'test-fixtures', 'includes', 'scripts', 'assets'];
            if (in_array($user, $denylist)) {
                LogService::log("Artifact found: $name. Attempting data rescue...", LogService::LOG_INFO, "StorageMigrationService");
                exec("mount -o loop,ro " . escapeshellarg($file) . " " . escapeshellarg($mnt) . " 2>/dev/null", $out, $res);
                if ($res === 0) {
                    // Rescue any .json env files back to the raw envs/ directory
                    $rescueCount = 0;
                    foreach (glob("$mnt/*.json") as $json) {
                        $dest = $envPath . "/" . basename($json);
                        if (!file_exists($dest)) {
                            copy($json, $dest);
                            $rescueCount++;
                        }
                    }
                    if ($rescueCount > 0) LogService::log("Rescued $rescueCount file(s) from $name to $envPath", LogService::LOG_INFO, "StorageMigrationService");
                    // #512: a real unmount only; a busy image is kept, not purged.
                    if (!StorageMountService::unmountReal($mnt)) continue;
                }
                LogService::log("Purging artifact: $name", LogService::LOG_INFO, "StorageMigrationService");
                @unlink($file);
            }
        }
        
        LogService::log("Legacy artifact purge complete.", LogService::LOG_INFO, "StorageMigrationService");
        return true;
    }

    /**
     * Migrates home persistence storage.
     */
    public static function migratePersistence($newPath) {
        if (empty($newPath)) return false;
        if (!StorageMountService::isBackingMountAvailable((string)$newPath)) {
            LogService::log("Refusing home migration: target storage is not mounted: $newPath", LogService::LOG_WARN, "StorageMigrationService");
            return false;
        }
        $config = ConfigService::getConfig();
        $oldPath = $config['home_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents/persistence';
        if ($newPath === $oldPath) return true;

        LogService::log("Migrating home persistence from $oldPath to $newPath...", LogService::LOG_INFO, "StorageMigrationService");
        if (!is_dir($newPath)) @mkdir($newPath, 0755, true);
        if (is_dir($oldPath)) exec("rsync -a " . escapeshellarg($oldPath . "/") . " " . escapeshellarg($newPath . "/"));
        return ConfigService::saveConfig(['home_storage_path' => $newPath]);
    }

    /**
     * Migrates agent binary storage path.
     */
    public static function migrateAgentStorage($newPath) {
        if (empty($newPath)) return false;
        if (!StorageMountService::isBackingMountAvailable((string)$newPath)) {
            LogService::log("Refusing agent migration: target storage is not mounted: $newPath", LogService::LOG_WARN, "StorageMigrationService");
            return false;
        }
        $config = ConfigService::getConfig();
        $oldPath = $config['agent_storage_path'] ?? '/boot/config/plugins/unraid-aicliagents';
        if ($newPath === $oldPath) return true;

        LogService::log("Migrating agent storage from $oldPath to $newPath...", LogService::LOG_INFO, "StorageMigrationService");
        if (!is_dir($newPath)) @mkdir($newPath, 0755, true);
        
        // Move all SquashFS files to the new location
        if (is_dir($oldPath)) {
            exec("mv " . escapeshellarg($oldPath) . "/*.sqsh " . escapeshellarg($newPath) . "/ 2>/dev/null");
        }
        
        return ConfigService::saveConfig(['agent_storage_path' => $newPath]);
    }
}
