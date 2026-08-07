# Plan: Omnichannel — post to (and chat from) LinkedIn / Facebook in one place

**Status:** ⛔ NOT STARTED — confirmed 2026-08-08: no social/LinkedIn/Facebook command or `ClusterTool` case exists.

## 🎯 The ask

The team runs a **LinkedIn Page** and a **Facebook Page** and wants one
dashboard to **post** to both and **maybe chat** (respond to messages) from both.

## 🔍 Honest reality first (verified, July 2026)

There is **no single OSS tool** that does both posting *and* chatting across both
networks — and the hard limits are the **platforms**, not the tools:

- **Posting to LinkedIn + Facebook Pages: yes, feasible.** OSS schedulers do it.
- **Chat/inbox for Facebook (+ Instagram): yes.** Meta exposes Messenger &
  Instagram messaging APIs; OSS inboxes support them.
- **Chat/inbox for LinkedIn: no — not a tool gap, a platform wall.** LinkedIn
  does **not** expose Page messaging/DMs to third-party apps. No self-hosted (or
  SaaS) tool can pull a LinkedIn Page inbox. LinkedIn chat stays manual in
  LinkedIn itself.

So the realistic split is **two tools**: a **scheduler** (post to both) and a
**social inbox** (chat for FB/IG only). And both require real platform-side
setup that dwarfs the deploy (see Risks).

## 🧱 Candidates (researched, not assumed)

### Posting / scheduling

| | License | Footprint | Fit |
|---|---|---|---|
| **Mixpost (Lite)** ✅ | free core (paid "Pro" adds team approvals/roles) | **Light — Laravel + Postgres + Redis**, nothing exotic | Laravel-native (matches the whole LaraKube stack), reuses Plex Commons (Postgres+Redis) cleanly, easy standalone Docker. |
| Postiz | AGPL-3.0 (fully free) | **Heavy — 6–9 containers incl. a full Temporal queue stack + Elasticsearch + its own Postgres**, 2–4GB RAM | More platforms (30+) and more active, but the Temporal+Elasticsearch footprint is a lot for the $24 box already running Stalwart/Zitadel/Bulwark/etc. |

**Recommendation: Mixpost**, specifically because of the resource constraint that
drives every other choice in this repo — it's Laravel (thematically native),
reuses the Commons Postgres+Redis, and doesn't drag in Temporal/Elasticsearch.
Postiz is the pick only if max-platform-coverage outweighs the RAM cost.

### Social inbox / chat (Facebook + Instagram)

- **Chatwoot** — MIT core (enterprise/ dir is separately licensed but not needed
  for self-host), the most-adopted OSS omnichannel inbox. Channels: FB Messenger,
  Instagram DM, WhatsApp, Telegram, email, website live-chat — **not LinkedIn**.
  Stack: Rails + **Postgres + Redis** → reuses Plex Commons. This is the "social
  version of FreeScout" (which is email-only).

## 🧩 How it slots into LaraKube

- **`social:init` → Mixpost** — Commons-backed (Postgres + Redis) shared tool in
  `larakube-shared`, same shape as `desk:init`/`chat:init`. Host `social.<tld>`.
- **Chat/inbox → Chatwoot** — either `inbox:init` or an engine under the existing
  `desk` family (FreeScout = email desk; Chatwoot = social desk). Commons-backed.
  Ship only if they actually want FB/IG *chat* — posting alone (Mixpost) may be
  the whole need.
- Both are ordinary Commons tools: `DeploysClusterTool`, `allocateDatabase`,
  `--vpn-only`, `sso:wire` (both support OIDC), `mail:wire` (both send email).
  No new infra patterns.

## 🚦 Phases

1. [ ] `social:init` → Mixpost (Commons Postgres+Redis, ingress, admin bootstrap,
   `--remove`). Posting to LinkedIn + FB Pages. This alone likely satisfies the
   core ask.
2. [ ] (Only if FB/IG chat is wanted) Chatwoot as `inbox:init` (or a `desk`
   engine) — FB Messenger + Instagram DM inbox. **Explicitly no LinkedIn.**
3. [ ] Docs: the platform-app-setup runbook (the real work — see Risks) + the
   plain statement that LinkedIn chat isn't possible.

## ⚠️ Risks / open questions (the honest part)

- **The platform app-review IS the project.** For *either* tool, LinkedIn and
  Facebook require the team to create their own developer app, request the right
  scopes, and pass review before it works in production:
  - **Facebook**: a Meta app with `pages_manage_posts` / `pages_read_engagement`
    (posting) and `pages_messaging` (inbox), flipped from *Development* to
    *Live* — Live mode needs Meta App Review. Weeks, not minutes.
  - **LinkedIn**: page posting needs the **Community Management API**
    (`w_organization_social`) product, which LinkedIn gates behind an approval
    that "can't coexist with the products you actually need" — a known,
    fiddly LinkedIn pain point. And again: **no messaging API at all.**
  LaraKube can deploy the tool and template the OAuth-credential wiring, but it
  **cannot** shortcut Meta/LinkedIn review. Set that expectation up front.
- **Overlap with existing tools.** Matrix/Synapse (`chat`) = internal team chat;
  FreeScout (`desk`) = email shared-inbox. Chatwoot overlaps FreeScout (both are
  inboxes) — decide whether to run both or standardize the inbox on Chatwoot
  (which also does email). Don't deploy two inboxes by accident.
- **Mixpost Lite vs Pro.** Confirm the free Lite tier covers LinkedIn + FB Page
  posting for the team's needs before building — team-approval workflows are the
  Pro upsell, basic multi-account posting is in Lite.
- **"Maybe chat" may be a no.** Given LinkedIn chat is impossible and FB/IG chat
  needs the messaging-review gauntlet, the pragmatic v1 might be **posting only**
  (Mixpost), with the social inbox deferred until the team confirms they want to
  run FB/IG support through it.

## ✅ Bottom line for the team

- **Post to LinkedIn + Facebook from one dashboard:** yes — `social:init`
  (Mixpost). Needs your own Meta + LinkedIn developer apps.
- **Chat from one dashboard:** Facebook + Instagram yes (Chatwoot); **LinkedIn
  no** (platform doesn't allow it). If LinkedIn chat was the point, then "no
  such thing exists" is the honest answer — and that's a LinkedIn restriction
  nothing self-hosted can get around.
