# Share: one way, and it works

## Problem
There are now several ways to make a project public and none of them is the one to use:
- `larakube share`: quick tunnels, one random `trycloudflare.com` address per service. Breaks Vite (scripts load from another address), uploads and websockets, and the addresses change every run.
- `larakube share --token`: a tunnel made by hand in Cloudflare, with its routes typed in as prompts.
- `larakube share:domain` (+ `share:domains`, `share:domain-remove`, `share:show`): stable names under your own domain. The only one that handles app, Vite, Reverb and storage.
- Desktop: Share preview, Stop sharing, Use my domain, Remove names.
- And the sharing state is patched onto running deployments (`kubectl set env`), which `up` overwrites, so `up` re-patches it (`reapplyDomainShare`). `.env`, `APP_URL`, `ASSET_URL`, the Vite config and the Reverb host still carry the local `.kube` names.

## Decision
One mechanism: **stable names under the person's own Cloudflare domain.** Everything else is deleted, not hidden.

`larakube up` on a project that has names runs it shared, with no flag: the project's local environment simply has public hosts instead of `.kube` ones, and everything built from hosts follows.

## Design
1. **The names are the project's hosts.** `share` records the public names as the `local` environment's `hosts` (`web`, `vite`, `reverb`, `s3`, `s3-console`) in `.larakube.local.json`, the gitignored machine file, because the names include the machine's name. `ConfigData::getServiceHost()` already honours `EnvironmentData::$hosts`, so `.env` (`APP_URL`, `ASSET_URL`, `AWS_URL`, `REVERB_*`), the Vite config (`origin`, `hmr.host`, realigned by the existing managed-block code), the ingress and the Reverb host all follow with no new code path. Check first that the local file can carry an environment's `hosts`; if not, extend that overlay.
2. **`share` creates the Cloudflare side once.** Tunnel, routes, DNS and the connector, as `share:domain` does now (API token from the environment or a prompt, never stored). It needs a token and a domain, so it stays its own command and cannot run unattended the first time.
3. **`up` keeps it running.** `up` already spares the connector from its scale-down and restart. When the project has names, `up` also makes sure the connector is at 1. Nothing is patched afterwards.
4. **A project without names says so.** On a dev box, `up` ends with one line: "Make it public: larakube share".
5. **Removing it is its own command, not a flag.** `share:remove` deletes the DNS records, the tunnel and the recorded hosts; the next `up` is private again.

## Commands after this
| Command | What it does |
|---|---|
| `share` | Make the project public under your domain (first time), or re-run safely. Replaces `share:domain` and the old `share`. |
| `share:remove` | Take the names down. Replaces `share:domain-remove` and `share --stop`. |
| `share:show` | Read-only status, `--json`. Stays. |
| `share:domains` | Read-only list of the domains a token can use, for Desktop's picker. Stays. |

`up` has no share flag. Share state is read from the project.

## Delete
CLI:
- `ShareCommand` quick-tunnel path (`runQuickTunnels`, `extractQuickTunnelUrls`, per-service pods) and the hand-made named path (`--token`, `--reset`, `--detach`, `--stop`, `resolveNamedTunnelUrls`, saved `shareToken` and `shareUrls`).
- `reapplyDomainShare`, `applyEnvPatches` (the deployment `set env` calls), and the `VITE_DEV_ORIGIN`, `VITE_HMR_*` environment overrides added to the Vite block (commit `6bbffcec`, not pushed): the managed block goes back to literal hosts and the existing realign handles a host change.
- The `cloudflared` view's `targetUrl` (quick tunnel) branch.
- The dev box hint's separate lines about temporary links; one line pointing at `share`.

Desktop:
- Share preview and Stop sharing (routes `devboxes.share`, `devboxes.unshare`, `RunKind` `share-dev-box-project` and `unshare-dev-box-project`).
- "Use my domain" becomes **Share**; "Change or remove names" becomes **Remove names**.

## Order
1. Hosts from the local file (step 1), and `share` writing them. Test: `up` after `share` writes the public hosts into `.env` and the Vite config.
2. `up` ensures the connector; delete `reapplyDomainShare`, `applyEnvPatches`, the Vite environment overrides.
3. Rename `share:domain` to `share`, `share:domain-remove` to `share:remove`; delete the old `share` and the saved token and URL config.
4. Desktop: one Share action and one Remove action, one run kind each; delete the temporary-link routes and kinds.
5. Live: dev box, Laravel with Vite, Reverb and storage. Open the app, edit a view and watch hot reload, upload and open a file, receive a broadcast, run `up` again and confirm nothing changes, run `share:remove` and confirm `up` is private again.

## Risks to check early
- A public host as the local ingress host: Traefik will route it, which is harmless on a box; confirm the local certificate step does not try to issue for a public name.
- Laravel behind the tunnel needs to trust the forwarded protocol, or `url()` and `asset()` generate `http://` links. Check the scaffold's proxy trust setting.
- Vite rejecting an unknown Host: the connector already rewrites the Host header for the Vite route; verify on the current Vite version.
