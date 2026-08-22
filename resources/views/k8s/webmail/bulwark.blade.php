@php($suffix = ($instance ?? '') !== '' ? "-{$instance}" : '')
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  # Deliberately NOT instance-suffixed, unlike every other resource here:
  # renaming a PVC means a brand-new empty volume, not the existing one —
  # this holds Bulwark's real accumulated admin config + settings-sync data,
  # and Webmail can only ever have one instance anyway (1:1 bound to the one
  # Stalwart), so there's no collision risk to avoid by suffixing it.
  name: webmail-storage
  namespace: larakube-shared
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      # Bulwark stores only its own admin config + per-user settings-sync here,
      # never mail (that lives in Stalwart). 1Gi is generous.
      storage: 1Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: webmail-bulwark{{ $suffix }}
  namespace: larakube-shared
  labels:
    app: webmail-bulwark{{ $suffix }}
    app.kubernetes.io/part-of: webmail
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: webmail-bulwark{{ $suffix }}
  template:
    metadata:
      labels:
        app: webmail-bulwark{{ $suffix }}
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
                  name: webmail-secrets{{ $suffix }}
                  key: WEBMAIL_SESSION_SECRET
            - name: ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: webmail-secrets{{ $suffix }}
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
            claimName: webmail-storage
---
apiVersion: v1
kind: Service
metadata:
  name: webmail-bulwark{{ $suffix }}
  namespace: larakube-shared
spec:
  selector:
    app: webmail-bulwark{{ $suffix }}
  ports:
    - protocol: TCP
      port: 80
      targetPort: 3000
      name: http
  type: ClusterIP
---
@include('k8s.webmail.ingress')
