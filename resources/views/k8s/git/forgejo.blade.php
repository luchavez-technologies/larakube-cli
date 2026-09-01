@php
    // Forgejo's S3 driver (minio-go) wants a bare host:port — passing the
    // scheme fails at boot with "Endpoint url cannot have fully qualified
    // paths." MINIO_USE_SSL carries the http/https decision instead.
    $s3Host = preg_replace('#^https?://#', '', (string) ($s3Endpoint ?? ''));

    // Instance is always a real, host-derived slug — no bare/default form.
    $secretsName = "git-secrets-{$instance}";
    $dbSecretName = "forgejo-{$instance}";
    $deploymentName = "git-forgejo-{$instance}";
    $httpServiceName = "git-forgejo-http-{$instance}";
    $sshServiceName = "git-forgejo-ssh-{$instance}";
    $runnerDeploymentName = "git-forgejo-runner-{$instance}";
    $runnerConfigMapName = "git-forgejo-runner-config-{$instance}";
    $buckets ??= ['forgejo-storage', 'forgejo-packages', 'forgejo-lfs'];
@endphp
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: forgejo-data
  namespace: larakube-shared
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 5Gi
---
apiVersion: v1
kind: Secret
metadata:
  name: {{ $secretsName }}
  namespace: larakube-shared
type: Opaque
data:
  username: {{ base64_encode('larakube') }}
  password: {{ base64_encode($adminPassword ?? '') }}
  db-password: {{ base64_encode($dbPassword ?? '') }}
  registry-token: {{ base64_encode($registryToken ?? '') }}
  {{-- Forgejo Actions uses OFFLINE registration: a 40-char hex shared secret
       (first 16 chars become the runner UUID), not Forgejo's registration token. --}}
  runner-secret: {{ base64_encode($runnerSecret ?? 'pending') }}
  oauth-jwt-secret: {{ base64_encode($oauthJwtSecret ?? '') }}
  {{-- Long-lived, NOT regenerated per run. SECRET_KEY in particular encrypts
       stored data (2FA enrollments among it), so rotating it makes that data
       unreadable — the command reads these back before falling back to a fresh
       value. They live here, rather than inline in the Deployment, so a re-run
       can recover them. --}}
  secret-key: {{ base64_encode($secretKey ?? '') }}
  internal-token: {{ base64_encode($internalToken ?? '') }}
  lfs-jwt-secret: {{ base64_encode($jwtSecret ?? '') }}
  admin-email: {{ base64_encode($adminEmail ?? '') }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $deploymentName }}
  namespace: larakube-shared
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $deploymentName }}
  template:
    metadata:
      labels:
        app: {{ $deploymentName }}
    spec:
      containers:
        - name: forgejo
          image: codeberg.org/forgejo/forgejo:{{ $forgejoVersion ?? '16.0.1' }}
          ports:
            - containerPort: 3000
              name: http
            - containerPort: 22
              name: ssh
          env:
            {{-- FORGEJO__<SECTION>__<KEY> is Forgejo's native prefix. GITEA__ is
                 still honoured for compatibility, but we template these, so there
                 is no reason to emit the legacy names too. --}}
@if ($noPlex ?? false)
            - name: FORGEJO__database__DB_TYPE
              value: sqlite3
            - name: FORGEJO__database__PATH
              value: /data/gitea/forgejo.db
@else
            - name: FORGEJO__database__DB_TYPE
              value: postgres
            - name: FORGEJO__database__HOST
              value: postgres.{{ $plexNamespace }}.svc.cluster.local:5432
            - name: FORGEJO__database__NAME
              value: {{ $tenant }}
            - name: FORGEJO__database__USER
              value: {{ $tenant }}
            - name: FORGEJO__database__PASSWD
              valueFrom:
                secretKeyRef:
                  name: {{ $dbSecretName }}
                  key: FORGEJO_DB_PASSWORD
            {{-- Forgejo/Gitea defaults MAX_OPEN_CONNS to 0 (unlimited). Against
                 a shared Commons Postgres with a small connection ceiling, a
                 single restart can burst-open more connections than the
                 cluster has room for, hit the SUPERUSER-reserved-slots error,
                 crash, and restart into the same burst — a self-sustaining
                 crash loop that also starves every other tenant. Capping it is
                 a stopgap; plans/active/commons-connection-pooling.md is the
                 real fix (a pooler in front of every tenant, not per-tool caps). --}}
            - name: FORGEJO__database__MAX_OPEN_CONNS
              value: "10"
            - name: FORGEJO__database__MAX_IDLE_CONNS
              value: "5"
            - name: FORGEJO__database__CONN_MAX_LIFETIME
              value: "3h"
@endif
@if($appName ?? null)
            - name: FORGEJO__ui__APP_NAME
              value: "{{ $appName }}"
@endif
            - name: FORGEJO__security__INSTALL_LOCK
              value: "true"
            {{-- Public self-registration has no place on this cluster — every
                 legitimate user already has a Zitadel identity, and an
                 anonymous account is free to push unbounded LFS blobs into
                 the shared Commons storage/disk. DISABLE_REGISTRATION only
                 blocks the local /user/sign_up form; it does NOT block OAuth2
                 auto-registration, which stays on via ENABLE_AUTO_REGISTRATION
                 below so a teammate's first Zitadel SSO login still just
                 works without a manual account-creation step. --}}
            - name: FORGEJO__service__DISABLE_REGISTRATION
              value: "true"
            - name: FORGEJO__oauth2_client__ENABLE_AUTO_REGISTRATION
              value: "true"
            {{-- ACCOUNT_LINKING=auto: when an OAuth2 identity arrives whose
                 email already belongs to a local account, link to that account
                 silently instead of showing the "Sign In to Link" page that
                 demands its local password — which SSO-only users never had
                 (live 2026-08-24: a teammate's Zitadel user was recreated on
                 2026-08-03, changing their `sub`, and Forgejo could no longer
                 match either lookup — user.login_name nor external_login_user).
                 The auto-link branch only runs inside the ENABLE_AUTO_REGISTRATION
                 path above (gitea v1.22 routers/web/auth/auth.go
                 createUserInContext) — setting it alone does nothing. Safe here:
                 the email claim comes from our own Zitadel org, never anonymous
                 signups, and DISABLE_REGISTRATION keeps the local form closed. --}}
            - name: FORGEJO__oauth2_client__ACCOUNT_LINKING
              value: "auto"
            {{-- Default USERNAME=nickname derives from goth's NickName; same
                 shape as preferred_username for Zitadel but relies on goth
                 mapping rather than the raw claim. `preferred_username`
                 reads the OIDC userinfo directly and handles the edge case
                 where the value itself contains "@" (Forgejo splits at "@"
                 before normalizing for this type too — confirmed v16.0
                 auth.go:406). `email` is WRONG here: Forgejo's email path
                 (auth.go:411) truncates at "@" for the USERNAME value, so
                 admin@nexa-web.site → "admin" → reserved name → 500 on
                 first registration (live 2026-08-25). The `profile` scope
                 (requested below) guarantees preferred_username is present. --}}
            - name: FORGEJO__oauth2_client__USERNAME
              value: "preferred_username"
            - name: FORGEJO__server__ROOT_URL
              value: "https://{{ $host }}/"
            - name: FORGEJO__server__DOMAIN
              value: "{{ $host }}"
            - name: FORGEJO__server__SSH_DOMAIN
              value: "{{ $host }}"
            - name: FORGEJO__server__SSH_PORT
              value: "2222"
            - name: FORGEJO__server__SSH_LISTEN_PORT
              value: "22"
            - name: FORGEJO__server__LFS_START_SERVER
              value: "true"
            - name: FORGEJO__actions__ENABLED
              value: "true"
            - name: FORGEJO__actions__DEFAULT_ACTIONS_URL
              value: github
            {{-- All four come from the Secret rather than inline values: they are
                 long-lived credentials, and inline env puts them in plaintext in
                 the Deployment spec, readable by anyone who can `get deploy`
                 without any access to Secrets. --}}
            - name: FORGEJO__security__SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretsName }}
                  key: secret-key
            - name: FORGEJO__security__INTERNAL_TOKEN
              valueFrom:
                secretKeyRef:
                  name: {{ $secretsName }}
                  key: internal-token
            - name: FORGEJO__server__LFS_JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretsName }}
                  key: lfs-jwt-secret
            {{-- Separate from the LFS one. Left unset, Forgejo regenerates it on
                 every boot and signs out every OIDC session. --}}
            - name: FORGEJO__oauth2__JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretsName }}
                  key: oauth-jwt-secret
@if (! ($noPlex ?? false))
@if(($redisIndex ?? null) !== null)
            {{-- Commons Valkey. Without these Forgejo caches in memory and keeps
                 sessions as files on its PVC, so every restart signs users out
                 and the queue can never leave a single replica. --}}
            - name: FORGEJO__cache__ADAPTER
              value: "redis"
            - name: FORGEJO__cache__HOST
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}"
            - name: FORGEJO__session__PROVIDER
              value: "redis"
            - name: FORGEJO__session__PROVIDER_CONFIG
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}"
            - name: FORGEJO__queue__TYPE
              value: "redis"
            - name: FORGEJO__queue__CONN_STR
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}"
@endif
            {{-- "minio" is Forgejo's generic S3 driver; it speaks to SeaweedFS
                 just as well. Path-style + plain HTTP inside the cluster. --}}
            - name: FORGEJO__storage__STORAGE_TYPE
              value: minio
            - name: FORGEJO__storage__MINIO_ENDPOINT
              value: "{{ $s3Host }}"
            - name: FORGEJO__storage__MINIO_ACCESS_KEY_ID
              value: "{{ $s3AccessKey }}"
            - name: FORGEJO__storage__MINIO_SECRET_ACCESS_KEY
              value: "{{ $s3SecretKey }}"
            - name: FORGEJO__storage__MINIO_BUCKET
              value: {{ $buckets[0] }}
            - name: FORGEJO__storage__MINIO_USE_SSL
              value: "false"
            - name: FORGEJO__storage__MINIO_INSECURE_SKIP_VERIFY
              value: "true"

            - name: FORGEJO__packages__STORAGE_TYPE
              value: minio
            - name: FORGEJO__packages__MINIO_ENDPOINT
              value: "{{ $s3Host }}"
            - name: FORGEJO__packages__MINIO_ACCESS_KEY_ID
              value: "{{ $s3AccessKey }}"
            - name: FORGEJO__packages__MINIO_SECRET_ACCESS_KEY
              value: "{{ $s3SecretKey }}"
            - name: FORGEJO__packages__MINIO_BUCKET
              value: {{ $buckets[1] }}
            - name: FORGEJO__packages__MINIO_USE_SSL
              value: "false"
            - name: FORGEJO__packages__MINIO_INSECURE_SKIP_VERIFY
              value: "true"

            - name: FORGEJO__lfs__STORAGE_TYPE
              value: minio
            - name: FORGEJO__lfs__MINIO_ENDPOINT
              value: "{{ $s3Host }}"
            - name: FORGEJO__lfs__MINIO_ACCESS_KEY_ID
              value: "{{ $s3AccessKey }}"
            - name: FORGEJO__lfs__MINIO_SECRET_ACCESS_KEY
              value: "{{ $s3SecretKey }}"
            - name: FORGEJO__lfs__MINIO_BUCKET
              value: {{ $buckets[2] }}
            - name: FORGEJO__lfs__MINIO_USE_SSL
              value: "false"
            - name: FORGEJO__lfs__MINIO_INSECURE_SKIP_VERIFY
              value: "true"
@endif
          volumeMounts:
            - name: forgejo-data
              mountPath: /data
      volumes:
        - name: forgejo-data
          persistentVolumeClaim:
            claimName: forgejo-data
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $httpServiceName }}
  namespace: larakube-shared
spec:
  selector:
    app: {{ $deploymentName }}
  ports:
    - protocol: TCP
      port: 3000
      targetPort: 3000
  type: ClusterIP
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $sshServiceName }}
  namespace: larakube-shared
spec:
  selector:
    app: {{ $deploymentName }}
  ports:
    - protocol: TCP
      port: 2222
      targetPort: 22
  type: LoadBalancer
---
@if (($runnerSecret ?? 'pending') !== 'pending')
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ $runnerConfigMapName }}
  namespace: larakube-shared
data:
  config.yml: |
    runner:
      labels:
        - "ubuntu-latest:docker://node:22-bookworm"
        - "ubuntu-22.04:docker://node:22-bookworm"
        - "docker:docker://node:22-bookworm"
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $runnerDeploymentName }}
  namespace: larakube-shared
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $runnerDeploymentName }}
  template:
    metadata:
      labels:
        app: {{ $runnerDeploymentName }}
    spec:
      initContainers:
        {{-- Offline registration: turn the shared secret into /data/.runner
             before the daemon starts. Fails loudly (pod never starts) rather
             than silently running an unregistered runner. --}}
        - name: register
          image: code.forgejo.org/forgejo/runner:{{ $runnerVersion ?? '6.4.0' }}
          command: ["sh", "-c"]
          args:
            - |
              set -e
              if [ ! -f /data/.runner ]; then
                forgejo-runner create-runner-file \
                  --instance "$FORGEJO_INSTANCE_URL" \
                  --secret "$RUNNER_SECRET" \
                  --name larakube \
                  --connect
              fi
          env:
            - name: FORGEJO_INSTANCE_URL
              value: "http://{{ $httpServiceName }}:3000"
            - name: RUNNER_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretsName }}
                  key: runner-secret
          workingDir: /data
          volumeMounts:
            - name: runner-data
              mountPath: /data
      containers:
        {{-- Actions jobs run in containers, so the runner needs a container
             engine. k3s runs containerd and has NO /var/run/docker.sock — a
             hostPath mount would silently create an empty DIRECTORY and every
             job would fail. Rootless Podman sidecar (no dind, no privileged:
             true) speaking Podman's Docker-API-compatible socket.

             MUST run as the image's built-in `podman` user (uid 1000, gid
             1000), not root: quay.io/podman/stable ships subuid/subgid ranges
             for uid 1000 (`1:999`, `1001:64535`) specifically so that user can
             go through Podman's real rootless path — unshare into a fresh
             user+mount namespace via newuidmap/newgidmap (the setuid-root
             helpers SETUID/SETGID exist for) and become "root" only inside
             that namespace. Running the container as root skips that path
             entirely and tries a raw bind-mount as real root instead, which
             fails without CAP_SYS_ADMIN.

             On Ubuntu 24.04+ nodes, `kernel.apparmor_restrict_unprivileged_userns=1`
             (the node default here) ALSO blocks unshare(CLONE_NEWUSER) for
             any process not covered by an AppArmor profile that allows
             `userns,` — mediated separately from Linux capabilities, so
             adding SYS_ADMIN alone does not clear it (confirmed live: the
             identical "permission denied" bind-mount persisted with SYS_ADMIN
             granted). k3s/containerd's default profile has no such rule, so
             this container needs AppArmor unconfined — the capabilities above
             are what actually keep it scoped once that MAC layer is out of
             the way. /dev/fuse is for fuse-overlayfs, the storage driver
             rootless mode uses. --}}
        - name: podman
          image: quay.io/podman/stable:v5.8.2
          securityContext:
            privileged: false
            allowPrivilegeEscalation: true
            appArmorProfile:
              type: Unconfined
            runAsUser: 1000
            runAsGroup: 1000
            capabilities:
              add: ["SETUID", "SETGID", "SYS_ADMIN"]
          command: ["podman", "system", "service", "--time=0", "unix:///run/podman/podman.sock"]
          volumeMounts:
            - name: podman-sock
              mountPath: /run/podman
            - name: podman-data
              mountPath: /var/lib/containers
            - name: fuse
              mountPath: /dev/fuse
          resources:
            requests:
              memory: 256Mi
              cpu: 100m
        - name: runner
          image: code.forgejo.org/forgejo/runner:{{ $runnerVersion ?? '6.4.0' }}
          command: ["forgejo-runner", "daemon", "--config", "/config/config.yml"]
          env:
            - name: DOCKER_HOST
              value: unix:///run/podman/podman.sock
          workingDir: /data
          volumeMounts:
            - name: podman-sock
              mountPath: /run/podman
            - name: runner-data
              mountPath: /data
            - name: tmp
              mountPath: /tmp
            - name: runner-config
              mountPath: /config
          resources:
            requests:
              memory: 128Mi
              cpu: 50m
      volumes:
        {{-- emptyDir, not a PVC: job images land on the node's ephemeral disk and
             are re-pulled after a restart. Swap for a PVC if the cache churn or
             the disk usage becomes a problem. --}}
        - name: podman-sock
          emptyDir: {}
        - name: podman-data
          emptyDir: {}
        - name: fuse
          hostPath:
            path: /dev/fuse
            type: CharDevice
        - name: runner-data
          emptyDir: {}
        - name: tmp
          emptyDir: {}
        - name: runner-config
          configMap:
            name: {{ $runnerConfigMapName }}
---
@endif
@include('k8s.git.ingress')
