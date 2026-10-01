@php
    // Every name comes from ToolInstance (ADR 0021).
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::RESUME->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) ($host ?? '')));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::RESUME, $instance);
    $dbName ??= $names->database();
    $deploymentName = $names->deployment();
    $secretName = $names->secret();
    $oidcSecret = $names->secret(\App\Enums\SecretKind::OIDC);
    $smtpSecret = $names->secret(\App\Enums\SecretKind::SMTP);
    $labels = '';
    foreach ($names->labels() as $key => $value) {
        $labels .= "\n    {$key}: {$value}";
    }
    $podLabels = str_replace("\n    ", "\n        ", $labels);
@endphp
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $deploymentName }}
  namespace: larakube-shared
  labels:
    app: {{ $deploymentName }}{!! $labels !!}
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
        app: {{ $deploymentName }}
    spec:
      containers:
        - name: reactive-resume
          image: amruthpillai/reactive-resume:v5.2.5
          ports:
            - containerPort: 3000
              name: http
          env:
            - name: PORT
              value: "3000"
            - name: APP_URL
              value: "https://{{ $host }}"
            - name: PUBLIC_URL
              value: "https://{{ $host }}"
            - name: AUTH_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: auth-secret
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: DATABASE_URL
              value: "postgresql://{{ $dbName }}:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/{{ $dbName }}"
            - name: REDIS_URL
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/0"
            - name: S3_ENDPOINT
              value: "{{ $s3Endpoint }}"
            - name: S3_BUCKET
              value: "{{ $s3Bucket }}"
            - name: S3_ACCESS_KEY
              value: "{{ $s3AccessKey }}"
            - name: S3_SECRET_KEY
              value: "{{ $s3SecretKey }}"
            - name: S3_REGION
              value: "us-east-1"
            - name: S3_FORCE_PATH_STYLE
              value: "true"
            - name: OAUTH_PROVIDER_NAME
              value: "Zitadel"
            - name: OAUTH_SCOPES
              value: "openid profile email"
            - name: OAUTH_ALLOW_SIGNUPS
              value: "true"
            - name: OAUTH_CLIENT_ID
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecret }}
                  key: OAUTH_CLIENT_ID
                  optional: true
            - name: OAUTH_CLIENT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecret }}
                  key: OAUTH_CLIENT_SECRET
                  optional: true
            - name: OAUTH_DISCOVERY_URL
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecret }}
                  key: OAUTH_DISCOVERY_URL
                  optional: true
            - name: MAIL_SERVER
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: MAIL_SERVER
                  optional: true
            - name: MAIL_PORT
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: MAIL_PORT
                  optional: true
            - name: MAIL_USERNAME
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: MAIL_USERNAME
                  optional: true
            - name: MAIL_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: MAIL_PASSWORD
                  optional: true
            - name: MAIL_FROM
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: MAIL_FROM
                  optional: true
          startupProbe:
            httpGet:
              path: /api/health
              port: 3000
            periodSeconds: 5
            timeoutSeconds: 5
            failureThreshold: 30
          readinessProbe:
            httpGet:
              path: /api/health
              port: 3000
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 3
          livenessProbe:
            httpGet:
              path: /api/health
              port: 3000
            periodSeconds: 15
            timeoutSeconds: 5
            failureThreshold: 3
          resources:
            requests:
              memory: 256Mi
              cpu: 50m
            limits:
              memory: 1Gi
              cpu: 500m
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $deploymentName }}
  namespace: larakube-shared
  labels:{!! $labels !!}
spec:
  selector:
    app: {{ $deploymentName }}
  ports:
    - protocol: TCP
      port: 80
      targetPort: 3000
  type: ClusterIP
---
@include('k8s.resume.ingress', ['instance' => $instance])
