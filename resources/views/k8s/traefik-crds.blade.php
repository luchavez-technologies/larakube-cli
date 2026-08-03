{{-- Traefik v3 CustomResourceDefinitions.
     LaraKube ships its own Traefik, and these were never installed — so
     `--providers.kubernetescrd` had nothing to watch and every Middleware apply
     failed with "the server doesn't have a resource type middleware". That
     silently broke BOTH `--vpn-only` (ip-allow-list Middleware) and ForwardAuth
     SSO (docs/decisions/0006).

     Schemas are intentionally permissive (x-kubernetes-preserve-unknown-fields):
     Traefik validates the spec itself, and the full upstream schema is thousands
     of lines that would have to be re-vendored on every Traefik bump. All ten
     types are declared — not just Middleware — because the kubernetescrd
     provider starts an informer for each and logs errors for any that are
     missing. --}}
@foreach ([
    ['ingressroutes', 'IngressRoute'],
    ['ingressroutetcps', 'IngressRouteTCP'],
    ['ingressrouteudps', 'IngressRouteUDP'],
    ['middlewares', 'Middleware'],
    ['middlewaretcps', 'MiddlewareTCP'],
    ['serverstransports', 'ServersTransport'],
    ['serverstransporttcps', 'ServersTransportTCP'],
    ['tlsoptions', 'TLSOption'],
    ['tlsstores', 'TLSStore'],
    ['traefikservices', 'TraefikService'],
] as [$plural, $kind])
apiVersion: apiextensions.k8s.io/v1
kind: CustomResourceDefinition
metadata:
  name: {{ $plural }}.traefik.io
spec:
  group: traefik.io
  scope: Namespaced
  names:
    kind: {{ $kind }}
    listKind: {{ $kind }}List
    plural: {{ $plural }}
    singular: {{ Str::lower($kind) }}
  versions:
    - name: v1alpha1
      served: true
      storage: true
      schema:
        openAPIV3Schema:
          type: object
          x-kubernetes-preserve-unknown-fields: true
---
@endforeach
