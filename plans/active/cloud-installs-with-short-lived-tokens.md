# LaraKube Cloud installs apps with short-lived tokens

## Goal
LaraKube Cloud installs apps (OpenBao first) on a server it created, without holding standing admin
access. Cloud's own access stays read-only. Admin access is borrowed per install from the customer's
cloud account and expires within an hour.

## Trust model
- **VPS (k3s):** at creation the LaraKube CLI makes k3s trust Google-signed ID tokens through
  structured authentication (`--authentication-config`, stable since Kubernetes 1.34):
  - issuer `https://accounts.google.com`, audience `larakube-cloud:<server-id>` (one audience per
    server, so a token for one server is refused by every other);
  - `claims.sub` must equal the connected service account's numeric ID (`client_id` in its key),
    which never moves to another account the way an email can;
  - `claims.email_verified == true`;
  - username `larakube-cloud:google:<sub>`, group `larakube-cloud:installers`.
  - ClusterRoleBinding `larakube-cloud-installers`: that group → `cluster-admin`. Installs create
    namespaces, CRDs and ClusterRoles, which only admin can do. Deleting the binding revokes Cloud.
- **Managed Kubernetes (later):** the provider's own short-lived tokens (GKE already accepts Google
  tokens for the service account). Same Job, different token source.
- **Verified locally** (k3s v1.36.2): the right token got admin only after the binding; another
  server's audience, a made-up token and a forged signature were refused (401); removing the binding
  revoked access.

## What each part does
1. **LaraKube CLI, `cloud:create`:** new `--cloud-installer-audience` and `--cloud-installer-subject`
   options. Before k3s is installed, the node gets
   `/etc/rancher/k3s/larakube-cloud-auth.yaml` and a drop-in
   `/etc/rancher/k3s/config.yaml.d/50-larakube-cloud.yaml` adding the `authentication-config`
   apiserver arg. After the cluster is up, the binding is applied. The result reports
   `installer: {ready, audience}`.
2. **LaraKube CLI, `headlamp:trust`:** `--oidc-*` flags and `--authentication-config` cannot be set
   together, so it refuses on a server that trusts LaraKube Cloud instead of breaking the API server.
   Moving Headlamp's issuer into the shared authentication file is a follow-up.
3. **LaraKube CLI, `cloud:apps:install <app> --kubeconfig=`:** one allow-listed verb for the runner.
   Apps come from an explicit map (`openbao` only for now) to their `*:init` command, run with
   `production --context=<kubeconfig context> --domain=<host> --force --no-interaction --json`.
4. **Runner:** `cloud:apps:install` joins the allow-list.
5. **LaraKube Cloud:**
   - mints the ID token from the stored key for audience `larakube-cloud:<server-id>`; it never reaches
     the database or the logs, only the Job's Secret, which expires with the Job;
   - builds the Job kubeconfig from the server's read kubeconfig (API URL and CA) plus that token;
   - `InstallApp` operation, `server_apps` table (app, host, version, status, error);
   - Apps tab on the server page: OpenBao card, host prefilled from the server's managed zones.
   - Servers created before this have no trust; the tab says so (recreate to enable installs).

## Follow-ups
- **Keyless access (Workload Identity Federation)** so Cloud stores no service account keys. ADR.
- k3s audit log for the installer identity, readable by the customer.
- Headlamp issuer in the shared authentication file.
