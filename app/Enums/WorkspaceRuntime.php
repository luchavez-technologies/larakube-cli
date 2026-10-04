<?php

namespace App\Enums;

/**
 * The language toolchain a workspace image carries. Several frameworks share one
 * (Next.js, Nest, Astro, Vite... all run on Node), so the image is chosen by
 * runtime and the framework only decides the dev command and ports.
 */
enum WorkspaceRuntime: string
{
    public function label(): string
    {
        return match ($this) {
            self::PHP => 'PHP',
            self::NODE => 'Node.js',
            self::PYTHON => 'Python',
            self::JAVA => 'Java',
            self::DOTNET => '.NET',
            self::GO => 'Go',
            self::RUST => 'Rust',
        };
    }

    /**
     * Versions offered, newest first. PHP follows the CLI's own list so a workspace
     * and the app image it edits can use the same one.
     *
     * @return list<string>
     */
    public function versions(): array
    {
        return match ($this) {
            self::PHP => array_values(array_map(
                fn (PhpVersion $version): string => $version->value,
                array_filter(PhpVersion::cases(), fn (PhpVersion $version): bool => (float) $version->value >= 8.2),
            )),
            self::NODE => ['24', '22'],
            self::PYTHON => ['3.13', '3.12'],
            self::JAVA => ['21'],
            self::DOTNET => ['10.0'],
            self::GO => ['1.27'],
            self::RUST => ['1'],
        };
    }

    public function defaultVersion(): string
    {
        return match ($this) {
            self::PHP => PhpVersion::PHP_8_4->value,
            default => $this->versions()[0],
        };
    }

    /**
     * Whether an image for this runtime is published. A runtime is switched on here once its
     * image exists in the larakube-workspace repository (its images.json).
     */
    public function published(): bool
    {
        return in_array($this, [self::PHP, self::NODE], true);
    }

    /** Memory a workspace of this runtime tends to need; the sizes in WorkspaceSpec are chosen against it. */
    public function heavy(): bool
    {
        return in_array($this, [self::JAVA, self::DOTNET, self::RUST], true);
    }
    case PHP = 'php';
    case NODE = 'node';
    case PYTHON = 'python';
    case JAVA = 'java';
    case DOTNET = 'dotnet';
    case GO = 'go';
    case RUST = 'rust';
}
