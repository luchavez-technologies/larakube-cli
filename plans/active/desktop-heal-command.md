# Plan: Desktop Heal Command (Infrastructure Maintenance)

## Goal
Add a first-class **Heal Infrastructure** action in the LaraKube Desktop UI so developers can non-destructively regenerate their project's `.infrastructure/k8s/` manifests, `Dockerfile.php`, `Dockerfile.node`, and `.dockerignore` from their `.larakube.json` blueprint with 1-click.

---

## Technical Details

### 1. Backend (`desktop`)
- **RunKind**: Add `RunKind::HealProject = 'heal-project'` in `desktop/app/Enums/RunKind.php`.
- **ProjectController**: Add `heal(Project $project, CliRunner $runner)` which launches:
  `$runner->run($project, RunKind::HealProject, "Heal {$projectName}", ['heal', '--force', '--prune'])`.
- **Routes**: Add `POST /projects/{project}/heal` -> `projects.heal` in `desktop/routes/web.php`.
- **Wayfinder**: Regenerate route definitions.

### 2. Frontend (`desktop/resources/js`)
- **Project Settings Card**: In `desktop/resources/js/pages/projects/show.tsx`, update `ProjectSettingsCard`:
  - Add an **Infrastructure Maintenance** section with description explaining that it regenerates manifests and Dockerfiles from `.larakube.json` without touching databases.
  - Add a **"Heal Infrastructure"** button with `HeartPulse` icon.
  - Include confirmation modal describing what will be regenerated.
  - When triggered, starts the run which automatically streams in the `RunDrawer`.

### 3. Testing & Verification
- Add Feature test in `desktop/tests/Feature/ProjectsTest.php` asserting that `projects.heal` triggers the `heal-project` run with `['heal', '--force', '--prune']`.
- Verify linting with Pint (`composer lint:check`), Biome (`npm run check`), and TypeScript (`npm run types:check`).
