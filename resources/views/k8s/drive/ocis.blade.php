@php($ocisImage = 'owncloud/ocis:8.0.6')
@php($cspYaml = view('k8s.drive.csp', ['office' => $office ?? false, 'officeHost' => $officeHost ?? ''])->render())
{{-- Indent here rather than in the partial: rendering drops the leading
     whitespace of its first line, which silently empties the block scalar. --}}
@php($cspYaml = implode("\n", array_map(fn ($l) => $l === '' ? '' : '    '.$l, explode("\n", rtrim($cspYaml)))))
apiVersion: apps/v1
kind: Deployment
metadata:
  name: drive-ocis
  namespace: larakube-shared
  labels:
    app: drive-ocis
spec:
  replicas: 1
  selector:
    matchLabels:
      app: drive-ocis
  template:
    metadata:
      labels:
        app: drive-ocis
      annotations:
        # csp.yaml is mounted by subPath, which NEVER sees a ConfigMap update —
        # and even a plain mount would need a restart. Without this checksum the
        # CSP change applies silently and the running pod keeps the old policy.
        larakube.io/csp-checksum: "{{ substr(md5($cspYaml), 0, 10) }}"
    spec:
      containers:
        - name: ocis
          image: {{ $ocisImage }}
          # Headless boot: everything is configured via OCIS_* env vars, so we skip
          # the interactive `ocis init` wizard and start the full server directly.
          command: ["ocis"]
          args: ["server"]
          env:
            - name: OCIS_URL
              value: "https://{{ $host }}"
            - name: OCIS_INSECURE
              value: "true"
            - name: PROXY_HTTP_ADDR
              value: "0.0.0.0:80"
            - name: PROXY_TLS
              value: "false"
            # Basic auth is disabled by default in oCIS 8 (PROXY_ENABLE_BASIC_AUTH
            # defaults to false, confirmed live 2026-07-31: every WebDAV/graph call
            # with username/password returned a silent 401 while the proxy's config
            # showed EnableBasicAuth=false). Enabled here so WebDAV clients (rclone,
            # Finder, davfs) and curl can authenticate directly; app auth adds
            # scoped app passwords generated in the web UI for third-party clients.
            - name: PROXY_ENABLE_BASIC_AUTH
              value: "true"
            - name: PROXY_ENABLE_APP_AUTH
              value: "true"
            # oCIS web is a browser SPA: it auto-discovers the external IdP by
            # fetching {issuer}/.well-known/openid-configuration and exchanges
            # tokens against {issuer}/oauth/v2/token — both cross-origin. The
            # proxy's default CSP connect-src ('self' + the app-store CDN) blocks
            # those fetches and every SSO login dies with "trouble connecting to
            # the login service" (CSP Network Error, confirmed live 2026-07-31).
            # Mount a csp.yaml (see the drive-ocis-csp ConfigMap below) that adds
            # the WIRED OIDC issuer origin to connect-src — mirrors the official
            # Keycloak external-IdP example, which sets
            # PROXY_CSP_CONFIG_FILE_LOCATION and never touches
            # WEB_OIDC_METADATA_URL. Whichever IdP sso:wire points OCIS_OIDC_ISSUER
            # at (LaraKube's Zitadel OR a customer's own Okta/Entra/etc.) is the
            # origin that gets allowed — see the ${OCIS_OIDC_ISSUER} interpolation
            # in the ConfigMap below.
            - name: PROXY_CSP_CONFIG_FILE_LOCATION
              value: "/etc/ocis/csp.yaml"
            - name: OCIS_LOG_LEVEL
              value: "info"
            - name: OCIS_SYSTEM_USER_ID
              value: "9ee82935-400f-4516-a676-e372b724a0d9"
            - name: OCIS_SERVICE_ACCOUNT_ID
              value: "4c510ada-c86b-4815-8820-42cdf27c3d51"
            # Without a fixed OCIS_ADMIN_USER_ID the IDM bootstrap skips creating
            # the uid=admin entry entirely (see idm server.go: only when
            # AdminUserID != "" is the admin appended to the service users), so
            # every login attempt fails with "Logon failed" — no password is
            # ever accepted. Stable UUID keeps the admin's opaque ID constant
            # across re-inits.
            - name: OCIS_ADMIN_USER_ID
              value: "e4f2a7c9-6d3b-4c1a-9f8e-2b5d7a1c3f90"
            - name: OCIS_STORAGE_USERS_MOUNT_ID
              value: "1284d23e-aa92-43ca-9e40-ad9a0e81fa6e"
            - name: OCIS_STORAGE_PUBLIC_LINK_MOUNT_ID
              value: "79b16132-c880-4540-9c62-d2780e0719e7"
            - name: OCIS_STORAGE_SHARES_MOUNT_ID
              value: "a0ca5353-70c9-46f1-ae76-27157e10885c"
            - name: GATEWAY_STORAGE_USERS_MOUNT_ID
              value: "1284d23e-aa92-43ca-9e40-ad9a0e81fa6e"
            - name: GATEWAY_STORAGE_PUBLIC_LINK_MOUNT_ID
              value: "79b16132-c880-4540-9c62-d2780e0719e7"
            - name: GATEWAY_STORAGE_SHARES_MOUNT_ID
              value: "a0ca5353-70c9-46f1-ae76-27157e10885c"
            - name: STORAGE_USERS_STORAGE_USERS_MOUNT_ID
              value: "1284d23e-aa92-43ca-9e40-ad9a0e81fa6e"
            - name: STORAGE_USERS_STORAGE_PUBLIC_LINK_MOUNT_ID
              value: "79b16132-c880-4540-9c62-d2780e0719e7"
            - name: STORAGE_USERS_STORAGE_SHARES_MOUNT_ID
              value: "a0ca5353-70c9-46f1-ae76-27157e10885c"
            - name: STORAGE_USERS_MOUNT_ID
              value: "1284d23e-aa92-43ca-9e40-ad9a0e81fa6e"
            - name: GRAPH_APPLICATION_ID
              value: "05857315-8a28-4be0-8637-e07d0f9a9415"
            - name: OCIS_ADMIN_USER
              value: "admin"
            - name: OCIS_ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: OCIS_LDAP_BIND_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: IDM_ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: IDM_SVC_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: IDM_REVASVC_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: IDM_IDPSVC_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: IDM_BIND_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: GROUPS_LDAP_BIND_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: USERS_LDAP_BIND_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: AUTH_BASIC_LDAP_BIND_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: GRAPH_LDAP_BIND_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: IDM_LDAP_BIND_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: IDP_LDAP_BIND_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: IDP_BIND_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: admin-password
            - name: OCIS_JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: jwt-secret
            - name: OCIS_TRANSFER_SECRET
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: transfer-secret
            - name: OCIS_MACHINE_AUTH_API_KEY
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: machine-auth-api-key
            - name: OCIS_SYSTEM_USER_API_KEY
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: system-user-api-key
            - name: OCIS_SERVICE_ACCOUNT_SECRET
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: service-account-secret
            - name: OCIS_REKEY_KEY
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: rekey-key
@if (! $noPlex && $s3Creds)
            # User file blobs live on Plex SeaweedFS via the S3NG driver. The old
            # S3 vars (the default-storage toggle plus the STORAGE_SYSTEM_S3
            # endpoint/bucket/keys) never switched the driver — STORAGE_SYSTEM_DRIVER
            # stays `ocis`, so all data (metadata AND blobs) silently landed on the
            # ephemeral pod disk and every restart wiped it (confirmed live
            # 2026-07-31: the drive-ocis SeaweedFS bucket stayed empty while the pod
            # wrote to /var/lib/ocis/storage/metadata). Metadata itself is kept on
            # the drive-ocis-storage PVC below — official oCIS guidance is to keep
            # system/space metadata on POSIX and only push blobs to S3.
            - name: STORAGE_USERS_DRIVER
              value: "s3ng"
            - name: STORAGE_USERS_S3NG_ENDPOINT
              value: "http://seaweedfs.{{ $plexNamespace }}.svc.cluster.local:8333"
            - name: STORAGE_USERS_S3NG_REGION
              value: "us-east-1"
            - name: STORAGE_USERS_S3NG_BUCKET
              value: "drive-ocis"
            - name: STORAGE_USERS_S3NG_ACCESS_KEY
              value: "{{ $s3Creds['access'] }}"
            - name: STORAGE_USERS_S3NG_SECRET_KEY
              value: "{{ $s3Creds['secret'] }}"
@else
            - name: OCIS_DEFAULT_STORAGE_SYSTEM
              value: "posix"
            - name: STORAGE_SYSTEM_POSIX_ROOT
              value: "/var/lib/ocis/data"
@endif
          ports:
            - containerPort: 80
          volumeMounts:
            - name: drive-ocis-data
              mountPath: /var/lib/ocis
            - name: drive-ocis-csp
              mountPath: /etc/ocis/csp.yaml
              subPath: csp.yaml
              readOnly: true
@if ($office ?? false)
        # The WOPI bridge is a SIDECAR, not its own Deployment: every oCIS
        # service binds 127.0.0.1 (registry 9233, gateway 9142) and the micro
        # registry hands back loopback addresses, so only a process sharing this
        # pod's network namespace can reach them.
        - name: collaboration
          image: {{ $ocisImage }}
          command: ["ocis"]
          args: ["collaboration", "server"]
          env:
            - name: OCIS_URL
              value: "https://{{ $host }}"
            - name: OCIS_INSECURE
              value: "true"
            - name: COLLABORATION_HTTP_ADDR
              value: "0.0.0.0:9300"
            - name: COLLABORATION_GRPC_ADDR
              value: "127.0.0.1:9301"
            - name: MICRO_REGISTRY
              value: "nats-js-kv"
            - name: MICRO_REGISTRY_ADDRESS
              value: "127.0.0.1:9233"
            # What CODE calls back on to read and write the file. Cluster-internal
            # by design — the browser never follows it.
            - name: COLLABORATION_WOPI_SRC
              value: "http://drive-collaboration.larakube-shared.svc.cluster.local:9300"
            - name: COLLABORATION_WOPI_SECRET
              valueFrom:
                secretKeyRef:
                  name: drive-office-secrets
                  key: wopi-secret
            - name: COLLABORATION_APP_NAME
              value: "CollaboraOnline"
            - name: COLLABORATION_APP_PRODUCT
              value: "Collabora"
            - name: COLLABORATION_APP_ADDR
              value: "https://{{ $officeHost }}"
            - name: COLLABORATION_APP_ICON
              value: "https://{{ $officeHost }}/favicon.ico"
            - name: COLLABORATION_APP_INSECURE
              value: "true"
            # Proof keys are a Microsoft Office Online mechanism; Collabora never
            # sends WopiProof/WopiStamp headers, so the bridge rejected every
            # CheckFileInfo with "ProofKeys verification failed: Invalid
            # timestamp" and CODE showed an empty editor. The WOPI access token
            # signed with COLLABORATION_WOPI_SECRET is the real authentication.
            - name: COLLABORATION_APP_PROOF_DISABLE
              value: "true"
            - name: COLLABORATION_CS3API_DATAGATEWAY_INSECURE
              value: "true"
            - name: OCIS_JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: jwt-secret
            - name: OCIS_TRANSFER_SECRET
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: transfer-secret
            - name: OCIS_MACHINE_AUTH_API_KEY
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: machine-auth-api-key
            - name: OCIS_SERVICE_ACCOUNT_ID
              value: "4c510ada-c86b-4815-8820-42cdf27c3d51"
            - name: OCIS_SERVICE_ACCOUNT_SECRET
              valueFrom:
                secretKeyRef:
                  name: drive-secrets
                  key: service-account-secret
          ports:
            - containerPort: 9300
@endif
      volumes:
        - name: drive-ocis-data
          persistentVolumeClaim:
            claimName: drive-ocis-storage
        - name: drive-ocis-csp
          configMap:
            name: drive-ocis-csp
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: drive-ocis-csp
  namespace: larakube-shared
  labels:
    app: drive-ocis
data:
  # Replicates the proxy's built-in default CSP exactly (verified against the
  # live 8.0.6 header: child-src 'self'; connect-src 'self' blob: awesome-ocis;
  # default-src 'none'; font-src 'self'; frame-ancestors 'self'; frame-src
  # 'self' blob: embed.diagrams.net; img-src 'self' data: blob: awesome-ocis;
  # manifest-src 'self'; media-src 'self'; object-src 'self' blob:; script-src
  # 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline') — plus the wired
  # OIDC issuer origin in connect-src. That origin is NOT hardcoded: oCIS
  # env-expands config files (the official Keycloak example interpolates
  # ${KEYCLOAK_DOMAIN} the same way), so ${OCIS_OIDC_ISSUER} follows whichever
  # IdP sso:wire is pointed at — LaraKube's Zitadel or a customer's own
  # provider — with NO per-provider wiring. When no IdP is wired the variable is
  # empty and the browser ignores that (invalid) source, leaving exactly the
  # built-in default. Only that one directive changes; the "frame-ancestors
  # 'none'" hardening in the official example is deliberately not adopted here
  # to keep the diff to exactly what the SSO login requires.
  csp.yaml: |
{!! $cspYaml !!}
---
apiVersion: v1
kind: Service
metadata:
  name: drive-ocis
  namespace: larakube-shared
spec:
  selector:
    app: drive-ocis
  ports:
    - port: 80
      targetPort: 80
@if ($office ?? false)
---
apiVersion: v1
kind: Service
metadata:
  name: drive-collaboration
  namespace: larakube-shared
spec:
  selector:
    app: drive-ocis
  ports:
    - port: 9300
      targetPort: 9300
@endif
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: drive-ocis-storage
  namespace: larakube-shared
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {{ $volumeSize('drive-ocis-storage', '10Gi', true) }}
---
@include('k8s.drive.ingress', ['engine' => 'ocis'])
