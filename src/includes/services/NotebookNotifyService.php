<?php
namespace AICliAgents\Services;

/**
 * <module_context>
 *     <purpose>Tell the agent of a workspace when a person answers in its Notebook: a question card
 *     answered, a checklist finished or failed, a comment, a login saved. One short line is typed into
 *     the workspace terminal through AdminService::sendInput (idle-prompt check, rate gap, ledger).</purpose>
 *     <spec>docs/specs/NOTEBOOK_ANSWER_NOTIFY.md</spec>
 *     <constraints>Only called for a change a person made in the browser. The line names the card by
 *     title, absolute path and id and NEVER holds an answer, a comment or a secret. A busy agent is not
 *     queued. No function throws.</constraints>
 * </module_context>
 */
class NotebookNotifyService
{
    /** Seconds between two lines of the same kind for the same note. */
    public const FAIL_GAP = 120;
    public const COMMENT_GAP = 60;
    public const CONNECTION_GAP = 60;

    /** Test seam: directory of the throttle markers. @var string|null */
    public static ?string $markerDir = null;
    /** Test seam: "now" in epoch seconds. @var int|null */
    public static ?int $now = null;
    /** Test seam: replaces the setting. @var string|null */
    public static ?string $settingOverride = null;

    public static function enabled(): bool
    {
        if (self::$settingOverride !== null) return self::$settingOverride === '1';
        try {
            return (string)(ConfigService::getConfig()['notebook_notify_agent'] ?? '1') === '1';
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * What the artefact looks like now: the form status, or the checklist counts.
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    public static function snapshot(array $ctx, string $id): array
    {
        try {
            $r = NotebookService::get($ctx, $id);
            if (isset($r['error']) || !isset($r['artefact'])) return [];
            $a = $r['artefact'];
            $type = (string)($a['type'] ?? '');
            $out = ['type' => $type, 'title' => (string)($a['title'] ?? ''), 'path' => (string)($a['path'] ?? '')];
            if ($type === 'form') {
                $out['status'] = (string)(($a['data']['status'] ?? '') ?: 'open');
            } elseif ($type === 'checklist') {
                $out['counts'] = NotebookFormat::parseChecklist((string)($a['body'] ?? ''))['counts'];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Decide whether a change needs a line, send it, and add `notify` to the reply.
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $before snapshot() taken before the change
     * @param array<string,mixed> $reply  the reply of the change (no `error`)
     * @param array<string,mixed> $seams  `send` callable(workspaceId, text): array
     * @return array<string,mixed> the reply, with `notify` when a line was due
     */
    public static function attach(string $action, array $ctx, string $id, array $before, array $reply, array $seams = []): array
    {
        try {
            if (isset($reply['error']) || !empty($reply['unchanged'])) return $reply;
            if (!self::enabled()) return $reply;
            $ws = (string)($ctx['workspaceId'] ?? '');
            if (isset($ctx['error']) || ($ctx['scope'] ?? '') === 'global' || $ws === '' || $ws === 'global') return $reply;

            $after = self::snapshot($ctx, $id);
            $event = null; $facts = [];
            switch ($action) {
                case 'notebook_answer':
                    if (($after['type'] ?? '') === 'form' && ($before['status'] ?? 'open') !== 'answered' && ($after['status'] ?? '') === 'answered') $event = 'form';
                    break;
                case 'notebook_item_state':
                    if (($after['type'] ?? '') !== 'checklist') break;
                    $b = $before['counts'] ?? []; $c = $after['counts'] ?? [];
                    $facts = $c;
                    if ((int)($b['pending'] ?? 0) > 0 && (int)($c['pending'] ?? 0) === 0) $event = 'checklist_done';
                    elseif ((int)($c['fail'] ?? 0) > (int)($b['fail'] ?? 0)) $event = 'checklist_fail';
                    break;
                case 'notebook_comment':
                    $event = 'comment';
                    break;
                case 'notebook_secret_set':
                    if (($after['type'] ?? '') === 'connection') $event = 'connection';
                    break;
            }
            if ($event === null) return $reply;
            $gap = ['checklist_fail' => self::FAIL_GAP, 'comment' => self::COMMENT_GAP, 'connection' => self::CONNECTION_GAP][$event] ?? 0;
            if ($gap > 0 && !self::gateOpen($ws, $id, $event, $gap)) return $reply;

            $path = rtrim((string)($ctx['root'] ?? ''), '/') . '/' . ltrim((string)($after['path'] ?? ''), '/');
            $text = self::compose($event, (string)($after['title'] ?? ''), $path, $id, $facts);
            $send = $seams['send'] ?? static function (string $w, string $t): array {
                if (!class_exists('AICliAgents\Services\AdminService', false)) require_once __DIR__ . '/AdminService.php';
                return AdminService::sendInput($w, $t, true, false);
            };
            $r = $send($ws, $text);
            $reply['notify'] = isset($r['error'])
                ? ['sent' => false, 'delivered' => false, 'deferred' => false, 'reason' => (string)$r['error']]
                : ['sent' => true, 'delivered' => !empty($r['delivered']), 'deferred' => !empty($r['deferred']), 'reason' => (string)($r['reason'] ?? '')];
            $reply['notify']['event'] = $event;
            return $reply;
        } catch (\Throwable $e) {
            return $reply;
        }
    }

    /**
     * The line typed into the terminal. Pure. Holds no answer, comment or secret.
     * @param array<string,mixed> $facts checklist counts
     */
    public static function compose(string $event, string $title, string $path, string $id, array $facts = []): string
    {
        $title = trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $title));
        if (function_exists('mb_substr')) $title = mb_substr($title, 0, 120);
        $ref = '"' . $title . '" (file: ' . $path . ', id ' . $id . ')';
        $n = static fn(string $k): int => (int)($facts[$k] ?? 0);
        switch ($event) {
            case 'form':
                return "The person answered the Notebook question card $ref. Read the answers in the file, then continue.";
            case 'checklist_done':
                return "The person finished the Notebook checklist $ref: {$n('pass')} passed, {$n('fail')} failed, {$n('blocked')} blocked, {$n('skipped')} skipped. Read the item notes in the file, then continue.";
            case 'checklist_fail':
                return "The person marked an item as failed on the Notebook checklist $ref. {$n('fail')} failed so far. Read the item note in the file.";
            case 'comment':
                return "The person commented on the Notebook note $ref. Read the comments with your Notebook tools and answer in the Notebook.";
            case 'connection':
                return "The person saved the login on the Notebook connection card $ref. The secret is in the vault. Read the card with your Notebook tools.";
        }
        return '';
    }

    private static function gateOpen(string $ws, string $id, string $event, int $gap): bool
    {
        $dir = self::$markerDir ?? SecretPaths::stateRoot() . '/notebook-notify';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $file = $dir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', "$ws-$id-$event");
        $now = self::$now ?? time();
        $last = @filemtime($file);
        if ($last !== false && ($now - $last) < $gap) return false;
        @touch($file, $now);
        return true;
    }
}
