apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: web
  annotations:
    cert-manager.io/cluster-issuer: null
spec:
  tls:
    - hosts:
@foreach($config->getWebHosts('local') as $host)
        - {{ $host }}
@endforeach
