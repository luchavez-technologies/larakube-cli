# Plan: Private `larakube-desktop` source, public `larakube-desktop-releases`

## Goal

Keep the `desktop` source private. Publish installers (`.dmg`, `.exe`, `.AppImage`) and the auto-update feed (`latest*.yml`) to the public repo [`luchavez-technologies/larakube-desktop-releases`](https://github.com/luchavez-technologies/larakube-desktop-releases), which also takes issues.

## The constraint that sets the order

Every installed app reads its update feed from a URL baked in at build time: `LARAKUBE_UPDATE_URL`, a generic electron-builder feed (`nativephp/electron/electron-builder.mjs`). The `GITHUB_*` updater settings are unused while that is set.

Today that URL is `github.com/luchavez-technologies/larakube-desktop/releases/...`. If `larakube-desktop` goes private first, the URL returns 404 and those installs never update again. So the source repo stays public until one build with the new URL has reached installed apps through the old feed.

## Steps

1. **Done (desktop, in code):** `release.yml` publishes to both repos once `RELEASES_TOKEN` is set.
   - Without the token it behaves exactly as before.
   - With it, `checks` sets `update_repo` to the releases repo, so new builds point their updater there.
   - The publish step runs the same canary/stable logic against each repo.
   - The moving `canary` git tag stays on the source repo. The releases repo's `canary` release is created once on its own default branch, and only its assets change after that.
2. **Done (desktop, in code):** the Help menu's issues/discussions links and the Help card's source link point at the releases repo.
3. **Give the releases repo a first commit:** a README, so it has a default branch for `gh release create`.
4. **Create `RELEASES_TOKEN`:**
   - A fine-grained PAT, resource owner `luchavez-technologies`, repository access limited to `larakube-desktop-releases`, permission **Contents: Read and write**.
   - Save it as an Actions secret on `larakube-desktop`.
   - It expires (at most a year unless the org allows longer). Note the date, or switch to a GitHub App token (`actions/create-github-app-token`) later.
5. **Enable Discussions** on the releases repo, since the Help menu links to them.
6. **Ship one canary** (any push to `develop`). Confirm:
   - the release appears publicly on the releases repo, with installers and `latest.yml`;
   - an installed Windows canary updates to it, and its next update check hits the releases repo.
7. **Repoint the docs** (`docs/`), only once step 6 has a release there, since the download page links straight to its files:
   - `src/pages/download.tsx` and `src/components/Landing/DownloadDropdown.tsx`: `REPO` constant;
   - `docs/community/changelog.md`: the releases link;
   - `docs/desktop/windows.md`: the issues link;
   - `docs/index.md`: the "LaraKube Desktop" source link (point to the releases repo, labelled "Desktop releases & issues").
8. **Make `larakube-desktop` private.**
9. **Remove the bridge:** publish only to the releases repo, and drop the source-repo publish plus the `update_repo` switch.

Keep internal names such as the `larakube-desktop` MCP server name unchanged. Only GitHub repository URLs move.
