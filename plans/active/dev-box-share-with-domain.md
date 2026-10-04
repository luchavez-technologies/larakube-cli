# Dev box: share a project under your own domain

## Problem
A Laravel app in development is not one address. The browser talks to the app, the Vite dev server (hot reload socket), object storage (S3 uploads and presigned links) and Reverb (websocket). On a dev box none of these resolve outside the box.

`larakube share` today covers this in two ways, and neither is enough for a dev box:
- **Quick tunnels:** one `cloudflared` pod per service, each with a random `trycloudflare.com` name that changes every run. The env patches (`VITE_HMR_*`, `VITE_REVERB_*`, `AWS_URL`) are rewritten each time, and a restart breaks every open page. Fine for "have a quick look" with a built (`npm run build`) app and no websockets; not for development.
- **Named tunnel with `--token`:** stable, but the person must create the tunnel and one Public Hostname rule per service in the Cloudflare dashboard by hand, then type each URL into a prompt (`resolveNamedTunnelUrls`). Four services means four manual routes that must match the prompts.

## Goal
`Share with my domain`: given a Cloudflare API token and a chosen domain, create everything and write the env, so the project has stable public names for all its services.

## Design
**Names.** One level under the chosen zone, because Cloudflare's free Universal certificate covers `*.zone` but not `*.sub.zone`:
`<project>-<box>.example.com` (web), `vite-<project>-<box>`, `ws-<project>-<box>`, `s3-<project>-<box>`, `s3c-<project>-<box>` (console). One DNS record per name (no wildcard record, since a wildcard would only help if the certificate covered it).

**Services come from the code that already knows them.** `buildServiceMap()` in `ShareCommand` already maps web, hmr, reverb, storage and storage-console to their in-cluster targets (`web:80`, `node:5173`, `reverb:8080`, storage pod and ports). The new command reuses it so the quick path and the domain path cannot drift. `StorageDriver::envVars` and `getServiceHost('s3', ...)` define the storage host; the domain path overrides them through the per-environment `hosts` override rather than patching deployments, so a later `up` keeps the names (an `up` after `share` should not undo it).

**API calls** (`CloudflareConnector` already exists; add requests):
1. List zones (`ListZonesRequest` exists) so the person picks the domain explicitly. A multi-domain token never means a guessed domain.
2. Create the tunnel (`POST /accounts/{account}/cfd_tunnel`, `config_src: cloudflare`). It returns the id and the connector token.
3. Put the tunnel configuration: one ingress rule per hostname, then a catch-all `http_status:404`.
4. Create one proxied CNAME per hostname to `<tunnel-id>.cfargotunnel.com` (`CreateDnsRecordRequest` exists).
5. Deploy the connector pod and write the env.

Idempotent per the AGENTS rules: look up an existing tunnel for this project and box (name `larakube-<project>-<box>`) before creating; PUT the whole configuration (replaces, not appends); upsert DNS records (`ListDnsRecordsRequest` then create or patch); `kubectl apply` for the connector.

**Token permissions** (verify against Cloudflare's current docs before shipping, per AGENTS.md): Account → Cloudflare Tunnel → Edit; Zone → DNS → Edit; Zone → Read. The "Edit zone DNS" template used on Create server is not enough. Desktop must show this list where it asks.

**Where the token lives.**
- Never in argv. It reaches the command by environment variable (`CLOUDFLARE_API_TOKEN`), as `devbox:create` already does for provider tokens.
- It is used once and not stored, as on the server form. Only the tunnel id, zone and hostnames are kept (global config `shareUrls`, which already exists), so a second run reuses them.
- The connector token returned by the API goes into the cluster as a Secret and is read by `cloudflared` as `TUNNEL_TOKEN`. The current `k8s/cloudflared/deployment.blade.php` puts `--token <value>` in the container args, which exposes it to anyone who can read the pod spec; fix that for the named path too.
- Where the command runs: over SSH on the box (`DevBoxShell`). Desktop sends the token on the first line of standard input (`printf '%s\n' "$CLOUDFLARE_API_TOKEN" | ssh …`, the box reads it with `IFS= read -r`), so it is in no command line, local or remote, and is not written anywhere. Decided against keeping it as a Kubernetes Secret on the box: it can edit DNS and tunnels for every domain in the account, and the box runs the app and any code an AI writes. Only the narrow connector token is stored as a Secret. An opt-in "remember on the box" can come later.

## Commands
Per the one-positional and no-hidden-flag rules, a real standalone command:
- `share:domain` (flags `--domain`, `--zone`, `--json`, `--detach`), no positional because dev boxes only share `local`.
- `share:domain-remove` to delete the routes, DNS records and tunnel (the `--stop` of `share` only stops tunnels).
- `share` stays for quick tunnels and the hand-made named tunnel. When a project already has a domain share, `share --stop` should say so.
- `up` on a dev box: print the stable public names when a domain share exists (the marker hint already points here), and not rewrite them.

## Desktop
- Share preview on a dev box project: two choices, "Quick look (random link)" and "Use my domain".
- "Use my domain" asks for the token (permission list shown), lists the token's domains for a pick, then runs `share:domain --json` and shows one card with every service link.
- Dev box card: remember the domain chosen for the box (global config, not a secret).
- Create dev box does not ask for any Cloudflare token: nothing at creation uses it.

## Known limits
- Free certificate depth: one-level names only.
- Reverb and Vite hot reload need the allowed-host and client-port settings written into the app's env; verify Vite's `allowedHosts` for the dev server version in use.
- Cloudflare tunnels support websockets; confirm current limits before promising anything about SSE or long polling.
- A public dev app is public: put the web name behind Cloudflare Access later, or at least say so in the UI.

## Verification
- CLI tests with Saloon `MockClient`: tunnel create, config PUT, DNS upsert; a second run changes nothing.
- A test that the token is never in any process argument or in the pod spec.
- A drift test between `buildServiceMap()` and the hostnames this command creates.
- Live: one dev box, Laravel app with Vite, Reverb and SeaweedFS; open the app through the domain, hot reload works, an upload and its presigned link work, a broadcast arrives.

## Order
1. Fix the connector token exposure in `deployment.blade.php` (small, independent).
2. Cloudflare requests (tunnel, configuration) and the `share:domain` command with tests.
3. Dev-box transport for the token (SSH stdin or Mac-side calls).
4. Desktop "Use my domain" and the quick-look labelling.
5. `share:domain-remove` and the `up` hint.

## Status
All five steps are built: connector token in a Secret (the named-tunnel path too), `share:domain` / `share:domain-remove` / `share:domains`, the stdin transport, Desktop "Use my domain", and `up` reapplying a running domain share. Automated tests pass; nothing has been run against real Cloudflare yet.

## Live test
1. Create a token in Cloudflare with the three permissions above, limited to one domain.
2. On a dev box with a Laravel app that has Vite, Reverb and SeaweedFS running: Desktop, Dev Boxes, "Use my domain", paste the token, Find my domains, choose, Share.
3. Open each printed name: the app, Vite (hot reload should work while editing a view), an upload plus its presigned link, a broadcast.
4. Run `larakube up` again on the box: the names are printed again and still work.
5. `larakube share --stop` then `larakube share:domain`: the same names come back.
6. Remove: the DNS records and the tunnel disappear in Cloudflare.
Things to check first: Vite accepts the rewritten Host header, and Cloudflare accepts the proxied CNAME with TTL 1.
