# Plan: Polyglot Application Scaffolding (`django:new`, `fastapi:new`, `springboot:new`, `dotnet:new`)

> **Status:** Completed (2026-08-03)
> **Created:** 2026-08-03  
> **Target Version:** LaraKube CLI v1.3.0  
> **Decision Origin:** Interactive `/grill-me` alignment session (2026-08-03)

---

## 0. Decision Log (from `/grill-me` session)

| Question | Decision |
|---|---|
| Python command structure | Separate into `django:new` and `fastapi:new` (isolated commands & templates) |
| Java framework & version | `springboot:new` targeting **Spring Boot 3.4 + Java 21 LTS** with Gradle (Kotlin DSL) |
| .NET framework & version | `dotnet:new` targeting **ASP.NET Core 9.0 Web API / Minimal API** |
| Database drivers & migrations | PostgreSQL (recommended), MySQL, MariaDB across all 4. Init containers: Django (`python manage.py migrate`), FastAPI (`alembic upgrade head`), Spring Boot (Flyway), .NET (`dotnet ef database update`). |
| Cache, Storage & Search | Cache: Redis across all 4. Storage: S3-compatible (MinIO, SeaweedFS, Garage). Search: Meilisearch & Typesense. |
| Production Base Images | `python:3.12-slim` (Django/FastAPI), `eclipse-temurin:21-jre-alpine` (Spring Boot), `mcr.microsoft.com/dotnet/aspnet:9.0-alpine` (.NET 9) |

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
        };
    }

    public function healthProbePath(): string
    {
        return match ($this) {
            self::LARAVEL, self::STATAMIC => '/up',
            self::WORDPRESS               => '/wp-includes/version.php',
            self::NEXTJS, self::FASTAPI, self::DJANGO, self::DOTNET => '/healthz',
            self::SPRINGBOOT              => '/actuator/health',
        };
    }

    public function proxyCommand(): string
    {
        return match ($this) {
            self::LARAVEL, self::STATAMIC => 'php artisan',
            self::WORDPRESS               => 'wp',
            self::NEXTJS                  => 'node',
            self::DJANGO                  => 'python manage.py',
            self::FASTAPI                 => 'python',
            self::SPRINGBOOT              => 'java -jar app.jar',
            self::DOTNET                  => 'dotnet',
        };
    }
}
```

---

## 2. Command Architecture & Deliverables

### Phase 1 — `django:new`
- **Command Class**: `app/Commands/Django/DjangoNewCommand.php`
- **Scaffolding Tool**: `django-admin startproject {name}` inside `python:3.12-slim` container
- **WSGI/ASGI Runner**: Gunicorn + Uvicorn worker class (`gunicorn config.asgi:application -k uvicorn.workers.UvicornWorker`)
- **Database**: PostgreSQL (`psycopg3`), MySQL (`mysqlclient`), MariaDB
- **Migrations**: K8s init container running `python manage.py migrate --noinput`
- **K8s Views**: `resources/views/k8s/django/` (`deployment.blade.php`, `ingress.blade.php`, `migrate-init-container.blade.php`)
- **Tests**: `tests/Feature/DjangoNewCommandTest.php`

### Phase 2 — `fastapi:new`
- **Command Class**: `app/Commands/FastAPI/FastApiNewCommand.php`
- **Scaffolding**: Async FastAPI project layout with Pydantic v2, SQLAlchemy 2.0, and Alembic
- **ASGI Runner**: `uvicorn main:app --host 0.0.0.0 --port 8000`
- **Database**: PostgreSQL (`asyncpg`), MySQL (`asyncmy`), MariaDB
- **Migrations**: K8s init container running `alembic upgrade head`
- **K8s Views**: `resources/views/k8s/fastapi/` (`deployment.blade.php`, `ingress.blade.php`, `migrate-init-container.blade.php`)
- **Tests**: `tests/Feature/FastApiNewCommandTest.php`

### Phase 3 — `springboot:new`
- **Command Class**: `app/Commands/SpringBoot/SpringBootNewCommand.php`
- **Scaffolding**: Spring Boot 3.4 initializer template (Java 21 LTS, Gradle Kotlin DSL, Spring Web, Spring Data JPA, Actuator)
- **Runtime**: `eclipse-temurin:21-jre-alpine`
- **Database**: PostgreSQL, MySQL, MariaDB
- **Migrations**: Flyway migration init container (`org.flywaydb:flyway-core`)
- **K8s Views**: `resources/views/k8s/springboot/` (`deployment.blade.php`, `ingress.blade.php`, `migrate-init-container.blade.php`)
- **Tests**: `tests/Feature/SpringBootNewCommandTest.php`

### Phase 4 — `dotnet:new`
- **Command Class**: `app/Commands/Dotnet/DotnetNewCommand.php`
- **Scaffolding Tool**: `dotnet new webapi -o {name}` inside `mcr.microsoft.com/dotnet/sdk:9.0`
- **Runtime**: `mcr.microsoft.com/dotnet/aspnet:9.0-alpine` (listening on port 8080)
- **Database**: PostgreSQL (`Npgsql.EntityFrameworkCore.PostgreSQL`), MySQL (`Pomelo.EntityFrameworkCore.MySql`), MariaDB
- **Migrations**: K8s init container running `dotnet ef database update`
- **K8s Views**: `resources/views/k8s/dotnet/` (`deployment.blade.php`, `ingress.blade.php`, `migrate-init-container.blade.php`)
- **Tests**: `tests/Feature/DotnetNewCommandTest.php`

---

## 3. Implementation Sequence

1. **Phase 1**: Add enum cases to `AppFramework` + `django:new` command, K8s views, Pest tests.
2. **Phase 2**: `fastapi:new` command, Alembic integration, K8s views, Pest tests.
3. **Phase 3**: `springboot:new` command, Spring Boot 3.4 template, Flyway integration, K8s views, Pest tests.
4. **Phase 4**: `dotnet:new` command, .NET 9 Web API template, EF Core integration, K8s views, Pest tests.
5. **Quality Assurance**: Pint formatting, PHPStan static analysis (`level 5`), full Pest test suite validation.
