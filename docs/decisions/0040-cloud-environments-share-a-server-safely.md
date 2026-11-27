# 0040: LaraKube Cloud Environments Can Share a Server Without Sharing Secrets or Jobs

**Status:** Accepted

## Context

LaraKube Cloud's staging and production are planned for the same server. Each runs in its own Kubernetes namespace (`cloud-staging`, `cloud-production`) with its own database, but two things were shared by construction:

- **Team secrets in OpenBao.** Every environment wrote to `secret/data/teams/<team id>/…`. Team ids restart at 1 in each database, so staging's team 1 and production's team 1 wrote the same paths: customer cloud keys, DNS tokens, state storage keys and server access, overwritten across environments.
- **Runner Jobs.** Both started Jobs in one `larakube-jobs` namespace, under one set of permissions, so either could delete the other's.

Cloud also reached OpenBao with a fixed token that was never renewed. OpenBao tokens expire (32 days by default), after which every server operation fails. Setting all this up meant pasting two tokens (`OPENBAO_TOKEN`, `LARAKUBE_K8S_TOKEN`) by hand per environment.

## Decision

1. **Each Cloud environment names itself by its Kubernetes namespace**, read from the pod (`/var/run/secrets/kubernetes.io/serviceaccount/namespace`), or `LARAKUBE_INSTANCE` where set. Nothing to configure.
2. **Its team secrets live under its own prefix:** `secret/data/<namespace>/teams/<team id>/…`. Paths stored before this keep working where they are.
3. **It signs in to OpenBao through Kubernetes,** using its pod's service account token, on the role `larakube-cloud-<namespace>`, whose policy allows only `<namespace>/teams/*`. The OpenBao token is short-lived and renewed by signing in again. A fixed `OPENBAO_TOKEN` is still honoured where set (local development).
4. **Its runner Jobs run in `<namespace>-jobs`,** with a Role bound to its own pods' service account. Cloud uses that service account's token to start them; `LARAKUBE_K8S_TOKEN` is no longer needed. Its callback address defaults to `http://web.<namespace>.svc.cluster.local`.
5. **One idempotent script per environment** sets this up on the server: `cloud/deploy/engine/install.sh <kube-context> <namespace>` creates the jobs namespace and its permissions, and the OpenBao policy and Kubernetes role, reusing the Kubernetes sign-in LaraKube already configures for External Secrets.

OpenBao namespaces (2.3+) were considered. A prefix with a per-environment policy gives the isolation needed with less setup; namespaces remain an option if environments should share nothing in OpenBao at all.

## Consequences

- Staging and production on one server cannot read, overwrite or delete each other's secrets or Jobs.
- A new Cloud environment needs no OpenBao or Kubernetes token in its `.env`; running the setup script once is enough.
- Cloud's pods use the namespace's `default` service account. Anything else running in a Cloud namespace would share its access, so Cloud namespaces hold only Cloud.
