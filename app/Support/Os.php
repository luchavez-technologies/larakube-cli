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
}
