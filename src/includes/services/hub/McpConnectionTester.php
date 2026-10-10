<?php
/**
 * <module_context>
 *     <name>McpConnectionTester</name>
 *     <description>Probes a remote MCP server the way an agent CLI would, so a wrong
 *     URL or token shows up in the hub editor before "Apply to agents". Streamable
 *     HTTP: POST initialize, then notifications/initialized and tools/list (tool
 *     count), then DELETE the session. Legacy SSE: GET until the `endpoint` event.
 *     Both JSON and text/event-stream responses are accepted.</description>
 *     <constraints>Never logs or returns header values. Bounded: 5 s connect,
 *     10 s per request, 1 MB per response.</constraints>
 * </module_context>
 */
namespace AICliAgents\Services\Hub;

class McpConnectionTester {

    const PROTOCOL_VERSION = '2025-06-18';
    const CONNECT_TIMEOUT_S = 5;
    const REQUEST_TIMEOUT_S = 10;
    const MAX_BODY = 1048576;

    /**
     * @param array $def normalized remote def with placeholders already resolved
     * @return array {ok: bool, message: string, status?: int, server?: string, tools?: int}
     */
    public static function test(array $def): array {
        $url = (string)($def['url'] ?? '');
        $headers = is_array($def['headers'] ?? null) ? $def['headers'] : [];
        if (($def['transport'] ?? '') === 'sse') return self::testSse($url, $headers);
        return self::testStreamable($url, $headers);
    }

    private static function testStreamable(string $url, array $headers): array {
        $init = self::post($url, $headers, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => self::PROTOCOL_VERSION, 'capabilities' => (object)[],
                         'clientInfo' => ['name' => 'aicliagents-connection-test', 'version' => '1']],
        ], 1);
        if ($init['error'] !== null) return ['ok' => false, 'message' => 'Unreachable: ' . $init['error']];
        if ($init['status'] < 200 || $init['status'] >= 300) return self::httpFailure($init, 'http');
        $res = $init['message']['result'] ?? null;
        if (!is_array($res)) {
            if (isset($init['message']['error']['message'])) {
                return ['ok' => false, 'status' => $init['status'],
                        'message' => 'The server refused initialize: ' . self::clip((string)$init['message']['error']['message'])];
            }
            return ['ok' => false, 'status' => $init['status'],
                    'message' => 'HTTP ' . $init['status'] . ' but the reply is not an MCP initialize result. Is this the MCP endpoint URL?'];
        }

        $info = $res['serverInfo'] ?? [];
        $server = trim((string)($info['name'] ?? 'unnamed server') . ' ' . (string)($info['version'] ?? ''));
        $out = ['ok' => true, 'status' => $init['status'], 'server' => $server];

        // Follow-up requests carry the session and negotiated version, as a CLI would.
        $follow = $headers;
        if ($init['session'] !== '') $follow['Mcp-Session-Id'] = $init['session'];
        $follow['MCP-Protocol-Version'] = (string)($res['protocolVersion'] ?? self::PROTOCOL_VERSION);
        self::post($url, $follow, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], null);
        $list = self::post($url, $follow, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], 2);
        if ($list['error'] === null && isset($list['message']['result']['tools']) && is_array($list['message']['result']['tools'])) {
            $out['tools'] = count($list['message']['result']['tools']);
        }
        if ($init['session'] !== '') self::request('DELETE', $url, $follow, null, null);

        $out['message'] = 'Connected to ' . $server
            . (isset($out['tools']) ? ', ' . $out['tools'] . ($out['tools'] === 1 ? ' tool' : ' tools') : '') . '.';
        return $out;
    }

    private static function testSse(string $url, array $headers): array {
        $r = self::request('GET', $url, $headers + ['Accept' => 'text/event-stream'], null, 'endpoint');
        if ($r['error'] !== null && !$r['found']) return ['ok' => false, 'message' => 'Unreachable: ' . $r['error']];
        if ($r['status'] < 200 || $r['status'] >= 300) return self::httpFailure($r, 'sse');
        if (!$r['found']) {
            return ['ok' => false, 'status' => $r['status'],
                    'message' => 'HTTP ' . $r['status'] . ' but no SSE endpoint event arrived. If the server uses streamable HTTP, switch the transport to http.'];
        }
        return ['ok' => true, 'status' => $r['status'], 'message' => 'Connected (legacy SSE endpoint answered).'];
    }

    /** Explain a non-2xx answer in terms of what the user should change. */
    private static function httpFailure(array $r, string $transport): array {
        $s = $r['status'];
        $out = ['ok' => false, 'status' => $s];
        if ($s === 401) {
            $oauth = stripos($r['wwwAuth'], 'resource_metadata') !== false;
            $out['message'] = 'HTTP 401: the server rejected the request as unauthenticated. Check the token'
                . ($oauth ? '. The server also offers an OAuth login, which agent CLIs run themselves on first use.' : '.');
        } elseif ($s === 403) {
            $out['message'] = 'HTTP 403: authenticated, but not allowed. The token may lack a permission or scope.';
        } elseif ($s >= 300 && $s < 400) {
            $out['message'] = "HTTP $s: the server redirects" . ($r['location'] !== '' ? ' to ' . self::clip($r['location']) : '')
                . '. Enter the final MCP endpoint URL instead; agent CLIs may not follow redirects.';
        } elseif ($s === 404 || $s === 405) {
            $out['message'] = "HTTP $s: nothing answers MCP at this URL. Check the path"
                . ($transport === 'sse' ? ', or switch the transport to http for a newer server.' : ', or try the sse transport for an older server.');
        } else {
            $out['message'] = "HTTP $s: " . (self::clip($r['body']) ?: 'unexpected answer from the server.');
        }
        return $out;
    }

    /** JSON-RPC POST; $wantId stops reading once that response id arrives (SSE). */
    private static function post(string $url, array $headers, array $msg, ?int $wantId): array {
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json, text/event-stream';
        return self::request('POST', $url, $headers, json_encode($msg), $wantId);
    }

    /**
     * @param int|string|null $want  int: JSON-RPC id to wait for; 'endpoint': SSE event name
     * @return array {status, error, body, message, session, wwwAuth, found}
     */
    private static function request(string $method, string $url, array $headers, ?string $body, $want): array {
        $state = ['buf' => '', 'message' => null, 'found' => false, 'headers' => []];
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) $lines[] = $k . ': ' . $v;
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_S,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_S,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$state) {
                $p = strpos($line, ':');
                if ($p !== false) $state['headers'][strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$state, $want) {
                $state['buf'] .= $chunk;
                if (strlen($state['buf']) > self::MAX_BODY) return -1;
                if ($want !== null && self::scan($state, $want)) return -1; // got what we need, stop the stream
                return strlen($chunk);
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = ($ok === false && !$state['found']) ? curl_error($ch) : null;
        curl_close($ch);
        if ($want !== null && !$state['found']) self::scan($state, $want);
        return ['status' => $status, 'error' => ($err !== null && $err !== '') ? $err : null,
                'body' => $state['buf'], 'message' => $state['message'], 'found' => $state['found'],
                'session' => (string)($state['headers']['mcp-session-id'] ?? ''),
                'wwwAuth' => (string)($state['headers']['www-authenticate'] ?? ''),
                'location' => (string)($state['headers']['location'] ?? '')];
    }

    /** Find the wanted JSON-RPC response (plain JSON or SSE data) or SSE event in the buffer. */
    private static function scan(array &$state, $want): bool {
        $buf = $state['buf'];
        if (is_int($want)) {
            $j = json_decode($buf, true);
            if (is_array($j) && ($j['id'] ?? null) === $want) { $state['message'] = $j; return $state['found'] = true; }
            foreach (preg_split('/\r?\n/', $buf) as $line) {
                if (strncmp($line, 'data:', 5) !== 0) continue;
                $j = json_decode(trim(substr($line, 5)), true);
                if (is_array($j) && ($j['id'] ?? null) === $want) { $state['message'] = $j; return $state['found'] = true; }
            }
            return false;
        }
        return $state['found'] = (bool)preg_match('/^event:\s*' . preg_quote((string)$want, '/') . '\s*$/m', $buf);
    }

    private static function clip(string $s): string {
        $s = trim(preg_replace('/\s+/', ' ', strip_tags($s)));
        return strlen($s) > 200 ? substr($s, 0, 200) . '...' : $s;
    }
}
