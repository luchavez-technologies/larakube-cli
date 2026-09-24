# ADR 0025: Conventional Commits, Semantic Versioning, and Automated Releases

## Status
Accepted (2026-09-24)

## Context
LaraKube development is driven heavily by human pair programming with autonomous AI agents (Claude, Antigravity, etc.). With the migration to Forgejo (`git.luchtech.dev/luchaveztech/larakube-cli`) and automated CI/CD pipelines, releases must be cut reliably and deterministically without manual human tagging for every patch or minor feature.

However, an automated release pipeline depends completely on structured commit history. Without codified standards:
1. AI agents use inconsistent commit message conventions (`Update foo`, `fix bug`, `Add new feature`).
2. Agents may inadvertently trigger breaking changes or premature major releases.
3. The project is currently in initial development (`0.y.z`, specifically `v0.34.0`). Under Semantic Versioning (SemVer 2.0.0), pre-1.0.0 software treats breaking changes differently: breaking changes bump minor, not major. Graduating to `v1.0.0` is an intentional project milestone that must never occur accidentally.

## Decision

### 1. Mandatory Conventional Commit Syntax
All commits authored by humans and AI agents MUST follow the Conventional Commits specification:
```text
<type>(<scope>): <summary in imperative mood>

[optional body explaining motivation, rationale, and non-obvious context]

[optional footer(s)]
```

### 2. Commit Type Taxonomy & Release Behavior
| Type | Purpose | User-Facing? | Release Impact |
| :--- | :--- | :--- | :--- |
| `feat` | New end-user command, flag, workflow, or capability | Yes | Bumps **Minor** (`0.34.0` ➔ `0.35.0`) |
| `fix` | Bug fix in CLI command, manifest generation, or runtime | Yes | Bumps **Patch** (`0.34.0` ➔ `0.34.1`) |
| `perf` | Performance improvement in CLI or container runtime | Yes | Bumps **Patch** (`0.34.0` ➔ `0.34.1`) |
| `refactor` | Code restructuring without behavioral or public API changes | No | No release |
| `test` | Adding, updating, or fixing tests | No | No release |
| `docs` | Documentation, ADRs, blueprints, or guides | No | No release |
| `chore` | Maintenance tasks, dependency updates, tooling | No | No release |
| `ci` | CI/CD workflows, Forgejo Actions, release scripts | No | No release |

### 3. Concrete Criteria for `BREAKING CHANGE`
A commit qualifies as a breaking change ONLY IF it breaks backwards compatibility for end users or existing cluster deployments:
- **Breaking**:
  - Renaming or removing an existing CLI command (e.g. deleting `data:init` or renaming `cloud:init`).
  - Renaming or removing an existing command option/flag.
  - Modifying `.larakube.json` blueprint schema in a way that invalidates existing configurations.
  - Altering cluster manifest contracts such that existing persistent volume claims or workloads are corrupted or fail to reconcile.
  - Altering public API signatures of core framework services (`EnvironmentResolver`, etc.).
- **Non-Breaking**:
  - Adding a new command or new optional flag.
  - Changing prompt wording, spinners, table formatting, or console output styles.
  - Refactoring internal classes or helpers.
  - Fixing broken behavior where the prior implementation was erroneous.

**Syntax for Breaking Changes:**
Use `!` after type/scope or include a `BREAKING CHANGE:` footer:
```text
feat(cloud)!: drop deprecated --legacy-dns flag

BREAKING CHANGE: The --legacy-dns flag has been removed. Use --dns instead.
```

### 4. Pre-v1 (`0.y.z`) Semantic Versioning Rules
As long as the current major version is `0`:
1. `feat:` or `BREAKING CHANGE:` bumps the **MINOR** version (`0.34.0` ➔ `0.35.0`).
2. `fix:` or `perf:` bumps the **PATCH** version (`0.34.0` ➔ `0.34.1`).
3. Under no circumstances may an automated pipeline or AI agent bump to `v1.0.0` unless explicitly directed.

### 5. Graduation to `v1.0.0`
Graduating to `v1.0.0` is a milestone requiring deliberate intent. It is triggered by either:
1. **Commit Footer Override**: Including `Release-As: 1.0.0` in the commit footer on `main`.
2. **Explicit Git Tag**: Manually tagging `git tag v1.0.0 && git push origin v1.0.0`.

Once the latest tag is `>= 1.0.0`, standard post-v1 SemVer activates (`BREAKING CHANGE` ➔ Major, `feat` ➔ Minor, `fix` ➔ Patch).

### 6. Dual-Branch Release Pipeline
- **`develop` branch:** Every push publishes/updates the **`canary`** release on Forgejo.
- **`main` branch:** Merging code into `main` automatically runs the version calculator script (`scripts/calculate-next-version.php`), cuts the calculated SemVer tag (`vX.Y.Z`), creates the official Forgejo release with auto-generated release notes, and updates the Homebrew tap formula.

## Consequences
- AI agents have unambiguous guidelines when selecting commit types and scopes.
- Unintentional breaking releases or accidental `v1.0.0` promotions are prevented.
- Version calculation and changelog generation are completely automated and reproducible in CI.
