@php
    // Every name comes from ToolInstance (ADR 0021). Rendered by twenty:init
    // (which passes the instance) and by anything that passes only the host.
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::CRM->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) $host));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::CRM, $instance);
    $deploymentName = $names->deployment('server');
    $workerDeploymentName = $names->deployment('worker');
    $serviceName = $deploymentName;
    $ingressName = $deploymentName;
    $secretName = $names->secret();
    $oidcSecretName = $names->secret(\App\Enums\SecretKind::OIDC);
    $dbName = $names->database();
    $dbUser = $dbName;
    $labels = function (string $component) use ($names) {
        $out = '';
        foreach ($names->labels($component) as $key => $value) {
            $out .= "\n    {$key}: {$value}";
        }

        return $out;
    };
    $podLabels = fn (string $component) => str_replace("\n    ", "\n        ", $labels($component));
@endphp
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $deploymentName }}
  namespace: larakube-shared
  labels:
    app: {{ $deploymentName }}
    larakube-tool: crm{!! $labels('server') !!}
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $deploymentName }}
  template:
    metadata:
      labels:
        app: {{ $deploymentName }}{!! $podLabels('server') !!}
    spec:
      containers:
        - name: twenty
          image: twentycrm/twenty:v2.35.0
          ports:
            - containerPort: 3000
              name: http
          env:
            - name: PORT
              value: "3000"
            - name: NODE_PORT
              value: "3000"
            - name: NODE_OPTIONS
              value: "--max-old-space-size=1536"
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: FRONT_BASE_URL
              value: "https://{{ $host }}"
            - name: SERVER_URL
              value: "https://{{ $host }}"
            - name: PG_DATABASE_URL
              value: "postgres://{{ $dbUser }}:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/{{ $dbName }}"
            - name: REDIS_URL
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}"
            - name: ACCESS_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: access-token-secret
            - name: LOGIN_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: login-token-secret
            - name: REFRESH_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: refresh-token-secret
            - name: FILE_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: file-token-secret
            - name: ENCRYPTION_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: encryption-key
            - name: APP_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: encryption-key
            # Commons SeaweedFS (S3-compatible). Twenty's enum value for S3
            # storage is literally "S_3" — not "s3"/"S3" — per its own
            # config-variables.ts (@CastToUpperSnakeCase doesn't produce the
            # underscore, so this can't be left to casing). forcePathStyle
            # is hardcoded in Twenty's S3 driver, no env var needed for it.
            - name: STORAGE_TYPE
              value: "S_3"
            - name: STORAGE_S3_REGION
              value: "us-east-1"
            - name: STORAGE_S3_NAME
              value: "{{ $bucket ?? 'crm-twenty-storage' }}"
            - name: STORAGE_S3_ENDPOINT
              value: "{{ $s3InternalEndpoint ?? '' }}"
            - name: STORAGE_S3_ACCESS_KEY_ID
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: s3-key
            - name: STORAGE_S3_SECRET_ACCESS_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: s3-secret
            # SeaweedFS denies anonymous reads by design — attachment links
            # handed to the browser must be presigned against the PUBLIC
            # host, never the cluster-internal DNS above (see
            # InteractsWithPlex::resolveCommonsS3Endpoints()).
            - name: STORAGE_S3_PRESIGNED_URL_ENABLED
              value: "true"
            - name: STORAGE_S3_PRESIGNED_URL_BASE
              value: "{{ $s3PublicEndpoint ?? '' }}"
            # OIDC
            - name: SSO_OIDC_ISSUER
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: SSO_OIDC_ISSUER
                  optional: true
            - name: SSO_OIDC_CLIENT_ID
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: SSO_OIDC_CLIENT_ID
                  optional: true
            - name: SSO_OIDC_CLIENT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecretName }}
                  key: SSO_OIDC_CLIENT_SECRET
                  optional: true
          startupProbe:
            httpGet:
              path: /health
              port: 3000
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 60
          readinessProbe:
            httpGet:
              path: /health
              port: 3000
            initialDelaySeconds: 15
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 6
          livenessProbe:
            httpGet:
              path: /health
              port: 3000
            initialDelaySeconds: 30
            periodSeconds: 15
            timeoutSeconds: 5
          resources:
            requests:
              memory: 512Mi
              cpu: 100m
            limits:
              memory: 2Gi
              cpu: 1000m
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $workerDeploymentName }}
  namespace: larakube-shared
  labels:
    app: {{ $workerDeploymentName }}{!! $labels('worker') !!}
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $workerDeploymentName }}
  template:
    metadata:
      labels:
        app: {{ $workerDeploymentName }}{!! $podLabels('worker') !!}
    spec:
      containers:
        - name: twenty-worker
          image: twentycrm/twenty:v2.35.0
          command: ["yarn", "worker:prod"]
          env:
            - name: NODE_OPTIONS
              value: "--max-old-space-size=1024"
            # The server's own boot already runs schema init/migrations —
            # the worker running them too would race it. Official
            # twentyhq/twenty docker-compose hardcodes this on the worker
            # for the same reason.
            - name: DISABLE_DB_MIGRATIONS
              value: "true"
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: SERVER_URL
              value: "https://{{ $host }}"
            - name: PG_DATABASE_URL
              value: "postgres://{{ $dbUser }}:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/{{ $dbName }}"
            - name: REDIS_URL
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}"
            - name: ACCESS_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: access-token-secret
            - name: LOGIN_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: login-token-secret
            - name: REFRESH_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: refresh-token-secret
            - name: FILE_TOKEN_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: file-token-secret
            - name: ENCRYPTION_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: encryption-key
            - name: APP_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: encryption-key
            - name: STORAGE_TYPE
              value: "S_3"
            - name: STORAGE_S3_REGION
              value: "us-east-1"
            - name: STORAGE_S3_NAME
              value: "{{ $bucket ?? 'crm-twenty-storage' }}"
            - name: STORAGE_S3_ENDPOINT
              value: "{{ $s3InternalEndpoint ?? '' }}"
            - name: STORAGE_S3_ACCESS_KEY_ID
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: s3-key
            - name: STORAGE_S3_SECRET_ACCESS_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: s3-secret
            - name: STORAGE_S3_PRESIGNED_URL_ENABLED
              value: "true"
            - name: STORAGE_S3_PRESIGNED_URL_BASE
              value: "{{ $s3PublicEndpoint ?? '' }}"
          resources:
            requests:
              memory: 256Mi
              cpu: 50m
            limits:
              memory: 1536Mi
              cpu: 500m
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $serviceName }}
  namespace: larakube-shared
  labels:{!! $labels('server') !!}
spec:
  selector:
    app: {{ $deploymentName }}
  ports:
    - protocol: TCP
      port: 80
      targetPort: 3000
  type: ClusterIP
---
@include('k8s.crm.ingress')
