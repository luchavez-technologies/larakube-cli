# Walkthrough: `statamic:new` with a starter kit

Verifies the official-CLI scaffold (Statamic 6), starter kits, Bun/PHP
adoption, HMR, database mode and the super user. Run on a machine with the
local cluster up. Database is the default for content and users; step 5
covers `--content=files`.

## 1. Bedrock
```bash
larakube statamic:new bedrock-demo --starter-kit=jasonbaciulis/bedrock
```
Answer the wizard: keep "Database" for where content and users live; use a
real email and a strong password (12+ characters) for the super user.

Expect, in order:
- `composer global require statamic/cli` then `statamic new … jasonbaciulis/bedrock`
  running in the builder container, including the kit's `bun install`;
- `Using bun, the package manager this site ships with.`;
- `Using PHP 8.5, which this site requires.` (Bedrock requires `^8.5`).

Check the blueprint recorded them:
```bash
grep -E '"packageManager"|"phpVersion"' bedrock-demo/.larakube.json
```
Expect `bun` and `8.5`.

## 2. It runs, content moves to the database, and you can log in
Accept the `larakube up` prompt. After `up`, expect these steps in the web pod:
`Moving content into the database (Statamic Eloquent driver)...`,
`Adding the users tables...`, `Running migrations...`,
`Creating super user …`, all ✔.

Open the printed URL: Bedrock's home page renders (its pages now come from the
database). Open `/cp` and sign in with the super user from step 1. In the
project, `git status` shows the changes the setup made (composer.json,
config/, database/migrations/, app/Models/User.php): commit them.

Re-run to confirm it's safe: `larakube statamic:database --no-user` completes
without duplicating anything.

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

## 5. Files mode
```bash
larakube statamic:new files-demo --content=files --email=you@example.com
```
Expect `Super user … created.` during the scaffold (a file in `users/`), no
database steps after `up`, and `/cp` login working.

## Result
- [ ] 1 scaffold  - [ ] 2 site + DB + login + HMR  - [ ] 3 build  - [ ] 4 plain site  - [ ] 5 files mode
