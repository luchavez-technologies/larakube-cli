@php
    $port = $port ?? 8200;
    $image = $image ?? 'openbao/openbao:2.6.1';
    $namespace = $namespace ?? 'larakube-secrets';
    // SecretsInitCommand always passes true now (see its own comment for
    // why manual-unseal-in-production was reconsidered). false here is just
    // a safe default for any other direct caller of this view.
    $autoUnseal = $autoUnseal ?? false;
@endphp
apiVersion: v1
kind: ServiceAccount
metadata:
  name: openbao
  namespace: {{ $namespace }}
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: openbao-auth-delegator
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: system:auth-delegator
subjects:
  - kind: ServiceAccount
    name: openbao
    namespace: {{ $namespace }}
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: openbao-data
  namespace: {{ $namespace }}
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 5Gi
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: openbao-config
  namespace: {{ $namespace }}
data:
  bao.hcl: |
    ui = true
    disable_mlock = true

    storage "file" {
      path = "/openbao/data"
    }

    listener "tcp" {
      address = "0.0.0.0:8200"
      tls_disable = 1
    }
---
apiVersion: v1
kind: Service
metadata:
  name: openbao-backend
  namespace: {{ $namespace }}
  labels:
    app: openbao-backend
spec:
  type: ClusterIP
  ports:
  - name: http
    port: 8200
    targetPort: 8200
  selector:
    app: openbao-backend
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: openbao-backend
  namespace: {{ $namespace }}
  labels:
    app: openbao-backend
spec:
  replicas: 1
  selector:
    matchLabels:
      app: openbao-backend
  template:
    metadata:
      labels:
        app: openbao-backend
    spec:
      serviceAccountName: openbao
      containers:
      - name: openbao
        image: "{{ $image }}"
        args:
        - server
        - -config=/openbao/config/bao.hcl
        ports:
        - name: http
          containerPort: 8200
        volumeMounts:
        - name: config
          mountPath: /openbao/config
        - name: data
          mountPath: /openbao/data
@if($autoUnseal)
        - name: bootstrap
          mountPath: /openbao/bootstrap
          readOnly: true
        lifecycle:
          postStart:
            exec:
              command:
              - sh
              - -c
              - >
                i=0;
                while [ $i -lt 30 ]; do
                  if [ -f /openbao/bootstrap/unseal-key ]; then
                    BAO_ADDR=http://127.0.0.1:8200 bao operator unseal "$(cat /openbao/bootstrap/unseal-key)" >/dev/null 2>&1 && exit 0;
                  fi;
                  sleep 1;
                  i=$((i+1));
                done;
                exit 0
@endif
        readinessProbe:
          httpGet:
            path: /v1/sys/health?uninitcode=200&sealedcode=200&standbycode=200
            port: 8200
          initialDelaySeconds: 2
          periodSeconds: 5
        livenessProbe:
          httpGet:
            path: /v1/sys/health?uninitcode=200&sealedcode=200&standbycode=200
            port: 8200
          initialDelaySeconds: 5
          periodSeconds: 10
      volumes:
      - name: config
        configMap:
          name: openbao-config
      - name: data
        persistentVolumeClaim:
          claimName: openbao-data
@if($autoUnseal)
      - name: bootstrap
        secret:
          secretName: openbao-bootstrap
          optional: true
          items:
          - key: unseal-key
            path: unseal-key
@endif
@if(!empty($host))
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: openbao-backend
  namespace: {{ $namespace }}
  annotations:
    traefik.ingress.kubernetes.io/router.entrypoints: web,websecure
    traefik.ingress.kubernetes.io/router.tls: "true"
    traefik.ingress.kubernetes.io/router.tls.certresolver: letsencrypt
spec:
  rules:
  - host: {{ $host }}
    http:
      paths:
      - path: /
        pathType: Prefix
        backend:
          service:
            name: openbao-backend
            port:
              number: 8200
@endif

