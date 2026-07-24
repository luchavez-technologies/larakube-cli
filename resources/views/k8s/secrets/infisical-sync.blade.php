{{--
  The CRD triple that makes an Infisical secret available to workloads in one
  namespace: a Secret holding the machine-identity credentials, an InfisicalAuth
  pointing at the in-cluster backend, and an InfisicalStaticSecret that mirrors
  the LaraKube project into a native Secret the pods can consume via envFrom.

  Previously inlined as string concatenation in MailInitCommand; a template
  keeps the YAML readable and lets every caller share it.
--}}
apiVersion: v1
kind: Secret
metadata:
  name: {{ $authName }}
  namespace: {{ $namespace }}
type: Opaque
data:
  client-id: {{ $clientId }}
  client-secret: {{ $clientSecret }}
---
apiVersion: secrets.infisical.com/v1beta1
kind: InfisicalConnection
metadata:
  name: {{ $authName }}-conn
  namespace: {{ $namespace }}
spec:
  address: {{ $hostAPI ?? 'http://infisical-backend.larakube-secrets.svc.cluster.local:8080' }}
---
apiVersion: secrets.infisical.com/v1beta1
kind: InfisicalAuth
metadata:
  name: {{ $authName }}
  namespace: {{ $namespace }}
spec:
  infisicalConnectionRef:
    name: {{ $authName }}-conn
    namespace: {{ $namespace }}
  method: universal
  universal:
    clientIdRef:
      name: {{ $authName }}
      namespace: {{ $namespace }}
      key: client-id
    clientSecretRef:
      name: {{ $authName }}
      namespace: {{ $namespace }}
      key: client-secret
---
apiVersion: secrets.infisical.com/v1beta1
kind: InfisicalStaticSecret
metadata:
  name: {{ $secretName }}
  namespace: {{ $namespace }}
spec:
  infisicalAuthRef:
    name: {{ $authName }}
    namespace: {{ $namespace }}
  sources:
    - projectSlug: larakube
      environmentSlug: {{ $environmentSlug ?? 'production' }}
      secretPath: /
      recursive: false
  syncOptions:
    instantUpdates: true
    refreshInterval: 60s
  targets:
    - kind: Secret
      name: {{ $secretName }}
      namespace: {{ $namespace }}
      creationPolicy: Owner
