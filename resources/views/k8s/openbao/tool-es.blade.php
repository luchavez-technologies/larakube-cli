apiVersion: external-secrets.io/v1
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
{{-- keyMap when the KV name and the Secret key cannot be the same string: a
     Deployment reading `pat` from its Secret cannot have `production/pat` as
     its KV name, because that collides with every other tool in the store.
     The plain list stays the form every database password uses. --}}
@foreach(($keyMap ?? null) ?: array_combine($keys, $keys) as $kvKey => $secretKey)
    - secretKey: {{ $secretKey }}
      remoteRef:
        key: {{ $environment ?? 'production' }}/{{ $kvKey }}
        property: value
@endforeach