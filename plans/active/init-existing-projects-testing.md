# Test Plan: `larakube init` for existing non-Laravel projects

**Status:** ⛔ NOT STARTED

`init` now detects the framework (`AppFramework::detect()`), confirms it with a
picker of deployable frameworks, and routes static sites and Next.js away from
the Laravel wizard. Static sites get the same blueprint `astro:new`/`vite:new`/
`docs:new` write (`ConfigData::forStaticSite()`); Next.js gets the same project
changes `nextjs:new` makes (`PreparesNextjsProject`), each skipped when present.

Run from a checkout **without** `.larakube.json`. Commit or stash first, since
`init` writes files into the project.

## 1. Docusaurus (the `docs/` site)
- [ ] `larakube init --dry-run`: the picker pre-selects **Docusaurus (detected)**. The preview lists `Dockerfile.static`, `Caddyfile`, and the local/cloud overlays. No files are written.
- [ ] `larakube init`, then confirm: `.larakube.json` has `"framework": "docusaurus"` and only a `local` environment; `.dockerignore` excludes `node_modules` and `build`.
- [ ] The package manager matches the lockfile (`package-lock.json` → npm).
- [ ] `larakube up`: the site serves on its local host.
- [ ] Running `larakube init` again warns "ALREADY INITIALIZED" and, once confirmed, only regenerates manifests.

## 2. Picker and flags
- [ ] In an empty directory, `larakube init --no-interaction` fails with "Could not detect … Pass --framework=".
- [ ] `larakube init --framework=vite --dry-run` skips the picker.
- [ ] `larakube init --framework=django` is refused ("can't be deployed by the LaraKube CLI yet").
- [ ] In a Laravel app, the picker pre-selects **Laravel (detected)** and the existing wizard runs unchanged.

## 3. Next.js (a create-next-app project, ideally with `src/app`)
- [ ] `larakube init --dry-run` lists the project changes: standalone `next.config`, `cache-handler.mjs` plus its npm install, Prisma, `src/app/api/health/route.ts`, and the `.env` connection vars. The project is untouched.
- [ ] `larakube init --no-plex`: the changes are applied and a second `--dry-run` lists none of them.
- [ ] `larakube up`: `/api/health` returns 200.

## Report back
Which step failed and its output, or "all passed".
