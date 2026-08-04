---
# ── Prometheus RBAC ──────────────────────────────────────────────────────────
apiVersion: v1
kind: ServiceAccount
metadata:
  name: prometheus
  namespace: larakube-shared
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: larakube-prometheus
rules:
  - apiGroups: [""]
    resources: [nodes, nodes/proxy, services, endpoints, pods, namespaces]
    verbs: [get, list, watch]
  - nonResourceURLs: [/metrics]
    verbs: [get]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: larakube-prometheus
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: larakube-prometheus
subjects:
  - kind: ServiceAccount
    name: prometheus
    namespace: larakube-shared
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: prometheus-config
  namespace: larakube-shared
data:
  prometheus.yml: |
    global:
      scrape_interval: 15s
      evaluation_interval: 15s
    scrape_configs:
      - job_name: 'kube-state-metrics'
        static_configs:
          - targets: ['kube-state-metrics.larakube-shared.svc.cluster.local:8080']
      - job_name: 'kubernetes-pods'
        kubernetes_sd_configs:
          - role: pod
        relabel_configs:
          - source_labels: [__meta_kubernetes_pod_annotation_prometheus_io_scrape]
            action: keep
            regex: "true"
          - source_labels: [__meta_kubernetes_pod_annotation_prometheus_io_path]
            action: replace
            target_label: __metrics_path__
            regex: (.+)
          - source_labels: [__address__, __meta_kubernetes_pod_annotation_prometheus_io_port]
            action: replace
            regex: ([^:]+)(?::\d+)?;(\d+)
            replacement: $1:$2
            target_label: __address__
          - action: labelmap
            regex: __meta_kubernetes_pod_label_(.+)
          - source_labels: [__meta_kubernetes_namespace]
            action: replace
            target_label: kubernetes_namespace
      - job_name: 'kubernetes-cadvisor'
        scheme: https
        tls_config:
          insecure_skip_verify: true
        bearer_token_file: /var/run/secrets/kubernetes.io/serviceaccount/token
        kubernetes_sd_configs:
          - role: node
        relabel_configs:
          - action: labelmap
            regex: __meta_kubernetes_node_label_(.+)
          - target_label: __address__
            replacement: kubernetes.default.svc:443
          - source_labels: [__meta_kubernetes_node_name]
            regex: (.+)
            target_label: __metrics_path__
            replacement: /api/v1/nodes/$1/proxy/metrics/cadvisor
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: prometheus-storage
  namespace: larakube-shared
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 2Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: prometheus
  namespace: larakube-shared
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: prometheus
  template:
    metadata:
      labels:
        app: prometheus
    spec:
      serviceAccountName: prometheus
      containers:
        - name: prometheus
          image: prom/prometheus:v2.51.2
          args:
            - "--config.file=/etc/prometheus/prometheus.yml"
            - "--storage.tsdb.path=/prometheus/"
            - "--storage.tsdb.retention.time=7d"
            - "--web.enable-lifecycle"
          ports:
            - containerPort: 9090
          volumeMounts:
            - name: config
              mountPath: /etc/prometheus/
            - name: storage
              mountPath: /prometheus/
          startupProbe:
            httpGet:
              path: /-/healthy
              port: 9090
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 30
          readinessProbe:
            httpGet:
              path: /-/ready
              port: 9090
            initialDelaySeconds: 5
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /-/healthy
              port: 9090
            initialDelaySeconds: 10
            periodSeconds: 10
      volumes:
        - name: config
          configMap:
            name: prometheus-config
        - name: storage
          persistentVolumeClaim:
            claimName: prometheus-storage
---
apiVersion: v1
kind: Service
metadata:
  name: prometheus
  namespace: larakube-shared
spec:
  selector:
    app: prometheus
  ports:
    - protocol: TCP
      port: 9090
      targetPort: 9090
  type: ClusterIP
@if($withLogs ?? true)
---
# ── Loki ─────────────────────────────────────────────────────────────────────
apiVersion: v1
kind: ConfigMap
metadata:
  name: loki-config
  namespace: larakube-shared
data:
  loki.yaml: |
    auth_enabled: false
    server:
      http_listen_port: 3100
      grpc_listen_port: 9096
    common:
      instance_addr: 127.0.0.1
      path_prefix: /loki/data
      storage:
        filesystem:
          chunks_directory: /loki/data/chunks
          rules_directory: /loki/data/rules
      replication_factor: 1
      ring:
        kvstore:
          store: inmemory
    query_range:
      results_cache:
        cache:
          embedded_cache:
            enabled: true
            max_size_mb: 100
    schema_config:
      configs:
        - from: 2024-01-01
          store: tsdb
          object_store: filesystem
          schema: v13
          index:
            prefix: index_
            period: 24h
    ruler:
      alertmanager_url: http://localhost:9093
    limits_config:
      retention_period: 168h
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: loki-storage
  namespace: larakube-shared
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 5Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: loki
  namespace: larakube-shared
spec:
  replicas: 1
  selector:
    matchLabels:
      app: loki
  template:
    metadata:
      labels:
        app: loki
      annotations:
        prometheus.io/scrape: "true"
        prometheus.io/port: "3100"
        prometheus.io/path: "/metrics"
    spec:
      containers:
        - name: loki
          image: grafana/loki:3.0.0
          args: ["-config.file=/etc/loki/loki.yaml"]
          ports:
            - containerPort: 3100
              name: http
          volumeMounts:
            - name: config
              mountPath: /etc/loki/
            - name: storage
              mountPath: /loki/data/
          readinessProbe:
            httpGet:
              path: /ready
              port: 3100
            initialDelaySeconds: 10
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /ready
              port: 3100
            initialDelaySeconds: 20
            periodSeconds: 10
      volumes:
        - name: config
          configMap:
            name: loki-config
        - name: storage
          persistentVolumeClaim:
            claimName: loki-storage
---
apiVersion: v1
kind: Service
metadata:
  name: loki
  namespace: larakube-shared
spec:
  selector:
    app: loki
  ports:
    - protocol: TCP
      port: 3100
      targetPort: 3100
  type: ClusterIP
---
# ── Promtail (DaemonSet — one per node, tails /var/log/pods) ─────────────────
apiVersion: v1
kind: ServiceAccount
metadata:
  name: promtail
  namespace: larakube-shared
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: larakube-promtail
rules:
  - apiGroups: [""]
    resources: [nodes, services, pods]
    verbs: [get, list, watch]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: larakube-promtail
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: larakube-promtail
subjects:
  - kind: ServiceAccount
    name: promtail
    namespace: larakube-shared
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: promtail-config
  namespace: larakube-shared
data:
  promtail.yaml: |
    server:
      http_listen_port: 9080
      grpc_listen_port: 0
    positions:
      filename: /tmp/positions.yaml
    clients:
      - url: http://loki.larakube-shared.svc.cluster.local:3100/loki/api/v1/push
    scrape_configs:
      - job_name: kubernetes-pods
        pipeline_stages:
          - docker: {}
        kubernetes_sd_configs:
          - role: pod
        relabel_configs:
          - source_labels: [__meta_kubernetes_pod_node_name]
            target_label: __host__
          - action: labelmap
            regex: __meta_kubernetes_pod_label_(.+)
          - source_labels: [__meta_kubernetes_namespace]
            target_label: namespace
          - source_labels: [__meta_kubernetes_pod_name]
            target_label: pod
          - source_labels: [__meta_kubernetes_pod_container_name]
            target_label: container
          - replacement: /var/log/pods/*$1/*.log
            separator: /
            source_labels: [__meta_kubernetes_pod_uid, __meta_kubernetes_pod_container_name]
            target_label: __path__
---
apiVersion: apps/v1
kind: DaemonSet
metadata:
  name: promtail
  namespace: larakube-shared
spec:
  selector:
    matchLabels:
      app: promtail
  template:
    metadata:
      labels:
        app: promtail
    spec:
      serviceAccountName: promtail
      tolerations:
        - key: node-role.kubernetes.io/master
          operator: Exists
          effect: NoSchedule
      containers:
        - name: promtail
          image: grafana/promtail:3.0.0
          args: ["-config.file=/etc/promtail/promtail.yaml"]
          env:
            - name: HOSTNAME
              valueFrom:
                fieldRef:
                  fieldPath: spec.nodeName
          ports:
            - containerPort: 9080
              name: http
          volumeMounts:
            - name: config
              mountPath: /etc/promtail/
            - name: pods
              mountPath: /var/log/pods
              readOnly: true
            - name: containers
              mountPath: /var/lib/docker/containers
              readOnly: true
          readinessProbe:
            httpGet:
              path: /ready
              port: 9080
            initialDelaySeconds: 10
            periodSeconds: 5
      volumes:
        - name: config
          configMap:
            name: promtail-config
        - name: pods
          hostPath:
            path: /var/log/pods
        - name: containers
          hostPath:
            path: /var/lib/docker/containers
@endif
---
# ── kube-state-metrics ───────────────────────────────────────────────────────
apiVersion: v1
kind: ServiceAccount
metadata:
  name: kube-state-metrics
  namespace: larakube-shared
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: larakube-kube-state-metrics
rules:
  - apiGroups: [""]
    resources: [configmaps, secrets, nodes, pods, services, resourcequotas, replicationcontrollers, limitranges, persistentvolumeclaims, persistentvolumes, namespaces, endpoints]
    verbs: [list, watch]
  - apiGroups: [apps]
    resources: [statefulsets, daemonsets, deployments, replicasets]
    verbs: [list, watch]
  - apiGroups: [batch]
    resources: [cronjobs, jobs]
    verbs: [list, watch]
  - apiGroups: [networking.k8s.io]
    resources: [ingresses]
    verbs: [list, watch]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: larakube-kube-state-metrics
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: larakube-kube-state-metrics
subjects:
  - kind: ServiceAccount
    name: kube-state-metrics
    namespace: larakube-shared
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: kube-state-metrics
  namespace: larakube-shared
spec:
  replicas: 1
  selector:
    matchLabels:
      app: kube-state-metrics
  template:
    metadata:
      labels:
        app: kube-state-metrics
      annotations:
        prometheus.io/scrape: "true"
        prometheus.io/port: "8080"
    spec:
      serviceAccountName: kube-state-metrics
      containers:
        - name: kube-state-metrics
          image: registry.k8s.io/kube-state-metrics/kube-state-metrics:v2.12.0
          ports:
            - containerPort: 8080
              name: http-metrics
            - containerPort: 8081
              name: telemetry
          readinessProbe:
            httpGet:
              path: /healthz
              port: 8080
            initialDelaySeconds: 5
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /healthz
              port: 8080
            initialDelaySeconds: 5
            periodSeconds: 5
---
apiVersion: v1
kind: Service
metadata:
  name: kube-state-metrics
  namespace: larakube-shared
spec:
  selector:
    app: kube-state-metrics
  ports:
    - name: http-metrics
      protocol: TCP
      port: 8080
      targetPort: 8080
  type: ClusterIP
---
# ── Grafana ───────────────────────────────────────────────────────────────────
# Admin credentials — generated once on first install; stable across re-runs
apiVersion: v1
kind: Secret
metadata:
  name: grafana-admin
  namespace: larakube-shared
type: Opaque
data:
  password: {{ base64_encode($grafanaPassword) }}
---
# Pre-wired Prometheus + Loki data sources
apiVersion: v1
kind: ConfigMap
metadata:
  name: grafana-datasources
  namespace: larakube-shared
data:
  datasources.yaml: |
    apiVersion: 1
    datasources:
      - name: Prometheus
        type: prometheus
        access: proxy
        url: http://prometheus.larakube-shared.svc.cluster.local:9090
        isDefault: true
        editable: false
@if($withLogs ?? true)
      - name: Loki
        type: loki
        access: proxy
        url: http://loki.larakube-shared.svc.cluster.local:3100
        editable: false
@endif
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: grafana
  namespace: larakube-shared
spec:
  replicas: 1
  selector:
    matchLabels:
      app: grafana
  template:
    metadata:
      labels:
        app: grafana
    spec:
      containers:
        - name: grafana
          image: grafana/grafana:10.4.2
          ports:
            - containerPort: 3000
          env:
            - name: GF_SECURITY_ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: grafana-admin
                  key: password
            - name: GF_SERVER_ROOT_URL
              value: "https://{{ $host }}"
@if($appName ?? null)
            - name: GF_BRANDING_APP_TITLE
              value: "{{ $appName }}"
@endif
@if($logoUrl ?? null)
            - name: GF_BRANDING_FAV_ICON
              value: "{{ $logoUrl }}"
@endif
            - name: GF_PATHS_PROVISIONING
              value: /etc/grafana/provisioning
          volumeMounts:
            - name: datasources
              mountPath: /etc/grafana/provisioning/datasources/
          readinessProbe:
            httpGet:
              path: /api/health
              port: 3000
            initialDelaySeconds: 5
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /api/health
              port: 3000
            initialDelaySeconds: 10
            periodSeconds: 10
      volumes:
        - name: datasources
          configMap:
            name: grafana-datasources
---
apiVersion: v1
kind: Service
metadata:
  name: grafana
  namespace: larakube-shared
spec:
  selector:
    app: grafana
  ports:
    - protocol: TCP
      port: 3000
      targetPort: 3000
  type: ClusterIP
---
{{-- Single source of truth for the grafana.{tld} Ingress. Also rendered
     standalone by ensureGrafanaIngress() so a `config:tld` change re-points the
     host on the next `up` (when the monitoring stack is active) instead of
     leaving it stale on the old host. --}}
@include('k8s.monitoring.grafana-ingress')
