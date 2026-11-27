# LaraKube Cloud: staging and production on one server (ADR 0040)

## Steps
1. Cloud config: `larakube.instance` from the pod's namespace (or LARAKUBE_INSTANCE, else `local`);
   jobs namespace defaults to `<instance>-jobs`, callback URL to `http://web.<instance>.svc.cluster.local`.
2. Team secret paths through one helper, under `secret/data/<instance>/teams/…`; the vault accepts
   stored paths from before.
3. OpenBao Kubernetes sign-in when no OPENBAO_TOKEN is set: role `larakube-cloud-<instance>`, the pod's
   service account token, short-lived OpenBao token cached and renewed by signing in again.
4. `deploy/engine/install.sh <context> <namespace>`: jobs namespace + Role/RoleBinding/NetworkPolicy,
   OpenBao policy + Kubernetes role. Idempotent.
5. `.env.staging` / `.env.production`: drop OPENBAO_TOKEN, LARAKUBE_K8S_TOKEN, LARAKUBE_JOBS_NAMESPACE,
   LARAKUBE_CALLBACK_BASE_URL and local-only leftovers; fix drivers and broadcasting.

## Verification
- Tests: path prefix and legacy paths; Kubernetes sign-in and re-sign-in on expiry; config defaults.
- Live (user): run install.sh for each environment on hello-cloud, deploy, add a credential in each,
  confirm each lands under its own prefix and the other environment's token is refused.
