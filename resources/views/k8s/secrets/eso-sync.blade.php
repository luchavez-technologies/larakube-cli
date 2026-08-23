@php
    $provider = $provider ?? 'openbao';
    $prefix = ($prefix ?? '') !== '' ? $prefix.'/' : '';
@endphp
apiVersion: v1
kind: Secret
metadata:
  name: {{ $authName }}
  namespace: {{ $namespace }}
type: Opaque
data:
  token: {{ $token }}
---
apiVersion: external-secrets.io/v1
kind: SecretStore
metadata:
  name: {{ $authName }}
  namespace: {{ $namespace }}
spec:
  provider:
    vault:
      server: {{ $hostAPI ?? 'http://openbao-backend.larakube-secrets.svc.cluster.local:8200' }}
      path: secret
      version: v2
      auth:
        tokenSecretRef:
          name: {{ $authName }}
          key: token
---
apiVersion: external-secrets.io/v1
kind: ExternalSecret
metadata:
  name: {{ $secretName }}
  namespace: {{ $namespace }}
spec:
  refreshInterval: 1m
  secretStoreRef:
    name: {{ $authName }}
    kind: SecretStore
  target:
    name: {{ $secretName }}
    creationPolicy: Merge
    template:
      engineVersion: v2
      metadata:
        annotations:
          reloader.stakater.com/auto: "true"
  data:
@foreach($keys as $key)
    - secretKey: {{ $key }}
      remoteRef:
        key: {{ ($environmentSlug ?? 'production').'/'.$prefix.$key }}
        property: value
@endforeach
