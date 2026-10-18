<?php
/**
 * <module_context>
 *     <name>CodexProjector</name>
 *     <description>Codex CLI MCP projection into ~/.codex/config.toml (OP #1362 / H-01).
 *     TOML vendor: managed servers are emitted inside a comment-fenced block
 *     (`# &gt;&gt;&gt; aicli-hub managed — do not edit inside &gt;&gt;&gt;` … `# &lt;&lt;&lt; aicli-hub &lt;&lt;&lt;`)
 *     appended at EOF / replaced in place on each run. TOML tables are position-
 *     independent so EOF placement is safe. Keys outside the fence are never touched.
 *     Drift hashes the full fence content (single managed key per file).</description>
 *     <dependencies>VendorProjector, Transpiler</dependencies>
 *     <constraints>Idempotent: projecting twice yields a byte-identical file.
 *     ~/.codex/auth.json is never read or written.</constraints>
 * </module_context>
 */

namespace AICliAgents\Services\Hub;

class CodexProjector extends VendorProjector {

    const FENCE_KEY   = 'aicli-hub-fence';
    const FENCE_OPEN  = '# >>> aicli-hub managed — do not edit inside >>>';
    const FENCE_CLOSE = '# <<< aicli-hub <<<';

    public function agentId(): string { return 'codex-cli'; }
    public function relPath(): string { return '.codex/config.toml'; }
    public function label(): string   { return 'Codex CLI'; }

    public function desired(array $servers): array {
        if (empty($servers)) return [];
        return [self::FENCE_KEY => self::FENCE_OPEN . "\n" . Transpiler::toCodexToml($servers, $this->headersKey()) . "\n" . self::FENCE_CLOSE];
    }

    /** TOML sub-table that carries a remote server's custom HTTP headers. */
    protected function headersKey(): string { return 'http_headers'; }

    public function current(string $file, array $keys): array {
        if (!in_array(self::FENCE_KEY, $keys, true) || !is_file($file)) return [];
        $block = self::extractFence((string)@file_get_contents($file), self::FENCE_OPEN, self::FENCE_CLOSE);
        return $block === null ? [] : [self::FENCE_KEY => $block];
    }

    /** Server names a TOML text defines as [mcp_servers.NAME...] tables or mcp_servers.NAME.key lines. */
    public static function tomlServerNames(string $text): array {
        $name = '(?:"([^"]+)"|\'([^\']+)\'|([A-Za-z0-9_-]+))';
        $names = [];
        $res = [
            '/^[ \t]*\[[ \t]*mcp_servers[ \t]*\.[ \t]*' . $name . '[ \t]*[\].]/m',
            '/^[ \t]*mcp_servers[ \t]*\.[ \t]*' . $name . '[ \t]*[.=]/m',
        ];
        foreach ($res as $re) {
            if (!preg_match_all($re, $text, $m, PREG_SET_ORDER)) continue;
            foreach ($m as $hit) { $hit = array_pad($hit, 4, ''); $names[] =$hit[1] !== '' ? $hit[1] : ($hit[2] !== '' ? $hit[2] : $hit[3]); }
        }
        return array_values(array_unique($names));
    }

    public function conflictsOutside(string $file, $value): array {
        if (!is_file($file) || !is_string($value)) return [];
        $raw = (string)@file_get_contents($file);
        $fence = self::extractFence($raw, self::FENCE_OPEN, self::FENCE_CLOSE);
        $outside = $fence === null ? $raw : str_replace($fence, '', $raw);
        $mine = self::tomlServerNames($value);
        $theirs = self::tomlServerNames($outside);
        $hit = array_values(array_intersect($mine, $theirs));
        sort($hit);
        return $hit;
    }

    public function write(string $file, array $set, array $remove): bool {
        $raw = is_file($file) ? (string)@file_get_contents($file) : '';
        if (array_key_exists(self::FENCE_KEY, $set)) {
            // A same-name table outside the fence would make a duplicate key; Codex then rejects the file (#642).
            if (!empty($this->conflictsOutside($file, (string)$set[self::FENCE_KEY]))) return false;
            $raw = self::replaceOrAppendFence($raw, (string)$set[self::FENCE_KEY], self::FENCE_OPEN, self::FENCE_CLOSE);
        } elseif (in_array(self::FENCE_KEY, $remove, true)) {
            $raw = self::stripFence($raw, self::FENCE_OPEN, self::FENCE_CLOSE);
        } else {
            return true; // nothing to do
        }
        return $this->atomicWrite($file, $raw);
    }

    // ---------- fence helpers (shared with GooseProjector via static calls) ----------

    /** Extract the fenced block (markers included), or null if absent. */
    public static function extractFence(string $raw, string $open, string $close): ?string {
        $re = '/^' . preg_quote($open, '/') . '\n.*?^' . preg_quote($close, '/') . '$/ms';
        return preg_match($re, $raw, $m) ? $m[0] : null;
    }

    /**
     * Replace an existing fence in place, or append at EOF (blank-line separated).
     * Deterministic: same inputs → same bytes (idempotency contract).
     */
    public static function replaceOrAppendFence(string $raw, string $block, string $open, string $close): string {
        $re = '/^' . preg_quote($open, '/') . '\n.*?^' . preg_quote($close, '/') . '$/ms';
        if (preg_match($re, $raw)) {
            return (string)preg_replace($re, self::quoteReplacement($block), $raw, 1);
        }
        $base = rtrim($raw, "\n");
        return ($base === '' ? '' : $base . "\n\n") . $block . "\n";
    }

    /** Remove the fence and its preceding blank separator; leaves the rest untouched. */
    public static function stripFence(string $raw, string $open, string $close): string {
        $re = '/\n?\n?^' . preg_quote($open, '/') . '\n.*?^' . preg_quote($close, '/') . '$\n?/ms';
        $out = (string)preg_replace($re, "\n", $raw, 1);
        $out = ltrim($out, "\n");
        return $out === '' ? '' : rtrim($out, "\n") . "\n";
    }

    /** Escape backslashes and $ in the replacement string for preg_replace. */
    protected static function quoteReplacement(string $s): string {
        return strtr($s, ['\\' => '\\\\', '$' => '\\$']);
    }
}
