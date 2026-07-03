apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ $config->getServerVariation()->getPodName($config) }}
  annotations:
@if($view = $config->getIngress($environment)?->getAnnotationView())
{{-- We use a custom indent filter to ensure every line of the included view is aligned --}}
{!! preg_replace('/^/m', '    ', trim(view($view, ['config' => $config])->render())) !!}
@else
    traefik.ingress.kubernetes.io/router.tls: "true"
@if($config->getStrategy($environment) === \App\Enums\DeploymentStrategy::SINGLE_NODE && !($config->environments[$environment]?->offline ?? false))
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@endif
@endif
@if($config->environments[$environment]->certManagerIssuer)
    cert-manager.io/cluster-issuer: {{ $config->environments[$environment]->certManagerIssuer }}
@endif
@if($extraAnnotations = $config->getIngressAnnotations($environment))
{{-- Per-env passthrough (ACM cert ARN, security groups, ALB conditions/actions).
     JSON-encoded so free-form values stay valid YAML. Dumb merge: extends the
     controller defaults above. --}}
@foreach($extraAnnotations as $key => $value)
    {{ $key }}: {!! json_encode($value) !!}
@endforeach
@endif
spec:
@if($config->getIngress($environment)?->getIngressClass())
  ingressClassName: {{ $config->getIngress($environment)->getIngressClass() }}
@endif
  rules:
@foreach($config->getWebHosts($environment) as $host)
    - host: {{ $host }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ $config->getServerVariation()->getPodName($config) }}
                port:
                  number: 80
@endforeach
  tls:
    - hosts:
@foreach($config->getWebHosts($environment) as $host)
        - {{ $host }}
@endforeach
@if($config->getStrategy($environment) === \App\Enums\DeploymentStrategy::SINGLE_NODE && !($config->environments[$environment]?->offline ?? false))
      secretName: {{ $config->getName() }}-tls
@endif
