<?php
/**
 * <module_context>
 *     <name>NotebookHandler</name>
 *     <description>AJAX handler for the Notebook drawer (docs/specs/ARTEFACTS.md,
 *     docs/specs/ARTEFACTS_CONTRACT.md section 5): every notebook_* action. Reads: info, list,
 *     get, search, history, comments, backlinks, templates, wiki_index. Writes: lock (a person locks a page so agents may only comment), create, save, patch, move, pin,
 *     archive, restore, item_state, answer, comment, comment_resolve, mark_read, secret_set,
 *     secret_reveal, secret_clear, check_url, asset_upload, import, share, read_aloud. Two RAW
 *     actions stream bytes instead of JSON: notebook_asset (an image, video, audio or PDF inside the
 *     notebook's assets/ folder) and notebook_export (a download), dispatched through rawAsset() and
 *     rawExport().</description>
 *     <dependencies>NotebookService (all logic), NotebookFormat (reply shaping only).</dependencies>
 *     <constraints>CSRF is validated once, centrally, by the AJAX dispatcher (AICliAjax.php).
 *     notebook_asset is the ONLY action exempt from CSRF (a media tag cannot send a token); it is
 *     read only and path validated (NotebookService::assetPath: inside assets/, no symlink escape).
 *     notebook_export needs the token, so the link that opens it carries csrf_token in its query,
 *     like the bundle download. A browser author is always {kind: human}. Replies are flat:
 *     {status:'ok', ...fields} or {status:'error', message, currentVersion?, needsConfirm?}.
 *     A secret value appears in exactly one reply, notebook_secret_reveal, with no-store caching,
 *     and never in a log line. No action throws into the dispatcher.</constraints>
 * </module_context>
 */

namespace AICliAgents\Handlers;

use AICliAgents\Services\NotebookService;
use AICliAgents\Services\NotebookNotifyService;

require_once __DIR__ . '/../services/NotebookService.php';
require_once __DIR__ . '/../services/NotebookNotifyService.php';

class NotebookHandler
{
    private const RAW_ACTIONS = ['notebook_asset', 'notebook_export'];

    public static function handle($action, $id): ?array
    {
        if (!in_array($action, self::actions(), true)) return null;
        if (in_array($action, self::RAW_ACTIONS, true)) return null; // raw stream, dispatched via rawAsset()/rawExport()
        // Settings > Notebook > Notebook = Off: only notebook_info still answers, so the drawer can read
        // the setting and hide itself. Every other action refuses with one clear sentence.
        if ($action !== 'notebook_info' && (string)(\AICliAgents\Services\ConfigService::getConfig()['notebook_enabled'] ?? '1') !== '1') {
            return self::error('The Notebook is turned off. A person turns it on in Settings > Notebook.');
        }
        // The Wiki page (docs/specs/NOTEBOOK_WIKI_PAGE.md R1): the project list needs no workspace id, and the page
        // setting must be on. Both checks come before any note data is touched.
        if ($action === 'notebook_wiki_projects') {
            if ((string)(\AICliAgents\Services\ConfigService::getConfig()['wiki_page_enabled'] ?? '0') !== '1') {
                return self::error('The Wiki page is turned off. A person turns it on in Settings > Notebook.');
            }
            return ['status' => 'ok', 'projects' => NotebookService::wikiProjects()];
        }
        try {
            $ctx = NotebookService::contextFor(self::str('workspaceId'));
            $aid = self::str('id');
            $author = self::humanAuthor($ctx);
            switch ($action) {
                // ---- reads ----
                case 'notebook_info':
                    return ['status' => 'ok'] + NotebookService::info($ctx);
                case 'notebook_list':
                    return self::reply(NotebookService::listArtefacts($ctx, [
                        'area' => self::str('area'), 'type' => self::str('type'), 'tag' => self::str('tag'),
                        'q' => self::str('q'), 'madeBy' => self::str('madeBy'), 'changedSince' => self::str('changedSince'),
                    ]));
                case 'notebook_get':       return self::reply(NotebookService::get($ctx, $aid));
                case 'notebook_search':    return self::reply(NotebookService::search($ctx, self::str('q')));
                case 'notebook_history':   return self::reply(NotebookService::history($ctx, $aid));
                case 'notebook_comments':  return self::reply(NotebookService::comments($ctx, $aid));
                case 'notebook_backlinks': return self::reply(NotebookService::backlinks($ctx, $aid));
                case 'notebook_templates': return self::reply(NotebookService::templates());
                case 'notebook_wiki_index': return self::reply(NotebookService::wikiIndex($ctx));

                // ---- writes ----
                case 'notebook_create': {
                    $p = [
                        'title' => self::str('title'), 'type' => self::str('type') ?: 'markdown',
                        'content' => (string)($_REQUEST['content'] ?? ''), 'area' => self::str('area') ?: 'inbox',
                        'tags' => self::json('tags') ?? [], 'template' => self::str('template'),
                        'needsYou' => self::flag('needsYou'), 'confirmSecret' => self::flag('confirmSecret'),
                        'runOf' => self::str('runOf'), 'username' => self::str('username'), 'notes' => self::str('notes'),
                    ];
                    if (($u = self::json('urls')) !== null) $p['urls'] = $u;
                    if (($s = self::json('secrets')) !== null) $p['secrets'] = $s;
                    if (self::str('template') === '') unset($p['template']);
                    if (self::str('runOf') === '') unset($p['runOf']);
                    return self::reply(NotebookService::create($ctx, $p, $author));
                }
                case 'notebook_save':
                    if (self::str('expectedVersion') === '') return self::error('notebook_save needs expectedVersion (the version you read), so a newer change is not overwritten.');
                    return self::reply(NotebookService::save($ctx, $aid, (string)($_REQUEST['raw'] ?? ''), self::str('expectedVersion'), $author));
                case 'notebook_patch': {
                    $ops = self::json('ops');
                    if (!is_array($ops)) return self::error('ops must be a JSON list of operations.');
                    $ev = self::str('expectedVersion');
                    return self::reply(NotebookService::patch($ctx, $aid, $ops, $ev === '' ? null : $ev, $author));
                }
                case 'notebook_move':      return self::reply(NotebookService::move($ctx, $aid, self::str('area'), $author));
                case 'notebook_pin':       return self::reply(NotebookService::pin($ctx, $aid, self::flag('pinned'), $author));
                case 'notebook_lock':      return self::reply(NotebookService::lock($ctx, $aid, self::flag('locked'), $author));
                case 'notebook_archive':   return self::reply(NotebookService::archive($ctx, $aid, $author));
                case 'notebook_remove': {
                    $ev = self::str('expectedVersion');
                    return self::reply(NotebookService::remove($ctx, $aid, $ev === '' ? null : $ev, $author));
                }
                case 'notebook_request_refresh':
                    return self::reply(NotebookService::requestRefresh($ctx, $aid, (string)($_REQUEST['note'] ?? '')));
                case 'notebook_restore': {
                    $area = self::str('area');
                    return self::reply(NotebookService::restore($ctx, $aid, $area === '' ? null : $area, $author));
                }
                case 'notebook_item_state': {
                    $note = array_key_exists('note', $_REQUEST) ? (string)$_REQUEST['note'] : null;
                    $before = NotebookNotifyService::snapshot($ctx, $aid);
                    $r = NotebookService::itemState($ctx, $aid, self::str('item'), self::str('result'), $note, $author);
                    return self::reply(NotebookNotifyService::attach($action, $ctx, $aid, $before, $r));
                }
                case 'notebook_answer': {
                    $note = array_key_exists('note', $_REQUEST) ? (string)$_REQUEST['note'] : null;
                    $raw = (string)($_REQUEST['value'] ?? '');
                    $val = json_decode($raw, true);
                    if (json_last_error() !== JSON_ERROR_NONE) $val = $raw;
                    $before = NotebookNotifyService::snapshot($ctx, $aid);
                    $r = NotebookService::answer($ctx, $aid, self::str('question'), $val, $note, $author);
                    return self::reply(NotebookNotifyService::attach($action, $ctx, $aid, $before, $r));
                }
                case 'notebook_comment': {
                    $parent = self::str('parent');
                    $r = NotebookService::addComment($ctx, $aid, self::str('kind'), self::str('ref'), (string)($_REQUEST['text'] ?? ''), $parent === '' ? null : $parent, $author);
                    return self::reply(NotebookNotifyService::attach($action, $ctx, $aid, [], $r));
                }
                case 'notebook_comment_resolve':
                    return self::reply(NotebookService::resolveComment($ctx, $aid, self::str('commentId'), self::flag('resolved'), $author));
                case 'notebook_mark_read': {
                    $all = self::flag('all');
                    return self::reply(NotebookService::markRead($ctx, $all ? null : $aid, $all));
                }
                case 'notebook_secret_set':
                    $r = NotebookService::secretSet($ctx, $aid, self::str('field'), (string)($_REQUEST['value'] ?? ''), $author);
                    return self::reply(NotebookNotifyService::attach($action, $ctx, $aid, [], $r));
                case 'notebook_secret_reveal': {
                    $r = NotebookService::secretReveal($ctx, $aid, self::str('field'), $author);
                    if (!isset($r['error']) && !headers_sent()) header('Cache-Control: no-store');
                    return self::reply($r);
                }
                case 'notebook_secret_clear':
                    return self::reply(NotebookService::secretClear($ctx, $aid, self::str('field'), $author));
                case 'notebook_check_url':
                    return self::reply(NotebookService::checkUrl($ctx, $aid, (string)($_REQUEST['url'] ?? '')));
                case 'notebook_asset_upload': return self::assetUpload($ctx, $aid, $author);
                case 'notebook_import': {
                    $bytes = base64_decode((string)($_REQUEST['data'] ?? ''), true);
                    if ($bytes === false || $bytes === '') return self::error('The file data is missing or is not valid base64.');
                    return self::reply(NotebookService::import($ctx, $bytes, self::str('filename') ?: self::str('name'), $author));
                }
                case 'notebook_share':
                    return self::reply(NotebookService::share($ctx, $aid, self::str('targetWorkspaceId'), $author));
                case 'notebook_read_plan': {
                    $ref = self::str('ref');
                    return self::reply(NotebookService::readPlan($ctx, $aid, $ref === '' ? null : $ref, $author));
                }
                case 'notebook_read_clip': {
                    $ref = self::str('ref');
                    return self::reply(NotebookService::readClip($ctx, $aid, $ref === '' ? null : $ref, (int)($_REQUEST['index'] ?? -1), self::str('etag')));
                }
                case 'notebook_read_aloud': {
                    $ref = self::str('ref');
                    return self::reply(NotebookService::readAloud($ctx, $aid, $ref === '' ? null : $ref, $author));
                }
            }
            return null;
        } catch (\Throwable $e) {
            if (function_exists('aicli_log')) aicli_log("$action failed: " . $e->getMessage(), defined('AICLI_LOG_ERROR') ? AICLI_LOG_ERROR : 0, 'NotebookHandler');
            return self::error('The notebook request failed.');
        }
    }

    /** Actions handled by this handler. */
    public static function actions(): array
    {
        return [
            'notebook_info', 'notebook_list', 'notebook_get', 'notebook_search', 'notebook_history', 'notebook_comments',
            'notebook_backlinks', 'notebook_templates', 'notebook_wiki_index', 'notebook_asset', 'notebook_export', 'notebook_lock', 'notebook_remove',
            'notebook_create', 'notebook_save', 'notebook_patch', 'notebook_move', 'notebook_pin', 'notebook_archive',
            'notebook_restore', 'notebook_item_state', 'notebook_answer', 'notebook_comment', 'notebook_comment_resolve',
            'notebook_mark_read', 'notebook_secret_set', 'notebook_secret_reveal', 'notebook_secret_clear',
            'notebook_check_url', 'notebook_asset_upload', 'notebook_import', 'notebook_share', 'notebook_read_aloud',
            'notebook_read_plan', 'notebook_read_clip', 'notebook_request_refresh', 'notebook_wiki_projects',
        ];
    }

    // ---------- RAW streams ----------

    /** notebook_asset: stream one file from <notebook>/assets/. GET workspaceId, path. */
    public static function rawAsset(): void
    {
        $ctx = NotebookService::contextFor(self::str('workspaceId'));
        $abs = NotebookService::assetPath($ctx, self::str('path'));
        if ($abs === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            return;
        }
        $size = (int)filesize($abs);
        $mime = NotebookService::assetMime($abs);
        $start = 0;
        $end = $size - 1;
        $partial = false;
        if ($size > 0 && isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim((string)$_SERVER['HTTP_RANGE']), $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); }
            else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }
            $partial = true;
        }
        set_time_limit(120);
        header('Content-Type: ' . $mime);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        // A directly opened SVG or PDF must not run script or reach the plugin origin.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; media-src 'self'; sandbox");
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._\-]/', '_', basename($abs)) . '"');
        header('Accept-Ranges: bytes');
        if ($partial) {
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: ' . ($size === 0 ? 0 : ($end - $start + 1)));
        $fh = fopen($abs, 'rb');
        if ($fh === false) return;
        if ($start > 0) fseek($fh, $start);
        $left = $size === 0 ? 0 : $end - $start + 1;
        while ($left > 0 && !feof($fh)) {
            $chunk = fread($fh, (int)min(65536, $left));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $left -= strlen($chunk);
            if (connection_aborted()) break;
        }
        fclose($fh);
    }

    /** notebook_export: stream a download. GET workspaceId, id or area, format. Needs csrf_token in the query. */
    public static function rawExport(): void
    {
        $ctx = NotebookService::contextFor(self::str('workspaceId'));
        $id = self::str('id');
        $area = self::str('area');
        $r = NotebookService::export($ctx, $id === '' ? null : $id, $area === '' ? null : $area, self::str('format') ?: 'md');
        if (isset($r['error'])) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => (string)$r['error']]);
            return;
        }
        $name = preg_replace('/[^A-Za-z0-9._\-]/', '_', (string)$r['filename']);
        header('Content-Type: ' . $r['mime']);
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($r['bytes']));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $r['bytes'];
    }

    // ---------- Helpers ----------

    /** POST name, data (base64), id? to link the file into an artefact. */
    private static function assetUpload(array $ctx, string $aid, array $author): array
    {
        $bytes = base64_decode((string)($_REQUEST['data'] ?? ''), true);
        if ($bytes === false || $bytes === '') return self::error('The file data is missing or is not valid base64.');
        $name = self::str('name');
        if ($aid !== '') {
            $existing = NotebookService::get($ctx, $aid);
            if (isset($existing['error'])) return self::reply($existing);
        }
        $put = NotebookService::assetPut($ctx, $name, $bytes);
        if (isset($put['error'])) return self::reply($put);
        if ($aid !== '') {
            $got = NotebookService::get($ctx, $aid);
            $art = $got['artefact'] ?? null;
            if (is_array($art)) {
                $ext = strtolower(pathinfo($put['path'], PATHINFO_EXTENSION));
                $kind = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg'], true) ? 'image'
                    : (in_array($ext, ['mp4', 'webm', 'mov'], true) ? 'video'
                    : (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'], true) ? 'audio' : ($ext === 'pdf' ? 'pdf' : 'file')));
                $label = pathinfo($name, PATHINFO_FILENAME);
                if ($art['type'] === 'media') {
                    NotebookService::patch($ctx, $aid, [['op' => 'addMedia', 'asset' => $put['path'], 'caption' => $label, 'kind' => $kind]], null, $author);
                } elseif (in_array($art['type'], ['markdown', 'checklist', 'deck'], true)) {
                    $line = ($kind === 'image' ? '!' : '') . '[' . $label . '](' . $put['path'] . ')';
                    NotebookService::patch($ctx, $aid, [['op' => 'append', 'text' => $line]], null, $author);
                }
            }
        }
        return ['status' => 'ok', 'path' => $put['path']];
    }

    /** A browser author is always human. */
    private static function humanAuthor(array $ctx): array
    {
        $a = NotebookService::author(null);
        if (!isset($ctx['error'])) {
            $a['workspaceId'] = $ctx['scope'] === 'global' ? '' : (string)$ctx['workspaceId'];
            $a['workspace'] = (string)($ctx['workspaceName'] ?? '');
        }
        if (self::flag('confirmSecret')) $a['confirmSecret'] = true;
        return $a;
    }

    /** Service result to a flat reply. */
    private static function reply(array $r): array
    {
        if (isset($r['error'])) {
            $out = ['status' => 'error', 'message' => (string)$r['error']];
            foreach (['currentVersion', 'needsConfirm', 'notOnServer', 'locked'] as $k) {
                if (array_key_exists($k, $r)) $out[$k] = $r[$k];
            }
            return $out;
        }
        return ['status' => 'ok'] + $r;
    }

    private static function error(string $message): array
    {
        return ['status' => 'error', 'message' => $message];
    }

    private static function str(string $key): string
    {
        $v = $_REQUEST[$key] ?? '';
        return is_scalar($v) ? trim((string)$v) : '';
    }

    private static function flag(string $key): bool
    {
        $v = $_REQUEST[$key] ?? '';
        return is_scalar($v) && in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on'], true);
    }

    /** A request value that is JSON text (or already an array). null when absent or invalid. */
    private static function json(string $key)
    {
        if (!isset($_REQUEST[$key])) return null;
        $v = $_REQUEST[$key];
        if (is_array($v)) return $v;
        if (!is_string($v) || trim($v) === '') return null;
        $d = json_decode($v, true);
        return json_last_error() === JSON_ERROR_NONE ? $d : null;
    }
}
