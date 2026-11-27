# LaraKube Cloud: Login with Google/GitHub, and Connect with Google (ADR 0039 amendment)

## Goal
A non-developer signs in with Google or GitHub, clicks **Connect with Google**, allows Google's
permission screen, picks the payment account that holds their credits, and LaraKube Cloud sets up
everything in their Google Cloud by itself. No project IDs, no Cloud Shell, no terminal.

## Login with Google / GitHub (Socialite, already installed)
- `social_accounts` (user_id, provider, provider_id, unique provider+provider_id).
- `/auth/{google|github}/redirect` and `/callback`; a provider shows only when its client is configured.
- Basic scopes only (openid email profile; GitHub user:email), so no Google review and no user cap.
- First sign-in creates the user (email marked verified by the provider) and their personal team, as
  registration does. An existing account is linked by email only when both the provider and
  LaraKube Cloud have it verified, the same rule admin SSO uses.
- Two-factor still applies: a user with 2FA goes to Fortify's challenge, not straight in.
- Buttons on the login and register pages.

## Connect with Google
1. `credentials/google/oauth/redirect`: Google's screen asking for `cloud-platform`, online access
   (no refresh token is ever requested). The callback keeps the token encrypted in the session.
2. A "Which payment account pays for your servers?" step lists open billing accounts
   (Cloud Billing API). None → a plain explanation and a link to Google's billing setup.
3. A queued job (payload encrypted) with the one-hour token:
   create project `larakube-<random>` → link billing → enable Compute, IAM, IAM Credentials →
   create the service account (its numeric ID is the subject) → grant the ADR 0039 roles →
   grant LaraKube Cloud's platform account Token Creator on it → verify by impersonation, retrying
   while Google applies the grants. Progress shows on the Credentials page; a failure says which
   step and why. The token is dropped when the job ends.
4. The result is the same keyless impersonation credential as the Cloud Shell flow, which moves
   behind an "Advanced" link.

## Needs from the user
- One Google OAuth client (Web) in `luchtech`: app name, External + Testing, friends as test users,
  scope `cloud-platform`; redirect URIs `/auth/google/callback` and `/credentials/google/callback`.
- A GitHub OAuth app with callback `/auth/github/callback`.
- Cloud Resource Manager, Cloud Billing and Service Usage APIs enabled in `luchtech` (the OAuth
  client's project carries the quota): `deploy/google-platform-accounts.sh` enables them.

## Verification
- Tests: login creates/links/refuses; 2FA handoff; connect callback, billing list, the setup job's
  calls in order, failure messages, idempotent re-run.
- Live: sign in with a second Google account listed as a test user; connect with credits; create
  and destroy a small server (credits, with the user's go-ahead).
