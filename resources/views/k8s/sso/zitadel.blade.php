@if($noPlex)
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: sso-zitadel-db-storage
  namespace: larakube-sso
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 5Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: sso-zitadel-db
  namespace: larakube-sso
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: sso-zitadel-db
  template:
    metadata:
      labels:
        app: sso-zitadel-db
    spec:
      containers:
        - name: postgres
          image: postgres:15-alpine
          env:
            - name: POSTGRES_USER
              value: zitadel
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: sso-secrets
                  key: db-password
            - name: POSTGRES_DB
              value: zitadel
            - name: PGDATA
              value: /var/lib/postgresql/data/pgdata
          volumeMounts:
            - name: storage
              mountPath: /var/lib/postgresql/data
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: sso-zitadel-db-storage
---
apiVersion: v1
kind: Service
metadata:
  name: sso-zitadel-db
  namespace: larakube-sso
spec:
  selector:
    app: sso-zitadel-db
  ports:
    - protocol: TCP
      port: 5432
      targetPort: 5432
---
@endif
apiVersion: apps/v1
kind: Deployment
metadata:
  name: sso-zitadel
  namespace: larakube-sso
  labels:
    app: sso-zitadel
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: sso-zitadel
  template:
    metadata:
      labels:
        app: sso-zitadel
    spec:
      # The DB provisioning step (CREATE DATABASE/ROLE/GRANT) is done externally
      # by Plex Commons — the shared Postgres only ever hands Zitadel a
      # non-superuser, DB-owner role, so `start-from-init` (which RUNS that
      # provisioning step) dies with "permission denied to create database".
      # Instead: an initContainer bootstraps ONLY the schema as the owner
      # (`init schema`), then the main container runs setup + start
      # (`start-from-setup`). The image is distroless (no shell), so this is an
      # initContainer, not a chained `sh -c`. Both steps are idempotent on
      # restarts. The env list is shared via the &zitadelEnv YAML anchor.
      # fsGroup makes the shared /machinekey emptyDir group-writable so the
      # (non-root) Zitadel container can drop the PAT there for the sidecar.
      securityContext:
        fsGroup: 1000
      initContainers:
        - name: zitadel-init
          # PINNED, not :latest — and it MUST stay identical to the zitadel
          # container image below: the init container runs the schema setup the
          # server then expects, so a floating tag can migrate the database to a
          # version the running server doesn't match.
          image: ghcr.io/zitadel/zitadel:v4.16.1
          command: ["/app/zitadel", "init", "schema"]
          env: &zitadelEnv
            - name: ZITADEL_MASTERKEY
              valueFrom:
                secretKeyRef:
                  name: sso-secrets
                  key: masterkey
            - name: ZITADEL_EXTERNALDOMAIN
              value: "{{ $host }}"
            - name: ZITADEL_EXTERNALPORT
              value: "443"
            - name: ZITADEL_EXTERNALSECURE
              value: "true"
            # Zitadel v4 marks "Login V2" required on new instances and stops
            # serving login itself — it redirects to /ui/v2/login, a SEPARATE
            # Next.js container we don't run, so the console login 404s. Keep the
            # legacy Login V1 that's still bundled in this container (served at
            # /ui/login). DEFAULTINSTANCE = applied at instance-creation only, so
            # an already-created instance needs `sso:init --remove` + re-init.
            - name: ZITADEL_DEFAULTINSTANCE_FEATURES_LOGINV2_REQUIRED
              value: "false"
            - name: ZITADEL_DATABASE_POSTGRES_HOST
@if($noPlex)
              value: sso-zitadel-db
@else
              value: "postgres.{{ $plexNamespace }}.svc.cluster.local"
@endif
            - name: ZITADEL_DATABASE_POSTGRES_PORT
              value: "5432"
            - name: ZITADEL_DATABASE_POSTGRES_DATABASE
              value: zitadel
            - name: ZITADEL_DATABASE_POSTGRES_USER_USERNAME
              value: zitadel
            - name: ZITADEL_DATABASE_POSTGRES_USER_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: sso-secrets
                  key: db-password
            - name: ZITADEL_DATABASE_POSTGRES_USER_SSL_MODE
              value: disable
            # Admin DB credential. Unused by start-from-setup (which never runs
            # the provisioning step), but kept — set to the SAME tenant-owner
            # role, never the Commons' real superuser — so config validation is
            # satisfied and a future full-init path wouldn't reach for a
            # superuser that would break other tenants' isolation.
            - name: ZITADEL_DATABASE_POSTGRES_ADMIN_USERNAME
              value: zitadel
            - name: ZITADEL_DATABASE_POSTGRES_ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: sso-secrets
                  key: db-password
            - name: ZITADEL_DATABASE_POSTGRES_ADMIN_SSL_MODE
              value: disable
            # First-boot-only bootstrap: creates the default org + a human
            # admin with a LaraKube-generated (known, printable) password —
            # same posture as Stalwart's STALWART_RECOVERY_ADMIN. No dashboard
            # click required for basic console access. Ignored on restarts of
            # an already-initialized instance.
            - name: ZITADEL_FIRSTINSTANCE_ORG_HUMAN_USERNAME
              value: admin
            - name: ZITADEL_FIRSTINSTANCE_ORG_HUMAN_EMAIL_ADDRESS
              value: "{{ $adminEmail }}"
            - name: ZITADEL_FIRSTINSTANCE_ORG_HUMAN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: sso-secrets
                  key: admin-password
            # A machine (service-account) user with IAM_OWNER, for the CLI's own
            # API automation (mail:create --sso, sso:wire) — distinct from the
            # human admin above (a console login). The user/name/expiry ARE
            # nested under ORG_MACHINE, but the PAT OUTPUT PATH is a
            # FirstInstance-level key: `ZITADEL_FIRSTINSTANCE_PATPATH`, NOT
            # `..._ORG_MACHINE_PATPATH` (the original bug — Zitadel silently
            # wrote nothing). It lands on a shared emptyDir the `pat-reader`
            # sidecar can `cat`, because the Zitadel image is distroless (no
            # shell/cat of its own). SsoInitCommand reads it once after rollout
            # and caches it in sso-secrets; a miss is non-fatal.
            - name: ZITADEL_FIRSTINSTANCE_ORG_MACHINE_MACHINE_USERNAME
              value: larakube-automation
            - name: ZITADEL_FIRSTINSTANCE_ORG_MACHINE_MACHINE_NAME
              value: "LaraKube Automation"
            - name: ZITADEL_FIRSTINSTANCE_ORG_MACHINE_PAT_EXPIRATIONDATE
              value: "2099-01-01T00:00:00Z"
            - name: ZITADEL_FIRSTINSTANCE_PATPATH
              value: /machinekey/pat.txt
      containers:
        - name: zitadel
          # Keep in lockstep with the zitadel-init image above.
          image: ghcr.io/zitadel/zitadel:v4.16.1
          # Provisioning + schema are already done (Commons + the initContainer),
          # so start from SETUP (setup + start) — never start-from-init, which
          # would re-run the superuser-only provisioning step. The FIRSTINSTANCE
          # bootstrap (human admin + machine PAT) runs here in the setup phase,
          # writing the PAT to /tmp in THIS container for SsoInitCommand to read.
          command: ["/app/zitadel", "start-from-setup", "--masterkeyFromEnv", "--tlsMode", "external"]
          env: *zitadelEnv
          volumeMounts:
            - name: machinekey
              mountPath: /machinekey
          ports:
            - containerPort: 8080
              name: http
          readinessProbe:
            httpGet:
              path: /debug/ready
              port: 8080
            initialDelaySeconds: 20
            periodSeconds: 10
          livenessProbe:
            httpGet:
              path: /debug/healthz
              port: 8080
            initialDelaySeconds: 60
            periodSeconds: 15
        # Tiny sidecar with a shell, purely so the CLI can read the PAT the
        # distroless Zitadel container drops on the shared emptyDir:
        # `kubectl exec -c pat-reader -- cat /machinekey/pat.txt`. Idle otherwise.
        - name: pat-reader
          image: busybox:1.36
          command: ["sh", "-c", "sleep infinity"]
          volumeMounts:
            - name: machinekey
              mountPath: /machinekey
              readOnly: true
      volumes:
        - name: machinekey
          emptyDir: {}
---
apiVersion: v1
kind: Service
metadata:
  name: sso-zitadel
  namespace: larakube-sso
spec:
  selector:
    app: sso-zitadel
  ports:
    - protocol: TCP
      port: 8080
      targetPort: 8080
  type: ClusterIP
---
@include('k8s.sso.ingress')
