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
        key: {{ $environment ?? 'production' }}/{{ $key }}
        property: value
@endforeach