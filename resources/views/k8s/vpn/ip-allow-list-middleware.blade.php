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
