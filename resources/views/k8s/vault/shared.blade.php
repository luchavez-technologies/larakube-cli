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
          image: vaultwarden/server:1.30.0
          ports:
            - containerPort: 80
              name: http
          env:
            - name: ADMIN_TOKEN
              valueFrom:
                secretKeyRef:
                  name: vault-admin
                  key: admin-token
@if(isset($databaseUrl) && $databaseUrl)
            - name: DATABASE_URL
              value: "{{ $databaseUrl }}"
@endif
          envFrom:
            - secretRef:
                name: vaultwarden-infisical
                optional: true
          volumeMounts:
            - name: vaultwarden-volume
              mountPath: /data
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
