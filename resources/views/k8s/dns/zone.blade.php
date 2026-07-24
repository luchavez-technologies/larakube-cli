{{--
  One ExternalDNS instance per Cloudflare zone.

  Every name is suffixed with the zone slug so several zones — including zones
  in DIFFERENT Cloudflare accounts, each with its own API token — coexist on one
  cluster. Two args carry the safety properties:

    --domain-filter   confines this instance to a single zone. Without it,
                      ExternalDNS manages every zone the token can see, and with
                      --policy=sync it DELETES records it doesn't recognise.

    --txt-owner-id    the ownership registry. It must be unique per (cluster,
                      zone). LaraKube previously hardcoded `larakube`, so two
                      clusters pointed at one zone each treated the other's
                      records as their own orphans and deleted them — records
                      flapping between two clusters forever.

  The ClusterRole is cluster-scoped and read-only, so all instances share one;
  each instance gets its own ServiceAccount and binding.
--}}
apiVersion: v1
kind: ServiceAccount
metadata:
  name: external-dns-{{ $slug }}
  namespace: {{ $namespace }}
  labels:
    app.kubernetes.io/name: external-dns
    larakube.io/dns-zone: {{ $slug }}
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: external-dns
rules:
  - apiGroups: [""]
    resources: ["services","endpoints","pods"]
    verbs: ["get","watch","list"]
  - apiGroups: ["extensions","networking.k8s.io"]
    resources: ["ingresses"]
    verbs: ["get","watch","list"]
  - apiGroups: [""]
    resources: ["nodes"]
    verbs: ["list","watch"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: external-dns-{{ $slug }}
  labels:
    larakube.io/dns-zone: {{ $slug }}
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: external-dns
subjects:
  - kind: ServiceAccount
    name: external-dns-{{ $slug }}
    namespace: {{ $namespace }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: external-dns-{{ $slug }}
  namespace: {{ $namespace }}
  labels:
    app.kubernetes.io/name: external-dns
    larakube.io/dns-zone: {{ $slug }}
  annotations:
    larakube.io/dns-domain: {{ $zone }}
    larakube.io/dns-owner-id: {{ $ownerId }}
spec:
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: external-dns-{{ $slug }}
  template:
    metadata:
      labels:
        app: external-dns-{{ $slug }}
        larakube.io/dns-zone: {{ $slug }}
    spec:
      serviceAccountName: external-dns-{{ $slug }}
      containers:
        - name: external-dns
          image: registry.k8s.io/external-dns/external-dns:v0.21.0
          args:
            - --source=ingress
            - --provider=cloudflare
            - --policy=sync
            - --registry=txt
            - --domain-filter={{ $zone }}
            - --txt-owner-id={{ $ownerId }}
          env:
            - name: CF_API_TOKEN
              valueFrom:
                secretKeyRef:
                  name: cloudflare-token-{{ $slug }}
                  key: token
