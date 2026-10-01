# Documenso database credential, and the stale OpenBao rotation roles

Cluster side of the fix in this change. Context `larakube-159.89.205.239`,
host `sign.luchtech.dev`.

## What was wrong

**Documenso crash-loops on "password authentication failed"** (879 restarts).
Postgres rejects the `db-password` in `documenso-secrets-sign-luchtech-dev`, and
nothing keeps that Secret in step with the role. Verified live: the role is
SCRAM, the Secret's password fails over the network path, and no ExternalSecret
exists for Documenso.

**Cause (a code bug, now fixed).** `sign:init`, `record:init`, `resume:init` and
`design:init` each registered the database role as an OpenBao static role
themselves. That rotates the password at once and on a schedule, but the
ExternalSecret that carries each new password into the tool's Secret is created
only by `secrets:wire`. Init started the rotation and nothing delivered the
result. The same pattern is why the rotation recipe ends with `secrets:wire` and
`secrets:unwire`: a tool that skipped them is a time bomb. Only Sign was
installed, so only Sign broke. The exact moment the passwords diverged was not
determined; the mechanism is certain from the code and the live state.

**Dead rotation roles, and why the CLI could not remove them.** `secrets:unwire`
works out which static role to delete from the tool's *current* names, so when
a tool is renamed its old role can never be reached. Five of them error every 10
seconds: `grafana`, `data_directus`, `link_kutt`, `penpot`,
`sign_documenso_sign_luchtech_dev`.

## What changed in the code

- The four inits above no longer register a static role. Rotation is started
  only by `secrets:wire`. A test fails if any of them calls
  `registerStaticRole()` again, and `sign:init` has a behavioural test that no
  `static-roles` or `rotate-role` request is sent.
- New `secrets:prune {environment} [--dry-run] [--force]` deletes every OpenBao
  static role on the Commons Postgres whose Postgres role no longer exists. It
  refuses to delete anything if it cannot list the Postgres roles, never touches
  a role whose Postgres role exists, and leaves non-Postgres roles alone.

## Run it

Use the dev-mode binary from `cli/` so you get the new command without
`./build`:

```zsh
cd ~/Codes/Ideas/laravel-k8s/cli
CTX=larakube-159.89.205.239
```

### 1. Repair Documenso

`secrets:wire` registers the role, forces a rotation so OpenBao and Postgres
agree, creates the ExternalSecret that syncs the password into the Secret, waits
for that sync, and only then restarts the Deployment. It fixes the Secret
whatever the role's current password is.

```zsh
./larakube secrets:wire production --tool=sign --domain=sign.luchtech.dev --context=$CTX
```

Verify:

```zsh
kubectl --context $CTX -n larakube-shared get externalsecret | grep -i documenso
kubectl --context $CTX -n larakube-shared rollout status deploy/documenso-sign-luchtech-dev --timeout=180s
curl -s -o /dev/null -w '%{http_code}\n' https://sign.luchtech.dev/
```

The ExternalSecret must exist and be `SecretSynced`, the rollout must complete,
and the site must stop returning 404. Then log in as an existing user. Documenso
holds 3 users and 1 document. The Secret was recreated on 21 September, so if
a login or a stored 2FA setting fails afterwards, the encryption keys may have
been regenerated then; stop and tell me rather than retrying.

### 2. Prune the dead roles

Always look first:

```zsh
./larakube secrets:prune production --dry-run --context=$CTX
```

It must list exactly these five and nothing else: `grafana`, `data_directus`,
`link_kutt`, `penpot`, `sign_documenso_sign_luchtech_dev`. If it lists a role
for a tool that is installed (Forgejo, Outline, Documenso, Zitadel,
Vaultwarden, Grafana's current role `grafana_monitor_luchtech_dev`), stop.
Then:

```zsh
./larakube secrets:prune production --context=$CTX
```

It deletes only OpenBao's registration. No Postgres role or Secret is touched.
Afterwards the OpenBao log should stop printing the `ALTER ROLE` errors every
10 seconds:

```zsh
kubectl --context $CTX -n larakube-secrets logs deploy/openbao-backend --since=2m | grep -c ERROR
```

### 3. Leftover ExternalSecrets of tools that are not installed

Seven ExternalSecrets have no target Secret and no workload using them:
`record-sendrec-secrets-db`, `resume-reactive-secrets`,
`resume-reactive-secrets-db`, `sheet-secrets`, `sheet-secrets-db` (failing for
44 days), plus `data-secrets-db` and `link-kutt-secrets-db`, which pointed at
the static roles `secrets:prune` just removed. Verified live before deleting:
each one's target Secret does not exist and no Deployment, StatefulSet or
CronJob references it.

```zsh
kubectl --context $CTX -n larakube-shared delete externalsecret \
  record-sendrec-secrets-db resume-reactive-secrets resume-reactive-secrets-db \
  sheet-secrets sheet-secrets-db data-secrets-db link-kutt-secrets-db --ignore-not-found
```

## The rule this encodes

A tool's `:init` may read an existing OpenBao password so it never overwrites
it, but it never starts rotation. `secrets:wire` is the only command that does,
because it is the only one that also delivers the result. Add a tool to the
migration recipe's last step and `secrets:prune` finds whatever it left behind.
