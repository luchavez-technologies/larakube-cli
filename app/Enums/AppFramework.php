<?php

namespace App\Enums;

use App\Contracts\HasLabel;

enum AppFramework: string implements HasLabel
{
    public function getLabel(): string
    {
        return match ($this) {
            self::LARAVEL => 'Laravel',
            self::STATAMIC => 'Statamic',
            self::WORDPRESS => 'WordPress (Bedrock)',
            self::NEXTJS => 'Next.js',
            self::DJANGO => 'Django',
            self::FASTAPI => 'FastAPI',
            self::SPRINGBOOT => 'Spring Boot',
            self::DOTNET => '.NET Core',
            self::GIN => 'Gin (Go)',
            self::AXUM => 'Axum (Rust)',
            self::NESTJS => 'NestJS',
            self::ADONISJS => 'AdonisJS',
        };
    }

    /**
     * Path used for liveness / readiness / startup probes.
     */
    public function healthProbePath(): string
    {
        return match ($this) {
            self::LARAVEL, self::STATAMIC => '/up',
            self::WORDPRESS => '/wp-includes/version.php',
            self::NEXTJS => '/api/health',
            self::SPRINGBOOT => '/actuator/health',
            self::DJANGO, self::FASTAPI, self::DOTNET, self::GIN, self::AXUM, self::NESTJS, self::ADONISJS => '/healthz',
        };
    }

    /**
     * The runtime proxy verb for `larakube art` / `larakube run`.
     */
    public function proxyCommand(): string
    {
        return match ($this) {
            self::LARAVEL, self::STATAMIC => 'php artisan',
            self::WORDPRESS => 'wp',
            self::NEXTJS, self::NESTJS => 'node',
            self::ADONISJS => 'node ace',
            self::DJANGO => 'python manage.py',
            self::FASTAPI => 'python',
            self::SPRINGBOOT => 'java -jar app.jar',
            self::DOTNET => 'dotnet',
            self::GIN => 'go run .',
            self::AXUM => 'cargo run',
        };
    }

    /**
     * Marker files used by `larakube init` to auto-detect the framework in a
     * project directory. Each entry is an array of file paths (relative to the
     * project root) that must ALL exist to match.
     *
     * @return array<int, array<int, string>>
     */
    public function markerFiles(): array
    {
        return match ($this) {
            self::LARAVEL => [['artisan', 'composer.json']],
            self::STATAMIC => [['artisan', 'composer.json']],
            self::WORDPRESS => [
                ['composer.json'],
                ['wp-config.php'],
            ],
            self::NEXTJS => [
                ['next.config.js'],
                ['next.config.mjs'],
                ['next.config.ts'],
            ],
            self::DJANGO => [['manage.py']],
            self::FASTAPI => [['main.py']],
            self::SPRINGBOOT => [['build.gradle.kts'], ['pom.xml']],
            self::DOTNET => [['Program.cs']],
            self::GIN => [['go.mod']],
            self::AXUM => [['Cargo.toml']],
            self::NESTJS => [['nest-cli.json']],
            self::ADONISJS => [['adonisrc.ts'], ['adonisrc.js']],
        };
    }

    /**
     * Attempt to detect the framework from project-root marker files.
     * Returns null when no marker matches.
     */
    public static function detect(string $projectPath): ?self
    {
        // Next.js: config file present
        foreach (self::NEXTJS->markerFiles() as $files) {
            if (self::allFilesExist($projectPath, $files)) {
                return self::NEXTJS;
            }
        }

        // NestJS: nest-cli.json present
        if (file_exists("$projectPath/nest-cli.json")) {
            return self::NESTJS;
        }

        // AdonisJS: adonisrc.ts or adonisrc.js present
        if (file_exists("$projectPath/adonisrc.ts") || file_exists("$projectPath/adonisrc.js")) {
            return self::ADONISJS;
        }

        // Django: manage.py present
        if (file_exists("$projectPath/manage.py")) {
            return self::DJANGO;
        }

        // FastAPI: main.py present (and no manage.py)
        if (file_exists("$projectPath/main.py")) {
            return self::FASTAPI;
        }

        // Go (Gin): go.mod present
        if (file_exists("$projectPath/go.mod")) {
            return self::GIN;
        }

        // Rust (Axum): Cargo.toml present
        if (file_exists("$projectPath/Cargo.toml")) {
            return self::AXUM;
        }

        // Spring Boot: build.gradle.kts or pom.xml present
        if (file_exists("$projectPath/build.gradle.kts") || file_exists("$projectPath/pom.xml")) {
            return self::SPRINGBOOT;
        }

        // .NET Core: Program.cs present
        if (file_exists("$projectPath/Program.cs")) {
            return self::DOTNET;
        }

        // WordPress: wp-config.php OR composer.json containing roots/bedrock
        if (file_exists("$projectPath/wp-config.php")) {
            return self::WORDPRESS;
        }
        if (file_exists("$projectPath/composer.json")) {
            $composer = (string) file_get_contents("$projectPath/composer.json");
            $data = json_decode($composer, true) ?? [];
            $requires = array_merge($data['require'] ?? [], $data['require-dev'] ?? []);

            if (isset($requires['roots/bedrock']) || str_contains($composer, 'roots/bedrock')) {
                return self::WORDPRESS;
            }

            // Statamic: artisan present AND statamic/cms in composer.json
            if (file_exists("$projectPath/artisan") && (isset($requires['statamic/cms']) || str_contains($composer, 'statamic/cms'))) {
                return self::STATAMIC;
            }

            // Laravel: artisan present AND laravel/framework in composer.json
            if (file_exists("$projectPath/artisan") && (isset($requires['laravel/framework']) || str_contains($composer, 'laravel/framework'))) {
                return self::LARAVEL;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $files
     */
    private static function allFilesExist(string $base, array $files): bool
    {
        foreach ($files as $file) {
            if (! file_exists("$base/$file")) {
                return false;
            }
        }

        return true;
    }

    case LARAVEL = 'laravel';    // Default (backwards-compatible when field omitted)
    case STATAMIC = 'statamic';
    case WORDPRESS = 'wordpress';
    case NEXTJS = 'nextjs';
    case DJANGO = 'django';
    case FASTAPI = 'fastapi';
    case SPRINGBOOT = 'springboot';
    case DOTNET = 'dotnet';
    case GIN = 'gin';
    case AXUM = 'axum';
    case NESTJS = 'nestjs';
    case ADONISJS = 'adonisjs';
}
