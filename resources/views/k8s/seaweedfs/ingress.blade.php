apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: seaweedfs
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
spec:
  rules:
    - host: s3.{{ $config->getName() }}.{{ $config->getLocalTld() }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: seaweedfs
                port:
                  number: 8333
    - host: s3-admin.{{ $config->getName() }}.{{ $config->getLocalTld() }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: seaweedfs
                port:
                  number: 8888
  tls:
    - hosts:
        - s3.{{ $config->getName() }}.{{ $config->getLocalTld() }}
        - s3-admin.{{ $config->getName() }}.{{ $config->getLocalTld() }}
