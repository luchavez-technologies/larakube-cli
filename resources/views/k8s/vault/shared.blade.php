apiVersion: v1
kind: Secret
metadata:
  name: vault-secrets
  namespace: larakube-vault
type: Opaque
data:
  plain-token: {{ base64_encode($adminToken) }}
  admin-token: {{ base64_encode($hashedAdminToken ?? $adminToken) }}
@if(isset($databaseUrl) && $databaseUrl)
  VAULTWARDEN_DATABASE_URL: {{ base64_encode($databaseUrl) }}
@endif
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
          image: vaultwarden/server:1.37.1
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
                  name: vault-secrets
                  key: admin-token
@if(isset($databaseUrl) && $databaseUrl)
            - name: DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: vault-secrets
                  key: VAULTWARDEN_DATABASE_URL
@endif
            {{-- No `envFrom: secretRef` here — a synced Secret mirroring every
                 key stored in the cluster backend would inject every OTHER
                 tool's credentials into this container for no benefit. --}}
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
