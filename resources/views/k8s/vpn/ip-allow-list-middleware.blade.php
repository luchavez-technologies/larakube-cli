apiVersion: traefik.io/v1alpha1
kind: Middleware
metadata:
  name: {{ $name }}
  namespace: {{ $namespace }}
spec:
  ipAllowList:
    sourceRange:
      # NetBird's default peer overlay CIDR — confirmed no override in
      # k8s/vpn/management-config.blade.php, so this is what LaraKube's own
      # NetBird deployment actually assigns to connected peers.
      - 100.64.0.0/10
      # In-cluster pod/service network CIDRs (so in-cluster netbird-client gateway
      # proxy traffic arriving at Traefik is allowed).
      - 10.42.0.0/16
      - 10.43.0.0/16
      - 10.0.0.0/8
      - 127.0.0.1/32
