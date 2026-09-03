apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization

namespace: {{ $namespace }}

resources:
  - namespace.yaml
  - caddy.yaml

{{-- The deploy rewrites {name}:{env}-latest to the freshly built sha, so the
     manifest has to carry that exact string for the substitution to match. --}}
images:
  - name: {{ $config->getName() }}:latest
    newName: {{ $config->getName() }}
    newTag: {{ $environment }}-latest
