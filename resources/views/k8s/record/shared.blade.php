@php
    // Every name comes from ToolInstance (ADR 0021).
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::RECORD->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) ($host ?? '')));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::RECORD, $instance);
    $dbName ??= $names->database();
    $deploymentName = $names->deployment();
    $secretName = $names->secret();
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
        - name: sendrec
          image: ghcr.io/sendrec/sendrec:v1.89.1
          ports:
            - containerPort: 8080
              name: http
          env:
            - name: BASE_URL
              value: "https://{{ $host }}"
            - name: REGISTRATION_ENABLED
              value: "{{ ($allowRegistration ?? false) ? 'true' : 'false' }}"
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: DATABASE_URL
              value: "postgres://{{ $dbName }}:$(DB_PASSWORD)@postgres.{{ $plexNamespace }}.svc.cluster.local:5432/{{ $dbName }}?sslmode=disable"
            - name: JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: jwt-secret
            - name: S3_ENDPOINT
              value: "{{ $s3Endpoint }}"
            - name: S3_PUBLIC_ENDPOINT
              value: "{{ $s3PublicEndpoint }}"
            - name: S3_ACCESS_KEY
              value: "{{ $s3AccessKey }}"
            - name: S3_SECRET_KEY
              value: "{{ $s3SecretKey }}"
            - name: S3_BUCKET
              value: "{{ $s3Bucket }}"
            - name: S3_REGION
              value: "us-east-1"
            - name: S3_FORCE_PATH_STYLE
              value: "true"
            # 465 is implicit TLS; SendRec defaults to STARTTLS and would hang.
            # Must be "tls", not "implicit" — SendRec coerces any
            # unrecognised value back to starttls (see ClusterTool::RECORD's
            # smtpEnv(), which documents this exact deadlock).
            - name: SMTP_TLS
              value: "tls"
            - name: SMTP_HOST
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: SMTP_HOST
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
            - name: EMAIL_FROM_ADDRESS
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: EMAIL_FROM_ADDRESS
                  optional: true
          # NOTE: no OIDC_* env vars here on purpose. SendRec's SSO is
          # WORKSPACE-level and configured inside the app (its .env.example
          # declares no OIDC variables at all), so env-based wiring is inert.
          # A previous revision injected OIDC_ENABLED/CLIENT_ID/CLIENT_SECRET/
          # ISSUER from an OIDC Secret — those were invented and
          # did nothing. See plans/active/sendrec-native-sso.md.
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
    - port: 80
      targetPort: 8080
      name: http
---
@include('k8s.record.ingress', ['instance' => $instance])
