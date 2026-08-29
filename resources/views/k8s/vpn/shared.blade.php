@php($sfx = ($instance ?? '') !== '' ? '-'.$instance : '')
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: vpn-management-storage{{ $sfx }}
  namespace: larakube-vpn
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 2Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: vpn-management{{ $sfx }}
  namespace: larakube-vpn
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: vpn-management{{ $sfx }}
  template:
    metadata:
      labels:
        app: vpn-management{{ $sfx }}
    spec:
      containers:
        - name: management
          image: netbirdio/management:0.77.1
          {{-- The single-account domain is a FLAG, not an env var.

               netbird-mgmt reads none of the NETBIRD_MGMT_* names the upstream
               docker-compose uses — those are compose template variables that
               render this very command line. Setting the env var alone is
               decorative: the binary falls back to the flag's own default,
               "netbird.selfhosted". Confirmed live 2026-08-29, and it is silent
               and total — management logged "single account mode enabled" the
               whole time, and the account it created was stamped
               netbird.selfhosted while the Deployment env said luchtech.dev.

               That mismatch is the failure mode: a NEW SSO user's claim is
               rewritten to this domain, NetBird looks for an account whose
               private domain matches, finds none, and mints them a second
               account with its own /16. Which is exactly the fragmentation
               single-account mode exists to prevent.

               args replaces the image CMD, so the CMD's own "--log-file console"
               has to be restated. The "management" subcommand must NOT be: it is
               part of the image ENTRYPOINT, and repeating it lands a stray
               positional arg on the command line that cobra happens to ignore
               today (seen live as "management management") and may not tomorrow. --}}
          args:
            - --log-file
            - console
            - --single-account-mode-domain
            - "{{ $ssoDomain }}"
          env:
            - name: NB_SETUP_PAT_ENABLED
              value: "true"
@unless ($noPlex ?? false)
            {{-- Both are required. With the engine unset NetBird silently falls
                 back to SQLite on the PVC, which looks identical until the node
                 dies and the whole control plane goes with it. The DSN is
                 key=value (GORM), not a URL, and $(DB_PASSWORD) is expanded by
                 kubelet from the env var below — so the password never appears
                 as a literal in the Deployment (ADR 0018). --}}
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: vpn-management-store{{ $sfx }}
                  key: db-password
            - name: NETBIRD_STORE_ENGINE
              value: "postgres"
            - name: NB_STORE_ENGINE_POSTGRES_DSN
              value: "host=postgres.{{ $plexNamespace }}.svc.cluster.local user={{ $storeDb }} password=$(DB_PASSWORD) dbname={{ $storeDb }} port=5432"
@endunless
          ports:
            - containerPort: 80
              name: http
          volumeMounts:
            - name: storage
              mountPath: /var/lib/netbird
            - name: config
              mountPath: /etc/netbird/management.json
              subPath: management.json
          readinessProbe:
            tcpSocket:
              port: 80
            initialDelaySeconds: 10
            periodSeconds: 5
          livenessProbe:
            tcpSocket:
              port: 80
            initialDelaySeconds: 15
            periodSeconds: 10
      volumes:
        - name: storage
          persistentVolumeClaim:
            claimName: vpn-management-storage{{ $sfx }}
        - name: config
          secret:
            secretName: vpn-management-config{{ $sfx }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: vpn-dashboard{{ $sfx }}
  namespace: larakube-vpn
spec:
  replicas: 1
  selector:
    matchLabels:
      app: vpn-dashboard{{ $sfx }}
  template:
    metadata:
      labels:
        app: vpn-dashboard{{ $sfx }}
    spec:
      containers:
        - name: dashboard
          {{-- Versioned independently of NetBird itself, and ships no
               `latest` tag — only semver plus sha-/pr- builds. --}}
          image: netbirdio/dashboard:v2.91.1
          env:
            - name: NETBIRD_MGMT_API_ENDPOINT
              value: "https://{{ $host }}"
            - name: NETBIRD_MGMT_GRPC_API_ENDPOINT
              value: "https://{{ $host }}"
            - name: USE_AUTH0
              value: "false"
            {{-- Upstream default. Dex's access token carries the identity
                 claims management needs; the idToken override was only ever
                 needed while the dashboard pointed straight at Zitadel. --}}
            - name: NETBIRD_TOKEN_SOURCE
              value: "accessToken"
            {{-- /nb-auth and /nb-silent-auth are DEDICATED callback routes,
                 taken verbatim from the dashboard.env a supported 0.77 install
                 writes. They are not arbitrary: pointing AUTH_REDIRECT_URI at a
                 real app route (e.g. /peers) makes the OIDC callback handler
                 and the app's own router fight over the same URL, and omitting
                 the silent one leaves token renew with nowhere to land — both
                 present as an endless loading spinner with no error. --}}
            - name: AUTH_REDIRECT_URI
              value: "/nb-auth"
            - name: AUTH_SILENT_REDIRECT_URI
              value: "/nb-silent-auth"
            {{-- The dashboard authenticates against the EMBEDDED IdP, not
                 against Zitadel directly. Dex federates to whatever
                 /api/identity-providers has registered, so Zitadel shows up as
                 a login button rather than as this client. `netbird-dashboard`
                 is a static public client Dex registers itself
                 (StaticClientDashboard in management/server/idp/embedded.go) —
                 it is not something sso:wire creates, and it takes no secret. --}}
            - name: AUTH_AUTHORITY
              value: "https://{{ $host }}/oauth2"
            - name: AUTH_CLIENT_ID
              value: "netbird-dashboard"
            - name: AUTH_AUDIENCE
              value: "netbird-dashboard"
            {{-- `groups` (not offline_access) — matches upstream's dashboard.env. --}}
            - name: AUTH_SUPPORTED_SCOPES
              value: "openid profile email groups"
          ports:
            - containerPort: 80
              name: http
          readinessProbe:
            httpGet:
              path: /
              port: 80
            initialDelaySeconds: 5
            periodSeconds: 5
          resources:
            requests:
              memory: 48Mi
              cpu: 20m
            limits:
              memory: 192Mi
---
apiVersion: v1
kind: Service
metadata:
  name: vpn-dashboard{{ $sfx }}
  namespace: larakube-vpn
spec:
  selector:
    app: vpn-dashboard{{ $sfx }}
  ports:
    - protocol: TCP
      port: 80
      targetPort: 80
  type: ClusterIP
---
apiVersion: v1
kind: Service
metadata:
  name: vpn-management{{ $sfx }}
  namespace: larakube-vpn
  annotations:
    traefik.ingress.kubernetes.io/service.serversscheme: h2c
spec:
  selector:
    app: vpn-management{{ $sfx }}
  ports:
    - protocol: TCP
      port: 80
      targetPort: 80
  type: ClusterIP
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: vpn-signal{{ $sfx }}
  namespace: larakube-vpn
spec:
  replicas: 1
  selector:
    matchLabels:
      app: vpn-signal{{ $sfx }}
  template:
    metadata:
      labels:
        app: vpn-signal{{ $sfx }}
    spec:
      containers:
        - name: signal
          image: netbirdio/signal:0.77.1
          ports:
            - containerPort: 80
              name: grpc
          readinessProbe:
            tcpSocket:
              port: 80
            initialDelaySeconds: 5
            periodSeconds: 5
---
apiVersion: v1
kind: Service
metadata:
  name: vpn-signal{{ $sfx }}
  namespace: larakube-vpn
  annotations:
    traefik.ingress.kubernetes.io/service.serversscheme: h2c
spec:
  selector:
    app: vpn-signal{{ $sfx }}
  ports:
    - protocol: TCP
      port: 80
      targetPort: 80
  type: ClusterIP
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: vpn-relay{{ $sfx }}
  namespace: larakube-vpn
spec:
  replicas: 1
  selector:
    matchLabels:
      app: vpn-relay{{ $sfx }}
  template:
    metadata:
      labels:
        app: vpn-relay{{ $sfx }}
    spec:
      containers:
        - name: relay
          image: netbirdio/relay:0.77.1
          env:
            - name: NB_LOG_LEVEL
              value: "info"
            - name: NB_LISTEN_ADDRESS
              value: ":33080"
            - name: NB_EXPOSED_ADDRESS
              value: "rels://{{ $host }}:443/relay"
            - name: NB_AUTH_SECRET
              valueFrom:
                secretKeyRef:
                  name: vpn-management-config{{ $sfx }}
                  key: relay-secret
          ports:
            - containerPort: 33080
              name: http
          readinessProbe:
            tcpSocket:
              port: 33080
            initialDelaySeconds: 5
            periodSeconds: 5
---
apiVersion: v1
kind: Service
metadata:
  name: vpn-relay{{ $sfx }}
  namespace: larakube-vpn
spec:
  selector:
    app: vpn-relay{{ $sfx }}
  ports:
    - protocol: TCP
      port: 33080
      targetPort: 33080
  type: ClusterIP
---
@include('k8s.vpn.ingress')
