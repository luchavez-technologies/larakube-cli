apiVersion: v1
kind: Namespace
metadata:
  name: larakube-system
  labels:
    {{ \App\Support\Labels::MANAGED_BY }}: larakube
---
apiVersion: v1
kind: ServiceAccount
metadata:
  name: larakube-dashboard
  namespace: larakube-system
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: larakube-dashboard-role
rules:
  - apiGroups: [""]
    resources: ["namespaces", "pods", "pods/log", "pods/exec", "services", "nodes", "events"]
    verbs: ["get", "list", "watch", "create", "delete"]
  - apiGroups: ["apps"]
    resources: ["deployments", "statefulsets", "replicasets"]
    verbs: ["get", "list", "watch", "patch"]
  - apiGroups: ["networking.k8s.io"]
    resources: ["ingresses"]
    verbs: ["get", "list", "watch"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: larakube-dashboard-binding
subjects:
  - kind: ServiceAccount
    name: larakube-dashboard
    namespace: larakube-system
roleRef:
  kind: ClusterRole
  name: larakube-dashboard-role
  apiGroup: rbac.authorization.k8s.io
---
apiVersion: v1
kind: Service
metadata:
  name: larakube-dashboard
  namespace: larakube-system
spec:
  selector:
    app: larakube-dashboard
  ports:
    - protocol: TCP
      port: 80
      targetPort: 8080
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: larakube-dashboard
  namespace: larakube-system
spec:
  replicas: 1
  selector:
    matchLabels:
      app: larakube-dashboard
  template:
    metadata:
      labels:
        app: larakube-dashboard
    spec:
      serviceAccountName: larakube-dashboard
      containers:
        - name: dashboard
          # Primary: luchaveztech/larakube-dashboard:latest (Docker Hub)
          # Fallback: ghcr.io/larakube/larakube-dashboard:latest
          image: luchaveztech/larakube-dashboard:latest
          imagePullPolicy: Always
          ports:
            - containerPort: 8080
          env:
            - name: APP_URL
              value: https://{{ $host }}
            - name: ASSET_URL
              value: https://{{ $host }}
            - name: APP_ENV
              value: production
            - name: APP_DEBUG
              value: "true"
            - name: APP_KEY
              value: base64:{{ base64_encode(random_bytes(32)) }}
            - name: KUBERNETES_HOST
              value: https://kubernetes.default.svc
            - name: DB_CONNECTION
              value: sqlite
            - name: DB_DATABASE
              value: /var/lib/larakube/database.sqlite
            - name: LARAKUBE_HOST_WORKSPACE
              value: {{ $workspacePath }}
          livenessProbe:
            httpGet:
              path: /up
              port: 8080
            initialDelaySeconds: 30
            periodSeconds: 30
          readinessProbe:
            httpGet:
              path: /up
              port: 8080
            initialDelaySeconds: 10
            periodSeconds: 10
          volumeMounts:
            - name: larakube-db
              mountPath: /var/lib/larakube
            - name: larakube-config
              mountPath: /var/lib/larakube-config
            - name: larakube-workspace
              mountPath: /var/lib/larakube-workspace
              readOnly: true
      volumes:
        - name: larakube-db
          hostPath:
            path: {{ $_SERVER['HOME'] }}/.larakube/console-data
            type: DirectoryOrCreate
        - name: larakube-config
          hostPath:
            path: {{ $_SERVER['HOME'] }}/.larakube
            type: DirectoryOrCreate
        - name: larakube-workspace
          hostPath:
            path: {{ $workspacePath }}
            type: Directory
---
{{-- Single source of truth for the console.{tld} Ingress. Also rendered
     standalone by ensureConsoleIngress() so a `config:tld` change re-points the
     host on the next `up` instead of leaving it stale (console.kube → 404). --}}
@include('k8s.console-ingress')
