# Plan: `chat:user --admin` must also promote the user in MAS

**Status:** not started. Split out of
`plans/active/vpn-split-dns-and-single-account.md` on 2026-08-28 — it shared a
symptom with that work but nothing else.

## Problem

`larakube chat:user <env> --username=<user> --admin` updates Synapse only
(`matrixSetUserAccount()` → `admin: true`). Element Admin at
`admin.chat.<domain>` does not authenticate against Synapse directly — it goes
through MAS OAuth2, and **MAS gates the admin scopes** (`urn:mas:admin`,
`urn:synapse:admin:*`) behind its own `users.can_request_admin` column in the
`chat_mas` database.

So a user flagged admin by the CLI still gets no admin access in Element Admin.
The two systems disagree and nothing reports it.

Verified live 2026-08-27 on `larakube-159.89.205.239`: the column exists on
`chat_mas.users`, and its values do not track Synapse's admin flag.

## Implementation — use `mas-cli`, not SQL

The original draft of this specified writing `can_request_admin` directly in
Postgres. **Don't.** MAS ships first-class subcommands for exactly this,
confirmed on the running `chat-mas-chat-luchtech-dev` pod:

```
mas-cli manage promote-admin <USERNAME>
mas-cli manage demote-admin  <USERNAME>
mas-cli manage list-admin-users
```

Going through the vendor's own CLI keeps this on the right side of the
"official artifact, real auth story" bar, and lets MAS handle its own cache and
session invalidation rather than us mutating a column underneath it. A raw
`UPDATE` would leave MAS serving stale authorization until restart.

The codebase already shells into this binary — `InteractsWithChat.php:311`
(`stripMasCliLogLines()`) exists precisely because `mas-cli` interleaves log
lines with its output. Reuse it.

Note the pod image has **no shell** (`sh` is not on `$PATH`), so exec must
invoke `mas-cli` directly as argv, never wrapped in `sh -c`.

## The username-encoding trap

MAS stores usernames as MXID localparts, so an SSO user whose localpart is an
email address is stored **percent-encoded with `=` as the escape**:

```
james@luchtech.dev   →  james=40luchtech.dev
```

Both forms exist in the same table — SSO-provisioned users carry the encoded
form, while service accounts and locally-registered users are plain
(`alertbot`, `gail`). Any lookup must handle:

- a bare localpart (`alertbot`)
- an email (`user@domain.tld`) → try both the raw form and `user=40domain.tld`
- a full MXID (`@user:domain.tld`) → strip the sigil and server part first

Resolve to the actual stored username before calling `promote-admin`, and fail
loudly if no row matches rather than promoting nothing and reporting success.

## Changes

1. **`app/Traits/InteractsWithChat.php`** — add
   `syncMasUserAdminStatus(string $kubectl, string $ns, array $identifiers, bool $admin): bool`.
   Resolves the identifier against MAS, invokes `promote-admin`/`demote-admin`
   via `kubectl exec` (argv form, no `sh -c`), strips log lines, returns whether
   the operation actually applied.
2. **`app/Commands/Chat/ChatUserCommand.php`** — after the existing
   `matrixSetUserAccount()` call, call the above. Treat a MAS failure as a
   command failure: a half-applied admin grant is worse than a clean error,
   because it looks like it worked.
3. **Tests** — new `tests/Feature/ChatUserCommandTest.php` coverage for the
   three identifier forms and for the MAS-failure path. Per ADR 0019 every
   `kubectl exec` pattern needs its own `Process::fake()` entry.

## Verification

- [ ] `chat:user <env> --username=<user> --admin` reports success only when both
      Synapse and MAS applied.
- [ ] `mas-cli manage list-admin-users` includes the user afterwards.
- [ ] The user can open `admin.chat.<domain>` and reach admin functions without
      a manual database edit. (Requires VPN access to that host — see
      `plans/active/vpn-split-dns-and-single-account.md`.)
- [ ] Dropping `--admin` demotes in both systems.
- [ ] An identifier that matches no MAS user fails loudly.
- [ ] `./php vendor/bin/pint` and `./php vendor/bin/pest` pass.

## Follow-up found while investigating

`chat_mas.users` currently has several **leftover debug accounts holding
`can_request_admin = true`**: `debugadmin_tmp`, `debugadmin_tmp2`,
`debugadmin_cleanup`. These are admin-capable accounts from earlier debugging
that were never removed. `alertbot` also carries the flag — verify that is
intended rather than incidental.

Unrelated to this change, but worth clearing while the area is open. Use
`mas-cli manage demote-admin` and/or `lock-user`, not SQL, for the same reason
as above.
