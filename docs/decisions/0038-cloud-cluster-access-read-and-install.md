# 0038: LaraKube Cloud Reaches a Cluster Through Two Separate Accesses, Read and Install, on Every Platform

**Status:** Accepted

## Context

LaraKube Cloud needs two things from a customer's cluster:

- **To read it:** nodes, pods, events, logs and metrics, for the server page and stats.
- **To install apps on it:** create namespaces, Deployments, Secrets, ServiceAccounts and sometimes ClusterRoles.

Read access is already a ServiceAccount, created by `cloud:access:connect`. That command proves against the live API that the account can't read Secrets, exec, create pods or bind roles, and removes it if it can.

Install access was built for one platform. `CloudInstallerTrust` makes k3s trust Google-issued ID tokens for one audience (the server) and one subject (the team's Google service account), and Cloud's `InstallerTokens` mints those tokens. That only works where Google is the issuer.

LaraKube Cloud is going to provision servers on AWS, Hetzner, DigitalOcean, Azure and others, both plain VPSes running k3s and managed Kubernetes (EKS, AKS, DOKS, GKE). Install access needs one rule that covers all of them.

Install rights are effectively admin. Anyone who can create pods with any spec can mount Secrets or the host and escalate. A role "scoped to installs" limits mistakes, not attackers, so install access is treated as admin access in every decision below.

## Decision

1. **Read and install are separate accesses.** Each is created, shown and revoked on its own. Read access stays a standing, read-only ServiceAccount on every platform. Install access is never folded into it.

2. **Install access is minted per install wherever the platform allows it, and stored only where it doesn't.** The order of preference:
   1. **A token Cloud issues itself**, on every k3s server LaraKube provisions, whatever the provider.
      - Each team has its own signing key, held in OpenBao. OpenBao does the signing, so the key never leaves the vault.
      - Cloud publishes each team's issuer and public keys at `https://<cloud>/oidc/teams/<team>`.
      - k3s trusts that issuer for the server's own audience only, through `CloudInstallerTrust` (already issuer-agnostic).
      - Tokens live about 10 minutes.
   2. **The provider's own short-lived credential**, on managed Kubernetes:
      - **GKE:** a Google ID token, with rights through Kubernetes RBAC.
      - **EKS:** an IAM role in the customer's account that Cloud assumes with an external ID, mapped through an access entry. Tokens last 15 minutes.
      - **DOKS:** the credentials API with `expiry_seconds`, using the `kubernetes:access_cluster` scope.
      - **AKS:** an Entra service principal with Azure RBAC for Kubernetes. AKS structured authentication is preview-only, so it isn't used.
   3. **An installer ServiceAccount**, only where neither of the above is possible (Linode LKE, Vultr VKE, a customer's own cluster).
      - Its token is stored only in OpenBao and handed only to runner Jobs.
      - It is labelled as admin, and the user can choose to disconnect it after every install.

3. **One interface, one driver per platform.** Cloud gets install access through `InstallerCredential::for($server)->kubeconfig()`, which returns a kubeconfig for that one install Job.
   - The kubeconfig is never stored, and never sent to the browser or written to logs.
   - The Google-specific `InstallerTokens` becomes the GKE driver, and k3s servers move to Cloud-issued tokens, including k3s VMs on GCP.
   - A driver is added when its provider ships.

4. **The user can see both accesses and cut either one off.** The server page has an Access card: Read and Install, what each uses, when it was connected, when it was last used, and a Disconnect button for each.

5. **Revocation never depends on Cloud.** Every access can be revoked on the cluster or at the provider, without Cloud: `cloud:access:disconnect --reader|--installer`, plain `kubectl`, deleting the access entry or role assignment, or revoking the provider token. A compromised Cloud could show a fake Disconnect button, so the button is a convenience; the revocation on the cluster or provider is what counts.

6. **The provider credential is scoped by the customer.** On managed platforms, the credential Cloud uses to provision can also mint access to every cluster in that account or project. The setup guide tells users to give LaraKube Cloud a dedicated project, role or token, not their main one.

## Consequences

- No admin credential is stored for any server LaraKube provisions, on any provider. A breach of Cloud's database alone yields no cluster access. Cloud-issued tokens need live access to OpenBao's signing, and a leaked team key reaches only that team's servers.
- This is stronger than the Google-only design, where the stored service-account key could mint a token for any of a team's servers at any time.
- k3s servers depend on Cloud's issuer being reachable only to install. If Cloud goes away, nothing that is running breaks (the "works without Cloud" rule). Deleting the trust drop-in and the `larakube-cloud-installers` binding removes the last trace.
- Managed platforms get the shortest lifetime each one offers, from about 15 minutes to an hour. Clusters we can't configure get a stored credential, clearly labelled and revocable on the cluster.
- A local k3d cluster set up with the same trust config as a provisioned k3s server tests the Cloud-issued path end to end in runner dev. Each managed driver needs a real account to test.
- Kubernetes audit logs name the identity behind every change: the team issuer's subject, the provider identity, or the installer ServiceAccount.
