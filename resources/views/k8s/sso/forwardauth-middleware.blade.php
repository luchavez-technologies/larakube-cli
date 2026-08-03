{{-- Traefik ForwardAuth middleware pointing at the SHARED sso-proxy.
     Created in the TOOL's namespace and addressing the proxy by FQDN, so
     Traefik's allowCrossNamespace is not required.
     See docs/decisions/0006-centralized-forwardauth-sso.md --}}
apiVersion: traefik.io/v1alpha1
kind: Middleware
metadata:
  name: sso-forwardauth
  namespace: {{ $namespace }}
spec:
  forwardAuth:
    # Root path (not /oauth2/auth): unauthenticated requests get a 302 to
    # Zitadel, which Traefik relays to the browser. Authenticated ones hit the
    # static://202 upstream and are allowed through.
    address: "http://sso-proxy.{{ $proxyNamespace }}.svc.cluster.local:4180/"
    trustForwardHeader: true
    authResponseHeaders:
      - X-Auth-Request-User
      - X-Auth-Request-Email
      - X-Auth-Request-Preferred-Username
