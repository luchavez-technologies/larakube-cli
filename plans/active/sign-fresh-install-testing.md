# Test Plan: Sign as a fresh install (headless Chrome + ToolInstance names)

**Status:** ⛔ NOT STARTED
**Plan:** `plans/active/sign-headless-shell-and-signing-cert.md`

Production has no Sign now (removed with `--purge`), so this is a true fresh
install. Run from `cli/` with `./larakube` (current source) or after `./build`.

## 1. Install
`./larakube sign:init production --domain=sign.luchtech.dev`
- [ ] It asks to add **Headless Chrome** to the Commons (the Commons ConfigMap
  never listed the hand-made one). Accept.
- [ ] The existing `larakube-plex/headless-shell` Deployment is updated in place,
  not recreated: same Service ClusterIP (`kubectl -n larakube-plex get svc
  headless-shell`), image now `chromedp/headless-shell:151.0.7922.109`.
- [ ] `sign:init` finishes with "Documenso signature stack is live".

## 2. Names (ADR 0021, all instance `sign-luchtech-dev`)
- [ ] `kubectl -n larakube-shared get deploy,svc,ingress,secret -l larakube-tool=sign`
  and `get secret | grep sign-documenso`: Deployment/Service/Ingress
  `sign-documenso-sign-luchtech-dev`; Secrets `sign-documenso-secrets-…`,
  `sign-documenso-signing-cert-…`.
- [ ] `larakube plex:show production` lists database `sign_documenso_sign_luchtech_dev`
  and bucket `sign-storage-sign-luchtech-dev`.
- [ ] `larakube tool:list production` shows the `sign` row with instance
  `sign-luchtech-dev` (not empty).

## 3. Documents actually complete
- [ ] Open `https://sign.luchtech.dev`, create an account, upload a PDF, add
  yourself as signer, sign it.
- [ ] The document reaches **Completed** (not stuck at Pending), and the
  downloaded PDF has the certificate page and a signature panel.
- [ ] `kubectl -n larakube-shared logs deploy/sign-documenso-sign-luchtech-dev | grep -iE "browser|ENOENT|seal"`
  shows no errors.

## 4. Certificate: new host via the DNS challenge
- [ ] `echo | openssl s_client -connect 159.89.205.239:443 -servername sign.luchtech.dev | openssl x509 -noout -issuer -startdate`
  shows Let's Encrypt, issued today.

## 5. Optional: wiring names
- [ ] `larakube sso:wire production --tool=sign`: the OIDC Secret is
  `sign-documenso-oidc-sign-luchtech-dev` and SSO login works.

## Report back
Which step failed and its output, or "all passed". Then commit.
