# Plan: Rename Add/Remove Commands to Feature:Add and Feature:Remove with Framework Filtering

## Overview
As LaraKube expanded to support multi-framework workloads (Next.js, FastAPI, Django, Go Gin, Rust Axum, Spring Boot, Astro, Vite, etc.), the generic `add` and `remove` verbs became vague and confusing, presenting PHP/Laravel-specific options to non-PHP projects.

This plan renames `AddCommand` and `RemoveCommand` to `feature:add` and `feature:remove`, retains `add` and `remove` as backwards-compatible aliases, and introduces framework-aware filtering into the interactive configuration wizards.

---

## Architectural Changes

### 1. Renaming & Command Aliasing
- Rename `AddCommand` to `FeatureAddCommand`:
  - Signature: `feature:add {items?* : The database(s), feature(s), blueprint, or storage to add} {--dry-run : Show what will be done without making any changes}`
  - Description: `Add or swap databases, features, blueprints, or storage`
  - Aliases: `['add']`
- Rename `RemoveCommand` to `FeatureRemoveCommand`:
  - Signature: `feature:remove {items?* : The database(s), feature(s), blueprint(s), or storage to remove} {--dry-run : Show what will be removed without making changes}`
  - Description: `Remove architectural components (DB, Features, Storage, etc.)`
  - Aliases: `['remove']`

### 2. Framework-Aware Wizard Filtering
- In `AppFramework`:
  - Add `isPhp(): bool` (`in_array($this, [self::LARAVEL, self::STATAMIC, self::WORDPRESS], true)`).
  - Add `isLaravel(): bool` (`in_array($this, [self::LARAVEL, self::STATAMIC], true)`).
- In `ConfigData`:
  - Add `isPhp(): bool` (`$this->framework === null || $this->framework->isPhp()`).
  - Add `isLaravel(): bool` (`$this->framework === null || $this->framework->isLaravel()`).
- In `FeatureAddCommand`:
  - Hide Laravel Features (`horizon`, `reverb`, `pulse`, etc.) for non-Laravel frameworks.
  - Hide PHP Version, Server Variation (FrankenPHP, etc.), Operating System, and PHP Extensions for non-PHP frameworks.
  - Retain universal options (Databases, Cache Drivers, Object Storage, Cloud Configuration) across all frameworks.
- In `FeatureRemoveCommand`:
  - Only show Blueprints, Laravel Features, and PHP Extensions if applicable to the project's framework.

### 3. MCP & CLI Integration Updates
- `OrchestrateVerbTool`: Allow `feature:add` and `feature:remove` in `$allowedVerbs` while keeping `add` and `remove`.
- Update any command calls (`$this->call('feature:add', ...)`) where appropriate.

### 4. Tests & Verification
- Unit/Feature tests in `cli/tests/Feature/FeatureAddCommandTest.php` and `cli/tests/Feature/FeatureRemoveCommandTest.php`.
- Test running `feature:add` and `feature:remove` directly.
- Test running `add` and `remove` via aliases.
- Verify framework filtering for Laravel vs non-PHP projects (e.g. Next.js / Vite).
- Run `composer format`, `composer analyse`, `composer test`.
