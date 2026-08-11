apiVersion: generators.external-secrets.io/v1alpha1
kind: VaultDynamicSecret
metadata:
  name: {{ $secretName }}-db
  namespace: {{ $namespace }}
spec:
  path: "database/static-creds/{{ $roleName }}"
  method: GET
  provider:
    server: "http://openbao-backend.{{ $secretsNamespace ?? 'larakube-secrets' }}.svc.cluster.local:8200"
    auth:
      kubernetes:
        mountPath: kubernetes
        role: eso-controller
---
apiVersion: external-secrets.io/v1beta1
kind: ExternalSecret
metadata:
  name: {{ $secretName }}-db
  namespace: {{ $namespace }}
spec:
  refreshInterval: 5m
  target:
    name: {{ $secretName }}
    creationPolicy: Merge
    template:
      engineVersion: v2
      data:
@if(isset($usernameKey))
        {{ $usernameKey }}: "@{{ .username }}"
@endif
@if(isset($passwordTemplate))
        {{ $passwordKey }}: "{{ $passwordTemplate }}"
@else
        {{ $passwordKey ?? 'DB_PASSWORD' }}: "@{{ .password }}"
@endif
  dataFrom:
  - sourceRef:
      generatorRef:
        apiVersion: generators.external-secrets.io/v1alpha1
        kind: VaultDynamicSecret
        name: {{ $secretName }}-db
