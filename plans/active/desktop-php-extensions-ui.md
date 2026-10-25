# Plan: Desktop PHP Extensions & Runtime Management UI

## Goal
Provide a native, first-class UI in LaraKube Desktop on the project page for developers to view, add, and remove PHP extensions (`additionalExtensions`) in `.larakube.json` and `Dockerfile.php`, with quick-add presets and 1-click rebuild prompt.

---

## Technical Architecture

### 1. Backend
- **`ProjectInspector.php`**: Expose `additionalExtensions: string[]` and `phpVersion: string` from `.larakube.json`.
- **`ProjectController.php`**:
  - `addExtension(Request $request, Project $project)`:
    - Validates extension string (`alpha_dash`).
    - Updates `.larakube.json` (`additionalExtensions`).
    - Updates `Dockerfile.php` (appends to `RUN install-php-extensions` or injects block into `base` stage).
    - Clears inspector cache and redirects back.
  - `removeExtension(Request $request, Project $project)`:
    - Removes from `additionalExtensions`.
    - Updates `Dockerfile.php` (removes from `RUN install-php-extensions` line, or removes block if no extensions remain).
    - Clears inspector cache and redirects back.
- **`routes/web.php`**:
  - `POST /projects/{project}/extensions/add` -> `projects.extensions.add`
  - `POST /projects/{project}/extensions/remove` -> `projects.extensions.remove`
- Regenerate Wayfinder route definitions.

### 2. Frontend
- **Types (`types/larakube.ts`)**: Add `additionalExtensions?: string[]` and `phpVersion?: string` to `Project`.
- **Component (`components/php-extensions-card.tsx`)**:
  - Displays PHP runtime badge (e.g. `PHP 8.5 · fpm-nginx`).
  - Active extensions as removable tag pills with `X` button.
  - Quick-add suggestions for common extensions (`gd`, `imagick`, `intl`, `soap`, `pcntl`, `redis`, `zip`, `mongodb`, `bcmath`).
  - Custom text input with `Plus` button for any valid PHP extension.
  - Helpful hint: *"Rebuild image to apply changes to your container"* with a direct `Rebuild / Up` trigger.
- **Integration (`pages/projects/show.tsx`)**: Render in the right column above `ProjectSettingsCard` (only for PHP/Laravel projects).

### 3. Testing & Verification
- Feature test in `desktop/tests/Feature/ProjectsTest.php` testing both `addExtension` and `removeExtension`.
- Biome check (`npm run check`), TypeScript (`npm run types:check`), Pint (`composer lint:check`), and full test suite.
