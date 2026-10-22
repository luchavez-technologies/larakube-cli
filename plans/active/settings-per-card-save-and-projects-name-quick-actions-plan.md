# Implementation Plan: Settings Per-Card Save & Projects Name Quick Actions / IDE Column

## 🎯 Objectives
1. **Settings Page Per-Card Save Buttons**:
   - Provide a dedicated save button inside each configuration card on the Settings page so users don't have to scroll all the way to the bottom to persist changes.
   - Cards to enhance with individual save buttons:
     - **Default Code Editor & AI Agent**: Save editor preference with status feedback.
     - **Local Domain & TLD**: Save local vanity TLD.
     - **Workspace Navigation Mode**: Save hide projects preference.
     - **Experimental Features**: Save experimental mode toggle.
     - **Demo Mode**: Save demo mode and hidden servers.
     - **Default Cloud Provider**: Save chosen default cloud provider.
     - **LaraKube CLI Release Channel**: Save canary/stable channel.
     - **General & SSL**: Save Let's Encrypt email and share token.
   - Maintain the global bottom save button as an additional convenience or replace with per-card saving.

2. **Projects Page Table Layout**:
   - In the `Name` column:
     - Line 1: Project Name (link to project).
     - Line 2: Folder path with Folder icon and Copy button.
     - Line 3: Quick action buttons (`Up`, `Start`, `Stop`, `Down`) directly below the file path so actions are immediately adjacent to the project.
   - Rename the `QUICK ACTIONS` column to `IDE / AGENT` (or `IDE/Agent`).
   - In the `IDE / AGENT` column:
     - Render the preferred editor/agent with full label (e.g., `Claude Desktop`, `Antigravity`, `VS Code`) along with its `EditorLogo` and dropdown menu to switch or launch alternative editors/agents.
   - Preserve the right-aligned `Manage ->` column.

---

## 🏗️ Architecture & Component Design

### 1. Settings Page: Per-Card Save Buttons (`settings/index.tsx`)
- Create a reusable `<CardSaveButton form={form} ... />` inside each `<Card>`:
  - Button with `<Save className="size-3.5" />` and label `Save changes` / `Save`.
  - Submits the form via `form.post('/settings', { preserveScroll: true })`.
  - Shows processing state `Saving…` with spinner or checkmark when successful.
- For each card, add the save button in the card footer:
  - Default Code Editor & AI Agent
  - Local Domain & TLD
  - Workspace Navigation Mode
  - Experimental features
  - Demo Mode
  - Default Cloud Provider
  - CLI Release Channel
  - General & SSL

### 2. Projects Page: Name Column & IDE/Agent Column (`projects/index.tsx`)
- In `projects/index.tsx`:
  - `Name` column `td`:
    Move `<ProjectActions project={project} />` directly below the file path.
  - Table header: rename `Quick actions` to `IDE / Agent`.
  - In the `IDE / Agent` column:
    Render `<EditorMenu project={project} editors={editors} defaultEditor={defaultEditor} showFullLabel />` showing full editor name.

---

## 🧪 Testing & Verification
- `npm --prefix desktop run types:check`: TypeScript verification.
- `cd desktop && ./vendor/bin/pint --test`: Code style.
- `cd desktop && ./vendor/bin/phpstan analyse --memory-limit=1G`: Static analysis.
- `cd desktop && ./vendor/bin/pest tests/Feature/ProjectsTest.php tests/Feature/SettingsTest.php`: Feature tests.
- Full Pest suite verification.

---

## 🏷️ Addendum: Status Column & Local Status Streamlining
1. **Moved Status inside Name column**:
   - Removed standalone `Status` table column.
   - Positioned status badge (`Running`, `Paused`, `Missing`) in the upper-right corner of the `Name` cell with `justify-between items-center`.
2. **Eliminated "Needs a server" and "Ready to deploy"**:
   - "Needs a server" and "Ready to deploy" were legacy remote-cloud fallbacks that made no sense for local machines.
   - For local development, all idle / stopped / down / uninitialized projects are consistently reported as **`Paused`**.
   - If the folder is missing from disk, it reports **`Missing`**.
   - If containers are running, it reports **`Running`**.
   - In-flight operations report **`Starting…`**, **`Stopping…`**, or **`Creating…`**.

---

## 🌳 Addendum: Project Group Combobox & Icon Replacement
1. **Replaced Icon**: Replaced `FolderGit2` (which conflicts with the sidebar "Projects" menu item) with `FolderTree` across all grouping UI (`ProjectGroupCombobox`, `ProjectGroupSection`, `ProjectCard` badges, and `AssignGroupModal`).
2. **Interactive Group Combobox**:
   - Replaced static `—` in Table View with `<ProjectGroupCombobox />`.
   - If project has no group: renders an inviting `+ Add group` button.
   - If project has a group: renders a badge button `[FolderTree] {group} [ChevronsUpDown]`.
   - Clicking opens an inline search & type combobox:
     - Real-time search of existing groups.
     - Pressing Enter or clicking `Create group "{name}"` immediately creates and links the group.
     - Includes a `Remove from group` button to un-group.
     - Supports Escape and click-outside dismissal with autofocus.
   - Also integrated directly into Card View's action bar.
