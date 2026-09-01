apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization

namespace: {{ $namespace }}

{{-- No `../../base`: a static site's local workload (the framework's own dev
     server) and its cloud workload (Caddy over an S3 bundle) share nothing, so
     a common base would exist only to be patched away. --}}
resources:
  - dev-server.yaml
