apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization

namespace: {{ $namespace }}

{{-- The cloud workload on the local cluster: the same image and probes as
     production, tagged {name}:preview and named web-preview so it runs next to
     the dev server. --}}
resources:
  - deployment.yaml
  - service.yaml
  - ingress.yaml
  - secret.yaml

images:
  - name: {{ $config->getName() }}:latest
    newName: {{ $config->getName() }}
    newTag: preview
