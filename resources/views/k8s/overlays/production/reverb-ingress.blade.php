{{--
    Public websocket route for a cloud environment.

    The browser connects to Reverb DIRECTLY (Echo reads VITE_REVERB_HOST), so the
    in-cluster Service is not enough — without this Ingress the configured reverb
    host resolves to nothing and every socket attempt fails. Local gets the
    equivalent from LaravelFeature's `local` manifest bucket (k8s.reverb.ingress);
    cloud is rendered per-env because the host lives in that env's config.

    Controller/TLS handling deliberately mirrors ingress-patch.blade.php so a
    project that overrides its ingress class or annotations gets the same
    treatment on both routes. The TLS secret is per-service: reverb is a
    different hostname from web, so it needs its own certificate rather than
    fighting over {name}-tls.
--}}
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: reverb
  annotations:
@if($view = $config->getIngress($environment)?->getAnnotationView())
{!! preg_replace('/^/m', '    ', trim(view($view, ['config' => $config])->render())) !!}
@else
{{-- Unlike the web route, this Ingress is a standalone resource rather than a
     patch over base/laravel.yaml, so it inherits nothing — it has to pin the
     entrypoint itself. Without it Traefik binds the router to every default
     entrypoint (:80 included) while router.tls demands TLS there. --}}
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
@if($config->getStrategy($environment) === \App\Enums\DeploymentStrategy::SINGLE_NODE && !($config->environments[$environment]?->offline ?? false))
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
@endif
@endif
@if($config->environments[$environment]->certManagerIssuer)
    cert-manager.io/cluster-issuer: {{ $config->environments[$environment]->certManagerIssuer }}
@endif
@if($extraAnnotations = $config->getIngressAnnotations($environment))
@foreach($extraAnnotations as $key => $value)
    {{ $key }}: {!! json_encode($value) !!}
@endforeach
@endif
spec:
@if($config->getIngress($environment)?->getIngressClass())
  ingressClassName: {{ $config->getIngress($environment)->getIngressClass() }}
@endif
  rules:
    - host: {{ $config->getHost($environment, 'reverb') }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: reverb
                port:
                  number: 8080
  tls:
    - hosts:
        - {{ $config->getHost($environment, 'reverb') }}
@if($config->getStrategy($environment) === \App\Enums\DeploymentStrategy::SINGLE_NODE && !($config->environments[$environment]?->offline ?? false))
      secretName: {{ $config->getName() }}-reverb-tls
@endif
