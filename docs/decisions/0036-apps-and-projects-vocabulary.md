# 0036: "Apps" and "Projects" Replace "Cluster Tools" and "Your Apps" in What Users See

**Status:** Accepted

## Context

The docs used two names for the two kinds of workload:
- **Cluster Tools:** ready-made software installed by `{tool}:init`, from ADR 0014.
- **Your apps:** code you build. A project is any folder with a `.larakube.json`.

LaraKube Cloud is adding an app store, and non-developers are its first audience. They think in "apps", not "tools". The store will also let teams publish their own projects for others to install, so one team's project can become another team's app. That makes the two terms stages of one thing, not two separate kinds.

## Decision

1. **App** means anything installable from the store: LaraKube's official apps, such as OpenBao or Supabase, and published releases of other teams' projects.
2. **Project** means something you build: a folder with a `.larakube.json`, deployed through your own CI/CD, as ADR 0014 describes.
3. **Publish** is the step that turns a project release into an app.
4. **Users see these names** in Cloud, Desktop, the docs and the store. Desktop's "Projects & Apps" tab becomes "Projects".
5. **The LaraKube CLI keeps its code names and commands** (`ClusterTool`, `{tool}:init`, `tool:list`). The commands are the public interface that Desktop and people's scripts depend on, so renaming them would break them for no user-visible gain. `app:*` aliases can come later.
6. **New code uses "app".** LaraKube Cloud's new catalog tables are `apps`, `app_engines`, `app_releases` and so on.

## Consequences

- The docs page `architecture/cluster-tools-and-your-apps` is retitled "Apps and Projects". Its URL stays the same, so existing links keep working.
- For a while the CLI's command names say "tool" while its docs say "app". The docs explain this once, where the two meet.
- ADR 0014's split stands unchanged: apps are installed and updated with `{tool}:init`, with no CI/CD, and projects deploy through CI/CD. Only the names change.
- Community apps are published as data-only YAML, never Blade, because Blade runs as PHP on the installer's machine. See `plans/active/cloud-catalog-and-entitlements.md`.
