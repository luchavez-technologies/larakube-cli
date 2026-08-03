apiVersion: v1
kind: Secret
metadata:
  name: eso-openbao-token
  namespace: {{ $namespace }}
type: Opaque
data:
  token: {{ $token }}
---
apiVersion: external-secrets.io/v1beta1
kind: ClusterSecretStore
metadata:
  name: openbao
spec:
  provider:
    vault:
      server: {{ $hostAPI }}
      path: secret
      version: v2
      auth:
        tokenSecretRef:
          name: eso-openbao-token
          key: token
          namespace: {{ $namespace }}
