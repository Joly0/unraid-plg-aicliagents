#!/usr/bin/env php
<?php
/**
 * Plugin-owned TLS listener for the Relay HTTP MCP transport (#113).
 *
 * Deliberately a separate process from the Unraid webGUI: Unraid offers plugins
 * no nginx include hook (#114), and patching its own locations.conf risks
 * taking down the whole webGUI. A separate listener means a failure here can
 * only affect Relay HTTP.
 *
 * All request logic lives in RelayHttpService; this file is only the socket
 * loop. Never write to STDOUT expecting a client to read it — diagnostics go to
 * STDERR, which the bring-up script redirects to the daemon log.
 *
 * Usage: relay-http-server.php <bind-address> <port>
 */
require_once __DIR__ . '/../includes/AICliAgentsManager.php';

use AICliAgents\Services\AgentRelayService;
use AICliAgents\Services\RelayHttpService;
use AICliAgents\Services\RelayPeerService;

// RELAY_LINKED_BOXES.md §2: the listener never calls out to another box. Two
// single-threaded listeners that call each other could wait for each other.
RelayPeerService::$inListener = true;

$bind = (string)($argv[1] ?? '0.0.0.0');
$port = (int)($argv[2] ?? 8237);

// Follow Unraid's own HTTPS configuration: prefer the CA-signed bundle its SSL
// vhost serves, so a remote client can verify this endpoint rather than being
// told to skip verification.
$profile = AgentRelayService::unraidSslProfile();
$cert = (string)$profile['cert'];
// Test seam for the linked-box twin harness: a second instance serves its own
// certificate. Unset in production.
$certOverride = getenv('AICLI_RELAY_TLS_CERT');
if ($certOverride !== false && $certOverride !== '') { $cert = $certOverride; $profile['source'] = 'override'; }
if ($cert === '') {
    fwrite(STDERR, "relay-http: no usable certificate+key bundle in /boot/config/ssl/certs — refusing to serve in plaintext\n");
    exit(1);
}

$context = stream_context_create(['ssl' => [
    'local_cert' => $cert,
    'verify_peer' => false,
    'verify_peer_name' => false,
    'allow_self_signed' => true,
]]);

$errno = 0; $errstr = '';
$server = @stream_socket_server("tcp://$bind:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "relay-http: cannot bind $bind:$port — $errstr ($errno)\n");
    exit(1);
}
fwrite(STDERR, "relay-http: listening on https://$bind:$port using $cert ({$profile['source']})\n");

// R-055 (#633): many connections at once, each non-blocking and each with an overall
// deadline (see relay-http-lib.php). One slow client no longer stops the others.
require_once __DIR__ . '/relay-http-lib.php';

/** @return array{status:int,body:string,headers?:array<string,string>} */
function relayHttpHandle(string $method, string $path, array $headers, string $body, string $peer): array {
    return RelayHttpService::handleRequest($method, $headers, $body, $peer, $path);
}

stream_set_blocking($server, false);
/** @var array<int,array> $conns open connections, by resource id */
$conns = [];

while (true) {
    $read = [$server];
    $write = [];
    foreach ($conns as $conn) {
        if ($conn['phase'] === 'write') $write[] = $conn['sock']; else $read[] = $conn['sock'];
    }
    $except = null;
    // A one-second wake-up lets the deadline sweep run when no socket is ready.
    $ready = @stream_select($read, $write, $except, 1);
    if ($ready === false) { usleep(100000); continue; }   // interrupted by a signal

    foreach ($read as $sock) {
        if ($sock === $server) {
            while (($new = @stream_socket_accept($server, 0, $peerName)) !== false) {
                if (count($conns) >= RELAY_HTTP_MAX_CONNECTIONS) { @fclose($new); continue; }
                stream_set_blocking($new, false);
                $conns[(int)$new] = relayHttpNewConn($new, relayHttpPeerAddress((string)$peerName), true, time());
            }
            continue;
        }
        relayHttpAdvance($conns, (int)$sock, 'relayHttpHandle');
    }
    foreach ($write as $sock) relayHttpAdvance($conns, (int)$sock, 'relayHttpHandle');
    relayHttpSweepDeadlines($conns, time());
}

