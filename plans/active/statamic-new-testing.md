# Walkthrough: `statamic:new` with a starter kit (milestone 1)

Verifies the official-CLI scaffold, starter kits, Bun/PHP adoption and the
super user. Run on a machine with the local cluster up. Content and users are
files in this milestone (database mode is milestone 2).

## 1. Bedrock
```bash
larakube statamic:new bedrock-demo --starter-kit=jasonbaciulis/bedrock
```
Answer the wizard; for the super user, use a real email and a strong password
(12+ characters; it becomes the production login, since `users/` ships with
the site).

Expect, in order:
- `composer global require statamic/cli` then `statamic new … jasonbaciulis/bedrock`
  running in the builder container, including the kit's `bun install`;
- `Super user … created.`;
- `Using bun, the package manager this site ships with.`;
- `Using PHP 8.5, which this site requires.` (Bedrock requires `^8.5`).

Check the blueprint recorded them:
```bash
grep -E '"packageManager"|"phpVersion"' bedrock-demo/.larakube.json
```
Expect `bun` and `8.5`.

## 2. It runs, and you can log in
Accept the `larakube up` prompt (or `cd bedrock-demo && larakube up`). Open the
printed URL: Bedrock's home page renders. Open `/cp` and sign in with the super
user from step 1.

HMR: `kubectl get pods -n bedrock-demo-local` shows a `node` pod next to the web
pod (the Vite dev server). Change a colour in `resources/css/site.css`; the page
updates without a reload.

## 3. The image builds with Bun
```bash
cd bedrock-demo && larakube build
```
Bedrock's `build` script runs `bun run icons && vite build`; expect it to pass
(it would fail with npm alone).

## 4. No kit
```bash
larakube statamic:new plain-demo --fast --email=you@example.com
```
Interactively this asks for the password; `/cp` login works after `up`.

## Result
- [ ] 1 scaffold  - [ ] 2 site + login + HMR  - [ ] 3 build  - [ ] 4 plain site
