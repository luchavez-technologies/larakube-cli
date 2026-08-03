apiVersion: external-secrets.io/v1beta1
kind: ExternalSecret
metadata:
  name: {{ $secretName }}
  namespace: {{ $namespace }}
spec:
  refreshInterval: 1m
  secretStoreRef:
    name: openbao
    kind: ClusterSecretStore
  target:
    name: {{ $secretName }}
    creationPolicy: Owner
  data:
@foreach($keys as $key)
    - secretKey: {{ $key }}
      remoteRef:
        key: {{ $environment ?? 'production' }}/{{ $key }}
        property: value
@endforeach