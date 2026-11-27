# LaraKube Cloud: connect Google Cloud from Cloud Shell, by impersonation (ADR 0039)

## Goal
A friend with Google Cloud credits connects their project to LaraKube Cloud with one click and one Enter, and Cloud never holds a key.

## Steps
1. **LaraKube CLI:** accept `GOOGLE_OAUTH_ACCESS_TOKEN` as a Google credential: no key file and no local `gcloud` login required when it is set; OpenTofu gets it as now. Test the token path in `cloud:create` and `cloud:destroy`.
2. **Cloud, platform identity:** config for the platform service account (its key JSON from a deployment secret, `LARAKUBE_GOOGLE_PLATFORM_CREDENTIALS`), and a small client for IAM Credentials (`generateAccessToken`, `generateIdToken`) and IAM (`serviceAccounts.get`), signed as the platform account (reuse the JWT bearer code in `InstallerTokens`).
3. **Cloud, credentials:** `cloud_credentials.kind` (`key` | `impersonation`), plus `service_account_email` and `subject` (numeric ID) for impersonation; `vault_path` nullable for it. A pending connection stores the random service account name Cloud expects.
4. **Cloud, connect flow:** on the Credentials page, "Connect with Cloud Shell": choose the project ID, see the command (copy button) and an "Open Cloud Shell" link; "Verify" impersonates the expected account, reads it back, checks the project, and marks the connection ready.
5. **Cloud, operations:** `OperationLauncher` passes `GOOGLE_OAUTH_ACCESS_TOKEN` (a fresh one-hour token) instead of `gcp.json` for impersonation connections; `InstallerTokens` mints the install ID token with `generateIdToken` for them, and reads the subject from the stored numeric ID.
6. **Docs:** a short page on connecting Google Cloud from Cloud Shell, and on revoking it (remove the grant or delete the account).

## Verification
- Tests: command text per project; verify refuses an unexpected account or project; launcher env for both kinds; installer ID token via impersonation.
- Live, with the user's go-ahead: connect a real project from Cloud Shell, create and destroy a server, install an app, then remove the grant and see Cloud refused.

## Needs from the user
- A platform service account in LuchTech's own Google Cloud project, with the IAM Credentials API enabled; its key as staging Cloud's `LARAKUBE_GOOGLE_PLATFORM_CREDENTIALS` secret (and locally in `cloud/.env`).
