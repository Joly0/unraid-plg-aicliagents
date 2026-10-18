<?php
/**
 * <module_context>
 *     <name>ArgsService</name>
 *     <description>Two-tier CLI args storage: agent-wide defaults and per-workspace overrides. Server-side metachar validation.</description>
 *     <dependencies>ConfigService</dependencies>
 *     <constraints>Static methods only. Follows TmuxService path-hashing conventions.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services;

class ArgsService {

    // ---------- Paths ----------

    public static function getAgentArgsPath(string $agentId): string {
        return ConfigService::getUserStatePath() . "/args/args_agent_{$agentId}.json";
    }

    public static function getWorkspaceArgsPath(string $path, string $agentId): string {
        $hash = md5($path);
        return ConfigService::getUserStatePath() . "/args/args_ws_{$hash}_{$agentId}.json";
    }

    // ---------- Agent-level ----------

    public static function getAgentArgs(string $agentId): string {
        $file = self::getAgentArgsPath($agentId);
        if (!file_exists($file)) return '';
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? ($data['args'] ?? '') : '';
    }

    public static function saveAgentArgs(string $agentId, string $args): bool {
        $file = self::getAgentArgsPath($agentId);
        if ($args === '') {
            @unlink($file);
            return true;
        }
        return AtomicWriteService::writeJson($file, ['args' => $args]);
    }

    // ---------- Workspace-level ----------

    public static function getWorkspaceArgs(string $path, string $agentId): string {
        $file = self::getWorkspaceArgsPath($path, $agentId);
        if (!file_exists($file)) return '';
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? ($data['args'] ?? '') : '';
    }

    public static function saveWorkspaceArgs(string $path, string $agentId, string $args): bool {
        $file = self::getWorkspaceArgsPath($path, $agentId);
        if ($args === '') {
            @unlink($file);
            return true;
        }
        return AtomicWriteService::writeJson($file, ['args' => $args]);
    }

    // ---------- Effective (workspace wins) ----------

    public static function getEffectiveArgs(string $path, string $agentId): string {
        $ws = self::getWorkspaceArgs($path, $agentId);
        if ($ws !== '') return $ws;
        return self::getAgentArgs($agentId);
    }

    // ---------- Validation ----------

    /**
     * Returns [] on pass, or list of human-readable rejected char names on fail.
     */
    public static function validateArgs(string $args): array {
        $rejected = [];
        $checks = [
            ';'  => ';',
            '|'  => '|',
            '&'  => '&',
            '`'  => 'backtick',
            '$'  => '$',
            "\n" => 'newline',
            "\r" => 'CR',
            "\0" => 'null byte',
        ];
        foreach ($checks as $char => $label) {
            if (str_contains($args, $char)) {
                $rejected[] = $label;
            }
        }
        // #666 R-153: the launcher runs the text with `bash -c`. A redirect, a
        // subshell, a glob or a comment written OUTSIDE quotes changes what the
        // command does ("--foo > /boot/config/go" writes a file). Walk the text
        // like the shell does: quoted text is plain data, a backslash makes the
        // next character plain.
        $quote = '';
        $len = strlen($args);
        $outside = ['>' => '>', '<' => '<', '(' => '(', ')' => ')', '*' => '*'];
        for ($i = 0; $i < $len; $i++) {
            $c = $args[$i];
            if ($quote === "'") {
                if ($c === "'") $quote = '';
                continue;
            }
            if ($c === '\\') { $i++; continue; }
            if ($quote === '"') {
                if ($c === '"') $quote = '';
                continue;
            }
            if ($c === "'" || $c === '"') { $quote = $c; continue; }
            if (isset($outside[$c])) {
                $rejected[] = $outside[$c];
            } elseif ($c === '#' && ($i === 0 || ctype_space($args[$i - 1]))) {
                $rejected[] = '# (comment)';
            }
        }
        if ($quote !== '') {
            $rejected[] = 'unbalanced quote';
        }
        return array_values(array_unique($rejected));
    }
}
