{{--
  One ExternalDNS instance per tool:init --tool=external-dns GROUP — one or more zones on ONE
  provider that share a single credential (docs/decisions — see tool:init --tool=external-dns's own
  docblock for why credential-sharing, not zone count, is the actual isolation
  boundary).

  Every name is suffixed with the group slug so several groups — including
  zones on DIFFERENT providers or accounts, each with its own credential —
  coexist on one cluster. Two things carry the safety properties:

    --domain-filter   one flag PER zone in this group. Without at least one,
                      ExternalDNS manages every zone the token can see, and with
                      --policy=sync it DELETES records it doesn't recognise.
                      Multiple --domain-filter flags in one process is native
                      ExternalDNS behavior, not a LaraKube extension.

    --txt-owner-id    the ownership registry. It must be unique per (cluster,
                      group) — inherently ONE owner ID per process, shared by
                      every zone that process's --domain-filter covers.
                      LaraKube previously hardcoded `larakube`, so two clusters
                      pointed at one zone each treated the other's records as
                      their own orphans and deleted them — records flapping
                      between two clusters forever.

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
    larakube.io/dns-domain: {{ implode(',', $zones) }}
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
            - --provider={{ $provider->externalDnsProviderFlag() }}
            - --policy=sync
            - --registry=txt
@foreach($zones as $zone)
            - --domain-filter={{ $zone }}
@endforeach
            - --txt-owner-id={{ $ownerId }}
            {{-- Watch Ingress events instead of only polling. Without it
                 ExternalDNS reconciles on its default 60s interval, so a
                 freshly created Ingress waits up to a minute before its record
                 exists — and anything gated on DNS (Traefik's ACME challenge,
                 and every wait built on top of it) waits with it. --}}
            - --events
          env:
@if($provider === \App\Enums\DnsProvider::CLOUDFLARE)
            - name: CF_API_TOKEN
              valueFrom:
                secretKeyRef:
                  name: cloudflare-token-{{ $slug }}
                  key: token
@else
            - name: AWS_ACCESS_KEY_ID
              valueFrom:
                secretKeyRef:
                  name: route53-credential-{{ $slug }}
                  key: access_key_id
            - name: AWS_SECRET_ACCESS_KEY
              valueFrom:
                secretKeyRef:
                  name: route53-credential-{{ $slug }}
                  key: secret_access_key
            - name: AWS_DEFAULT_REGION
              valueFrom:
                secretKeyRef:
                  name: route53-credential-{{ $slug }}
                  key: region
@endif
