# Plan: self-hosted team chat (Synapse/Matrix)

## 🎯 Objective

`chat:init` — deploy **Synapse (Matrix)** into `larakube-shared` as a
Slack/Teams alternative. One cluster-wide instance, not per-project — same tier
as FreeScout/Metabase/GlitchTip.

## 🔍 Why Matrix over alternatives

| | DB | Notes |
|---|---|---|
| **Matrix/Synapse** ✅ | **Postgres** | Federation protocol, free native OIDC, single container. The sole supported chat engine. |
| Mattermost | Postgres | Previously considered — OIDC is paywalled behind Professional tier. Removed from LaraKube. |

Mattermost was the original chat engine but required a paid plan for SSO and its
free Team Edition was losing feature parity. Matrix/Synapse is the sole supported
engine, with free native OIDC and no licensing friction.

## 🧱 Design

- **`SharedClusterService::CHAT`** + **`ClusterTool::CHAT`** ('chat') — same
  dual-registration every shared tool has.
- **Namespace**: `larakube-shared`.
- **Database**: Plex Commons Postgres (`chat_matrix` tenant). `--no-plex` falls
  back to a bundled Postgres pod.
- **Outbound email**: via `mail:wire` — writes the SMTP block into Synapse's
  `homeserver.yaml` Secret (Synapse ignores env vars, so `configuresViaConfigFile()`
  returns true). `ClusterTool::smtpEnv()` defines the schema.
- **SSO**: native OIDC support via `sso:wire` — writes `oidc_providers:` block
  into `homeserver.yaml`.
- **Context resolution**: built on `DeploysClusterTool` from day one.

## 🛠 Commands

```bash
larakube chat:init [environment] [--context=] [--domain=] [--no-plex] [--vpn-only] [--remove]
larakube tool:add chat
larakube tool:remove chat
larakube mail:wire chat       # SMTP notifications through Stalwart (config-file path)
larakube sso:wire chat        # OIDC login via Zitadel (config-file path)
```

## ♻️ Reuse

- `InteractsWithChat` trait — `chatNamespace()`, `chatKubectl()`,
  `isChatInstalled()`, `chatEngineInstalled()`, `readChatSecret()`,
  `readChatWiredSmtp()`, `readChatWiredOidc()`, `renderSynapseConfig()`.
- `DeploysClusterTool` — `resolveToolContext()`, `removeResources()`.
- `SharedClusterService` / `ClusterTool` enums.
- Host resolution via `resolveChatHostReadOnly()`.

## 🚦 Phases

1. [x] `SharedClusterService::CHAT` + `ClusterTool::CHAT` cases; `chat:init`
   deploy (namespace, Commons Postgres alloc, secrets sync, manifest apply,
   rollout wait).
2. [x] `chat:init --remove` (Plex tenant drop + resource delete).
3. [x] `chat:init` renders `homeserver.yaml` as a Secret (not a ConfigMap) so
   `mail:wire` and `sso:wire` can re-render it with email/OIDC blocks.
4. [x] `mail:wire chat` — persists SMTP creds to `chat-smtp` Secret,
   re-renders `homeserver.yaml` with `email:` block, restarts Synapse.
5. [x] `sso:wire chat` — registers Synapse in Zitadel, persists creds to
   `chat-oidc` Secret, re-renders `homeserver.yaml` with `oidc_providers:` block.
6. [ ] Docs page (setup, Cinny/Element client access, SSO note).

## ✅ Verification

- `chat:init` (local) → register an account via Synapse admin API, open the
  Cinny client, send a message.
- `mail:wire chat` → trigger a notification email and confirm it arrives via
  Stalwart.
- `sso:wire chat` → log into Cinny using Zitadel SSO.
- `chat:init --remove` → everything gone cleanly.
