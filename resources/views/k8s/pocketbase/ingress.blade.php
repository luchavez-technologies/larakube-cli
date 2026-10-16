@php
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::POCKETBASE->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) $host));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::POCKETBASE, $instance, 'pocketbase');
    $deployName = $deployName ?? $serviceName ?? $names->deployment();
    $namespace = $namespace ?? \App\Enums\ClusterTool::POCKETBASE->namespace();
    $labels = $labels ?? $names->labels();
@endphp
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $deployName }}-ingress
  labels:
@foreach($labels ?? [] as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
  namespace: {{ $namespace }}
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
@if(!$isLocal)
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@endif
@if($vpnOnly)
    traefik.ingress.kubernetes.io/router.middlewares: larakube-shared-vpn-only@kubernetescrd
@endif
spec:
  rules:
    - host: {{ $host }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $deployName }}
                port:
                  number: 8090
@foreach($aliasHosts ?? [] as $aliasHost)
    - host: {{ $aliasHost }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $deployName }}
                port:
                  number: 8090
@endforeach
  tls:
    - hosts:
        - {{ $host }}
@foreach($aliasHosts ?? [] as $aliasHost)
        - {{ $aliasHost }}
@endforeach
