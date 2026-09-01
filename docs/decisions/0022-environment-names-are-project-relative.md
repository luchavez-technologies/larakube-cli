# 0022 — An environment name is project-relative; the cluster it means is recorded per project

**Status:** Accepted (2026-09-02)

## Context

`production` is a **label a project gives to one of its own environments**. It
names nothing on its own. The same physical cluster can legitimately be
`production` in one project, `staging` in another, and `prod` in a third —
and none of those projects is wrong, because the name only has to be coherent
inside the project that chose it.

Two kinds of command consume that name, and they had drifted apart:

- **Project commands** (`cloud:deploy`, `cloud:configure`, `plex:join`) resolve
  it through `ResolvesEnvironmentContext`, reading `environments.{env}.cloud`
  from the project blueprint. Correct, and unchanged by this ADR.
- **Cluster tools** (`data:init`, `crm:init`, `dns:init`, every `{tool}:init`)
  resolve it through `DeploysClusterTool::resolveToolContext()`.

The tool path used to end like this:

```php
$config = $this->getProjectConfig(getcwd());

return $config ? $this->environmentContextOrCurrent($config, $env) : null;
```

Both branches converge on the same silent outcome. No project, or a project
with no recorded target, and the answer becomes **whatever kube-context happens
to be active** — `null` meaning "use current" just as surely as the explicit
fallback does.

**Confirmed live 2026-09-01.** `larakube data:init`, answered `production`, with
the real public host `pocket.luchtech.dev`, deployed PocketBase and its Ingress
onto the **local orbstack cluster** and printed a success message. Production
received nothing. Nothing in the output named a cluster, so there was no signal
to notice.

## Decision

**An environment name resolves to a cluster only through the project that
defined it.** Concretely:

1. `resolveToolContext()` reads `environments.{env}.cloud` from the project
   blueprint — the same field, in the same file, that `cloud:deploy` reads.
   `.larakube.local.json` is that file (ADR 0007): gitignored, operator-local,
   because a kube-context is a coordinate of *your* machine, not a property of
   the repository.

2. **A tool run inside a project with no target recorded captures it once**, via
   the same `promptCloudTarget()` picker the project commands use, and writes it
   to `.larakube.local.json`. So `data:init production` establishes the answer
   and `crm:init production` afterwards asks nothing — one project, one answer,
   shared by every tool.

3. **`--context=` is the escape hatch** and bypasses all of the above. It is
   deliberately *not* recorded: passing a flag for a one-off run must never
   trigger the SSH-details capture as a side effect.

4. **Outside a project, with no `--context`, a cloud environment REFUSES.** There
   is nowhere to read from and nowhere to record to, so guessing is the only
   alternative — and guessing is what produced the incident above. The error
   names the flag that fixes it.

5. `larakube env <name>` asks which cluster the environment is at the moment the
   environment is created, rather than leaving it to be discovered two commands
   later in `cloud:configure`.

## Rejected: a machine-wide environment → context map

Briefly implemented (`GlobalConfigData::$environmentContexts`) and reverted the
same day. It removed the prompt by recording `production → larakube-<ip>` in
`~/.larakube/config.json`.

It is the wrong scope, and it breaks the model this ADR exists to state. A
machine-wide map can hold exactly one answer for the word `production`, so the
moment a second project means a different cluster by it, the map is wrong for
one of them — and it is wrong *silently*, by inheritance, in the direction of
deploying to a cluster the operator never chose for that project.

It also inverted an authority that was already correct: `EnvironmentData.cloud`
is per-project precisely because project A's production is not project B's. A
machine-level guess must not override a project-level fact.

The prompt it removed is worth paying. It happens once per project per
environment, and the answer is then shared by every tool in that project.

## Consequences

- A cluster tool never depends on which directory you are standing in *for
  correctness* — only for convenience. With no project, it demands `--context`.
- Deploying a cloud tool to the wrong cluster now requires explicitly naming the
  wrong context. It can no longer happen by omission.
- Scripted/CI runs must pass `--context=` for any non-local environment. That is
  intentional: a script that does not say which cluster it means has not
  specified its own behaviour.
- `environmentContextOrCurrent()` remains for the project deploy path, where a
  target is already guaranteed to have been captured. It must never be
  reintroduced into the tool path — the "or current" half is the whole bug.
