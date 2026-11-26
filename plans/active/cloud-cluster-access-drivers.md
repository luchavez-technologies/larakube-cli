# LaraKube Cloud cluster access: read and install drivers (ADR 0038)

## Goal
Install apps on any provider's servers with access minted per install, and let the user see and cut off both of Cloud's accesses.

## Steps, in order
1. **Cloud as token issuer** (Cloud repo):
   - per-team Ed25519 signing keys in OpenBao, signed through OpenBao and never exported;
   - `/oidc/teams/{team}/.well-known/openid-configuration` and its public-key (JWKS) endpoint;
   - `InstallerCredential` interface, starting with the k3s driver (aud `larakube-cloud:<server>`, sub `team:<id>`, exp 10 min).
2. **LaraKube CLI**: `cloud:provision` passes the team issuer and subject to `CloudInstallerTrust` (already issuer-agnostic). The trust config is unchanged otherwise.
3. **Access card** on the server page:
   - Read and Install, each with connected / last used / Disconnect;
   - `cloud:access:disconnect --reader|--installer`.
4. **Runner dev**: a k3d cluster with the same trust config, plus `dev.sh test-install <app>`, which runs `cloud:apps:install` the way a Cloud Job does.
5. **The current Google path becomes the GKE driver** once GKE servers exist. k3s VMs on GCP use step 1.
6. **Managed drivers, one per provider as it ships:** EKS (assume role + access entry), DOKS (credentials API, `expiry_seconds`), AKS (Entra service principal), and an installer ServiceAccount fallback.

## Verification
- Install an app on a k3d cluster in runner dev with a Cloud-issued token. A token for another server's audience is refused. An expired token is refused.
- Disconnect Install from the Access card, and the next install fails until it is reconnected. Deleting the binding with `kubectl` has the same effect.
- Read access is unaffected by either.
