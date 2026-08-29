# Sign: Commons Headless-Shell + Signing Certificate Fix — Handoff for Claude Code

Repo: `~/Codes/Ideas/laravel-k8s/cli` (the LaraKube CLI, Laravel Zero). Grounded
against the actual current code as of this write-up — file paths, method
names, and line numbers below are real, not guessed. `ensureCommons()` already
exists (`InteractsWithPlex.php:713`) and `sign:init` already calls it for
`postgres` and the S3 backend — this is an extension of an existing pattern,
not a new one.

Context: on 2026-08-27 we diagnosed and hand-fixed three stacked bugs keeping
self-hosted Documenso ("sign") documents stuck at PENDING forever, directly
against a live cluster (context `larakube-159.89.205.239`), then confirmed the
fix end-to-end (a test document reached COMPLETED). This spec is how to make
`plex:init` and `sign:init` reproduce that fix on every install — new and
existing — instead of it living only as manual kubectl surgery.

## 1. The three bugs, briefly

1. **Missing Chromium.** Documenso's image ships the Playwright package but no
   browser binary, so its "seal document" job (renders a certificate PDF via
   CDP) fails on every self-hosted install. Fix: point it at an external
   headless-Chromium CDP endpoint (`NEXT_PRIVATE_BROWSERLESS_URL` /
   `NEXT_PUBLIC_USE_INTERNAL_URL_BROWSERLESS` — both officially supported by
   Documenso).
2. **Internal job self-calls round-tripping through the public URL.**
   Documenso's job runner self-invokes over HTTP for every background job.
   Without `NEXT_PRIVATE_INTERNAL_WEBAPP_URL` it falls back to
   `NEXT_PUBLIC_WEBAPP_URL` — internal traffic leaving the cluster through
   ingress/TLS and back in. Works, but fragile; should always be set.
3. **No signing certificate.** Documenso defaults to `./example/cert.p12`,
   which only exists in local dev checkouts, never the production image.
   Every document's final PDF-signing step fails with `ENOENT` until a real
   P12 is mounted and `NEXT_PRIVATE_SIGNING_*` env vars are set.

Bug 1 masked bugs 2 and 3 completely — nothing got far enough in the pipeline
to hit them. Confirmed by log evidence: before the fix, sealing failed at
Chromium; after fixing Chromium + the internal URL, it got all the way to the
signing step and failed there with `ENOENT: ./example/cert.p12` — proof the
first two fixes work, and exposing the third.

## 2. `plex:init`: add `headless-shell` as a Commons service

### 2a. New driver: `app/Enums/RenderDriver.php`

`headless-shell` doesn't fit `DatabaseDriver`/`CacheDriver`/`SearchDriver`/
`StorageDriver` — those enums double as *project-level* architecture choices
(what cache/DB/storage a new Laravel project picks), which is why they each
implement a dozen-plus contracts (`HasArtisanCommands`,
`HasComposerDependencies`, `RequiresPhpExtensions`, etc.). Nothing ever
"chooses" headless-shell per-project — it's Commons-only, consumed by
whichever app declares it as a dependency. Forcing it into one of those enums
means fake-implementing a dozen irrelevant methods.

`commonsServiceCatalog()` (`InteractsWithPlex.php:132`) only actually calls
`->commonsServiceName()`, `->isPlexReady()`, and `->getLabel()` on each driver
case — so a new enum needs exactly those three methods, nothing else:

```php
<?php

namespace App\Enums;

use App\Contracts\PlexProvisionable;

/**
 * Commons-only backing services with no project-level equivalent — there is
 * no "choose this as your project's renderer" the way DatabaseDriver/
 * CacheDriver/StorageDriver double as project architecture choices. Kept
 * deliberately minimal: PlexProvisionable + getLabel() is everything
 * commonsServiceCatalog() actually calls.
 */
enum RenderDriver: string implements PlexProvisionable
{
    case HEADLESS_CHROME = 'headless-shell';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::HEADLESS_CHROME => 'Headless Chrome (PDF/certificate rendering)',
        };
    }

    public function isPlexReady(): bool
    {
        return true;
    }

    public function commonsServiceName(): ?string
    {
        return $this->value;
    }

    public function getDockerImage(): string
    {
        return match ($this) {
            self::HEADLESS_CHROME => 'chromedp/headless-shell:latest', // pin a tag for prod
        };
    }

    public function port(): int
    {
        return match ($this) {
            self::HEADLESS_CHROME => 9222,
        };
    }
}
```

### 2b. Wire it into the catalog and defaults (`InteractsWithPlex.php`)

- `commonsServiceCatalog()` (line 134): add `RenderDriver::cases()` to the
  `array_merge(...)`.
- `normalizeCommonsSpec()`'s `$defaults` array (line 65): add

  ```php
  'headless-shell' => ['image' => RenderDriver::HEADLESS_CHROME->getDockerImage(), 'port' => RenderDriver::HEADLESS_CHROME->port(), 'memory' => '1Gi'],
  ```

  No `storage` key — stateless, matches Redis's shape (line 69), not
  Postgres/Meilisearch/SeaweedFS's (which have PVCs).
- Default enabled/disabled: leave it out of the `in_array($name, ['postgres',
  'redis'], true)` default-on list (line 94) — opt-in like Meilisearch/object
  storage, demand-driven onto an existing Commons via `ensureCommons()` the
  same way `sign:init` already demand-drives `postgres` and `seaweedfs`.

### 2c. Manifest block (`resources/views/k8s/plex/commons.blade.php`)

Model directly on the `redis` block (line 381-457) — same shape (stateless,
no PVC), swapping the readiness mechanism and adding the `/dev/shm` tmpfs
Chromium needs:

```blade
@if(($spec['services']['headless-shell']['enabled'] ?? false))
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: headless-shell
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  replicas: 1
  selector:
    matchLabels:
      app: headless-shell
  template:
    metadata:
      labels:
        app: headless-shell
    spec:
      containers:
        - name: headless-shell
          image: {{ $spec['services']['headless-shell']['image'] }}
          # No custom args — the image's own entrypoint runs Chromium on
          # 127.0.0.1:9223 and proxies it onto 0.0.0.0:9222 via socat (the
          # supported workaround for Chrome's remote-debugging-address
          # restriction since M113). Passing --remote-debugging-port/-address
          # here fights the entrypoint and breaks it — confirmed by hand.
          ports:
            - containerPort: {{ $spec['services']['headless-shell']['port'] }}
          volumeMounts:
            - name: dshm
              mountPath: /dev/shm
          resources:
            requests:
              memory: "128Mi"
              cpu: "50m"
            limits:
              memory: "{{ $spec['services']['headless-shell']['memory'] }}"
              cpu: "500m"
          readinessProbe:
            httpGet:
              path: /json/version
              port: {{ $spec['services']['headless-shell']['port'] }}
            initialDelaySeconds: 3
            periodSeconds: 10
          livenessProbe:
            httpGet:
              path: /json/version
              port: {{ $spec['services']['headless-shell']['port'] }}
            initialDelaySeconds: 5
            periodSeconds: 20
      volumes:
        - name: dshm
          emptyDir:
            medium: Memory
            # k8s defaults /dev/shm to 64Mi like Docker; Chromium needs more
            # or it crashes with BUS_ADRERR.
            sizeLimit: 512Mi
---
apiVersion: v1
kind: Service
metadata:
  name: headless-shell
  labels:
    larakube.io/managed-by: larakube
    larakube.io/component: plex
spec:
  selector:
    app: headless-shell
  ports:
    - protocol: TCP
      port: {{ $spec['services']['headless-shell']['port'] }}
      targetPort: {{ $spec['services']['headless-shell']['port'] }}
  type: ClusterIP
@endif
```

No public host block needed (unlike SeaweedFS/MinIO) — `headless-shell`
never implements `HasPromptableHosts`, so `ensurePublicHosts()` skips it
automatically. ClusterIP only, by design — nothing outside the cluster needs
to reach it.

### 2d. The one real gotcha: consumers must use the ClusterIP, not the DNS name

Every other Commons service is consumed via
`{service}.{namespace}.svc.cluster.local` (see `printCommonsReady()`,
`InteractsWithPlex.php:543`). **headless-shell cannot be** — Chrome's DevTools
HTTP server (which `/json/version` discovery hits) rejects any request whose
Host header isn't a literal IP or `localhost` (Chrome 66+ DNS-rebinding
protection, no disabling flag). Confirmed by hand this session. Add a small
helper next to `readCommonsS3Credentials()` (`InteractsWithPlex.php:918`):

```php
/**
 * Resolve a Commons service's ClusterIP directly. Needed for headless-shell:
 * Chrome's DevTools HTTP server rejects any Host header that isn't a literal
 * IP or "localhost" (no disabling flag), so the usual
 * "{service}.{ns}.svc.cluster.local" DNS name every other Commons consumer
 * uses does NOT work here — this is the one exception.
 */
protected function resolveCommonsServiceClusterIp(string $service): ?string
{
    $ns = $this->plexNamespace();
    $ip = trim(Process::run(
        $this->plexKubectl()." get service {$service} -n {$ns} -o jsonpath=".escapeshellarg('{.spec.clusterIP}'),
    )->output());

    return $ip === '' ? null : $ip;
}
```

## 3. `sign:init` full wiring (`app/Commands/Sign/SignInitCommand.php`)

Current flow already does `ensureCommons(['postgres'])` (line 64) then the S3
service (line 82-84). Add right after:

```php
if (! $this->ensureCommons(['headless-shell'])) {
    return 1;
}

$browserlessIp = $this->resolveCommonsServiceClusterIp('headless-shell');
if ($browserlessIp === null) {
    $this->laraKubeError('Could not resolve the Commons headless-shell ClusterIP. Re-run `larakube plex:init`.');
    return 1;
}
```

And before rendering the manifest, the signing certificate (new helper —
see §4):

```php
$signingCert = $this->ensureSignSigningCert($kubectl, $ns, $host);
if ($signingCert === null) {
    return 1;
}
```

Pass through to the view:

```php
$manifest = view('k8s.sign.shared', [
    // ...existing keys...
    'browserlessUrl' => "http://{$browserlessIp}:9222",
    'internalWebappUrl' => "http://sign.{$ns}.svc.cluster.local", // static — safe as DNS, this is plain HTTP, not CDP
    'signingCertSecret' => 'sign-signing-cert',
])->render();
```

`internalWebappUrl` is safe as a DNS name (unlike the CDP endpoint) — it's
Documenso's own self-HTTP-call for job dispatch, no Host-header restriction
applies. Matches the existing `sign` Service (`shared.blade.php:174`, port 80
→ 3000).

## 4. Signing certificate: `ensureSignSigningCert()`

New method, `InteractsWithSign.php` (near `readSignSecret`). Same
generate-once-never-rotate idiom `ensureCommonsSecret()` already uses
(`PlexInitCommand.php:461`) and the same "read existing, else generate" idiom
`deploySign()` already uses for `db-password`/`nextauth-secret` (lines
106-109) — except the P12 itself has to come from `openssl`, not
`bin2hex(random_bytes())`, so it can't be a plain literal round-trip.

```php
/**
 * Ensure a per-install self-signed signing certificate exists as the
 * sign-signing-cert Secret. Generated once, on first sign:init; never
 * rotated on re-run (like ensureCommonsSecret() and every other credential
 * in this class — regenerating would just mean re-signing the cert every
 * deploy for no reason, since it's not shared with anything else that could
 * drift). Returns the passphrase (used nowhere else — the secretKeyRef
 * handles wiring), or null on failure.
 *
 * -legacy is required: OpenSSL 3.x's new default PBE (AES-256+PBKDF2) isn't
 * readable by Documenso's P12 parser (node-forge), which expects the old
 * RC2/3DES-based encoding. Confirmed by hand this session.
 */
protected function ensureSignSigningCert(string $kubectl, string $ns, string $host): ?string
{
    $exists = trim(Process::run(
        "{$kubectl} get secret sign-signing-cert -n {$ns} -o name",
    )->output()) !== '';

    if ($exists) {
        return 'unused'; // already provisioned — nothing to do
    }

    $tmp = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
    $dir = $tmp->path();
    $passphrase = bin2hex(random_bytes(16));

    $ok = $this->withSpin('Generating Documenso signing certificate...', function () use ($dir, $host, $passphrase) {
        Process::run("openssl req -x509 -newkey rsa:2048 -keyout {$dir}/key.pem -out {$dir}/cert.pem -days 3650 -nodes -subj ".escapeshellarg("/CN={$host}"));
        return Process::run("openssl pkcs12 -export -legacy -out {$dir}/cert.p12 -inkey {$dir}/key.pem -in {$dir}/cert.pem -passout pass:".escapeshellarg($passphrase))->successful();
    });

    if (! $ok) {
        $tmp->delete();
        $this->laraKubeError('Could not generate the Documenso signing certificate (is `openssl` installed?).');
        return null;
    }

    Process::run(
        "{$kubectl} create secret generic sign-signing-cert -n {$ns} "
        ."--from-file=cert.p12={$dir}/cert.p12 "
        .'--from-literal=passphrase='.escapeshellarg($passphrase).' '
        ."--dry-run=client -o yaml | {$kubectl} apply -f -",
    );

    $tmp->delete();

    return $passphrase;
}
```

### Blade wiring (`resources/views/k8s/sign/shared.blade.php`)

Add to the container `env:` block (near the existing S3/SMTP vars):

```yaml
            - name: NEXT_PRIVATE_BROWSERLESS_URL
              value: "{{ $browserlessUrl }}"
            - name: NEXT_PUBLIC_USE_INTERNAL_URL_BROWSERLESS
              value: "true"
            - name: NEXT_PRIVATE_INTERNAL_WEBAPP_URL
              value: "{{ $internalWebappUrl }}"
            - name: NEXT_PRIVATE_SIGNING_TRANSPORT
              value: "local"
            - name: NEXT_PRIVATE_SIGNING_LOCAL_FILE_PATH
              value: "/app/certs/cert.p12"
            - name: NEXT_PRIVATE_SIGNING_PASSPHRASE
              valueFrom:
                secretKeyRef:
                  name: {{ $signingCertSecret }}
                  key: passphrase
```

Add a volume + mount to the pod spec (new — nothing is currently mounted
in this Deployment):

```yaml
          volumeMounts:
            - name: signing-cert
              mountPath: /app/certs
              readOnly: true
      volumes:
        - name: signing-cert
          secret:
            secretName: {{ $signingCertSecret }}
```

## 5. `sign:remove` cleanup (`app/Commands/Sign/SignRemoveCommand.php`)

Line 27-28's `teardown()` deletes `secret/sign-secrets secret/sign-smtp
secret/sign-oidc` — add `secret/sign-signing-cert` to that list (app-owned,
not shared Commons infra, so it's correct to remove it here — unlike
`headless-shell` itself, which stays, exactly like `postgres`/`redis` are
never touched by an individual app's `:remove`).

## 6. Existing (pre-fix) installs get backfilled automatically

`ensureCommons()` (`InteractsWithPlex.php:713`) already diffs the requested
service list against what the live Commons ConfigMap currently offers and
offers to add whatever's missing (lines 741-756) — so any cluster that ran
`plex:init`/`sign:init` before this change gets `headless-shell` added the
next time `sign:init` runs against it, with no separate migration path
needed. `ensureSignSigningCert()` is naturally idempotent the same way
(checks for the Secret first). Worth explicitly testing against a cluster
seeded to look like a pre-fix install (Commons has postgres/redis/seaweedfs,
no headless-shell; `sign-documenso` exists, no `sign-signing-cert`).

## 7. Explicitly out of scope for v1

- **Redis-based concurrency throttling** on headless-shell — deprioritized.
  Real Chromium render cost is ~3ms warm / ~42ms cold per render, so the
  actual collision window under realistic load is tiny. Revisit only if
  concurrent-render OOM kills show up in practice.
- **BYO signing certificate** (`--signing-cert-file` / `--signing-cert-
  passphrase` flags on `sign:init`, for customers who want a CA-issued cert
  instead of self-signed) — worth having before this ships broadly to real
  customers doing legally-binding contracts, but not blocking the fix itself.
