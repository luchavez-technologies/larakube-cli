# Cleanup: stray `git` rows in the production tool registry

Manual, one-off. The CLI no longer creates these rows (host lookups and
branding now use the host-derived instance), and by the no-cleanup-code rule it
will not grow a command to delete them.

**Cluster:** `larakube-159.89.205.239` · **Secret:** `larakube-shared/larakube-tools-registry` (key `registry.json`)

Before this change the registry held 4 `git` rows. Keep only the one with
`instance: "git-luchtech-dev"`. Remove the rows whose `instance` is `""` or
`null`.

## 1. Back up

```bash
kubectl --context larakube-159.89.205.239 -n larakube-shared get secret larakube-tools-registry -o jsonpath='{.data.registry\.json}' | base64 -d > registry-backup.json
```

```bash
jq '[.[] | select(.tool == "git")] | map({instance, host, brandName})' registry-backup.json
```

Expect 4 rows, one with `"instance": "git-luchtech-dev"`.

## 2. Build the cleaned copy (touches `git` rows only)

```bash
jq '[.[] | select(.tool != "git" or ((.instance // "") != ""))]' registry-backup.json > registry-clean.json
```

```bash
jq '[.[] | select(.tool == "git")] | length' registry-clean.json
```

Expect `1`. Also confirm nothing else changed:

```bash
jq 'length' registry-backup.json registry-clean.json
```

The difference must be exactly `3`.

## 3. Preview, then apply

```bash
kubectl --context larakube-159.89.205.239 -n larakube-shared create secret generic larakube-tools-registry --from-file=registry.json=registry-clean.json --dry-run=client -o yaml | kubectl --context larakube-159.89.205.239 diff -f -
```

```bash
kubectl --context larakube-159.89.205.239 -n larakube-shared create secret generic larakube-tools-registry --from-file=registry.json=registry-clean.json --dry-run=client -o yaml | kubectl --context larakube-159.89.205.239 apply -f -
```

## 4. Verify (after `./build`)

- [ ] `larakube tool:list production` shows a single `git` row for `git.luchtech.dev`.
- [ ] `larakube git:init production` asks neither for the host nor for the brand name, and the `git` row count stays at 1.
- [ ] `larakube git:show production` still shows `https://git.luchtech.dev`.

Keep `registry-backup.json` until all three pass. To restore, repeat step 3 with it.
