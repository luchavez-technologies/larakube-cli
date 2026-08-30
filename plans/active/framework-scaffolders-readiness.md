# Tracker: framework scaffolder readiness

**Owner:** the operator declares readiness. Nothing here infers it.
**Why:** `new` (Laravel) is the only one that has genuinely been exercised, and
enough has changed around it since that it needs re-checking before it can be
called Ready again. The rest were written quickly and have not been run even
locally.

Test-file counts and code shape say what a command *intends*, not whether it
works. `snapshot:create` printed a green tick and applied nothing (commit
`a5f4ebc`) — that is the mistake this tracker exists to prevent, so nothing here
is marked Ready on the strength of reading its source.

## What "ready" means

A scaffolder is **Ready** when all five hold, on a clean directory:

1. `larakube <x>:new demo-app` completes without error.
2. The generated project's own toolchain accepts it — it builds/installs
   (`npm install`, `cargo build`, `mvn package`, `composer install`, …).
3. `larakube up` brings it up locally and the app answers on its health route.
4. It deploys to a real cluster environment and answers there.
5. Re-running the scaffolder in a fresh directory produces the same result.

Anything short of all five is **Partial** — say which step failed. Untouched is
**Unverified**, which is the honest default, not a criticism.

## Verification order

Agreed priority, in order. Everything else waits.

1. **Laravel** (`new`) — re-verify first; it is the only one with history and
   the most has changed around it.
2. **WordPress** (`wordpress:new`) — Bedrock, official CLI.
3. **Vite React + PocketBase backend** (`vite:new` + `data:init --engine=pocketbase`)
   — the one combination on this list that spans two commands.
4. **Next.js fullstack** (`nextjs:new`).

**Note on #3.** `vite:new` produces **no Kubernetes manifests** today, so the
"open decision" below stops being theoretical the moment this pairing is
attempted: either `vite:new` starts orchestrating infrastructure like the other
scaffolders, or the SPA is served some other way and the pairing is a documented
two-piece setup. Decide that before verifying, not during — otherwise the
verification will fail for a reason nobody chose.

## Status

Update the Status column as you verify. Do not mark Ready from reading code.

| Command | Stack | Scaffolds via | k8s infra | Tests | Status |
| --- | --- | --- | --- | --- | --- |
| `new` | Laravel | templated | yes | 6 files | **Was working** — re-verify (much has changed since) |
| `adonisjs:new` | AdonisJS v6 (Node, Lucid ORM) | official CLI | yes | 0 | Unverified |
| `astro:new` | Astro (minimal/blog/portfolio/docs) | official CLI | **no** | 1 | Unverified |
| `axum:new` | Axum (Rust, Tokio, SQLx) | templated | yes | 0 | Unverified |
| `django:new` | Django (Python) | official CLI | yes | 0 | Unverified |
| `docs:new` | Docusaurus | official CLI | **no** | 1 | Unverified |
| `dotnet:new` | ASP.NET Core 9.0 Web API | official CLI | yes | 0 | Unverified |
| `fastapi:new` | FastAPI (Pydantic v2, Alembic) | templated | yes | 0 | Unverified |
| `gin:new` | Gin (Go, GORM) | templated | yes | 0 | Unverified |
| `nestjs:new` | NestJS (TypeScript, Prisma, Terminus) | official CLI | yes | 0 | Unverified |
| `nextjs:new` | Next.js (standalone, Redis cache handler) | official CLI | yes | 1 | Unverified |
| `springboot:new` | Spring Boot 3.4 (Java 21 LTS) | templated | yes | 0 | Unverified |
| `statamic:new` | Statamic CMS | official CLI | yes | 0 | Unverified |
| `vite:new` | Vite SPA (React/Vue/Svelte/Solid) | official CLI | **no** | 1 | Unverified |
| `wordpress:new` | WordPress (Bedrock) | official CLI | yes | 1 | Unverified |

Aliases: `wp:new` → `wordpress:new`, `next:new` → `nextjs:new`. Same class, so
verifying one verifies both.

`cloud:new` is deliberately absent — it creates a cloud environment, not a
project, and does not belong in this list.

## What the columns mean

**Scaffolds via.** *official CLI* runs the framework's own creator
(`composer create-project`, `npx create-next-app`, `dotnet new`, `django-admin`),
so the generated project is whatever upstream produces and tracks their updates.
*templated* writes the project from templates in this repo — the right choice
where no universal creator exists (Rust, Go, Java), but it means the template
ages and is ours to maintain.

**k8s infra.** Whether the command runs `orchestrateProjectScaffolding()`, which
produces the Dockerfile and Kubernetes manifests. Three do not: `astro:new`,
`docs:new` and `vite:new` scaffold source only. Whether that is a gap or correct
for a static site is a decision, not an oversight — record which once you have
run them.

**Tests.** Files in `tests/` mentioning the command class. Only `new` has real
coverage. A count here is not evidence the command works; every one of these is
a signature or smoke test, not an end-to-end scaffold.

## Follow-ups this surfaced

- Nine of fifteen have no test file at all.
- The three without k8s infra need an explicit decision, not a default.
- No scaffolder is exercised in CI. A smoke test that runs one into a temp
  directory would catch the class of failure where a scaffolder reports success
  and writes nothing.
