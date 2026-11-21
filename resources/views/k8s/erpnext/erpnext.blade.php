@php
    $names ??= \App\Data\ToolInstance::forHost(\App\Enums\ClusterTool::ERPNEXT, $host);
    $labels ??= $names->labels();
    $dbName ??= $names->database();
    $deployName = $names->deployment();
    $ns = $names->namespace();
    $secretName = $names->secret();
    $pvcName = $names->volume('sites');
    $configMapName = $names->name('bootstrap');
    $dbHost = "mariadb.{$plexNamespace}.svc.cluster.local";
    $redisUrl = "redis://redis.{$plexNamespace}.svc.cluster.local:6379/{$redisIndex}";
    $image = 'docker.io/frappe/erpnext:v16.60.0';
@endphp
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $pvcName }}
  namespace: {{ $ns }}
  labels:
@foreach($labels as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 10Gi
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ $configMapName }}
  namespace: {{ $ns }}
  labels:
@foreach($labels as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
data:
  bootstrap.sh: |
    #!/bin/bash
    set -euo pipefail
    cd /home/frappe/frappe-bench

    echo "Waiting for MariaDB at ${DB_HOST}:3306..."
    until python3 -c "import socket; s=socket.create_connection(('${DB_HOST}', 3306), 2); s.close()" 2>/dev/null; do
      sleep 2
    done

    # The sites volume starts empty, and bench finds its apps through sites/apps.txt.
    ls -1 apps > sites/apps.txt

    python3 - <<'PY'
    import json, os
    path = '/home/frappe/frappe-bench/sites/common_site_config.json'
    cfg = {}
    if os.path.exists(path):
        try:
            with open(path) as f:
                cfg = json.load(f)
        except Exception:
            cfg = {}
    cfg.update({
        'db_host': os.environ['DB_HOST'],
        'db_port': 3306,
        'redis_cache': os.environ['REDIS_URL'],
        'redis_queue': os.environ['REDIS_URL'],
        'redis_socketio': os.environ['REDIS_URL'],
        'webserver_port': 8000,
        'socketio_port': 9000,
    })
    with open(path, 'w') as f:
        json.dump(cfg, f, indent=2)

    # Frappe keeps the database password in the site's own config, so carry the
    # Secret's current one (and the public URL) into it on every start.
    site = '/home/frappe/frappe-bench/sites/frontend/site_config.json'
    if os.path.exists(site):
        with open(site) as f:
            site_cfg = json.load(f)
        site_cfg['db_password'] = os.environ['DB_PASSWORD']
        site_cfg['host_name'] = os.environ['SITE_URL']
        with open(site, 'w') as f:
            json.dump(site_cfg, f, indent=1)
    PY

    if [ ! -f /home/frappe/frappe-bench/sites/frontend/site_config.json ]; then
      echo "Creating initial ERPNext site 'frontend'..."
      bench new-site frontend \
        --no-setup-db \
        --db-host "${DB_HOST}" \
        --db-port 3306 \
        --db-name "${DB_NAME}" \
        --db-user "${DB_USER}" \
        --db-password "${DB_PASSWORD}" \
        --admin-password "${ADMIN_PASSWORD}" \
        --install-app erpnext
      bench --site frontend set-config host_name "${SITE_URL}"
    else
      echo "Site 'frontend' already initialized — running pending migrations..."
      bench --site frontend migrate
    fi
  sync-wiring.py: |
    import os
    import frappe

    def sync():
        frappe.init(site='frontend', sites_path='/home/frappe/frappe-bench/sites')
        frappe.connect()
        try:
            client_id = os.environ.get('LARAKUBE_OIDC_CLIENT_ID', '').strip()
            client_secret = os.environ.get('LARAKUBE_OIDC_CLIENT_SECRET', '').strip()
            issuer = os.environ.get('LARAKUBE_OIDC_ISSUER', '').strip().rstrip('/')
            if client_id and client_secret and issuer:
                name = 'zitadel'
                doc = frappe.get_doc('Social Login Key', name) if frappe.db.exists('Social Login Key', name) else frappe.new_doc('Social Login Key')
                doc.provider_name = 'Zitadel'
                doc.social_login_provider = 'Custom'
                doc.enable_social_login = 1
                doc.client_id = client_id
                doc.client_secret = client_secret
                doc.base_url = issuer
                doc.authorize_url = '/oauth/v2/authorize'
                doc.access_token_url = '/oauth/v2/token'
                doc.redirect_url = '/api/method/frappe.integrations.oauth2_logins.custom/zitadel'
                doc.api_endpoint = f"{issuer}/oidc/v1/userinfo"
                doc.auth_url_data = '{"response_type": "code", "scope": "openid email profile"}'
                doc.user_id_property = 'sub'
                doc.flags.ignore_permissions = True
                if doc.is_new():
                    doc.name = name
                    doc.insert(ignore_permissions=True)
                else:
                    doc.save(ignore_permissions=True)
                frappe.db.commit()

            smtp_host = os.environ.get('LARAKUBE_SMTP_HOST', '').strip()
            smtp_from = os.environ.get('LARAKUBE_SMTP_FROM', '').strip()
            if smtp_host and smtp_from:
                acct_name = 'LaraKube SMTP'
                doc = frappe.get_doc('Email Account', acct_name) if frappe.db.exists('Email Account', acct_name) else frappe.new_doc('Email Account')
                doc.email_account_name = acct_name
                doc.email_id = smtp_from
                doc.smtp_server = smtp_host
                doc.smtp_port = int(os.environ.get('LARAKUBE_SMTP_PORT', '587') or 587)
                doc.use_tls = 1
                doc.enable_outgoing = 1
                doc.default_outgoing = 1
                smtp_user = os.environ.get('LARAKUBE_SMTP_USER', '').strip()
                smtp_pass = os.environ.get('LARAKUBE_SMTP_PASSWORD', '').strip()
                if smtp_user:
                    doc.email_login_id = smtp_user
                if smtp_pass:
                    doc.password = smtp_pass
                doc.flags.ignore_permissions = True
                if doc.is_new():
                    doc.insert(ignore_permissions=True)
                else:
                    doc.save(ignore_permissions=True)
                frappe.db.commit()
        finally:
            frappe.destroy()

    if __name__ == '__main__':
        sync()
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $deployName }}
  namespace: {{ $ns }}
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
      app.kubernetes.io/instance: {{ $deployName }}
  template:
    metadata:
      labels:
        app.kubernetes.io/instance: {{ $deployName }}
@foreach($labels as $key => $value)
        {{ $key }}: {{ $value }}
@endforeach
    spec:
      securityContext:
        fsGroup: 1000
      initContainers:
        - name: bootstrap
          image: {{ $image }}
          args: ["/bin/bash", "/bootstrap/bootstrap.sh"]
          env:
            - name: DB_HOST
              value: "{{ $dbHost }}"
            - name: DB_NAME
              value: "{{ $dbName }}"
            - name: DB_USER
              value: "{{ $dbName }}"
            - name: REDIS_URL
              value: "{{ $redisUrl }}"
            - name: SITE_URL
              value: "https://{{ $host }}"
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: admin-password
          volumeMounts:
            - name: sites
              mountPath: /home/frappe/frappe-bench/sites
            - name: bootstrap
              mountPath: /bootstrap
      containers:
        - name: frontend
          image: {{ $image }}
          args: ["nginx-entrypoint.sh"]
          env:
            - name: BACKEND
              value: "127.0.0.1:8000"
            - name: SOCKETIO
              value: "127.0.0.1:9000"
            - name: FRAPPE_SITE_NAME_HEADER
              value: "frontend"
            # Traefik terminates TLS; pass its https on so ERPNext builds https links and SSO redirects.
            - name: TRUST_UPSTREAM_FORWARDED_PROTO
              value: "on"
            - name: CLIENT_MAX_BODY_SIZE
              value: "50m"
          ports:
            - name: http
              containerPort: 8080
          readinessProbe:
            httpGet:
              path: /api/method/ping
              port: 8080
            initialDelaySeconds: 15
            periodSeconds: 10
          volumeMounts:
            - name: sites
              mountPath: /home/frappe/frappe-bench/sites
          resources:
            requests:
              memory: 64Mi
              cpu: 50m
            limits:
              memory: 256Mi
              cpu: 250m
        - name: backend
          image: {{ $image }}
          args:
            - /bin/bash
            - -c
            - |
              python3 /bootstrap/sync-wiring.py || echo "WARNING: could not sync SSO/SMTP settings into ERPNext; see the error above." >&2
              exec /home/frappe/frappe-bench/env/bin/gunicorn \
                --chdir=/home/frappe/frappe-bench/sites \
                --bind=127.0.0.1:8000 \
                --threads=4 \
                --workers=2 \
                --worker-class=gthread \
                --worker-tmp-dir=/dev/shm \
                --timeout=120 \
                --preload \
                frappe.app:application
          ports:
            - name: gunicorn
              containerPort: 8000
          readinessProbe:
            tcpSocket:
              port: 8000
            initialDelaySeconds: 10
            periodSeconds: 10
          volumeMounts:
            - name: sites
              mountPath: /home/frappe/frappe-bench/sites
            - name: bootstrap
              mountPath: /bootstrap
          resources:
            requests:
              memory: 512Mi
              cpu: 250m
            limits:
              memory: 1536Mi
              cpu: 1000m
        - name: websocket
          image: {{ $image }}
          args: ["node", "/home/frappe/frappe-bench/apps/frappe/socketio.js"]
          ports:
            - name: socketio
              containerPort: 9000
          volumeMounts:
            - name: sites
              mountPath: /home/frappe/frappe-bench/sites
          resources:
            requests:
              memory: 64Mi
              cpu: 50m
            limits:
              memory: 256Mi
              cpu: 250m
        - name: worker
          image: {{ $image }}
          args: ["bench", "worker", "--queue", "short,default,long"]
          volumeMounts:
            - name: sites
              mountPath: /home/frappe/frappe-bench/sites
          resources:
            requests:
              memory: 256Mi
              cpu: 100m
            limits:
              memory: 1Gi
              cpu: 500m
        - name: scheduler
          image: {{ $image }}
          args: ["bench", "schedule"]
          volumeMounts:
            - name: sites
              mountPath: /home/frappe/frappe-bench/sites
          resources:
            requests:
              memory: 128Mi
              cpu: 50m
            limits:
              memory: 512Mi
              cpu: 250m
      volumes:
        - name: sites
          persistentVolumeClaim:
            claimName: {{ $pvcName }}
        - name: bootstrap
          configMap:
            name: {{ $configMapName }}
            defaultMode: 0755
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $deployName }}
  namespace: {{ $ns }}
  labels:
@foreach($labels as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
spec:
  selector:
    app.kubernetes.io/instance: {{ $deployName }}
  ports:
    - name: http
      port: 80
      targetPort: 8080
---
@include('k8s.erpnext.ingress', [
    'host' => $host,
    'names' => $names,
    'labels' => $labels,
    'isLocal' => $isLocal ?? false,
    'proxied' => $proxied ?? false,
    'vpnOnly' => $vpnOnly ?? false,
])
