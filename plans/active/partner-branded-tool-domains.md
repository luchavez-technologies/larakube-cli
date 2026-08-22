# Wire `webmail`'s Ingress into the existing `tool:alias` mechanism

**Status:** 📝 PLANNED — not started. Triggered by ourfridays.com wanting `mail.ourfridays.com`
as their own branded webmail login URL, on the *same* shared Bulwark/Stalwart install.

## Correction to this plan's own first two drafts

Draft 1 proposed a new `webmail:domain` command doing a direct Cloudflare API write.
Draft 2 corrected that to reuse `dns:init`/ExternalDNS instead of hand-writing DNS.
Both drafts were still wrong about needing a **new command at all** — a generic
"attach an extra hostname to an already-deployed tool, re-render its Ingress, let Traefik
handle the cert" command already exists: **`tool:alias`**
(`app/Commands/Tool/ToolAliasCommand.php`). It:
- Takes `{tool} {alias} [--remove] [--domain=]` — exactly the `tool:domain`-shaped
  interface asked for.
- Persists the alias into the tool's own registry entry (`aliases` array, via
  `addToolAliasHost()`/`getToolAliasHosts()` in `InteractsWithToolRegistry.php` — this is
  the real "multi-domain per tool instance" data the RegistryData.php question was
  actually pointing at; `RegistryData.php` itself is the unrelated container-image-registry
  config under `EnvironmentData::$registry`).
- Re-renders `k8s.{tool}.ingress` with `aliasHosts` merged in and re-applies it.
- Prints "Traefik will issue/refresh ACME TLS certs automatically" — same HTTP-01 story
  confirmed in draft 2, already working for this command today.

So there is no command to build. There is exactly one gap.

## The actual gap: most `ingress.blade.php` templates silently ignore `$aliasHosts`

`ToolAliasCommand::reapplyToolIngress()` always passes `aliasHosts` to the view. Checked
every `k8s/*/ingress.blade.php`: only **`mail`**, **`notes`**, and **`data`** actually loop
over it (`@foreach($aliasHosts ?? [] as $aliasHost)`, adding both an extra `rules` entry and
an extra `tls.hosts` entry — see `resources/views/k8s/mail/ingress.blade.php` for the
reference shape). Every other tool's template — **including `webmail`** — has no such loop.
Running `tool:alias` against one of those today does not error: it happily updates the
registry and re-applies the Ingress, but the alias is silently absent from the actual
rendered manifest. No DNS record gets a reason to exist, no cert gets requested, and the
operator gets zero signal that anything went wrong. This is a real, previously-untracked
gap, not specific to Webmail — it's just the one blocking this specific ask.

## Fix, scoped to what's actually needed now

1. **`resources/views/k8s/webmail/ingress.blade.php`** — add the same two
   `@foreach($aliasHosts ?? [] as $aliasHost)` blocks `mail/ingress.blade.php` already has
   (one in `spec.rules`, one in `spec.tls.hosts`), pointing at the same `webmail-bulwark`
   Service:80 backend as the primary host's rule. Mechanical, low-risk, matches an existing
   pattern exactly.
2. Prerequisite unchanged from draft 2: `larakube dns:init production --zone=ourfridays.com
   --cloudflare-token=<token>` must already be running for ExternalDNS to pick up the new
   Ingress host and create the A record — `tool:alias` doesn't check this today (see below).
3. Then: `larakube tool:alias webmail mail.ourfridays.com` — done, no new command.

### Small, optional hardening worth doing alongside this
`ToolAliasCommand::handle()` never checks whether any `dns:init`-managed zone actually
covers the new alias before applying it — reuse `installedDnsZones()`
(`InteractsWithDnsZones.php`, already used by `dns:list`) to at least print a warning
("no ExternalDNS zone covers '{$aliasDomain}' — the record won't be created automatically,
run `dns:init` first or add it manually") rather than applying silently. Not a hard
refusal — an operator using an alias under a zone managed *outside* this cluster entirely
(e.g. manually) is a legitimate case, so warn, don't block.

## Explicitly out of scope for now (separate follow-up, not decided yet)
Propagating `@foreach($aliasHosts ...)` to the ~40 other `ingress.blade.php` templates that
are missing it (chat, sso, secrets, sign, git, dashboard, drive, desk, crm, support, tasks,
uptime, vpn, meet, design, resume, paste, link, flow, errors, analytics — the real
ClusterTool-backed ones; the app-framework scaffolds like nextjs/django/dotnet/etc. are
unrelated `new`-project templates, not `tool:alias` targets). `tool:alias` being silently
ineffective on every one of those today is worth fixing broadly at some point, but that's
a mechanical sweep across many files, not something to do speculatively inside this plan —
raise it separately once this Webmail case is done and verified.

## Branding caveat (unchanged from draft 1)
Per-domain branding *inside* Bulwark's own UI (different logo depending on which hostname a
user lands on) is still a separate, unverified question — `webmail:init --app-name=` is one
global value for the shared instance, and Zitadel's own org-branding only skins Zitadel's
login screen, not Bulwark's. Not addressed here.

## Verification (once built)
- `pint`, `phpstan`, `pest --parallel`.
- Live: `dns:init` for the zone, `tool:alias webmail mail.ourfridays.com`, confirm the
  Ingress actually gets the extra `rules`/`tls.hosts` entries, confirm ExternalDNS creates
  the A record, confirm Traefik issues a cert and login works end-to-end, confirm
  `tool:alias webmail mail.ourfridays.com --remove` cleans it back up.
