# Test Plan: NestJS end to end (server-app engine pilot)

**Status:** ⛔ NOT STARTED. NestJS stays `isDeployable() === false` (so
`init` refuses it) until every box below passes.

NestJS is the first framework on the shared server-app engine
(`generateServerAppManifests()`, `resources/views/k8s/server/*`,
`docker/nestjs.blade.php`). If the engine holds up here, the other seven
frameworks follow it one at a time.

After `./build`. Run from a scratch directory, not inside another project.

## 1. Scaffold
- [ ] `larakube nestjs:new demo --fast` completes (it used to crash at Dockerfile generation).
- [ ] `demo/src/health.controller.ts` exists, and `src/app.module.ts` imports it (`./health.controller.js` under ESM) and lists it in `controllers`.
- [ ] `demo/Dockerfile.nestjs` and `demo/.dockerignore` exist; the latter excludes `node_modules` and `dist`.

## 2. Local dev
- [ ] `cd demo && larakube up`: the dev pod runs `npm run start:dev` and `https://demo.<your tld>/healthz` returns `{"status":"ok"}`.
- [ ] Edit `src/app.controller.ts` and confirm the change reloads without restarting the pod. If it doesn't, file watching isn't crossing the bind mount; report it, and polling will be added.

## 3. Production image locally
- [ ] `docker build -f Dockerfile.nestjs --target deploy -t demo-nest .` succeeds.
- [ ] `docker run --rm -p 3100:3000 demo-nest`, then `curl localhost:3100/healthz` returns 200.

## 4. Cloud via CI (scratch Forgejo repo)
1. Create an empty repo on git.luchtech.dev.
2. `git init && git remote add origin <ssh url>` and commit.
3. `larakube cloud:configure production --skip-audit`: pick a scratch host such as `nest-demo.luchtech.dev`.
4. Create `.env.production` (e.g. `NODE_ENV=production`), then `larakube dotenv:push production`.
5. Commit `.larakube.json .infrastructure .github`, then push.

- [ ] The build job builds `Dockerfile.nestjs` with Podman and pushes.
- [ ] The deploy job passes "Verify runtime secrets were pushed", then rolls out `demo-nestjs`.
- [ ] `https://nest-demo.luchtech.dev/healthz` returns 200.
- [ ] No credentials anywhere under `.infrastructure/k8s/overlays/production/`.

## Cleanup
Delete the scratch repo, its package, and the `demo-production` namespace
(`larakube` remove path or by hand).

## Report back
Which step failed and its output, or "all passed". NestJS then flips to
deployable and the next framework (AdonisJS) starts.
