apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization

namespace: {{ $namespace }}

{{-- The CLOUD workload on the local cluster: same standalone image and probes
     as production, only the tag ({name}:preview, built by `preview:up`) and the
     resource names (web-preview) differ. Applied alongside the dev-server
     overlay, so both run at once. --}}
resources:
  - deployment.yaml
  - service.yaml
  - ingress.yaml
  - secret.yaml
@if($selfHosted ?? true)
  - redis.yaml
  - database.yaml
@endif

images:
  - name: {{ $config->getName() }}:latest
    newName: {{ $config->getName() }}
    newTag: preview
