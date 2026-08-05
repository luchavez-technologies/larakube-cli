# Cleanup: stop hiding destructive ops behind `--remove`/`--unwire`-style flags

> **What this is:** a code-grounded cleanup plan for a recurring violation of an
> existing hard rule — destructive operations (deregister, unwire, revoke) must be
> their own standalone command, never a flag on the command that does the
> opposite. `cluster:grant` / `cluster:revoke` is the proven-correct precedent:
> two fully independent command classes, neither calls the other.
>
> **Status:** not started. Pure refactor — no behavior change for end users (same
> command names, same flags, same output), only where the logic actually lives.
> Existing tests (`SsoUnwireCommandTest`, `MailUnwireCommandTest`,
> `SsoWireCommandTest`, `MailWireCommandTest`) should stay green throughout and
> are the regression safety net — don't relax their assertions to make this
> pass.

---

## The anti-pattern, precisely

Three tools currently violate the rule, in two flavors:

**Flavor A — a real-looking standalone command that's actually a thin proxy:**

```php
// app/Commands/Sso/SsoUnwireCommand.php — the WHOLE file
class SsoUnwireCommand extends Command
{
    protected $signature = 'sso:unwire {environment=local} {--tool=} {--context=} {--project=}';

    public function handle(): int
    {
        $params = ['environment' => ..., '--remove' => true, ...];
        return $this->call('sso:wire', $params);
    }
}
```

`sso:unwire` *looks* like a real command from the CLI's `list` output, but it has
zero logic of its own — it just re-invokes `sso:wire --remove`. That means:

- `larakube sso:wire production --tool=git --remove` is **still directly
  runnable** — the hidden flag was never actually removed, just given a second,
  friendlier front door. Anyone (a script, a teammate skimming `sso:wire --help`,
  a future agent) can trigger full OIDC deregistration through the command whose
  name and `--help` text say "wire", not "unwire".
- All the actual unwire logic (`unwire()`, `unwireForwardAuth()` — ~90 lines) still
  lives inside `SsoWireCommand`, coupling the two operations' code together for no
  reason.

Same shape, same problem: `app/Commands/Mail/MailUnwireCommand.php` →
`$this->call('mail:wire', ['--remove' => true, ...])`.

**Flavor B — no standalone command at all, just the bare flag:**

`app/Commands/Vpn/VpnWireCommand.php` has `{--remove : Lift the VPN-only
restriction instead of applying it}` and branches internally
(`$this->option('remove') ? ... : ...`) with **no** `vpn:unwire` front door at
all — this one doesn't even have the fig leaf.

**The proven-correct pattern** (`app/Commands/Cluster/ClusterGrantCommand.php` /
`ClusterRevokeCommand.php`): two independent classes. `ClusterRevokeCommand::handle()`
has its own confirmation prompts, its own `Process::run()` calls, its own error
handling — it does not call `cluster:grant` with a flag. Read
`ClusterRevokeCommand.php` in full before starting; it's the template every fix
below should match.

---

## Fix 1 — `sso:wire` / `sso:unwire` (do this one first — it's what's live-blocking right now)

**File: `app/Commands/Sso/SsoWireCommand.php`**

1. Remove `{--remove : Deregister the OIDC app and unset the tool's SSO env vars}`
   from `$signature`.
2. In `handle()`, delete the branch:
   ```php
   return $this->option('remove')
       ? $this->unwire($tool, $schema, $kubectl, $ssoNs, $ssoHost, $pat)
       : $this->wire(...);
   ```
   becomes just `return $this->wire(...);`.
3. **Move**, don't delete, these methods out of this file:
   - `unwire()` (currently ~line 441)
   - `unwireForwardAuth()` (currently ~line 685)
   - `gatedForwardAuthTools()` if `SsoUnwireCommand` ends up being the only
     caller after the move (check `unwireForwardAuth()`'s own use of it first —
     it's the only caller today).
4. Anything those methods depend on that's `protected` on `SsoWireCommand`
   itself (`resolveTool()`, `resolveToolEngine()`, `deploymentExists()`,
   `targetHost()`, `proxyNamespace()`, `apexDomain()`, `applyToolIngress()`,
   `traefikMiddlewareCrdExists()` — check by grepping the moved methods for
   `$this->`) needs a home. Two options, pick based on how much is shared:
   - If `SsoWireCommand` and `SsoUnwireCommand` both need the same handful of
     resolution helpers, pull them into a new trait
     (`app/Traits/InteractsWithSsoTools.php` or similar) both commands `use`.
   - If a helper is genuinely only needed by the unwire path, it moves wholesale
     into `SsoUnwireCommand`.

**File: `app/Commands/Sso/SsoUnwireCommand.php`**

Rewrite from scratch as a real command, following `ClusterRevokeCommand`'s shape:

- Own `$signature` (keep the existing one — `environment`, `--tool`, `--context`,
  `--project` are all fine as-is, this isn't a user-facing change).
- Own `handle()`: resolve environment/context/kubectl/ssoNs exactly as
  `SsoWireCommand::handle()` currently does up through resolving `$tool`,
  `$schema`, `$ssoHost`, `$pat` (that setup code is currently duplicated between
  `handle()`'s wire and remove paths already — when extracting, don't
  re-duplicate it a third time; put the shared "resolve everything I need to
  talk to Zitadel about this tool" bit in the trait from step 4 above and call
  it from both commands).
- Body is the `unwire()` / `unwireForwardAuth()` logic moved in step 3.

**Test file: `tests/Feature/SsoUnwireCommandTest.php`** — currently (if it
follows the same shape as the command) probably just asserts the proxy call
happens. Rewrite it to fake the actual Zitadel HTTP delete + kubectl calls
directly, the way `tests/Feature/SsoRevokeCommandTest.php` tests
`sso:revoke` for real. `tests/Feature/SsoWireCommandTest.php`'s `--remove`-path
tests (2 hits per the grep) move over to `SsoUnwireCommandTest.php` too, since
that behavior no longer exists on `sso:wire`.

---

## Fix 2 — `mail:wire` / `mail:unwire` (same shape, do second)

Identical structure to Fix 1, scoped to mail:

- `app/Commands/Mail/MailWireCommand.php`: remove `--remove` from `$signature`,
  delete the `option('remove') ? $this->unwireTargets(...) : ...` branch in
  `handle()`, move `unwireTargets()` (~line 444) and `unwireSynapseSmtp()`
  (~line 604) into `MailUnwireCommand`.
- `app/Commands/Mail/MailUnwireCommand.php`: rewrite as a real command, same
  pattern as `SsoUnwireCommand` above. Shared setup (`resolveTargets()`,
  `isToolInstalledForMail()`, `resolveToolEngine()`, `deploymentExists()`) goes
  in a shared trait if both commands need it.
- `tests/Feature/MailUnwireCommandTest.php` / `MailWireCommandTest.php`: same
  test-migration treatment as Fix 1.

---

## Fix 3 — `vpn:wire` needs a `vpn:unwire` created (do third — no existing front door to preserve)

`app/Commands/Vpn/VpnWireCommand.php` has `--remove` with no sibling command at
all. This one's slightly different: there's no `VpnUnwireCommand.php` to fix,
one needs to be **created**.

- Remove `--remove` from `VpnWireCommand`'s `$signature` and its
  `$this->option('remove') ? ... : ...` branches (2 call sites per the grep —
  the label-string branch at ~line 151 too, not just the top-level dispatch).
- Extract whatever the `remove`-branch currently does (lifting the VPN-only
  Middleware restriction) into a new `app/Commands/Vpn/VpnUnwireCommand.php`.
- Register it the same way every other command is auto-discovered (check how
  `SsoUnwireCommand`/`MailUnwireCommand` are registered — LaravelZero's
  auto-discovery from the `Commands/` directory should just pick it up with no
  extra wiring, but confirm against `larakube list` after the change).
- New test file `tests/Feature/VpnUnwireCommandTest.php`, splitting out any
  `--remove`-path assertions currently in `tests/Feature/VpnWireCommandTest.php`.

---

## Acceptance check (run before calling any of the three done)

1. `larakube {sso,mail,vpn}:wire --help` — confirm `--remove` no longer appears
   in any of the three.
2. `larakube {sso,mail,vpn}:unwire --help` — confirm each is a real command with
   its own description (not "See sso:wire --remove" or similar).
3. `./php vendor/bin/pest` — full suite green. Per this repo's CLAUDE.md, every
   `Process::run`/`Process::start` and `Http::` call in the moved code needs a
   matching fake — moving code between files doesn't change what needs faking,
   but re-check nothing was faked via a pattern that assumed the old
   `sso:wire ... --remove` command string specifically (e.g. a fake keyed on
   `str_contains($process->command, ...)` is fine; a fake keyed on the Artisan
   command name string `'sso:wire'` would need updating to `'sso:unwire'`).
4. `./php vendor/bin/pint && ./build` (user runs this, not the agent, per this
   repo's existing convention).
5. Grep for stragglers before closing this out:
   `grep -rn "option('remove')" app/Commands/` should return nothing, and
   `grep -rn "'\-\-remove' => true" app/Commands/` should return nothing.
