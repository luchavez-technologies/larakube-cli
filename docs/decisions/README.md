# Architecture Decision Records

Short, durable records of *why* the LaraKube CLI works the way it does — the
decisions and trade-offs that aren't obvious from the code alone. Each ADR is
self-contained: read it without needing the conversation that produced it.

These live in the repo (not in anyone's local notes) so contributors share the
same context, and so the knowledge-graph tooling indexes them.

## Format

One decision per file, `NNNN-kebab-title.md`, with **Status / Context / Decision /
Consequences**. Statuses: `Accepted`, `Superseded by NNNN`, `Proposed`.

## Index

| # | Decision | Status |
|---|----------|--------|
| [0001](0001-mail-sso-shelved.md) | Mail (Stalwart/Bulwark) stays on passwords — full-OIDC SSO shelved | Accepted |
| [0002](0002-mail-credential-model.md) | Mail server credentials: API-key daily driver, recovery break-glass, k8s-only | Accepted |
| [0003](0003-dkim-rsa-only.md) | DKIM is RSA-only | Accepted |
| [0004](0004-stalwart-oidc-two-roles.md) | Stalwart's two OIDC roles (provider vs directory) — reference | Accepted |
| [0005](0005-environment-names-are-dynamic.md) | Environment names are dynamic; only `local` is reserved | Accepted |
| [0006](0006-centralized-forwardauth-sso.md) | Centralized OAuth2-Proxy (ForwardAuth) for tools with no free native OIDC | Accepted |
| [0007](0007-environment-context-resolution.md) | Environment → kube-context resolution is a single contract | Accepted |
| [0008](0008-rbac-claim-flattening.md) | Role-gated SSO via a claim-flattening Zitadel Action | Accepted |
