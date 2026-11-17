# 0030 — A paused app stays paused until it is resumed

**Status:** Accepted (2026-11-18)

## Context

`tool:pause` scales an app's Deployments to zero and records the prior replica
count in the `larakube.app/paused-replicas` annotation. An app is usually paused
because it needs more memory or CPU than the cluster can spare alongside
everything else.

Every `:init` re-applies the app's full manifest, including `spec.replicas`.
`tool:update` (and Desktop's Update all) re-runs `:init`. So any update or
re-install of a paused app scales it straight back up: on a small node, the very
app paused to free room starts again unasked. The pods also start, briefly at
least, before anything could scale them down again.

## Decision

1. **Paused** means a Deployment scaled to zero **with** the pause marker set
   (`K8sAnnotations::isPausedDeployment()`). The marker alone does not make an
   app paused.
2. **Nothing applies over a paused app.** Before applying a tool's manifest,
   `guardPreInstall()` checks the Deployments that manifest names; if any is live
   and paused, the install refuses and says to resume it first. It runs ahead of
   the capacity guard (ADR 0026) on every `:init` path.
3. **`--force` does not bypass it.** `--force` skips confirmations and the
   capacity guard, and `tool:update` always passes it; neither may start a
   paused app.
4. **Updates wait.** `tool:update --all` skips a paused instance and reports it
   as `skipped`; `tool:update --tool=…` on a paused instance refuses. Desktop
   leaves paused apps out of Update all and shows "Resume to update" on the card.
5. **Resuming is the only way back.** `tool:resume` restores the recorded replica
   count and clears the marker; the next update then applies normally.

## Consequences

- A paused app can fall behind this CLI build until someone resumes it. Desktop
  keeps showing that an update is waiting.
- An install that also runs commands inside the app's pods (admin bootstrap,
  migrations) never runs against an app with no pods.
- Re-running `:init` with different options on a paused app means resuming it
  first, by design.
