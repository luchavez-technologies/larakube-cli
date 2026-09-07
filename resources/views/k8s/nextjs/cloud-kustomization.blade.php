apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization

namespace: {{ $namespace }}

resources:
  - namespace.yaml
  - deployment.yaml
  - service.yaml
  - ingress.yaml
  - secret.yaml
@if($selfHosted ?? true)
  - redis.yaml
  - database.yaml
@endif

{{-- The deploy rewrites {name}:latest to the freshly built {env}-latest sha, so
     the manifest carries that exact string for the substitution to match. --}}
images:
  - name: {{ $config->getName() }}:latest
    newName: {{ $config->getName() }}
    newTag: {{ $environment }}-latest
