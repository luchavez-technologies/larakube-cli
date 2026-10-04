<?php

namespace App\Services\Scaffolding;

/**
 * Prebuilt images the scaffolders run in, with the installer and Node already in place, so a
 * new project does not install them on every run. They are published from the
 * larakube-builder repository (its images.json lists them); a version is listed here once its
 * image exists. Callers fall back to the plain base image when one cannot be pulled.
 */
final class BuilderImage
{
    public const REGISTRY = 'ghcr.io/luchavez-technologies/larakube-builder';

    /** PHP versions that have a builder image. Scaffolding offers 8.3 and newer. */
    public const PHP_VERSIONS = ['8.5', '8.4', '8.3'];

    public const PYTHON_VERSIONS = ['3.12'];

    /** The PHP builder (Laravel installer, Statamic CLI, Node, Bun) for a PHP version, or null when none is published. */
    public static function php(string $version): ?string
    {
        return in_array($version, self::PHP_VERSIONS, true) ? self::REGISTRY.'/php:'.$version : null;
    }

    /** The Python builder (Django installed) for a Python version, or null when none is published. */
    public static function python(string $version): ?string
    {
        return in_array($version, self::PYTHON_VERSIONS, true) ? self::REGISTRY.'/python:'.$version : null;
    }

    /**
     * What runs inside the container. A builder image already has Node, the installer and its place
     * on the PATH; the plain base image needs all three first.
     *
     * @param  string  $prepare  the command that installs Node on a plain image
     */
    public static function laravelNewScript(bool $prebuilt, string $prepare, string $appName, string $flags): string
    {
        $new = "laravel new {$appName} {$flags}";

        return $prebuilt
            ? $new
            : "{$prepare} && composer config -g bin-dir /usr/local/bin && composer global require laravel/installer && {$new}";
    }

    /** Why a pull failed, as the one line worth showing: the last thing the container engine said on stderr. */
    public static function pullFailure(string $errorOutput): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $errorOutput)), fn (string $line): bool => $line !== ''));

        return $lines === [] ? 'no reason was given' : (string) end($lines);
    }
}
