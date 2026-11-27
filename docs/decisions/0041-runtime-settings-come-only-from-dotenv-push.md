# 0041: An Environment's Runtime Settings Come Only from `dotenv:push`

**Status:** Accepted

## Context

A cloud environment's runtime settings reached its pods two ways:

- `dotenv:push` sent the keys that look secret to OpenBao (or the app Secret).
- The deploy workflow wrote the blueprint's public values (`APP_URL`, `APP_ENV`, Reverb hosts…) into a `.env` baked into the workflow file, then replaced the app's ConfigMap with it on every deploy.

An app's own non-secret settings (a vault driver, an SSO base URL, a mail host) took neither path: the workflow's list comes from the blueprint, and `dotenv:push` skipped anything that didn't look secret. Anything put in the ConfigMap by other means was wiped by the next deploy. The same values also lived in two places, `.env.<env>` and the committed workflow, free to drift, and Desktop showed the workflow-written ones as "Local Only" because nothing pushed them.

The workflow writing settings bought little: a deploy already refuses to run until `dotenv:push` has created the app Secret, so someone pushes from their machine either way.

## Decision

1. **`.env.<env>` is the one source of an environment's runtime settings, and `dotenv:push` the one way they reach the cluster.** Secrets (by name, or values with credentials in them) go to OpenBao or the app Secret, as before. Every other key goes to the app's ConfigMap, written whole by `dotenv:push`.
2. **The deploy workflow no longer writes the ConfigMap.** It checks that both the Secret and the ConfigMap exist and stops with the fix (`larakube dotenv:push <env>`) if not, then builds and rolls out.
3. **Build-time values stay with the build.** The workflow's build job still writes the blueprint's public values and `<ENV>_BUILD_ENV` into the image build's `.env`, because a frontend (Vite, a static site) bakes them in at build time.
4. Plex Commons' secret keys stay out of the push, as before: OpenBao rotates them. Its connection addresses are pushed like any other setting.

## Consequences

- One habit for every change to a running environment's settings: edit `.env.<env>`, push, deploy.
- Desktop's settings table reads simply in sync or not.
- Environments whose workflow was generated before this still rewrite the ConfigMap on deploy, dropping what `dotenv:push` put there. Regenerate their workflow (`cloud:configure <env> --only=ci`, or Configure & Push Secrets in Desktop) once.
