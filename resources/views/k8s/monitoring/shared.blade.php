@php
    // Only Loki/Promtail are instance-suffixed here — Grafana/Prometheus/
    // Tempo/kube-state-metrics stay bare pending their own, DB-rename-aware
    // pass (Grafana has a Commons Postgres tenant; the rest don't need one
    // but are rendered/removed alongside it).
    $suffix = ($instance ?? '') !== '' ? "-{$instance}" : '';
    $lokiName = "monitor-loki{$suffix}";
    $lokiConfigMapName = "monitor-loki-config{$suffix}";
    $promtailName = "monitor-promtail{$suffix}";
    $promtailConfigMapName = "monitor-promtail-config{$suffix}";
@endphp
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
  name: {{ $lokiConfigMapName }}
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
  name: {{ $lokiName }}
  namespace: larakube-shared
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $lokiName }}
  template:
    metadata:
      labels:
        app: {{ $lokiName }}
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
            name: {{ $lokiConfigMapName }}
        - name: storage
          persistentVolumeClaim:
            claimName: loki-storage
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $lokiName }}
  namespace: larakube-shared
spec:
  selector:
    app: {{ $lokiName }}
  ports:
    - protocol: TCP
      port: 3100
      targetPort: 3100
  type: ClusterIP
---
@if($withTraces ?? false)
# ── Tempo (distributed tracing — OTLP :4317/:4318, HTTP API :3200) ───────────
apiVersion: v1
kind: ConfigMap
metadata:
  name: tempo-config
  namespace: larakube-shared
data:
  tempo.yaml: |
    stream_over_http_enabled: true
    server:
      http_listen_port: 3200
    distributor:
      receivers:
        otlp:
          protocols:
            grpc:
              endpoint: 0.0.0.0:4317
            http:
              endpoint: 0.0.0.0:4318
    ingester:
      trace_idle_period: 10s
      max_block_bytes: 1_000_000
      max_block_duration: 5m
    compactor:
      compaction:
        block_retention: 168h
        compacted_block_retention: 24h
    storage:
      trace:
        backend: local
        wal:
          path: /var/tempo/wal
        local:
          path: /var/tempo/blocks
    metrics_generator:
      storage:
        path: /var/tempo/metrics-generator
        remote_write:
          - url: http://prometheus.larakube-shared.svc.cluster.local:9090/api/v1/write
            send_native_histograms: true
      registry:
        external_labels:
          source: tempo
      processor:
        service_graphs:
          histogram_buckets: [0.1, 0.25, 0.5, 1, 2.5, 5, 10]
        span_metrics: {}
    usage_report:
      reporting_enabled: false
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: tempo-storage
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
  name: tempo
  namespace: larakube-shared
spec:
  replicas: 1
  selector:
    matchLabels:
      app: tempo
  template:
    metadata:
      labels:
        app: tempo
      annotations:
        prometheus.io/scrape: "true"
        prometheus.io/port: "3200"
        prometheus.io/path: "/metrics"
    spec:
      containers:
        - name: tempo
          image: grafana/tempo:2.10.7
          args: ["-config.file=/etc/tempo/tempo.yaml"]
          ports:
            - containerPort: 3200
              name: http
            - containerPort: 4317
              name: otlp-grpc
            - containerPort: 4318
              name: otlp-http
          volumeMounts:
            - name: config
              mountPath: /etc/tempo/
            - name: storage
              mountPath: /var/tempo/
          readinessProbe:
            httpGet:
              path: /ready
              port: 3200
            initialDelaySeconds: 10
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /ready
              port: 3200
            initialDelaySeconds: 20
            periodSeconds: 10
      volumes:
        - name: config
          configMap:
            name: tempo-config
        - name: storage
          persistentVolumeClaim:
            claimName: tempo-storage
---
apiVersion: v1
kind: Service
metadata:
  name: tempo
  namespace: larakube-shared
spec:
  selector:
    app: tempo
  ports:
    - protocol: TCP
      port: 3200
      targetPort: 3200
    - protocol: TCP
      port: 4317
      targetPort: 4317
    - protocol: TCP
      port: 4318
      targetPort: 4318
  type: ClusterIP
@endif
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
  name: {{ $promtailConfigMapName }}
  namespace: larakube-shared
data:
  promtail.yaml: |
    server:
      http_listen_port: 9080
      grpc_listen_port: 0
    positions:
      filename: /tmp/positions.yaml
    clients:
      {{-- MUST track Loki's renamed Service — a stale value here fails
           silently: Promtail's own health checks don't depend on Loki, so it
           keeps reporting Ready while dropping every log line. --}}
      - url: http://{{ $lokiName }}.larakube-shared.svc.cluster.local:3100/loki/api/v1/push
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
  name: {{ $promtailName }}
  namespace: larakube-shared
spec:
  selector:
    matchLabels:
      app: {{ $promtailName }}
  template:
    metadata:
      labels:
        app: {{ $promtailName }}
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
            name: {{ $promtailConfigMapName }}
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
  name: monitor-secrets
  namespace: larakube-shared
type: Opaque
data:
  password: {{ base64_encode($grafanaPassword) }}
  db-password: {{ base64_encode($dbPassword) }}
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
        uid: prometheus-ds
        type: prometheus
        access: proxy
        url: http://prometheus.larakube-shared.svc.cluster.local:9090
        isDefault: true
        editable: false
@if($withLogs ?? true)
      - name: Loki
        uid: loki-ds
        type: loki
        access: proxy
        {{-- MUST track Loki's renamed Service — same silent-failure risk as
             promtail-config's push URL above. --}}
        url: http://{{ $lokiName }}.larakube-shared.svc.cluster.local:3100
        editable: false
@endif
@if($withTraces ?? false)
      - name: Tempo
        uid: tempo-ds
        type: tempo
        access: proxy
        url: http://tempo.larakube-shared.svc.cluster.local:3200
        editable: false
@endif
---
# Dashboards-as-code: one static provider scans /var/lib/grafana/dashboards
# every 10s, so dashboard JSON ConfigMaps added/removed by monitor:init take
# effect without a Grafana restart (unlike datasources, which load at startup).
apiVersion: v1
kind: ConfigMap
metadata:
  name: grafana-dashboard-provider
  namespace: larakube-shared
data:
  dashboards.yaml: |
    apiVersion: 1

    providers:
      - name: larakube
        orgId: 1
        folder: 'LaraKube'
        type: file
        disableDeletion: false
        updateIntervalSeconds: 10
        allowUiUpdates: false
        options:
          path: /var/lib/grafana/dashboards
@if($noPlex ?? false)
---
# --no-plex: no Commons Postgres to lease, so Grafana keeps its own SQLite —
# but backed by a real PVC this time, not the pod's ephemeral filesystem.
# Survives pod recreation; NOT covered by the Commons nightly backup (there's
# no Commons here at all) — the default (Commons Postgres) is preferred when
# available.
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: grafana-storage
  namespace: larakube-shared
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 1Gi
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
                  name: monitor-secrets
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
            {{-- Grafana's own database — dashboards created/edited via the UI,
                 folders, alert rules, users. Previously unset, so it defaulted
                 to Grafana's built-in SQLite on the pod's ephemeral filesystem
                 with nothing backing it — wiped on every pod recreation.
                 Confirmed live 2026-08-18. Commons Postgres, same pattern
                 every other Commons-backed tool uses, so it also rides along
                 with the existing nightly Commons backup. --no-plex has no
                 Commons to lease, so it falls back to SQLite on a real PVC
                 (see the mount below) instead of these GF_DATABASE_* vars —
                 Grafana defaults to SQLite at /var/lib/grafana when none are
                 set, which is exactly what that mount backs. --}}
@unless($noPlex ?? false)
            - name: GF_DATABASE_TYPE
              value: postgres
            - name: GF_DATABASE_HOST
              value: "postgres.{{ $plexNamespace }}.svc.cluster.local:5432"
            - name: GF_DATABASE_NAME
              value: grafana
            - name: GF_DATABASE_USER
              value: grafana
            - name: GF_DATABASE_SSL_MODE
              value: disable
            - name: GF_DATABASE_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: monitor-secrets
                  key: db-password
@endunless
          volumeMounts:
            - name: datasources
              mountPath: /etc/grafana/provisioning/datasources/
            - name: dashboard-provider
              mountPath: /etc/grafana/provisioning/dashboards/
            - name: dashboards
              mountPath: /var/lib/grafana/dashboards/
@if($noPlex ?? false)
            - name: storage
              mountPath: /var/lib/grafana/
@endif
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
        - name: dashboard-provider
          configMap:
            name: grafana-dashboard-provider
        - name: dashboards
          configMap:
            name: grafana-dashboards
@if($noPlex ?? false)
        - name: storage
          persistentVolumeClaim:
            claimName: grafana-storage
@endif
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
