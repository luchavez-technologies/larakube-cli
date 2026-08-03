# `larakube clone` — Plan

## What it is

A single command that clones any Laravel repo and prepares it for LaraKube CLI in one shot. Think `git clone` + `larakube init` fused together.

```bash
larakube clone https://github.com/user/my-app
larakube clone git@github.com:user/my-app.git
larakube clone user/my-app          # shorthand → GitHub
larakube clone user/my-app my-dir   # clone into a custom directory name
```

---

## Why it matters

Right now, a new dev joining a LaraKube CLI project must:
1. `git clone <repo>`
2. `cd <dir>`
3. `composer install`
4. `cp .env.example .env` + fill values
5. `larakube init` (or figure out if the project already has `.larakube.json`)

`larakube clone` collapses this to one command and handles the ambiguity of "is this already a LaraKube CLI project or not?"

---

## Signature

```
clone {repo : GitHub URL, git URL, or user/repo shorthand} {directory? : Target directory (defaults to repo name)}
      {--branch= : Branch to clone}
      {--env= : Environment to prepare (default: local)}
      {--no-install : Skip composer install}
```

---

## Flow

```
1. Resolve the repo URL:
   - Full HTTPS/SSH URL → use as-is
   - "user/repo" shorthand → https://github.com/user/repo.git
   - Future: GitLab/Bitbucket via --provider flag (later phase)

2. git clone <url> [directory] [--branch]
   - Use the same getGhCommand() / plain git detection (git is always present)
   - Show a spinner

3. cd into the cloned directory

4. Detect project state:
   a. Has .larakube.json → already a LaraKube CLI project ("larakube init" was run before)
      → Skip init questions; just run composer install + .env setup + announce ready
   b. Has composer.json but no .larakube.json → Laravel project, not yet LaraKube CLI
      → Run the full `larakube init` wizard (same as calling init directly)
   c. Neither → warn that this doesn't look like a Laravel project; offer to continue anyway

5. composer install (unless --no-install)
   - Detect if composer is installed natively; if not, run via Docker (like gh fallback)

6. .env bootstrap:
   a. .env already exists → leave it alone (developer's own copy)
   b. .env.example exists → copy to .env + run `php artisan key:generate`
   c. Neither → warn

7. If state was (b): run the larakube init wizard for the local environment
   → This sets up .larakube.json, manifests, etc.

8. Print a "ready" summary:
   - Project dir
   - Next step: larakube up
```

---

## Edge cases

- **Private repo**: git clone will prompt for credentials normally (SSH key or PAT). No special handling needed — falls through to git.
- **Already cloned / directory exists**: git clone will fail; catch and tell the user to `cd` into the existing dir and run `larakube init` directly.
- **Non-GitHub hosts**: full URLs work as-is (GitLab, Bitbucket, self-hosted). The `user/repo` shorthand is GitHub-only; add `--provider=gitlab` later.
- **No composer**: fall back to `docker run --rm -v $(pwd):/app composer install` (same philosophy as gh Docker fallback).
- **Monorepo**: if the Laravel app is in a subdirectory, the user points `larakube init` at it via the existing `--path` flag (if we have one) or cds in manually. Not a clone concern.

---

## Files to create / touch

| Path | What |
|---|---|
| `app/Commands/CloneCommand.php` | The command |
| `app/Traits/ClonesRepositories.php` | git clone + URL resolution helpers (keep pure for testing) |

`larakube init` is already `InitCommand.php` — `clone` calls it internally (via `$this->call('init', [...])`) after cloning.

---

## Phasing

- **Phase 1 (MVP)**: GitHub HTTPS/SSH + `user/repo` shorthand, composer install, .env bootstrap, auto-detect LaraKube CLI project vs fresh Laravel, call `init` if needed.
- **Phase 2**: `--provider=gitlab|bitbucket`, monorepo `--path` support, composer Docker fallback.
- **Phase 3**: `larakube clone` from a LaraKube CLI bundle URL (airgap scenario — clone + install in one shot).
