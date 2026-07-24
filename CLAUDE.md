## Running PHP/Composer

All PHP and Composer commands in this directory MUST go through the local wrapper scripts, not the host `php`/`composer` binaries — they run inside a persistent Docker daemon that has the right PHP version and extensions.

- `./php vendor/bin/pest` (tests), `./php vendor/bin/pint` (formatting, per repo-wide hard rule), `./php vendor/bin/phpstan` (static analysis)
- `./composer <args>` for dependency management
- Never run `./build` yourself — tell the user to run it and wait.

## graphify

This project supports a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships. It is gitignored and built per-contributor (`pipx install graphifyy` then `graphify extract . --code-only`, no API key needed) — see `.agents/workflows/graphify.md`. On a fresh clone it won't exist; fall back to normal search until it's built.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).

## Writing Tests

All shell commands MUST be faked via `Process::fake()` — never let a test hit a real cluster, Docker, or filesystem command. Every pattern that a command might execute must have a matching entry:

```php
Process::fake([
    '*get secret*' => Process::result(output: '', exitCode: 1),
    '*apply -f *' => Process::result(output: 'applied'),
    '*port-forward*' => Process::result(),
    // ^ required: without it, Process::start() creates a real process
    //   whose running() returns true, triggering usleep() delays
]);
```

HTTP calls MUST be faked via `Http::fake()` with `Http::sequence()` or `Http::response()`. Every endpoint the command calls must have a matching fake response — unmatched requests throw or hang:

```php
Http::fake([
    'localhost:*' => Http::sequence()
        ->push(['identity' => ['credentials' => ['token' => 'bt_123']]])
        ->push(['project' => ['id' => 'proj_1']]),
]);
```

Key rules:
- `Process::start()` (async) needs its own pattern — `Process::run()` patterns don't cover it.
- `Http::withBody()->send()` and `Http::withToken()->send()` need matching fakes just like `Http::get()` / `Http::post()`.
- If a test is slow (multiple seconds per assertion), a real process or HTTP call is leaking through — add the missing fake pattern.
