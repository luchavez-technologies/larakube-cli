# Graduating a workspace to production

Part of `remote-workspaces.md`. A workspace edits code on a dev server; production is reached through the existing project flow, so the only new thing is the hand-off between them.

## Why not "Remote projects" with the same buttons
New environment and Deploy need the project folder on the computer running Desktop, a container engine (`cloud:deploy` builds and sideloads an image) and the CLI's cluster access. A workspace has none of these by design (no Docker, no cluster API, no cluster key in an AI-controlled shell). A location switcher would show buttons that cannot work. Revisit only if a workspace can ever deploy without holding a cluster credential.

## The path
1. **Push.** The workspace pushes its branch with the deploy key (already built). Branch protection on the repository is what keeps the default branch safe; the pre-push hook is only a guard.
2. **Bring it onto this computer.** Desktop clones the repository and registers it as a Project (CLI `clone`; check that it fits, otherwise a plain `git clone` into a chosen folder and the existing "Add existing folder").
3. **New environment.** The existing flow (`env <environment> --context=...`), which writes the environment into `.larakube.json` and the server binding into `.larakube.local.json`.
4. **Address.** `cloud:configure <environment> --only=hosts --web-host=...`, already wired in Desktop.
5. **CI/CD.** `cloud:configure <environment> --only=registry` then `--only=ci`. This is the piece Desktop does not use today (it only calls `--only=hosts`). It generates the GitHub Actions (or GitLab, Forgejo) pipeline and uploads the deploy secrets, so a merge to the deploy branch ships the app without Docker on anyone's machine. That is the right default for people who work in workspaces.
6. **Commons.** `cloud:configure` offers `plex:join` on the production server's own Commons. Production data never shares a Postgres with workspaces, which is why the dev server is separate.
7. **Deploy.** Merge, or the existing Deploy button when the person does have Docker locally.

## What `cloud:configure` needs before Desktop can drive it
- It is guided and prompt-heavy; non-interactive use needs `--platform`, `--registry`, `--image`, `--branch` and the gate flags. Desktop must not own that list: add a machine-readable spec (the pattern of `tool:init` fields and `new:frameworks`) so the form is drawn from the CLI.
- `--json` output with the result, as the other commands Desktop drives have.
- The secret upload shells out to `gh` (or `tea`/GitLab variables) on the user's machine, so Desktop must check that `gh` is installed and logged in, and say so before running (the Setup page already tracks CLI tools and logins).
- It reads the project's `.env` and regenerates manifests, so it must run in the cloned project folder.

## Desktop
A **Graduate** panel on the workspace card with the steps above as a checklist, each step a button that runs the existing command and shows done/not done from the CLI (`.larakube.json` has the environment; the pipeline file exists; the secrets exist). It opens the new Project when the clone finishes. Nothing here is built.

## Order
After the image/runtime work and `workspace:up`: the `cloud:configure` spec and `--json` first (they help Projects too, not only workspaces), then the Graduate panel.
