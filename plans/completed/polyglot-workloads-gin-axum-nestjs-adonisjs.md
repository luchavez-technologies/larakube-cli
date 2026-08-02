# Plan: Polyglot Application Scaffolding (`gin:new`, `axum:new`, `nestjs:new`, `adonisjs:new`)

> **Status:** Completed (2026-08-03)
> **Created:** 2026-08-03  
> **Target Version:** LaraKube CLI v1.4.0  
> **Decision Origin:** Interactive `/grill-me` alignment session (2026-08-03)

---

## 0. Decision Log (from `/grill-me` session)

| Question | Decision |
|---|---|
| Command naming strategy | **Framework-based naming**: `gin:new`, `axum:new`, `nestjs:new`, `adonisjs:new` (avoiding generic language names like `go:` or `rust:`) |
| Gin (Go) stack | **Gin** framework (78k+ stars) + **GORM** (36k+ stars) with `golang-migrate` init container |
| Axum (Rust) stack | **Axum** framework ( official Tokio team) + **SQLx** (compile-time SQL & migrations) |
| NestJS stack | `npx @nestjs/cli new` + TypeScript + Prisma ORM (`prisma migrate deploy` init container) + `@nestjs/terminus` |
| AdonisJS stack | **AdonisJS v6** (the Node.js Laravel equivalent) + Lucid ORM (`node ace migration:run --force` init container) + `@adonisjs/redis` + `@adonisjs/drive` |
| Database drivers | PostgreSQL (recommended), MySQL, MariaDB across all 4 frameworks |
| Cache, Storage & Search | Cache: Redis across all 4. Storage: S3-compatible (MinIO, SeaweedFS, Garage). Search: Meilisearch & Typesense. |
| Production Base Images | `alpine:3.20` for compiled binaries (~10-15MB runtime for Gin and Axum), `node:22-alpine` for NestJS (port 3000) and AdonisJS (port 3333) |

---

## 1. AppFramework Enum Extension

Add 4 new cases to `AppFramework` (`app/Enums/AppFramework.php`):

```php
enum AppFramework: string implements HasLabel
{
    case LARAVEL    = 'laravel';
    case STATAMIC   = 'statamic';
    case WORDPRESS  = 'wordpress';
    case NEXTJS     = 'nextjs';
    case DJANGO     = 'django';
    case FASTAPI    = 'fastapi';
    case SPRINGBOOT = 'springboot';
    case DOTNET     = 'dotnet';
    case GIN        = 'gin';
    case AXUM       = 'axum';
    case NESTJS     = 'nestjs';
    case ADONISJS   = 'adonisjs';

    public function getLabel(): string
    {
        return match ($this) {
            self::LARAVEL    => 'Laravel',
            self::STATAMIC   => 'Statamic',
            self::WORDPRESS  => 'WordPress (Bedrock)',
            self::NEXTJS     => 'Next.js',
            self::DJANGO     => 'Django',
            self::FASTAPI    => 'FastAPI',
            self::SPRINGBOOT => 'Spring Boot',
            self::DOTNET     => '.NET Core',
            self::GIN        => 'Gin (Go)',
            self::AXUM       => 'Axum (Rust)',
            self::NESTJS     => 'NestJS',
            self::ADONISJS   => 'AdonisJS',
        };
    }

    public function healthProbePath(): string
    {
        return match ($this) {
            self::LARAVEL, self::STATAMIC => '/up',
            self::WORDPRESS               => '/wp-includes/version.php',
            self::SPRINGBOOT              => '/actuator/health',
            self::NEXTJS, self::FASTAPI, self::DJANGO, self::DOTNET, self::GIN, self::AXUM, self::NESTJS, self::ADONISJS => '/healthz',
        };
    }

    public function proxyCommand(): string
    {
        return match ($this) {
            self::LARAVEL, self::STATAMIC => 'php artisan',
            self::WORDPRESS               => 'wp',
            self::NEXTJS, self::NESTJS    => 'node',
            self::ADONISJS                => 'node ace',
            self::DJANGO                  => 'python manage.py',
            self::FASTAPI                 => 'python',
            self::SPRINGBOOT              => 'java -jar app.jar',
            self::DOTNET                  => 'dotnet',
            self::GIN                     => 'go run .',
            self::AXUM                    => 'cargo run',
        };
    }
}
```

---

## 2. Command Architecture & Deliverables

### Phase 1 — `gin:new`
- **Command Class**: `app/Commands/Gin/GinNewCommand.php`
- **Framework & ORM**: Gin web framework + GORM ORM + `golang-migrate`
- **Runtime**: Multi-stage Docker build (`golang:1.23-alpine` → `alpine:3.20` 15MB runner on port 8080)
- **Database**: PostgreSQL (`pgx`), MySQL, MariaDB
- **K8s Views**: `resources/views/k8s/gin/` (`deployment.blade.php`, `ingress.blade.php`, `migrate-init-container.blade.php`)
- **Tests**: `tests/Feature/GinNewCommandTest.php`

### Phase 2 — `axum:new`
- **Command Class**: `app/Commands/Axum/AxumNewCommand.php`
- **Framework & ORM**: Axum (Tokio stack) + SQLx (compile-time SQL & migrations)
- **Runtime**: Multi-stage Docker build (`rust:1.80-alpine` → `alpine:3.20` ~10MB runner on port 8080)
- **Database**: PostgreSQL, MySQL, MariaDB
- **K8s Views**: `resources/views/k8s/axum/` (`deployment.blade.php`, `ingress.blade.php`, `migrate-init-container.blade.php`)
- **Tests**: `tests/Feature/AxumNewCommandTest.php`

### Phase 3 — `nestjs:new`
- **Command Class**: `app/Commands/Nestjs/NestjsNewCommand.php`
- **Scaffolding Tool**: `npx @nestjs/cli new {name}` inside `node:22-alpine` container
- **Architecture**: TypeScript + Prisma ORM + `@nestjs/terminus` health checks
- **Runtime**: Node.js 22 LTS on port 3000
- **Database**: PostgreSQL, MySQL, MariaDB
- **K8s Views**: `resources/views/k8s/nestjs/` (`deployment.blade.php`, `ingress.blade.php`, `migrate-init-container.blade.php`)
- **Tests**: `tests/Feature/NestjsNewCommandTest.php`

### Phase 4 — `adonisjs:new`
- **Command Class**: `app/Commands/Adonisjs/AdonisjsNewCommand.php`
- **Scaffolding Tool**: `npm create adonisjs@latest {name}` inside `node:22-alpine` container
- **Architecture**: TypeScript + Lucid ORM + `@adonisjs/drive` + `@adonisjs/redis`
- **Runtime**: Node.js 22 LTS on port 3333
- **Migrations**: `node ace migration:run --force` as K8s init container
- **K8s Views**: `resources/views/k8s/adonisjs/` (`deployment.blade.php`, `ingress.blade.php`, `migrate-init-container.blade.php`)
- **Tests**: `tests/Feature/AdonisjsNewCommandTest.php`

---

## 3. Implementation Sequence

1. **Phase 1**: Add enum cases `GIN`, `AXUM`, `NESTJS`, `ADONISJS` to `AppFramework` + `gin:new` command, K8s views, Pest tests.
2. **Phase 2**: `axum:new` command, Axum + SQLx layout, K8s views, Pest tests.
3. **Phase 3**: `nestjs:new` command, NestJS CLI scaffold, Prisma layout, K8s views, Pest tests.
4. **Phase 4**: `adonisjs:new` command, AdonisJS v6 scaffold, Lucid ORM, K8s views, Pest tests.
5. **Quality Assurance**: Pint formatting, PHPStan static analysis (`level 5`), full Pest test suite validation.
