<?php
namespace AICliAgents\Services;

/**
 * <module_context>
 *     <purpose>Copy each recorded voice mail message to the Notebook of the speaking workspace, as one
 *     markdown note in the Notes area. The note is a normal note, not a wiki page (no section tag). The note is independent of
 *     voice mail: deleting or pruning the message never touches it.</purpose>
 *     <spec>docs/specs/VOICE_MAIL_NOTEBOOK_COPY.md</spec>
 *     <constraints>Needs voice_enabled and notebook_enabled both on. Never throws. Never delays a
 *     spoken message. The note is person-owned (no agent rate limit).</constraints>
 * </module_context>
 */
class VoiceMailNotebookService
{
    public const TITLE_MAX = 80;

    /** Test seam: replaces the two settings. @var array<string,string>|null */
    public static ?array $settingsOverride = null;

    public static function enabled(): bool
    {
        try {
            $c = self::$settingsOverride ?? ConfigService::getConfig();
            return (string)($c['voice_enabled'] ?? '0') === '1' && (string)($c['notebook_enabled'] ?? '1') === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Pure. "Voice mail: first words of the text", at most TITLE_MAX characters. */
    public static function title(string $text): string
    {
        $t = trim((string)preg_replace('/\s+/u', ' ', $text));
        $prefix = 'Voice mail: ';
        $room = self::TITLE_MAX - mb_strlen($prefix);
        if (mb_strlen($t) > $room) $t = rtrim(mb_substr($t, 0, $room - 1)) . '…';
        return $prefix . ($t === '' ? 'message' : $t);
    }

    /** Pure. The note body. @param array<string,mixed> $message a voice mail record */
    public static function body(array $message, string $workspaceName): string
    {
        $when = gmdate('Y-m-d H:i', (int)($message['ts'] ?? time())) . ' UTC';
        $agent = trim((string)($message['name'] ?? ''));
        $lines = [
            '**Workspace:** ' . ($workspaceName !== '' ? $workspaceName : (string)($message['workspaceId'] ?? '')),
        ];
        if ($agent !== '') $lines[] = '**Agent:** ' . $agent;
        $lines[] = '**Said:** ' . $when;
        $lines[] = '';
        $lines[] = (string)($message['text'] ?? '');
        return implode("\n", $lines) . "\n";
    }

    /**
     * Copy one recorded message. Returns the new note id, or null (off, no notebook, or a failure).
     * @param array<string,mixed> $message a voice mail record
     */
    public static function copy(array $message, string $workspaceName = ''): ?string
    {
        try {
            if (!self::enabled()) return null;
            $ws = (string)($message['workspaceId'] ?? '');
            $text = trim((string)($message['text'] ?? ''));
            if ($ws === '' || $text === '') return null;
            $ctx = NotebookService::contextFor($ws);
            if (isset($ctx['error'])) return null;
            $r = NotebookService::create($ctx, [
                'type'    => 'markdown',
                'area'    => 'notes',
                'title'   => self::title($text),
                'content' => self::body($message, $workspaceName !== '' ? $workspaceName : (string)($ctx['name'] ?? '')),
                'tags'    => ['voicemail', 'vm:' . (string)($message['id'] ?? '')],
            ], ['kind' => 'human', 'name' => 'Voice mail']);
            return isset($r['error']) ? null : (string)($r['artefact']['id'] ?? '');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
