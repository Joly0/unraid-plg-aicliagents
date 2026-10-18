<?php

namespace AICliAgents\Services;

/** Repairs Claude Code's persisted absolute plugin paths after a managed HOME move. */
class ClaudePluginPathService
{
    private const FILES = [
        '.claude/plugins/known_marketplaces.json',
        '.claude/plugins/installed_plugins.json',
    ];

    public static function repairHome(string $home): int
    {
        $changed = 0;
        foreach (self::FILES as $relative) {
            $file = rtrim($home, '/') . '/' . $relative;
            if (!is_file($file)) continue;
            // #666 R-156: decode to objects (not arrays) so an empty `"plugins": {}` is
            // written back as `{}`. An array decode turned it into `[]`, which Claude Code
            // rejects.
            $decoded = json_decode((string)@file_get_contents($file));
            if ((!is_array($decoded) && !($decoded instanceof \stdClass)) || json_last_error() !== JSON_ERROR_NONE) continue;
            [$rebased, $count] = self::rebaseDocument($decoded, $home);
            if ($count > 0 && AtomicWriteService::writeJson($file, $rebased)) {
                $changed += $count;
                // The file is replaced as root on a root web request; give it back to the home owner.
                AtomicWriteService::chownToHomeOwner($file);
            }
        }
        if ($changed > 0) {
            LogService::log("Rebased $changed stale Claude plugin path(s) into $home", LogService::LOG_INFO, 'ClaudePluginPathService');
        }
        return $changed;
    }

    /**
     * @param array|\stdClass $document a decoded JSON document (arrays, or objects for an exact round trip)
     * @return array{0: array|\stdClass, 1: int} the document with rebased paths, and how many were changed
     */
    public static function rebaseDocument($document, string $home): array
    {
        $count = 0;
        $walk = function ($value, ?string $key = null) use (&$walk, &$count, $home) {
            if (is_array($value)) {
                foreach ($value as $childKey => $child) $value[$childKey] = $walk($child, (string)$childKey);
                return $value;
            }
            if ($value instanceof \stdClass) {
                foreach (get_object_vars($value) as $childKey => $child) $value->{$childKey} = $walk($child, (string)$childKey);
                return $value;
            }
            if (!is_string($value) || !in_array($key, ['installLocation', 'installPath'], true)) return $value;
            $marker = '/.claude/plugins/';
            $offset = strpos($value, $marker);
            if ($offset === false) return $value;
            $expected = rtrim($home, '/') . substr($value, $offset);
            if ($expected !== $value) { $count++; return $expected; }
            return $value;
        };
        return [$walk($document), $count];
    }
}
