apiVersion: v1
kind: Secret
metadata:
  name: vault-admin
  namespace: larakube-vault
type: Opaque
data:
  plain-token: {{ base64_encode($adminToken) }}
  admin-token: {{ base64_encode($hashedAdminToken ?? $adminToken) }}
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: vaultwarden-storage
  namespace: larakube-vault
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 2Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: vaultwarden
  namespace: larakube-vault
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: vaultwarden
  template:
    metadata:
      labels:
        app: vaultwarden
    spec:
      containers:
        - name: vaultwarden
          image: vaultwarden/server:1.37.0
          ports:
            - containerPort: 80
              name: http
          env:
            # Vaultwarden derives its SSO callback (…/identity/connect/oidc-signin)
            # and other absolute URLs from DOMAIN — SSO fails without it.
            - name: DOMAIN
              value: "https://{{ $host }}"
            - name: ADMIN_TOKEN
              valueFrom:
                secretKeyRef:
                  name: vault-admin
                  key: admin-token
@if(isset($databaseUrl) && $databaseUrl)
            - name: DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: vaultwarden-secrets
                  key: VAULTWARDEN_DATABASE_URL
@endif
            {{-- No `envFrom: secretRef` here. The synced Secret mirrors every key
                 stored in the cluster backend, so it injected every OTHER tool's credentials
                 into this container — and injected VAULTWARDEN_ADMIN_TOKEN /
                 VAULTWARDEN_DB_PASSWORD, which Vaultwarden does not read (it
                 wants ADMIN_TOKEN and DATABASE_URL), so it was pure blast radius
                 for no benefit. --}}
          volumeMounts:
            - name: vaultwarden-volume
              mountPath: /data
          startupProbe:
            httpGet:
              path: /
              port: 80
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 30
          readinessProbe:
            httpGet:
              path: /
              port: 80
            initialDelaySeconds: 5
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /
              port: 80
            initialDelaySeconds: 10
            periodSeconds: 10
      volumes:
        - name: vaultwarden-volume
          persistentVolumeClaim:
            claimName: vaultwarden-storage
---
apiVersion: v1
kind: Service
metadata:
  name: vaultwarden
  namespace: larakube-vault
spec:
  selector:
    app: vaultwarden
  ports:
    - protocol: TCP
      port: 80
      targetPort: 80
  type: ClusterIP
---
@include('k8s.vault.ingress')
