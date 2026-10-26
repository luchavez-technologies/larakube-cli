<?php

namespace App\Support;

class Os
{
    private static ?string $forcedFamily = null;

    public static function setForcedFamily(?string $family): void
    {
        self::$forcedFamily = $family;
    }

    public static function family(): string
    {
        return self::$forcedFamily ?? PHP_OS_FAMILY;
    }

    public static function isDarwin(): bool
    {
        return self::family() === 'Darwin';
    }

    public static function isLinux(): bool
    {
        return self::family() === 'Linux';
    }

    public static function isWindows(): bool
    {
        return self::family() === 'Windows';
    }

    /**
     * The PATH-list separator for the current (possibly forced) OS. Routed
     * through here rather than PHP's own `PATH_SEPARATOR` constant, which is
     * fixed at build time by the REAL host OS and can't be faked by
     * `setForcedFamily()` — every other OS-conditional in this codebase is
     * testable without a real Windows host; this keeps that true here too.
     */
    public static function pathSeparator(): string
    {
        return self::isWindows() ? ';' : ':';
    }
}
