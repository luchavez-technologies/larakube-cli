# Plan: SendRec native workspace SSO (replace / complement the ForwardAuth gate)

**Status:** Not started. Immediate manual path works today; automation is a
follow-up in the same bucket as Windmill.

## What we got wrong
ForwardAuth was chosen for `record` on the premise that *"SendRec has no native
OIDC in its OSS edition"* (docs/decisions/0006). **That premise is false.**
SendRec's README advertises **workspace OIDC + SAML 2.0 + SCIM 2.0**, and the
project is **AGPL v3 — all of it free**.

Consequence for the operator: the ForwardAuth gate authenticates at the edge, but
SendRec still shows its own login, so the user signs in twice. That is exactly
what a gate does — it was never going to be single sign-on.

## Verified
- SendRec supports workspace-level OIDC/SAML, free (AGPL v3).
- It is **not** env-configurable: upstream `.env.example` contains **no** OIDC
  variables. Configuration lives **in the app, per workspace**.
- The `OIDC_ENABLED` / `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET` / `OIDC_ISSUER`
  refs previously in `k8s/record/shared.blade.php` were **fabricated** and inert
  (the `record-sendrec-oidc` Secret was never even created, because
  `usesForwardAuth()` short-circuits `applyToolEnv`). Removed.

## Immediate path (no code, works today)
1. In SendRec → workspace settings → SSO/OIDC, point it at Zitadel
   (issuer `https://sso.<apex>`, client id/secret from a Zitadel app registered
   for SendRec itself). Note the redirect URI SendRec shows and register it.
2. Verify a single sign-in works end to end.
3. Then decide on the gate:
   - **Drop it** — `larakube sso:wire <env> --tool=record --remove`. Simplest;
     SendRec's own login page becomes the Zitadel button.
   - **Keep it** — defence in depth (nothing without a Zitadel session even
     reaches the app). Because the Zitadel session already exists after the gate,
     SendRec's OIDC redirect should complete without a second prompt — one extra
     click, not a second credential entry. Verify before relying on this.

## Automation (follow-up)
SendRec's workspace OIDC is API/UI-driven, so it needs the same treatment as
Windmill (`plans/active/windmill-wiring.md`): a `InteractsWithSendrecApi` trait
plus a branch in `sso:wire`. Before writing any of it, **read back SendRec's own
serialization** of the SSO config from its API rather than guessing field
names — guessing has caused four bugs this cycle.

## Decisions to revisit
- `ClusterTool::usesForwardAuth()` returns true for `RECORD`. If native OIDC is
  adopted and the gate dropped, set it back to false and the ForwardAuth
  machinery becomes unused — at which point decide whether to keep it for genuine
  no-SSO tools or remove it (ADR 0006 would need a superseding note).
- ADR 0006's Context table names SendRec as the motivating "no free native OIDC"
  tool. That justification is void; the ADR needs an amendment recording this.
- `ghcr.io/sendrec/sendrec:latest` is unpinned — pin it.
