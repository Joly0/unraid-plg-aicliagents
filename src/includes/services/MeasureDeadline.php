<?php
/**
 * <module_context>
 * Description: One shared deadline for the background measurement of the Boot disk space
 *   card (docs/specs/BOOT_DISK_SPACE.md, "Implementation notes (#552)"). Every part of the
 *   measurement asks this class how many seconds are left, and caps its own command timeout
 *   by that number. When the time is gone, the part skips its work and the measurement is
 *   marked partial.
 * Dependencies: none.
 * Constraints: Used only by the background measurement (BootSpaceService::run). When no
 *   deadline is set, remaining() gives the cap that the caller passes in, so a caller
 *   outside the measurement keeps its own fixed timeout. State is per process.
 * </module_context>
 */
namespace AICliAgents\Services;

class MeasureDeadline
{
    /** The default length of one measurement, in seconds. Env AICLI_BOOT_SPACE_DEADLINE overrides it. */
    public const DEFAULT_SECONDS = 300;

    /** @var int|null Unix time at which the measurement must stop; null when none is set. */
    private static ?int $at = null;
    private static bool $partial = false;

    /** The length of one measurement in seconds (env override, at least 5). */
    public static function seconds(): int
    {
        $e = getenv('AICLI_BOOT_SPACE_DEADLINE');
        if ($e !== false && ctype_digit($e) && (int)$e >= 5) return (int)$e;
        return self::DEFAULT_SECONDS;
    }

    public static function start(int $at): void
    {
        self::$at = $at;
        self::$partial = false;
    }

    public static function clear(): void
    {
        self::$at = null;
        self::$partial = false;
    }

    public static function isSet(): bool
    {
        return self::$at !== null;
    }

    /** Seconds left, at least 0. With no deadline set: $cap. The result never exceeds $cap. */
    public static function remaining(int $cap, ?int $now = null): int
    {
        if (self::$at === null) return $cap;
        return max(0, min($cap, self::$at - ($now ?? time())));
    }

    public static function expired(?int $now = null): bool
    {
        return self::$at !== null && ($now ?? time()) >= self::$at;
    }

    /** Record that a part of the measurement was skipped or cut short. */
    public static function markPartial(): void
    {
        self::$partial = true;
    }

    public static function wasPartial(): bool
    {
        return self::$partial;
    }
}
