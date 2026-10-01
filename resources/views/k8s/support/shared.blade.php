@php
    // Every name comes from ToolInstance (ADR 0021).
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::SUPPORT->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) ($host ?? '')));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::SUPPORT, $instance);
    $dbName ??= $names->database();
    $webName = $names->deployment();
    $workerName = $names->deployment('worker');
    $secretName = $names->secret();
    $smtpSecret = $names->secret(\App\Enums\SecretKind::SMTP);
    $oidcSecret = $names->secret(\App\Enums\SecretKind::OIDC);
    $labelsFor = function (?string $component = null) use ($names): string {
        $out = '';
        foreach ($names->labels($component) as $key => $value) {
            $out .= "\n    {$key}: {$value}";
        }

        return $out;
    };
    $podLabelsFor = fn (?string $component = null): string => str_replace("\n    ", "\n        ", $labelsFor($component));
@endphp
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $webName }}
  namespace: larakube-shared
  labels:
    app: {{ $webName }}{!! $labelsFor() !!}
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $webName }}
  template:
    metadata:
      labels:
        app: {{ $webName }}
    spec:
      containers:
        - name: chatwoot
          image: chatwoot/chatwoot:v4.16.2
          command:
            - docker/entrypoints/render.sh
          ports:
            - containerPort: 3000
              name: http
          env:
            - name: FRONTEND_URL
              value: "https://{{ $host }}"
            - name: SECRET_KEY_BASE
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: secret-key-base
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: POSTGRES_DATABASE
              value: "{{ $dbName }}"
            - name: POSTGRES_USERNAME
              value: "{{ $dbName }}"
            - name: POSTGRES_HOST
              value: "postgres.{{ $plexNamespace }}.svc.cluster.local"
            - name: REDIS_URL
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}"
            - name: RAILS_ENV
              value: "production"
            - name: FORCE_SSL
              value: "true"
            - name: ENABLE_ACCOUNT_SIGNUP
              value: "false"
@if($appName ?? null)
            - name: INSTALLATION_NAME
              value: "{{ $appName }}"
            - name: BRAND_NAME
              value: "{{ $appName }}"
@endif
@if($logoUrl ?? null)
            - name: LOGO_URL
              value: "{{ $logoUrl }}"
@endif
            # SMTP Setup
            - name: SMTP_ADDRESS
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: SMTP_ADDRESS
                  optional: true
            - name: SMTP_PORT
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: SMTP_PORT
                  optional: true
            - name: SMTP_USERNAME
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: SMTP_USERNAME
                  optional: true
            - name: SMTP_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: SMTP_PASSWORD
                  optional: true
            - name: MAILER_SENDER_EMAIL
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: MAILER_SENDER_EMAIL
                  optional: true
            - name: SMTP_DOMAIN
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: SMTP_DOMAIN
                  optional: true
            # mail:wire sets this as a plain literal (kubectl set env
            # NAME=value), never through the SMTP Secret —
            # must stay a literal here too, or a future kubectl apply
            # conflicts with mail:wire's live value (see ClusterTool::SUPPORT's
            # smtpEnv()).
            - name: SMTP_ENABLE_STARTTLS_AUTO
              value: "true"
            # OIDC
            - name: OIDC_ISSUER
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecret }}
                  key: OIDC_ISSUER
                  optional: true
            - name: OIDC_CLIENT_ID
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecret }}
                  key: OIDC_CLIENT_ID
                  optional: true
            - name: OIDC_CLIENT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecret }}
                  key: OIDC_CLIENT_SECRET
                  optional: true
          startupProbe:
            httpGet:
              path: /api
              port: 3000
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 60
          readinessProbe:
            httpGet:
              path: /api
              port: 3000
            initialDelaySeconds: 15
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 6
          livenessProbe:
            httpGet:
              path: /api
              port: 3000
            initialDelaySeconds: 30
            periodSeconds: 15
            timeoutSeconds: 5
          resources:
            requests:
              memory: 256Mi
              cpu: 50m
            limits:
              memory: 512Mi
              cpu: 200m
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $workerName }}
  namespace: larakube-shared
  labels:
    app: {{ $workerName }}{!! $labelsFor('worker') !!}
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $workerName }}
  template:
    metadata:
      labels:
        app: {{ $workerName }}
    spec:
      containers:
        - name: sidekiq
          image: chatwoot/chatwoot:v4.16.2
          command:
            - bundle
            - exec
            - sidekiq
            - -C
            - config/sidekiq.yml
          env:
            - name: FRONTEND_URL
              value: "https://{{ $host }}"
            - name: SECRET_KEY_BASE
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: secret-key-base
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: POSTGRES_DATABASE
              value: "{{ $dbName }}"
            - name: POSTGRES_USERNAME
              value: "{{ $dbName }}"
            - name: POSTGRES_HOST
              value: "postgres.{{ $plexNamespace }}.svc.cluster.local"
            - name: REDIS_URL
              value: "redis://redis.{{ $plexNamespace }}.svc.cluster.local:6379/{{ $redisIndex }}"
            - name: RAILS_ENV
              value: "production"
            # SMTP Setup
            - name: SMTP_ADDRESS
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: SMTP_ADDRESS
                  optional: true
            - name: SMTP_PORT
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: SMTP_PORT
                  optional: true
            - name: SMTP_USERNAME
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: SMTP_USERNAME
                  optional: true
            - name: SMTP_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: SMTP_PASSWORD
                  optional: true
            - name: MAILER_SENDER_EMAIL
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: MAILER_SENDER_EMAIL
                  optional: true
            - name: SMTP_DOMAIN
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: SMTP_DOMAIN
                  optional: true
            # mail:wire sets this as a plain literal (kubectl set env
            # NAME=value), never through the SMTP Secret —
            # must stay a literal here too, or a future kubectl apply
            # conflicts with mail:wire's live value (see ClusterTool::SUPPORT's
            # smtpEnv()).
            - name: SMTP_ENABLE_STARTTLS_AUTO
              value: "true"
            # OIDC
            - name: OIDC_ISSUER
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecret }}
                  key: OIDC_ISSUER
                  optional: true
            - name: OIDC_CLIENT_ID
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecret }}
                  key: OIDC_CLIENT_ID
                  optional: true
            - name: OIDC_CLIENT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $oidcSecret }}
                  key: OIDC_CLIENT_SECRET
                  optional: true
          resources:
            requests:
              memory: 256Mi
              cpu: 50m
            limits:
              memory: 512Mi
              cpu: 200m
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $webName }}
  namespace: larakube-shared
  labels:{!! $labelsFor() !!}
spec:
  selector:
    app: {{ $webName }}
  ports:
    - protocol: TCP
      port: 80
      targetPort: 3000
  type: ClusterIP
---
@include('k8s.support.ingress', ['instance' => $instance])
