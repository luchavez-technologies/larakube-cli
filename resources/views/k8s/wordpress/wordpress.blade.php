apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $pvcName }}
  labels:
@foreach($labels ?? [] as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
  namespace: {{ $namespace }}
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: {{ $volumeSize($pvcName, '5Gi', true) }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $deployName }}
  namespace: {{ $namespace }}
  labels:
    app: {{ $deployName }}
@foreach($labels ?? [] as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
    app.kubernetes.io/component: data
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $deployName }}
  template:
    metadata:
      labels:
        app: {{ $deployName }}
@foreach($labels ?? [] as $key => $value)
        {{ $key }}: {{ $value }}
@endforeach
        app.kubernetes.io/component: data
    spec:
      securityContext:
        fsGroup: 33
      initContainers:
        - name: seed-core
          image: wordpress:6.7-php8.3-apache
          command: ['sh', '-c']
          args:
            - |
              if [ ! -f /var/www/html/wp-settings.php ]; then
                echo "Seeding WordPress core into persistent volume..."
                cp -a -n /usr/src/wordpress/. /var/www/html/
              fi
          volumeMounts:
            - name: wp-data
              mountPath: /var/www/html
@if(($dbEngine ?? 'sqlite') === 'sqlite')
        - name: init-sqlite
          image: busybox:1.36
          command: ['sh', '-c']
          args:
            - |
              mkdir -p /var/www/html/wp-content/plugins /var/www/html/wp-content/database
              if [ ! -f /var/www/html/wp-content/db.php ]; then
                wget -q -O /tmp/sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip 2>/dev/null || true
                if [ -f /tmp/sqlite.zip ]; then
                  unzip -q -o /tmp/sqlite.zip -d /var/www/html/wp-content/plugins/ 2>/dev/null || true
                  rm -f /tmp/sqlite.zip
                  if [ -f /var/www/html/wp-content/plugins/sqlite-database-integration/db.copy ]; then
                    cp /var/www/html/wp-content/plugins/sqlite-database-integration/db.copy /var/www/html/wp-content/db.php
                  fi
                fi
              fi
          volumeMounts:
            - name: wp-data
              mountPath: /var/www/html
@endif
        - name: seed-platform
          image: busybox:1.36
          command: ['sh', '-c']
          args:
            - |
              mkdir -p /var/www/html/wp-content/mu-plugins
              cat << 'EOF' > /var/www/html/wp-content/mu-plugins/larakube-platform.php
              @php echo '<?php'; @endphp

              /**
               * Plugin Name: LaraKube Platform Integration
               * Description: Handles HTTPS reverse proxying, Stalwart/Mailpit SMTP relay, and XML-RPC hardening.
               */
              if (!defined('ABSPATH')) exit;

              if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
                  $_SERVER['HTTPS'] = 'on';
              }

              add_action('phpmailer_init', function ($phpmailer) {
                  $host = getenv('WORDPRESS_SMTP_HOST') ?: 'mail.{{ $plexNamespace }}.svc.cluster.local';
                  $port = (int) (getenv('WORDPRESS_SMTP_PORT') ?: 25);
                  $phpmailer->isSMTP();
                  $phpmailer->Host = $host;
                  $phpmailer->Port = $port;
                  $phpmailer->SMTPAuth = false;
                  $phpmailer->SMTPAutoTLS = false;
              });

              add_filter('xmlrpc_enabled', '__return_false');
              EOF
              chown -R 33:33 /var/www/html 2>/dev/null || true
          volumeMounts:
            - name: wp-data
              mountPath: /var/www/html
        - name: auto-install
          image: wordpress:cli-php8.3
          env:
@if(($dbEngine ?? 'sqlite') === 'sqlite')
            - name: WORDPRESS_DB_NAME
              value: "wordpress"
            - name: WORDPRESS_DB_USER
              value: "root"
            - name: WORDPRESS_DB_PASSWORD
              value: ""
            - name: WORDPRESS_DB_HOST
              value: "127.0.0.1"
@else
            - name: WORDPRESS_DB_HOST
              value: "{{ $dbHost ?? 'mysql.'.$plexNamespace.'.svc.cluster.local:3306' }}"
            - name: WORDPRESS_DB_NAME
              value: "{{ $dbName ?? 'data_wordpress' }}"
            - name: WORDPRESS_DB_USER
              value: "{{ $dbName ?? 'data_wordpress' }}"
            - name: WORDPRESS_DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
@endif
            - name: WP_ADMIN_EMAIL
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: admin-email
            - name: WP_ADMIN_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: admin-password
          command: ['sh', '-c']
          args:
            - |
              if ! wp core is-installed --path=/var/www/html --allow-root 2>/dev/null; then
                echo "WordPress not installed yet. Creating config and running automated install..."
@if(($dbEngine ?? 'sqlite') === 'sqlite')
                wp config create --path=/var/www/html --dbname="$WORDPRESS_DB_NAME" --dbuser="$WORDPRESS_DB_USER" --dbpass="$WORDPRESS_DB_PASSWORD" --dbhost="$WORDPRESS_DB_HOST" --skip-check --force --allow-root 2>/dev/null || true
@else
                wp config create --path=/var/www/html --dbname="$WORDPRESS_DB_NAME" --dbuser="$WORDPRESS_DB_USER" --dbpass="$WORDPRESS_DB_PASSWORD" --dbhost="$WORDPRESS_DB_HOST" --force --allow-root 2>/dev/null || true
@endif
                wp config set --path=/var/www/html WP_HOME "https://{{ $host }}" --allow-root 2>/dev/null || true
                wp config set --path=/var/www/html WP_SITEURL "https://{{ $host }}" --allow-root 2>/dev/null || true
                wp config set --path=/var/www/html FORCE_SSL_ADMIN true --raw --allow-root 2>/dev/null || true
@if(isset($redisIndex) && $redisIndex !== null)
                wp config set --path=/var/www/html WP_REDIS_HOST "redis.{{ $plexNamespace }}.svc.cluster.local" --allow-root 2>/dev/null || true
                wp config set --path=/var/www/html WP_REDIS_PORT 6379 --raw --allow-root 2>/dev/null || true
                wp config set --path=/var/www/html WP_REDIS_DATABASE {{ $redisIndex }} --raw --allow-root 2>/dev/null || true
@endif
                wp core install \
                  --path=/var/www/html \
                  --url="https://{{ $host }}" \
                  --title="{{ $siteTitle ?? 'WordPress' }}" \
                  --admin_user="admin" \
                  --admin_password="$WP_ADMIN_PASSWORD" \
                  --admin_email="$WP_ADMIN_EMAIL" \
                  --skip-email \
                  --allow-root || true
                chown -R 33:33 /var/www/html 2>/dev/null || true
              fi
          volumeMounts:
            - name: wp-data
              mountPath: /var/www/html
      containers:
        - name: wordpress
          image: wordpress:6.7-php8.3-apache
          ports:
            - containerPort: 80
              name: http
          env:
@if(($dbEngine ?? 'sqlite') === 'sqlite')
            - name: WORDPRESS_DB_NAME
              value: "wordpress"
            - name: WORDPRESS_DB_USER
              value: "root"
            - name: WORDPRESS_DB_PASSWORD
              value: ""
            - name: WORDPRESS_DB_HOST
              value: "127.0.0.1"
@else
            - name: WORDPRESS_DB_HOST
              value: "{{ $dbHost ?? 'mysql.'.$plexNamespace.'.svc.cluster.local:3306' }}"
            - name: WORDPRESS_DB_NAME
              value: "{{ $dbName ?? 'data_wordpress' }}"
            - name: WORDPRESS_DB_USER
              value: "{{ $dbName ?? 'data_wordpress' }}"
            - name: WORDPRESS_DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
@endif
            - name: WORDPRESS_CONFIG_EXTRA
              value: |
                define('WP_HOME', 'https://{{ $host }}');
                define('WP_SITEURL', 'https://{{ $host }}');
                define('FORCE_SSL_ADMIN', true);
                if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
                    $_SERVER['HTTPS'] = 'on';
                }
@if(isset($redisIndex) && $redisIndex !== null)
                define('WP_REDIS_HOST', 'redis.{{ $plexNamespace }}.svc.cluster.local');
                define('WP_REDIS_PORT', 6379);
                define('WP_REDIS_DATABASE', {{ $redisIndex }});
@endif
          volumeMounts:
            - name: wp-data
              mountPath: /var/www/html
          resources:
            requests:
              memory: 128Mi
              cpu: 50m
            limits:
              memory: 512Mi
              cpu: 1000m
          startupProbe:
            httpGet:
              path: /wp-login.php
              port: 80
            initialDelaySeconds: 10
            periodSeconds: 10
            failureThreshold: 30
          readinessProbe:
            httpGet:
              path: /wp-login.php
              port: 80
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 6
          livenessProbe:
            httpGet:
              path: /wp-login.php
              port: 80
            periodSeconds: 15
            timeoutSeconds: 5
      volumes:
        - name: wp-data
          persistentVolumeClaim:
            claimName: {{ $pvcName }}
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $deployName }}
  namespace: {{ $namespace }}
  labels:
    app: {{ $deployName }}
@foreach($labels ?? [] as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
spec:
  selector:
    app: {{ $deployName }}
  ports:
    - protocol: TCP
      port: 80
      targetPort: 80
  type: ClusterIP
---
@include('k8s.wordpress.ingress', ['ingressName' => $deployName, 'serviceName' => $deployName, 'labels' => $labels ?? []])
