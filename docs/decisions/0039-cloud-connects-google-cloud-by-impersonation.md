# 0039: LaraKube Cloud Connects to Google Cloud by Impersonation, Set Up from Cloud Shell

**Status:** Accepted

## Context

LaraKube Cloud creates servers in the customer's own Google Cloud project. Until now the customer created a service account, downloaded a JSON key and pasted it into Cloud, which stored it in OpenBao. That is hard for newcomers (several console screens) and leaves a long-lived key that can be copied and used anywhere until it is deleted.

LaraKube Desktop and the LaraKube CLI avoid keys by using the person's own `gcloud` login on their own machine. Cloud runs on a server, so the same approach would mean Cloud holding each customer's Google login, with access to every project they have.

## Decision

1. **The customer's project gets a service account that LaraKube Cloud impersonates; no key exists.** Cloud never stores a Google key or a Google login for a customer.

2. **Setup is one command the customer runs in Google Cloud Shell, as themselves.** Cloud shows an "Open Cloud Shell" button and a command tailored to their project. The command:
   - enables the APIs LaraKube needs;
   - creates a service account with a name Cloud chose at random for this connection (`larakube-<random>`);
   - grants it only what creating a LaraKube server touches (ADR 0027): Compute Instance Admin (v1) for the VM and its address, Compute Security Admin and Compute Network Admin for its firewall rules, Service Account User, and Service Usage Consumer so OpenTofu can read the Compute API's state (the command itself enables the APIs, as the customer), plus DNS Administrator when Cloud DNS is used;
   - grants LaraKube Cloud's platform service account the Service Account Token Creator role on that one service account.

   The customer can read every line before pressing Enter. No Google OAuth app is involved, so there is no Google verification review and no 100-user cap.

3. **Cloud verifies before it trusts the connection.** On "Verify", Cloud impersonates the expected service account (the random name it chose), reads it back, and checks it lives in the stated project. Only that account is ever impersonated for that connection, which closes the confused-deputy gap: a customer cannot point Cloud at a service account in someone else's project.

4. **Every operation borrows a short-lived token.** Creating or destroying a server gets a one-hour access token (IAM Credentials `generateAccessToken`), passed to the runner Job as `GOOGLE_OAUTH_ACCESS_TOKEN`; the LaraKube CLI and OpenTofu use it as they already use a `gcloud` token. Installs get an ID token for the server's audience (`generateIdToken`), so ADR 0038's install trust works unchanged: its subject is still the service account's numeric ID.

5. **LaraKube Cloud's own identity is one platform service account** in LuchTech's project. Its key is LaraKube Cloud's own secret, kept in the deployment's secrets, never in a customer's project. A later change can replace it with Cloud's own issuer (ADR 0038) and workload identity federation.

6. **The customer can cut Cloud off without Cloud.** Removing the Token Creator grant, or deleting the service account, ends Cloud's access at once. Tokens already issued expire within the hour; Google cannot revoke them early, so lifetimes stay short.

7. **Keys remain supported** for anyone who prefers them, and existing key connections keep working. "Sign in with Google" (a one-time OAuth grant that runs the same setup) can be added later, once the app passes Google's verification; it creates the same impersonation setup.

## Consequences

- Connecting a project takes one click and one Enter, with nothing installed locally, and no Google key or login reaches LaraKube Cloud.
- A leaked Cloud database yields no customer cloud access; the platform key plus a customer's grant is needed, and the customer can withdraw the grant.
- LaraKube Cloud depends on its platform service account; losing or rotating its key interrupts operations until the new key is deployed, though customers' grants name the account, not the key.
- The LaraKube CLI accepts a Google access token from `GOOGLE_OAUTH_ACCESS_TOKEN` as a credential source, alongside a key file and the local `gcloud` login.

## Amendment: Connect with Google

Cloud Shell still assumes people know what a Google Cloud project is, and LaraKube Cloud is for
non-developers first. So the main path becomes **Connect with Google**:

- The person allows Google's permission screen for `cloud-platform` once. LaraKube Cloud asks for
  online access only, so no refresh token exists, and the access token lives for at most an hour,
  encrypted, only for the setup.
- They pick the billing account that pays (where their credits are). LaraKube Cloud then creates a
  project just for LaraKube, links that billing account, enables the APIs, and sets up the same
  service account and grants as point 2, on their behalf.
- The outcome is identical to the Cloud Shell setup: LaraKube Cloud impersonates that service
  account and holds no Google key or login for the customer. Cloud Shell stays as the advanced path
  for people who want to read every line or use an existing project.
- Until Google reviews the app, it is limited to 100 users for the project's lifetime, each listed as
  a test user, who see an "unverified app" notice. Review is required before the public can use it.
- Signing in to LaraKube Cloud with Google or GitHub is separate and asks only for basic profile
  scopes, so it needs no review and does not count toward that cap.
