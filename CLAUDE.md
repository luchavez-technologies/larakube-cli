## Running PHP/Composer

Requires PHP 8.4 installed locally (see CONTRIBUTING.md for the extension list) — run everything directly on the host.

- `./vendor/bin/pest --parallel` (tests — parallel is the default; shared-state races that broke it were fixed 2026-08-19, see `tests/TestCase.php`), `./vendor/bin/pint` (formatting, per repo-wide hard rule), `./vendor/bin/phpstan` (static analysis)
- `./vendor/bin/rector process` (automated refactoring — applies `rector.php`'s `PestSetList::CODING_STYLE` set across `app/`, `bootstrap/`, `config/`, `resources/`, `scripts/`, `tests/`: adds explicit `: void` return types to test closures, merges chained `expect()->and()` assertions, and similar Pest coding-style normalizations). Run it, then `./vendor/bin/pint` to clean up formatting afterward, then `./vendor/bin/pest --parallel` to confirm nothing broke — Rector rewrites code structure, so always re-verify rather than trusting the diff on sight.
- `composer <args>` for dependency management
- Never run `./build` yourself — tell the user to run it and wait.

## graphify

This project supports a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships. It is gitignored and built per-contributor (`pipx install graphifyy` then `graphify extract . --code-only`, no API key needed) — see `.agents/workflows/graphify.md`. On a fresh clone it won't exist; fall back to normal search until it's built.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).

## Writing Tests

See `docs/decisions/0019-test-fidelity-conventions.md` for the full rationale behind every rule below — read it before writing a new test file.

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
- Calling `Http::fake()` with a pattern that doesn't match a request throws — but a test that never calls `Http::fake()` at all does NOT throw or hang, it just makes a real, unmocked HTTP call. This is the more dangerous gap: `openBaoApi()`-style helpers port-forward (properly faked via `Process::fake()`) then make a real `Http::` call to `http://localhost:{port}` — with no `Http::fake()`, that request goes out for real. It usually fails fast (nothing's listening) and looks like it "worked," but confirmed live (2026-08-19): under `pest --parallel`, it intermittently picked up a response from an unrelated real local service, flipping the test's result. Any test reaching an `openBaoApi()`/similar HTTP-over-port-forward call needs its own `Http::fake(['localhost:*' => ...])`, even if the test "passes without it."
- Real `sleep()`/`usleep()` in production code must go through the `Sleep` facade (`Illuminate\Support\Sleep`), not raw PHP calls — `Tests\TestCase::setUp()` fakes it globally, so faked code is instant in tests; raw calls are not. Wall-clock deadline loops (`while (time() < $deadline)`) must use `now()`/Carbon, not raw `time()` — `Sleep::fake(syncWithCarbon: true)` only advances Carbon's test-"now", not PHP's native `time()`.
- Temp directories in tests use `Spatie\TemporaryDirectory\TemporaryDirectory::make()` (already a dependency), not `sys_get_temp_dir().'/fixed-name'` or manual `mkdir`/`rm -rf` — a shared fixed path races under `pest --parallel` even if it "works" sequentially.
- One test file per command/feature (ADR 0019 rule 9) — e.g. `mail:create` lives in `MailCreateCommandTest.php`, not folded into a catch-all `MailCommandsTest.php`. Exception: shared base-class/cross-cutting behavior tested once across many commands (`ToolRemoveCommandTest.php`, `WhitelabelInitCommandsTest.php`, `CommandSmokeTest.php`) legitimately lives in one file — that's the correct pattern for that kind of test, not scatter.
- Fixture setup uses a plain top-of-file helper function (`function xFakes(): array { ... }`), not `beforeEach()` — this is the convention the large majority of the suite already follows. Helper function names must be globally unique across the whole suite (Pest loads every file's top-level functions into one shared namespace per process), so scope the name to the file, e.g. `mailCreateBaseFakes()` not `baseFakes()`.
- Any `confirm()`/`text()`/`select()`/`multiselect()` a command's code path can reach during a `$this->artisan(...)` call needs a matching `->expectsConfirmation(...)`/`->expectsQuestion(...)`/`->expectsChoice(...)` in the test — `configurePrompts()` runs on every real command execution (including in tests) and always routes prompts through Laravel's own fallback (`$this->components->confirm()`/etc. on a Mockery-mocked `OutputStyle`), never real terminal rendering. An unstubbed prompt throws `Received Mockery_..., but no expectations were specified`. Match the prompt's `label:` text exactly (no suffix is added) and, for `expectsChoice`, the exact `options` array (label text, not just keys) — Mockery compares both. Order matters: stubs are consumed in the order the command asks them. If a command gates its own prompt behind a raw `stream_isatty(STDIN)` check (e.g. `confirmComponentRemoval()`-style guards), that prompt's presence depends on whether the *test runner* has a real TTY — pass `--force`/`--no-interaction` in the test instead of stubbing it, so the test is deterministic across environments. Tests that use Laravel Prompts' own `Prompt::fake([...keys])` + a manually-run `$command->handle()` (bypassing `$this->artisan()`) are unaffected by this as long as they never call `$command->run()` (which is what triggers `configurePrompts()`).
