@php
    // Every name comes from ToolInstance (ADR 0021). Rendered both by
    // webmail:init (which passes the instance) and by the shared reconcile
    // path (which passes only the host), so derive what is missing.
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::WEBMAIL->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) $host));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::WEBMAIL, $instance);
    $deployment = $names->deployment();
    $secretName = $names->secret();
    $volume = $names->volume();
    $labels = $names->labels();
@endphp
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $volume }}
  namespace: {{ $names->namespace() }}
  labels:
@foreach($labels as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      # Bulwark stores only its own admin config + per-user settings-sync here,
      # never mail (that lives in Stalwart). 1Gi is generous.
      storage: {{ $volumeSize($volume, '1Gi', true) }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $deployment }}
  namespace: {{ $names->namespace() }}
  labels:
@foreach($labels as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $deployment }}
  template:
    metadata:
      labels:
        app: {{ $deployment }}
@foreach($labels as $key => $value)
        {{ $key }}: {{ $value }}
@endforeach
    spec:
      containers:
        - name: bulwark
          image: ghcr.io/bulwarkmail/webmail:1.7.8
          env:
            # Setting JMAP_SERVER_URL skips Bulwark's interactive setup wizard
            # (headless deploy). This MUST be the PUBLIC Stalwart host, not the
            # in-cluster Service DNS: the browser connects to JMAP directly
            # (hence the CORS flip in webmail:init), so it has to be a URL the
            # browser can actually resolve.
            - name: JMAP_SERVER_URL
              value: https://{{ $mailHost }}
            - name: PORT
              value: "3000"
            # Passed as an env var (not a mounted *_FILE) on purpose: mounting a
            # secret volume at /run/secrets collides with the kubelet's own
            # service-account token mount at /var/run/secrets/kubernetes.io
            # (/var/run is a symlink to /run), which fails container init with
            # a read-only-filesystem mkdir error and CrashLoopBackOffs the pod.
            - name: SESSION_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: WEBMAIL_SESSION_SECRET
            - name: ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: WEBMAIL_ADMIN_PASSWORD
            # Persist admin config + settings-sync across restarts so the
            # wizard/admin state isn't re-initialised on every rollout.
            - name: ADMIN_CONFIG_DIR
              value: /data/admin
            - name: SETTINGS_DATA_DIR
              value: /data/settings
            - name: APP_NAME
              value: "{{ $appName }}"
            - name: APP_SHORT_NAME
              value: "{{ $appName }}"
          ports:
            - containerPort: 3000
              name: http
          volumeMounts:
            - name: webmail-data
              mountPath: /data
          startupProbe:
            httpGet:
              path: /
              port: 3000
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 30
          readinessProbe:
            httpGet:
              path: /
              port: 3000
            initialDelaySeconds: 10
            periodSeconds: 10
          livenessProbe:
            httpGet:
              path: /
              port: 3000
            initialDelaySeconds: 30
            periodSeconds: 15
      volumes:
        - name: webmail-data
          persistentVolumeClaim:
            claimName: {{ $volume }}
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $deployment }}
  namespace: {{ $names->namespace() }}
  labels:
@foreach($labels as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
spec:
  selector:
    app: {{ $deployment }}
  ports:
    - protocol: TCP
      port: 80
      targetPort: 3000
      name: http
  type: ClusterIP
---
@include('k8s.webmail.ingress')
