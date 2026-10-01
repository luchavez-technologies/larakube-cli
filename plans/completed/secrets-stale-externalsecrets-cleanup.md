# Dead ExternalSecrets and generators in `larakube-shared`

Context `larakube-159.89.205.239`. One-off cleanup; no CLI change. Tool removal
already deletes its own ExternalSecret and generator, so this does not recur.

Fourteen objects are left from tools removed by older code. Each ExternalSecret
reports `SecretSyncedError` or `SecretMissing` forever (6 of the cluster's 16 show
as errors) and is retried every refresh. None has a database, role, bucket,
registry row, workload or target Secret.

| ExternalSecret (7) | Generator (7, `VaultDynamicSecret`) |
|---|---|
| `data-secrets-db` | `data-secrets-db` |
| `link-kutt-secrets-db` | `link-kutt-secrets-db` |
| `record-sendrec-secrets-db` | `record-sendrec-secrets-db` |
| `resume-reactive-secrets` | `resume-reactive-secrets-db` |
| `resume-reactive-secrets-db` | `sheet-secrets-db` |
| `sheet-secrets` | `monitor-secrets-db` |
| `sheet-secrets-db` | `forgejo-git-luchtech-dev-db` |

`monitor-secrets-db` reads the old `grafana` role, which is gone.
`forgejo-git-luchtech-dev-db` is an unused duplicate: Forgejo's live ExternalSecret
reads `forgejo-secrets-git-luchtech-dev-db`.

## 1. Check (read-only)

```zsh
CTX=larakube-159.89.205.239
lsh() { kubectl --context=$CTX -n larakube-shared "$@"; }

# none of the target Secrets exists
for s in data-secrets link-kutt-secrets record-sendrec-secrets resume-reactive-secrets sheet-secrets monitor-secrets; do
  printf "%s: " $s; lsh get secret $s --no-headers 2>&1 | head -1
done

# nothing live reads the two generators that are not obviously dead
lsh get externalsecret -o json | jq -r '.items[] | select(.spec.dataFrom != null) | "\(.metadata.name) -> \(.spec.dataFrom[].sourceRef.generatorRef.name)"'
```

Every Secret must answer `NotFound`. The second list must contain neither
`monitor-secrets-db` nor `forgejo-git-luchtech-dev-db`.

## 2. Delete

```zsh
lsh delete externalsecret data-secrets-db link-kutt-secrets-db record-sendrec-secrets-db \
  resume-reactive-secrets resume-reactive-secrets-db sheet-secrets sheet-secrets-db --ignore-not-found

lsh delete vaultdynamicsecret.generators.external-secrets.io data-secrets-db link-kutt-secrets-db \
  record-sendrec-secrets-db resume-reactive-secrets-db sheet-secrets-db monitor-secrets-db \
  forgejo-git-luchtech-dev-db --ignore-not-found
```

## 3. Verify

```zsh
lsh get externalsecret
```

Nine ExternalSecrets remain across the cluster, **all `SecretSynced`**: documenso,
forgejo, grafana, outline, stalwart and synapse in `larakube-shared`, plus
`sso-secrets-db`, vaultwarden and netbird. Then `larakube secrets:prune production
--dry-run` still says "Nothing to prune".
