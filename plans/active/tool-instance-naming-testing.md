# Test Plan: `ToolInstance` naming refactor

**Status:** ⛔ NOT STARTED (the feature isn't built yet).
**Plan:** `plans/active/tool-instance-naming.md`

After `./build`, per stage. Never route a check through a destructive picker
over live data: use a scratch second instance of a cheap tool on a new
one-level host (e.g. `notes-check.luchtech.dev`), and `--dry-run` where offered.

## Every stage
- [ ] `larakube tool:list production` shows the same rows as before the stage.
- [ ] Re-running `{tool}:init production` for 3 live tools touched by the stage
  changes nothing: `kubectl diff` on the rendered manifests is empty, pods don't
  restart.

## Stage 1: Commons tenants
- [ ] `larakube plex:show production`: every tenant name matches what the
  tool's `:init` would allocate (no fixed `link_kutt`/`teable`/`stalwart` left
  after re-running those tools' `:init`).
- [ ] Scratch pair: `paste:init production --domain=paste-a.luchtech.dev` and
  `--domain=paste-b.luchtech.dev`. `plex:show` lists two Redis tenants with
  different indexes and two buckets.
- [ ] `paste:remove production --domain=paste-a.luchtech.dev --purge`: only A's
  tenants disappear from `plex:show`; A's Redis index holds 0 keys; B still
  works.

## Stage 2: workload resources
- [ ] Scratch pair of a tool with shared resources (e.g. Meet or Link on two
  one-level hosts). Removing A leaves B serving 200 and its shared Secret in
  place; removing B last also removes the shared resources.
- [ ] `paste:remove --domain=…` and `link:remove --domain=…` both run (no
  "does not support multiple instances" refusal).

## Stage 3: readers
- [ ] `{tool}:show production --domain=…` for each scratch instance reports the
  right URL and state.
- [ ] `larakube backup:run production` (or its dry run) lists each scratch
  instance's volume under its own name.

## Cleanup
Remove every scratch instance with `--purge`, then `tls:prune production`.

## Report back
Which step failed and its output, or "all passed".
