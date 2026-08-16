# Plan: Align Twenty CRM Deployment with Official Twenty HQ Documentation

## Goal Description
Clean up ad-hoc trial environment variables and strictly align Twenty CRM's Kubernetes deployment manifest and CLI wiring with [Twenty HQ's official Docker Compose specification](https://github.com/twentyhq/twenty/blob/main/packages/twenty-docker/docker-compose.yml) and [official self-hosting documentation](https://docs.twenty.com).

---

## Official Documentation Audit

Per official `twentyhq/twenty` reference files (`packages/twenty-docker/docker-compose.yml` and `.env.example`):

1. **Port Variable**: Official image uses `NODE_PORT: 3000` (not generic `PORT` or custom flags).
2. **Core Connection & URL Settings**:
   - `SERVER_URL`: `"https://{{ $host }}"`
   - `PG_DATABASE_URL`: `"postgres://{{ $dbUser }}:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/{{ $dbName }}"`
   - `REDIS_URL`: `"redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}"`
3. **Encryption & Security**:
   - `ENCRYPTION_KEY`: 32-character key generated via `openssl rand -base64 32` or hex (used for encrypting tokens at rest).
   - `APP_SECRET`: Session & token signing secret.
   - `ACCESS_TOKEN_SECRET`, `LOGIN_TOKEN_SECRET`, `REFRESH_TOKEN_SECRET`, `FILE_TOKEN_SECRET`.
4. **Node Memory**: `NODE_OPTIONS: "--max-old-space-size=1536"` (Node.js V8 allocation limit to accommodate TypeORM entity compilation).

---

## Proposed Changes

### `cli/resources/views/k8s/crm/shared.blade.php`

Strip all non-standard trial flags (`SERVER_TYPE`, `IS_AUTO_MIGRATION_ENABLED`, `STORAGE_TYPE`, `DISABLE_TELEMETRY`) and clean up the manifest to use official standard Twenty CRM environment variables:

```yaml
          env:
            - name: NODE_PORT
              value: "3000"
            - name: NODE_OPTIONS
              value: "--max-old-space-size=1536"
            - name: SERVER_URL
              value: "https://{{ $host }}"
            - name: FRONT_BASE_URL
              value: "https://{{ $host }}"
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName ?? 'crm-twenty-secrets' }}
                  key: db-password
            - name: PG_DATABASE_URL
              value: "postgres://{{ $dbUser ?? 'crm_twenty' }}:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/{{ $dbName ?? 'crm_twenty' }}"
            - name: REDIS_URL
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}"
            - name: ENCRYPTION_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName ?? 'crm-twenty-secrets' }}
                  key: encryption-key
            - name: APP_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName ?? 'crm-twenty-secrets' }}
                  key: encryption-key
            - name: ACCESS_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName ?? 'crm-twenty-secrets' }}
                  key: access-token-secret
            - name: LOGIN_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName ?? 'crm-twenty-secrets' }}
                  key: login-token-secret
            - name: REFRESH_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName ?? 'crm-twenty-secrets' }}
                  key: refresh-token-secret
            - name: FILE_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName ?? 'crm-twenty-secrets' }}
                  key: file-token-secret
```

---

## Verification Plan

### Automated Tests
Run Pest test suite to ensure manifest rendering and command execution tests pass:
```bash
./php vendor/bin/pest tests/Feature/CrmInitCommandTest.php
```

### Manual Verification
1. Re-build LaraKube CLI: `./build`
2. Deploy or update Twenty CRM instance: `larakube crm:init production`
3. Verify pod readiness and HTTP connectivity against official specs.
