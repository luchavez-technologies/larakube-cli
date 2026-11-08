# Static-site deploy: point to free hosting, don't build a full integration

## Status: scope cut down 2026-11-09 — see "Decision" below (original full-integration research kept for later reference)

## Context

LaraKube's whole deploy model assumes a VPS or managed Kubernetes cluster exists. That's real money and real complexity for someone who just built a static Astro/Vite/Docusaurus site and has no backend at all — the exact case the "portfolio" project in the Desktop screenshot is in.

## Decision: defer the full integration, ship a small build+links helper instead

Investigated building a real OAuth-connected deploy-to-Cloudflare-Pages flow (full research below, kept for reference). **Decided against it**: Cloudflare Pages/Netlify/Vercel/GitHub Pages already have near-zero-friction onboarding (drag a built folder onto a dashboard, done) — replicating that inside LaraKube means a new non-cluster environment type (`EnvironmentData` is entirely cluster-shaped today), a new `CliTool::WRANGLER` dependency, OAuth scope changes, and auditing every place that assumes `$environment->cloud` exists — a lot of surface area for marginal convenience over "drag a folder."

**What ships instead**: a small `larakube static:build` command that builds the project locally (reusing `AppFramework::buildScriptCandidates()`/`staticPublicDir()`, already in the codebase) and prints the output folder's path alongside direct links to manually deploy it on Cloudflare Pages, Netlify, Vercel, and GitHub Pages. No OAuth, no new environment type, no new CLI dependency. Solves the actual problem ("newbie has no server and no money") without the integration cost.

### Implementation

- New command: `cli/app/Commands/StaticBuildCommand.php`, signature `static:build {environment=local}` (or no argument — check whether an environment argument is even meaningful here, since this never touches a cluster) `{--json}`.
- Reuse `getProjectConfig()` + `AppFramework::isStaticSpa()` to refuse cleanly on a non-static project ("this project has a backend — static:build is for static-only sites").
- Run the build via `buildScriptCandidates()` (same resolution `PreviewUpCommand` already uses) with `runStreaming()`/`withSpin()` for output, same CLI output conventions as every other command.
- On success, print:
  - The resolved absolute path to `staticPublicDir()`'s output folder.
  - Four plain links/instructions, no API calls: Cloudflare Pages (dashboard upload), Netlify (drop-zone), Vercel (CLI/dashboard), GitHub Pages (push to a `gh-pages` branch or docs/ folder — the one genuinely different mechanism, worth a one-line note since it's git-based, not drag-and-drop).
- `--json` mode: emit the build dir path + the four URLs as structured data, so Desktop can render it as a card with working links rather than needing to parse CLI text.
- Desktop (deferred like the rest of Desktop work — Antigravity caution still applies): a simple result card, not a modal/flow — "Build finished. Your site is ready to deploy:" + the folder path (with "Show folder" / "Open in editor" style buttons this UI already has elsewhere) + four link buttons.

---

## Original research: full OAuth-connected integration (not being built now, kept for reference)

Investigated building Cloudflare Pages deploy as a first-class environment type, reusing the OAuth client already shipped for DNS. Verified Cloudflare's own CI docs deploy via Wrangler (not a clean documented raw API), found the environment model (`EnvironmentData`) has no non-cluster concept at all, and `larakube env`/Desktop's "+ New environment" both assume a server. Full writeup of what exists, what Cloudflare Pages' real API requires, and the environment-model changes needed is preserved in this plan's git history (the version of this file before 2026-11-09) if this gets revisited later — e.g. if a paying LaraKube Cloud tier wants a one-click "point a real domain at your static site" flow, which is a different, higher-value case than a free newbie tier.
