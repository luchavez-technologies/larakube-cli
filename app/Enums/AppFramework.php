<?php

namespace App\Enums;

use App\Contracts\HasLabel;
use App\Contracts\RequiresPhpExtensions;

enum AppFramework: string implements HasLabel, RequiresPhpExtensions
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
            self::ASTRO => 'Astro',
            self::VITE => 'Vite',
            self::DOCUSAURUS => 'Docusaurus',
        };
    }

    /**
     * @return list<DatabaseDriver>
     */
    public function supportedDatabaseDrivers(): array
    {
        return match ($this) {
            self::LARAVEL, self::STATAMIC => [
                DatabaseDriver::POSTGRESQL,
                DatabaseDriver::MYSQL,
                DatabaseDriver::MARIADB,
                DatabaseDriver::SQLITE,
            ],
            self::WORDPRESS => [
                DatabaseDriver::MYSQL,
                DatabaseDriver::MARIADB,
            ],
            default => [
                DatabaseDriver::POSTGRESQL,
                DatabaseDriver::MYSQL,
                DatabaseDriver::SQLITE,
            ],
        };
    }

    /**
     * @return list<CacheDriver>
     */
    public function supportedCacheDrivers(): array
    {
        return match ($this) {
            self::LARAVEL, self::STATAMIC => [
                CacheDriver::REDIS,
                CacheDriver::MEMCACHED,
                CacheDriver::DATABASE,
            ],
            default => [
                CacheDriver::REDIS,
                CacheDriver::MEMCACHED,
            ],
        };
    }

    /**
     * @return list<StorageDriver>
     */
    public function supportedStorageDrivers(): array
    {
        return [
            StorageDriver::SEAWEEDFS,
            StorageDriver::MINIO,
            StorageDriver::GARAGE,
        ];
    }

    /**
     * @return list<SearchDriver>
     */
    public function supportedSearchDrivers(): array
    {
        return [
            SearchDriver::MEILISEARCH,
            SearchDriver::DATABASE,
        ];
    }

    /**
     * Path used for liveness / readiness / startup probes.
     */
    public function healthProbePath(): string
    {
        return match ($this) {
            self::LARAVEL, self::STATAMIC => '/up',
            // Bedrock serves WordPress core from web/wp; no database needed to answer.
            self::WORDPRESS => '/wp/wp-includes/version.php',
            self::NEXTJS => '/api/health',
            self::SPRINGBOOT => '/actuator/health',
            self::DJANGO, self::FASTAPI, self::DOTNET, self::GIN, self::AXUM, self::NESTJS, self::ADONISJS => '/healthz',
            self::ASTRO, self::VITE, self::DOCUSAURUS => '/',
        };
    }

    /**
     * Whether the LaraKube CLI can take this framework to a running cloud
     * deployment. `init` only offers these; the rest can be scaffolded by their
     * `:new` command but have no image or manifests yet.
     */
    public function isDeployable(): bool
    {
        return in_array($this, [
            self::LARAVEL, self::STATAMIC, self::WORDPRESS,
            self::NEXTJS,
            self::ASTRO, self::VITE, self::DOCUSAURUS,
        ], true);
    }

    /** The Dockerfile that builds this framework's production image, relative to the project root. */
    public function dockerfile(): string
    {
        return match (true) {
            $this->isStaticSpa() => 'Dockerfile.static',
            $this->isPhp() => 'Dockerfile.php',
            default => "Dockerfile.{$this->value}",
        };
    }

    /** The Dockerfile stage to build, or null when the image has a single final stage. */
    public function buildTarget(): ?string
    {
        return $this->isStaticSpa() ? null : 'deploy';
    }

    /** The cloud Deployment a rollout waits on. */
    public function workloadName(string $appName): string
    {
        return $this->isStaticSpa() || $this->isPhp() ? 'web' : "{$appName}-{$this->value}";
    }

    /**
     * A long-running server that is neither PHP nor a static bundle, deployed
     * through the shared server-app engine. Next.js predates the engine and
     * keeps its own manifests.
     */
    public function isServerApp(): bool
    {
        return ! $this->isPhp() && ! $this->isStaticSpa() && $this !== self::NEXTJS;
    }

    /** The port the production server listens on inside its container. */
    public function containerPort(): int
    {
        return match ($this) {
            self::NESTJS, self::NEXTJS => 3000,
            self::ADONISJS => 3333,
            self::DJANGO, self::FASTAPI => 8000,
            self::GIN, self::AXUM, self::SPRINGBOOT, self::DOTNET => 8080,
            default => 80,
        };
    }

    /**
     * The command a migration init container runs before the server starts,
     * or null when there is nothing to migrate. $hasPrisma: the project ships
     * a prisma/schema.prisma.
     */
    public function migrateCommand(bool $hasPrisma): ?string
    {
        return match ($this) {
            self::NESTJS => $hasPrisma ? 'npx --yes prisma@6 migrate deploy' : null,
            default => null,
        };
    }

    /** Whether dependencies come from npm, so CI audits them with `npm audit`. */
    public function usesNpm(): bool
    {
        return $this->isStaticSpa() || in_array($this, [self::NEXTJS, self::NESTJS, self::ADONISJS], true);
    }

    public function isStaticSpa(): bool
    {
        return in_array($this, [self::ASTRO, self::VITE, self::DOCUSAURUS], true);
    }

    /** Whether components may add composer packages, artisan commands or Laravel JS. */
    public function isPhp(): bool
    {
        return in_array($this, [self::LARAVEL, self::STATAMIC, self::WORDPRESS], true);
    }

    /**
     * Package scripts, most-preferred first, that produce the deployable
     * bundle. A HINT, not an assertion: the resolver picks the first that
     * actually exists in the project's package.json, so a renamed script is a
     * loud generation-time error instead of a wrong command baked into an
     * image. Empty for frameworks that ship a running server, so a caller that
     * assumes "static" fails loudly rather than guessing.
     *
     * @return list<string>
     */
    public function buildScriptCandidates(): array
    {
        // Every create-* scaffolder emits `build`; the list leaves room for a
        // framework that diverges without another code path.
        return $this->isStaticSpa() ? ['build'] : [];
    }

    /**
     * Whether the production build reads `.env.{environment}` and compiles its
     * public values into the bundle (Vite's VITE_*, Astro's PUBLIC_*).
     * Docusaurus reads no env file, so its bundle has no configured hosts to
     * check, and a docs site legitimately mentions local URLs in its content.
     */
    public function bakesEnvIntoBuild(): bool
    {
        return in_array($this, [self::VITE, self::ASTRO], true);
    }

    /**
     * The paths `larakube watch` follows for a static site, or null for the
     * blueprint's defaults (Laravel's).
     *
     * @return list<string>|null
     */
    public function staticWatchPaths(): ?array
    {
        return match ($this) {
            self::VITE => ['src', 'public', 'index.html', 'package.json', 'vite.config.js', 'vite.config.ts'],
            self::ASTRO => ['src', 'public', 'package.json', 'astro.config.mjs', 'astro.config.ts'],
            self::DOCUSAURUS => ['docs', 'blog', 'src', 'static', 'package.json', 'docusaurus.config.ts', 'docusaurus.config.js', 'sidebars.ts', 'sidebars.js'],
            default => null,
        };
    }

    /**
     * Directory the build lands in, relative to the project root. Docusaurus is
     * the odd one out — it writes `build/`, not `dist/`.
     */
    public function staticOutputDir(): ?string
    {
        return match ($this) {
            self::VITE, self::ASTRO => 'dist',
            self::DOCUSAURUS => 'build',
            default => null,
        };
    }

    /**
     * Package scripts, most-preferred first, that start this framework's dev
     * server. A HINT the resolver checks against the project's real
     * package.json, not the last word.
     *
     * The whole reason this is a list and package.json-checked: create-*
     * scaffolders own these script names and change them. Docusaurus emits
     * `start` and no `dev` at all, so a hardcoded `dev` gave a CrashLoopBackOff
     * ("Missing script: dev") that no amount of enum-correctness could prevent
     * if the scaffolder ever renamed it — the file states the truth, the enum
     * only guesses. `dev` trails `start` for Docusaurus so a project that adds
     * its own `dev` alias is still honoured.
     *
     * @return list<string>
     */
    public function devServerScriptCandidates(): array
    {
        return match ($this) {
            self::VITE, self::ASTRO, self::NEXTJS => ['dev'],
            self::NESTJS => ['start:dev'],
            self::DOCUSAURUS => ['start', 'dev'],
            default => [],
        };
    }

    /**
     * Flags the dev server needs to be reachable from inside a Pod.
     *
     * Astro and Docusaurus both bind to localhost by default, so the container
     * listens only on its own loopback, the Service reaches nothing, and the
     * readiness probe never passes. Vite needs none of this because its own
     * config file carries host, port, allowedHosts and polling — which is also
     * why only Vite gets an empty string here rather than a duplicate.
     */
    public function devServerFlags(): string
    {
        return match ($this) {
            self::VITE => '',
            self::ASTRO => '--host 0.0.0.0 --port 5173',
            // --poll: inotify does not reliably cross the hostPath/VirtioFS
            // boundary on macOS, and Docusaurus is webpack, so Vite's
            // watch.usePolling does not apply to it.
            self::DOCUSAURUS => '--host 0.0.0.0 --port 3000 --poll 300',
            // `next dev` binds to localhost by default; -H 0.0.0.0 makes it
            // reachable from the Service, and -p pins the port the probe checks.
            self::NEXTJS => '--hostname 0.0.0.0 --port 3000',
            default => '',
        };
    }

    /**
     * Port this framework's own dev server binds to, used by the local HMR pod.
     */
    public function devServerPort(): ?int
    {
        return match ($this) {
            self::VITE, self::ASTRO => 5173,
            self::DOCUSAURUS, self::NEXTJS, self::NESTJS => 3000,
            default => null,
        };
    }

    /**
     * Prefixes a framework's BROWSER bundle requires for an env var to be
     * exposed to client code. Empty for server-rendered frameworks, which read
     * the environment directly and need no prefix at all — so a caller writing
     * connection URLs emits only what the framework can actually read, instead
     * of four variants of which three are dead.
     *
     * Docusaurus is deliberately empty: it has no standard client-env prefix
     * and exposes build-time values through customFields instead.
     *
     * @return list<string>
     */
    public function publicEnvPrefixes(): array
    {
        return match ($this) {
            // Laravel and Statamic ship their browser assets through Vite too.
            self::VITE, self::LARAVEL, self::STATAMIC => ['VITE_'],
            self::ASTRO => ['PUBLIC_'],
            self::NEXTJS => ['NEXT_PUBLIC_'],
            default => [],
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
            self::ASTRO, self::VITE, self::DOCUSAURUS => 'npm run dev',
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
            self::ASTRO => [['astro.config.mjs'], ['astro.config.ts'], ['astro.config.js']],
            self::DOCUSAURUS => [['docusaurus.config.ts'], ['docusaurus.config.js']],
            self::VITE => [['vite.config.ts'], ['vite.config.js']],
        };
    }

    /**
     * Attempt to detect the framework from project-root marker files.
     * Returns null when no marker matches.
     */
    public static function detect(string $projectPath): ?self
    {
        // Pure marker-file frameworks: each case's own markerFiles() is the
        // single source of truth, checked in priority order (most specific
        // first — a Next.js/NestJS/Adonis project can also carry a package.json
        // that would otherwise be ambiguous with a plain Node setup).
        foreach ([self::NEXTJS, self::NESTJS, self::ADONISJS, self::DJANGO, self::FASTAPI, self::GIN, self::AXUM, self::SPRINGBOOT, self::DOTNET, self::DOCUSAURUS, self::ASTRO, self::VITE] as $case) {
            foreach ($case->markerFiles() as $files) {
                if (self::allFilesExist($projectPath, $files)) {
                    return $case;
                }
            }
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
     * Extensions the framework itself needs, regardless of drivers.
     *
     * Statamic's `['gd', 'exif']` lived on Blueprint::STATAMIC until b7d7a10
     * moved Statamic to its own command — the framework is not part of
     * getComponents(), so the declaration was silently lost and both the built
     * image AND `composer create-project` came up without GD. Intervention's GD
     * driver checkHealth() then throws during package:discover.
     */
    public function getPhpExtensions(): array
    {
        return match ($this) {
            self::STATAMIC => ['gd', 'exif'],
            default => [],
        };
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
    case ASTRO = 'astro';
    case VITE = 'vite';
    case DOCUSAURUS = 'docusaurus';
}
