---
name: graphify
description: Turn any folder of files into a navigable knowledge graph
---

# Workflow: graphify

A knowledge graph of this repo, so an agent can answer codebase questions from a
scoped subgraph instead of reading its way through the whole tree.

The graph lives in `graphify-out/` and is **gitignored** — it's ~65MB of generated
artifacts that churn on every code change. Each contributor builds their own.

## First-time setup

```
pipx install graphifyy
```

The package is `graphifyy`; the command it installs is `graphify`.

Then build the graph from the repo root:

```
graphify extract . --code-only
```

`--code-only` uses local AST parsing and needs **no API key**. Drop it to also index
docs and get richer semantic edges, which does require a backend (`--backend
gemini|claude|openai|…` plus that provider's key).

Then wire up automatic rebuilds, so the graph can't go stale:

```
graphify hook install
```

Installs `post-commit` and `post-checkout` git hooks that re-extract code-only after
each commit and on branch switch. No LLM, and the rebuild is spawned in the
**background** (log: `~/.cache/graphify-rebuild.log`), so it adds no wait to your
commit — the graph catches up a few seconds later. It leaves the existing
`pre-commit` Pint hook alone and can never fail or block a commit.

**Every contributor must run this themselves.** Git hooks live in `.git/hooks`,
which isn't tracked — cloning the repo does not bring them along.

Optional — install the graphify skill into your own agent config so `/graphify`
works as a slash command (writes to your home config, not this repo):

```
graphify install --platform claude
```

That also registers `PreToolUse` hooks in `.claude/settings.json` which nudge the
agent to query the graph instead of grepping. Note those only *read* the graph —
keeping it current is the git hooks' job, above.

## Excluding files from the graph

graphify honours `.gitignore` (and `$GIT_DIR/info/exclude`), and auto-skips
`node_modules`, `dist`, `build`, `out`, `coverage`, `__snapshots__` and friends.

For files that are **committed but not worth indexing** — compiled bundles are the
usual case — add a `.graphifyignore`. Same syntax as gitignore, read per-directory,
merged after `.gitignore` so its patterns win. It only ever excludes more; a `!`
negation cannot re-include something `.gitignore` already excluded.

`cli` needs none (0% noise). The sibling repos that commit Vite/Filament bundles
into `public/` do — see `console/.graphifyignore`, where excluding `public/js/`
dropped ~4,900 built-asset nodes with no loss of real source.

Note that `graphify update` (and the post-commit hook) also index **markdown**,
which `extract --code-only` skips — so a repo can look clean after `extract` and
then pick up doc noise on the first hook rebuild. Laravel Boost is the trap here: it
publishes the same `skills/**/SKILL.md` into `.agents/`, `.claude/` AND `.github/`,
so every skill file gets indexed three times. `console/.graphifyignore` drops the
`.claude/` and `.github/` copies and keeps `.agents/` as the single indexed one.

## Keeping it current

```
graphify update .
```

AST-only, no LLM, no API cost. Run it after changing code so queries don't answer
from a stale graph.

## Using it

See `.agents/rules/graphify.md` for the query commands. If no path argument is
given, graphify uses `.` (current directory).
