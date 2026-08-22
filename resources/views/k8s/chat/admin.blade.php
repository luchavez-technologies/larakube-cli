{{--
    Element Admin — official, AGPL-3.0 open-source web console for managing
    chat's users and rooms. A static SPA: no service credentials of its own
    to manage — the operator logs into it exactly like any other Matrix
    client (MAS-native OIDC, discovered via SERVER_NAME's .well-known), and
    it exercises Synapse's/MAS's own Admin APIs using THAT session, scoped
    by whatever admin privileges the logged-in account already holds
    (Synapse's `admin: true` flag, grantable via `chat:user --admin`).

    Rendered and applied ONLY once MAS is active (deployMas()'s caller in
    ChatInitCommand gates this) — Element Admin explicitly requires MAS's
    own Admin API per its docs, so deploying it before MAS exists would just
    be a UI with nothing it can authenticate against.

    Pre-1.0 project (v0.x tags) — pin bumps deserve a quick changelog check,
    not blind trust, more than the other pinned images here.
--}}
@php($__tplHash = substr(hash_file('sha256', resource_path('views/k8s/chat/admin.blade.php')), 0, 12))
apiVersion: apps/v1
kind: Deployment
metadata:
  name: chat-admin
  namespace: larakube-shared
  labels:
    app: chat-admin
    app.kubernetes.io/part-of: chat
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: chat-admin
  template:
    metadata:
      labels:
        app: chat-admin
      annotations:
        larakube.io/config-checksum: "{{ substr(hash('sha256', $host.$__tplHash), 0, 16) }}"
    spec:
      containers:
        - name: admin
          image: oci.element.io/element-admin:v0.1.12
          env:
            - name: SERVER_NAME
              value: "{{ $host }}"
          resources:
            requests:
              memory: 32Mi
              cpu: 10m
            limits:
              memory: 128Mi
              cpu: 200m
          ports:
            - containerPort: 8080
---
apiVersion: v1
kind: Service
metadata:
  name: chat-admin
  namespace: larakube-shared
spec:
  selector:
    app: chat-admin
  ports:
    - protocol: TCP
      port: 8080
      targetPort: 8080
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: chat-admin-ingress
  namespace: larakube-shared
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
@unless($isLocal ?? false)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@if($proxied ?? false)
    external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"
@endif
@endunless
    {{-- Admin console — VPN-only unconditionally, regardless of chat's own
         --vpn-only setting, since this is a materially higher-privilege
         surface than the chat UI itself. A Traefik router referencing a
         Middleware that doesn't exist 500s EVERY request through it, not a
         harmless no-op — so the caller (ChatInitCommand's admin deploy
         step) MUST call ensureVpnMiddleware(CHAT, ...) unconditionally
         before applying this manifest, even on installs that never passed
         --vpn-only for chat's own ingress. --}}
    traefik.ingress.kubernetes.io/router.middlewares: "larakube-shared-chat-vpn-only@kubernetescrd"
spec:
  rules:
    - host: admin.{{ $host }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: chat-admin
                port:
                  number: 8080
  tls:
    - hosts:
        - admin.{{ $host }}
