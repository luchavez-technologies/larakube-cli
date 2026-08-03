{{-- Shared OAuth2-Proxy: ONE instance gates every ForwardAuth tool.
     See docs/decisions/0006-centralized-forwardauth-sso.md --}}
apiVersion: v1
kind: Secret
metadata:
  name: sso-proxy
  namespace: {{ $namespace }}
type: Opaque
stringData:
  OAUTH2_PROXY_CLIENT_ID: "{{ $clientId }}"
  OAUTH2_PROXY_CLIENT_SECRET: "{{ $clientSecret }}"
  OAUTH2_PROXY_COOKIE_SECRET: "{{ $cookieSecret }}"
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: sso-proxy
  namespace: {{ $namespace }}
  labels:
    app: sso-proxy
spec:
  replicas: 1
  selector:
    matchLabels:
      app: sso-proxy
  template:
    metadata:
      labels:
        app: sso-proxy
      annotations:
        # Rotating a Secret does NOT roll a Deployment — the pod template is
        # unchanged, so a healthy pod keeps the old credentials forever. Folding
        # a checksum into the template makes any credential change roll the pod.
        larakube.io/secret-checksum: "{{ $secretChecksum }}"
    spec:
      containers:
        - name: oauth2-proxy
          image: quay.io/oauth2-proxy/oauth2-proxy:{{ $image ?? 'v7.15.3' }}
          # Credentials come from the Secret as env (OAUTH2_PROXY_*), never as
          # args — args are readable by anyone who can `get pod`.
          envFrom:
            - secretRef:
                name: sso-proxy
          args:
            - --provider=oidc
            - --oidc-issuer-url=https://{{ $ssoHost }}
            # Single, permanent callback on the shared auth host: adding a gated
            # tool never needs another Zitadel redirect URI.
            - --redirect-url=https://{{ $authHost }}/oauth2/callback
            # Auth-only: the proxy is NOT in the data path. Traefik forwards just
            # the auth subrequest here and 202 means "allowed".
            - --upstream=static://202
            - --http-address=0.0.0.0:4180
            # Required behind Traefik: trust X-Forwarded-* so the post-login
            # redirect returns to the original tool URL.
            - --reverse-proxy=true
            # --reverse-proxy alone trusts X-Forwarded-* from ANY source
            # (0.0.0.0/0), and /oauth2 is publicly reachable on the auth host —
            # so restrict it to in-cluster (private) pod CIDRs. Covers k3s
            # (10.42/16), DOKS (10.244/16) and the usual EKS/AKS ranges.
            - --trusted-proxy-ip=10.0.0.0/8
            - --trusted-proxy-ip=172.16.0.0/12
            - --trusted-proxy-ip=192.168.0.0/16
            # Zitadel advertises S256; without this the proxy silently skips PKCE.
            - --code-challenge-method=S256
            - --set-xauthrequest=true
            - --skip-provider-button=true
            - "--email-domain=*"
            - --scope=openid profile email
            - --cookie-secure=true
            - --cookie-samesite=lax
            - --silence-ping-logging=true
@if($cookieDomain)
            # Shares one session across every gated subdomain, and permits the
            # post-login redirect back to them.
            - --cookie-domain={{ $cookieDomain }}
            - --whitelist-domain={{ $cookieDomain }}
@endif
          ports:
            - containerPort: 4180
              name: http
          startupProbe:
            httpGet:
              path: /ping
              port: 4180
            periodSeconds: 5
            failureThreshold: 30
          readinessProbe:
            httpGet:
              path: /ping
              port: 4180
            periodSeconds: 10
          livenessProbe:
            httpGet:
              path: /ping
              port: 4180
            periodSeconds: 15
          resources:
            requests:
              memory: 32Mi
              cpu: 10m
            limits:
              memory: 128Mi
              cpu: 200m
---
apiVersion: v1
kind: Service
metadata:
  name: sso-proxy
  namespace: {{ $namespace }}
spec:
  selector:
    app: sso-proxy
  ports:
    - port: 4180
      targetPort: 4180
      name: http
  type: ClusterIP
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: sso-proxy
  namespace: {{ $namespace }}
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
@unless($isLocal ?? false)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
    @if($proxied ?? false)
    external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"
    @endif
@endunless
spec:
  rules:
    - host: {{ $authHost }}
      http:
        paths:
          - path: /oauth2
            pathType: Prefix
            backend:
              service:
                name: sso-proxy
                port:
                  number: 4180
  tls:
    - hosts:
        - {{ $authHost }}
