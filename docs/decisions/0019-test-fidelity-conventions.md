# 0019 — Tests must faithfully reproduce production behavior: no unfaked I/O, no unstubbed prompts, no ad-hoc temp dirs

**Status:** Accepted (2026-08-19)

## Context

The test suite grew to 1770+ tests across many sessions and agents with no
single enforced convention for how a command's external effects — shell
processes, HTTP calls, sleeps, filesystem fixtures, interactive prompts — get
faked. Four independent gaps accumulated, each individually easy to miss in
a single PR review, that together made the suite slow, order-dependent under
`pest --parallel`, and — in the worst case — silently untested:

1. **Real sleeps.** Production polling loops called `sleep()`/`usleep()`
   directly. Nothing faked them, so tests paid the real wall-clock cost —
   the suite ran in ~200s sequentially before this was fixed.
2. **Silently-real I/O.** `Http::fake()` with a non-matching pattern throws,
   which looks safe — but a test that never calls `Http::fake()` at all does
   **not** throw or hang, it just sends a real request. Helpers that
   port-forward (properly faked via `Process::fake()`) and then call
   `Http::` against `http://localhost:{random port}` are the sharpest
   version of this trap: the request usually fails fast against nothing
   listening and looks like it "passed" — until, under `--parallel`, the
   port collides with an unrelated real local service and the test's result
   flips. `Process::start()` (async) also needs its own fake pattern
   independent of `Process::run()`'s.
3. **Manual temp directories.** Tests created fixture directories by hand
   (`sys_get_temp_dir().'/fixed-or-uniqid-name'` + `mkdir`/`rm -rf`/`exec`).
   A shared, fixed name races under `pest --parallel` even when it "works"
   sequentially — two workers hitting the same path's create/read/delete
   cycle simultaneously.
4. **Prompt behavior no test actually exercised.** `config/app.php`'s
   `'env'` key was a hardcoded literal, never reading `APP_ENV` — so
   `Illuminate\Foundation\Application::runningUnitTests()` was permanently
   `false` regardless of `phpunit.xml.dist`. Every test's `confirm()`/
   `text()`/`select()` calls were therefore governed purely by whether the
   *test runner's own process* had a real TTY: false in a plain CI/sandbox
   runner (so an unfaked prompt silently returned its default — looked like
   a pass), **true** in a real developer terminal or a `script`-wrapped
   pseudo-TTY (so the exact same command block on real keyboard input
   forever). The whole suite had been asserting against a code path that
   only existed in one of the two environments it needed to work in.

None of these are exotic mistakes — they're the natural result of many
different sessions each writing tests that "passed," without a single shared
contract for what "passed" is allowed to mean.

## Decision

A test is only trustworthy if it exercises the same code path production
does, with every external effect explicitly faked — never accidentally real,
and never silently skipped by an environment quirk. Concretely:

1. **Every shell command a test's code path can reach must be in
   `Process::fake()`**, including a separate pattern for `Process::start()`
   if the code path uses it. If a test is slow (multiple seconds per
   assertion), a real process is leaking through — that's a bug in the
   test, not an acceptable cost.
2. **Every HTTP call a test's code path can reach must be in `Http::fake()`**
   — `Http::withBody()->send()` / `Http::withToken()->send()` need matching
   fakes exactly like `Http::get()`/`Http::post()`. Any helper that
   port-forwards then calls `Http::` (`openBaoApi()`-shaped code) needs its
   own `Http::fake()`, even when the test currently "passes without it" —
   passing by luck is not passing.
3. **Real `sleep()`/`usleep()` in production code goes through the `Sleep`
   facade** (`Illuminate\Support\Sleep`), never a raw call. `Sleep::fake()`
   is enabled globally in `tests/TestCase::setUp()`. Wall-clock deadline
   loops use `now()`/Carbon (`while (now()->lt($deadline))`), never raw
   `time()` — `Sleep::fake(syncWithCarbon: true)` only advances Carbon's
   test-`now`, not PHP's native clock.
4. **Temp directories/files in tests use `Spatie\TemporaryDirectory`**
   (`TemporaryDirectory::make()->deleteWhenDestroyed()`), never
   `sys_get_temp_dir()` + manual `mkdir`/`rm -rf`/`exec`. One gotcha worth
   naming explicitly: `->path($name)` auto-creates `$name` **as a
   directory** when it contains no `.` — a single fake-file fixture
   (`fake-composer`, `fake-k9s`, a stub binary with no extension) must be
   built off the bare `->path()` root instead
   (`$temporaryDirectory->path().'/fake-composer'`), or the later
   `file_put_contents()` fails with "Is a directory."
5. **`config/app.php`'s `'env'` key must read `env('APP_ENV', ...)`**, never
   a hardcoded literal, and `phpunit.xml.dist` must set
   `<env name="APP_ENV" value="testing"/>`. This is what makes
   `runningUnitTests()` — and therefore prompt behavior — identical whether
   the suite runs in a non-TTY CI runner or a real developer terminal. A
   test environment that only works in one of those is not verified at all
   in the other.
6. **Every `confirm()`/`text()`/`select()`/`multiselect()` a command's code
   path can reach during `$this->artisan(...)` needs a matching
   `->expectsConfirmation(...)`/`->expectsQuestion(...)`/`->expectsChoice(...)`.**
   `Illuminate\Console\Concerns\ConfiguresPrompts::configurePrompts()` runs
   on every real command execution — including in tests — and always routes
   prompts through Laravel's fallback (`$this->components->confirm()` etc.
   on a Mockery-mocked `OutputStyle`), never true default-taking and never
   real terminal rendering. An unstubbed prompt throws
   `Received Mockery_..., but no expectations were specified`. Match the
   prompt's `label:` text exactly (no suffix is added) and, for
   `expectsChoice`, the exact `options` array (label text, not just keys).
   Stubs are consumed in the order the command asks them — order the
   `->expects*()` chain to match.
7. **If a command gates a prompt behind a raw `stream_isatty(STDIN)` check**
   (a `confirmComponentRemoval()`-shaped guard), don't try to script that
   prompt — its presence depends on whether the *test runner itself* has a
   real TTY, which is exactly the non-determinism this ADR exists to kill.
   Pass `--force`/`--no-interaction` in the test instead, so the test's
   result is identical in every runner.
8. **`Prompt::fake([...scripted keys])` + a manually-run `$command->handle()`**
   (bypassing `$this->artisan()`) is a narrower, separate mechanism —
   Laravel Prompts' own low-level interactive-rendering test tool. It only
   stays valid as long as the test never calls `$command->run()`, which is
   what triggers `configurePrompts()` and forces the fallback path
   regardless of what `Prompt::fake()` configured. Reach for it only when
   the wizard's actual interactive rendering is what's under test; default
   to rule 6's `$this->artisan()->expects*()` idiom otherwise.
9. **One test file per command/feature.** A command's tests live in one
   file named after it; a shared concern (a trait, a cross-cutting
   behavior) gets its own file named after *that* concern — never split
   across files by who happened to write which test, and never dumped into
   an unrelated file for convenience.
10. **`./vendor/bin/pest --parallel` is the default way the suite is run**,
    including in CI and pre-commit. A test that isn't safe under `--parallel`
    (shared state, a fixed temp path, a real network/process side effect)
    is a bug in the test — see rules 1–4 for exactly the shapes that break.

`CLAUDE.md`'s "Writing Tests" section is the operational cheat-sheet for
rules 1–4 and 6; this ADR is the durable record of *why* each rule exists
and the failure it prevents.

## Consequences

- A missing fake now fails fast and loud (a `Received Mockery_...`
  assertion, a clearly-slow test, a "Question ... was not asked" message) —
  never a silent pass-by-luck and never an indefinite hang, in any runner.
- The suite's pass/fail result is identical whether it runs in CI, a
  non-interactive sandbox, or a real developer terminal — no code path is
  exercised in only one of those environments.
- `pest --parallel` is safe to run as the default, not an opt-in — no test
  depends on being the only one touching a given path, port, or global
  Prompt/Sleep static.
- New tests that violate rules 1–8 will be caught the first time they run,
  not discovered months later when an unrelated refactor happens to change
  `runningUnitTests()`'s return value or a worker's scheduling order.
- Rule 9 doesn't retroactively fix the suite's existing file organization —
  that's tracked separately as its own cleanup pass — but it's now the
  documented target every new test file is written against, and existing
  files are moved toward on next touch.
