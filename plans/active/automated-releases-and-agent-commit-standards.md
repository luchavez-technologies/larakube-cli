# Automated Releases, SemVer 0.x Strategy, and AI Agent Commit Standards

## Status: Active
**Date:** 2026-09-24  
**Author:** Pair programming (User & Antigravity)  

---

## 1. Problem Statement & Motivation
LaraKube CLI has migrated its remote origin to Forgejo (`ssh://git@git.luchtech.dev:2222/luchaveztech/larakube-cli.git`).
Currently:
1. `UpdateCommand.php` still hardcodes GitHub API and GitHub download URLs (`api.github.com/repos/luchavez-technologies/...`), meaning `larakube update` and `larakube update --canary` fail for users.
2. The release process on `main` requires manual tagging (`git tag vX.Y.Z`).
3. Releases should be automated based on Conventional Commits when code lands on `main`.
4. However, commits are primarily authored by AI agents (Claude, Antigravity, etc.). Without codified rules and an Architectural Decision Record (ADR), agents cannot consistently decide between `feat:`, `fix:`, `chore:`, or `BREAKING CHANGE:`.
5. The project is currently at `v0.34.0` (pre-1.0.0). In SemVer, pre-1.0.0 has distinct rules (breaking changes bump minor, not major; reaching `v1.0.0` is a deliberate human milestone).

---

## 2. Architectural Decisions

### A. ADR 0025: Conventional Commits, Semantic Versioning, and Automated Releases
Codify an official ADR in `cli/docs/decisions/0025-conventional-commits-and-automated-releases.md` defining:
1. **Commit Structure**:
   ```
   <type>(<scope>): <summary in imperative mood>

   [optional body explaining motivation and context]

   [optional footer(s)]
   ```
2. **Taxonomy for AI Agents**:
   - `feat(<scope>)`: New end-user or developer functionality, new commands, new options.
   - `fix(<scope>)`: Bug fixes in CLI execution, manifests, or integrations.
   - `perf(<scope>)`: Performance improvements.
   - `refactor(<scope>)`: Internal code restructure without public API or behavioral changes.
   - `test(<scope>)`: New or updated tests.
   - `docs(<scope>)`: Documentation, ADRs, or guides.
   - `chore(<scope>)` / `ci(<scope>)`: CI workflows, build scripts, formatting, dependencies.
3. **What Constitutes a `BREAKING CHANGE` in LaraKube**:
   - Removing or renaming CLI commands or flags.
   - Changing the schema or interpretation of `.larakube.json`.
   - Changing cluster manifest contract defaults in a non-backwards-compatible way.
   - Changing public signatures of shared framework services (`EnvironmentResolver`, etc.).
   - *Syntax:* `feat(command)!: description` or footer `BREAKING CHANGE: description`.
4. **Pre-v1 (`0.y.z`) SemVer Rules**:
   - Current version: `0.34.0`.
   - `BREAKING CHANGE:` or `feat:` ➔ Bumps **MINOR** (`0.34.0` ➔ `0.35.0`).
   - `fix:` or `perf:` ➔ Bumps **PATCH** (`0.34.0` ➔ `0.34.1`).
   - `chore:`, `docs:`, `test:`, `refactor:` ➔ No release cut (unless combined with `feat`/`fix`).
5. **Graduating to `v1.0.0`**:
   - Never automated by accident.
   - Triggered either by explicit commit footer `Release-As: 1.0.0` or manual `git tag v1.0.0`.
   - Post-v1: `BREAKING CHANGE` bumps major (`2.0.0`), `feat` bumps minor (`1.1.0`), `fix` bumps patch (`1.0.1`).

### B. Modernize `UpdateCommand.php` (Plan C)
1. Point default endpoints to Forgejo:
   - Base URL: `https://git.luchtech.dev` (configurable via `FORGEJO_URL` / `config('app.forgejo.url')`)
   - Repository: `luchaveztech/larakube-cli` (configurable via `FORGEJO_REPOSITORY` / `config('app.forgejo.repository')`)
   - Latest release API: `/api/v1/repos/{repo}/releases/latest`
   - Canary release API: `/api/v1/repos/{repo}/releases/tags/canary`
   - Binary download: `/{repo}/releases/download/{version}/{binaryName}`
2. Update user-facing strings ("Forgejo" / "release server" instead of "GitHub").
3. Update `cli/tests/Feature/UpdateCommandTest.php` to mock and assert against Forgejo URLs.

### C. Automated Release Workflow on `main` (Plan B)
1. Provide `cli/scripts/calculate-next-version.php`:
   - Runs in PHP 8.4 (already part of CI runner).
   - Reads git tags to find latest release tag.
   - Inspects `git log <latest-tag>..HEAD`.
   - Evaluates SemVer rules (Pre-v1 and Post-v1), supports `Release-As:` footer.
   - Outputs release notes markdown and JSON payload with `should_release`, `version`, `notes`.
2. Provide automated tests for the script in `cli/tests/Unit/CalculateNextVersionTest.php`.
3. Update `.forgejo/workflows/ci.yml` (the only pipeline — the GitHub copy was
   deleted, since Forgejo is the sole remote and its release action uses a URL
   in `uses:`, which GitHub Actions cannot parse):
   - On push to `develop`: build and publish `canary` release.
   - On push to `main`: run version calculation. If `should_release == true`:
     - Tag commit with calculated version.
     - Build all standalone binaries.
     - Publish to Forgejo using `actions/forgejo-release@v2`.
     - Update Homebrew tap with new version & SHA256 checksums.

### D. Codify Agent Rules
Update:
- `AGENTS.md` (root)
- `.agents/AGENTS.md` (root)
- `cli/CLAUDE.md`
- `cli/GEMINI.md`
- `cli/docs/decisions/README.md` (ADR index)

---

## 3. Execution Checklist
- [ ] Create ADR `cli/docs/decisions/0025-conventional-commits-and-automated-releases.md`
- [ ] Update `cli/docs/decisions/README.md` index
- [ ] Update `AGENTS.md`, `.agents/AGENTS.md`, `cli/CLAUDE.md`, and `cli/GEMINI.md` with commit standards
- [ ] Modernize `cli/app/Commands/UpdateCommand.php` for Forgejo
- [ ] Update `cli/tests/Feature/UpdateCommandTest.php`
- [ ] Create `cli/scripts/calculate-next-version.php` and its unit test
- [ ] Update `.forgejo/workflows/ci.yml`
- [ ] Run `composer format`, `composer analyse`, `composer test`
- [ ] Commit all changes with pre-commit verification
