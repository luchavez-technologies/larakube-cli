@php
    $tool = \App\Enums\ClusterTool::SUPABASE;
    $names = \App\Data\ToolInstance::forInstance($tool, $instance);
    $secretName = $names->secret();
    $smtpSecret = $names->secret(\App\Enums\SecretKind::SMTP);
    $dbHost = $names->name('db');
    $authName = $names->name('auth');
    $restName = $names->name('rest');
    $metaName = $names->name('meta');
    $storageName = $names->name('storage');
    $studioName = $names->name('studio');
    $storagePvc = $names->volume('storage');
    $snippetsPvc = $names->volume('snippets');
    $noPlex ??= false;
    $labelsFor = function (string $component) use ($names): string {
        $out = '';
        foreach ($names->labels($component) as $k => $v) {
            $out .= "\n    {$k}: {$v}";
        }

        return $out;
    };
    $podLabelsFor = fn (string $component): string => str_replace("\n    ", "\n        ", $labelsFor($component));
@endphp
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $authName }}
  namespace: larakube-shared
  labels:{!! $labelsFor('auth') !!}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $authName }}
  template:
    metadata:
      labels:
        app: {{ $authName }}{!! $podLabelsFor('auth') !!}
    spec:
      containers:
        - name: supabase-auth
          image: {{ $tool->vendor()->image('auth') }}
          ports:
            - containerPort: 9999
              name: http
          env:
            - name: GOTRUE_API_HOST
              value: "0.0.0.0"
            - name: GOTRUE_API_PORT
              value: "9999"
            - name: API_EXTERNAL_URL
              value: "https://{{ $host }}"
            - name: GOTRUE_DB_DRIVER
              value: postgres
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: GOTRUE_DB_DATABASE_URL
              value: "postgres://supabase_auth_admin:$(DB_PASSWORD){{ "@".$dbHost }}:5432/postgres"
            - name: GOTRUE_SITE_URL
              value: "https://{{ $host }}"
            - name: GOTRUE_URI_ALLOW_LIST
              value: ""
            - name: GOTRUE_DISABLE_SIGNUP
              value: "false"
            - name: GOTRUE_JWT_ADMIN_ROLES
              value: service_role
            - name: GOTRUE_JWT_AUD
              value: authenticated
            - name: GOTRUE_JWT_DEFAULT_GROUP_NAME
              value: authenticated
            - name: GOTRUE_JWT_EXP
              value: "3600"
            - name: GOTRUE_JWT_ISSUER
              value: "https://{{ $host }}"
            - name: GOTRUE_EXTERNAL_ANONYMOUS_USERS_ENABLED
              value: "false"
            # No SMS provider is wired, so phone sign-up stays off.
            - name: GOTRUE_EXTERNAL_PHONE_ENABLED
              value: "false"
            - name: GOTRUE_SMS_AUTOCONFIRM
              value: "false"
            # Email links go through the gateway, where Auth lives under /auth/v1.
            - name: GOTRUE_MAILER_URLPATHS_INVITE
              value: /auth/v1/verify
            - name: GOTRUE_MAILER_URLPATHS_CONFIRMATION
              value: /auth/v1/verify
            - name: GOTRUE_MAILER_URLPATHS_RECOVERY
              value: /auth/v1/verify
            - name: GOTRUE_MAILER_URLPATHS_EMAIL_CHANGE
              value: /auth/v1/verify
            - name: GOTRUE_SMTP_SENDER_NAME
              value: Supabase
            - name: GOTRUE_JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: jwt-secret
            - name: GOTRUE_EXTERNAL_EMAIL_ENABLED
              value: "true"
            - name: GOTRUE_MAILER_AUTOCONFIRM
              value: "true"
            - name: GOTRUE_SMTP_HOST
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: GOTRUE_SMTP_HOST
                  optional: true
            - name: GOTRUE_SMTP_PORT
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: GOTRUE_SMTP_PORT
                  optional: true
            - name: GOTRUE_SMTP_USER
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: GOTRUE_SMTP_USER
                  optional: true
            - name: GOTRUE_SMTP_PASS
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: GOTRUE_SMTP_PASS
                  optional: true
            - name: GOTRUE_SMTP_ADMIN_EMAIL
              valueFrom:
                secretKeyRef:
                  name: {{ $smtpSecret }}
                  key: GOTRUE_SMTP_ADMIN_EMAIL
                  optional: true
          resources:
            requests:
              cpu: 100m
              memory: 128Mi
            limits:
              cpu: 500m
              memory: 256Mi
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $authName }}
  namespace: larakube-shared
  labels:{!! $labelsFor('auth') !!}
spec:
  selector:
    app: {{ $authName }}
  ports:
    - port: 9999
      targetPort: 9999
      name: http
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $restName }}
  namespace: larakube-shared
  labels:{!! $labelsFor('rest') !!}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $restName }}
  template:
    metadata:
      labels:
        app: {{ $restName }}{!! $podLabelsFor('rest') !!}
    spec:
      containers:
        - name: supabase-rest
          image: {{ $tool->vendor()->image('rest') }}
          ports:
            - containerPort: 3000
              name: http
          env:
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: PGRST_DB_URI
              value: "postgres://authenticator:$(DB_PASSWORD){{ "@".$dbHost }}:5432/postgres"
            - name: PGRST_DB_SCHEMAS
              value: "public,graphql_public"
            - name: PGRST_DB_ANON_ROLE
              value: anon
            - name: PGRST_DB_MAX_ROWS
              value: "1000"
            - name: PGRST_DB_EXTRA_SEARCH_PATH
              value: public
            - name: PGRST_ADMIN_SERVER_PORT
              value: "3001"
            - name: PGRST_ADMIN_SERVER_HOST
              value: localhost
            - name: PGRST_JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: jwt-secret
            - name: PGRST_DB_USE_LEGACY_GUCS
              value: "false"
            - name: PGRST_APP_SETTINGS_JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: jwt-secret
            - name: PGRST_APP_SETTINGS_JWT_EXP
              value: "3600"
          resources:
            requests:
              cpu: 100m
              memory: 128Mi
            limits:
              cpu: 500m
              memory: 256Mi
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $restName }}
  namespace: larakube-shared
  labels:{!! $labelsFor('rest') !!}
spec:
  selector:
    app: {{ $restName }}
  ports:
    - port: 3000
      targetPort: 3000
      name: http
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $metaName }}
  namespace: larakube-shared
  labels:{!! $labelsFor('meta') !!}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $metaName }}
  template:
    metadata:
      labels:
        app: {{ $metaName }}{!! $podLabelsFor('meta') !!}
    spec:
      containers:
        - name: supabase-meta
          image: {{ $tool->vendor()->image('meta') }}
          ports:
            - containerPort: 8080
              name: http
          env:
            - name: PG_META_PORT
              value: "8080"
            - name: PG_META_DB_HOST
              value: {{ $dbHost }}
            - name: PG_META_DB_PORT
              value: "5432"
            - name: PG_META_DB_NAME
              value: postgres
            - name: PG_META_DB_USER
              value: supabase_admin
            - name: PG_META_DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: CRYPTO_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: pg-meta-crypto-key
          resources:
            requests:
              cpu: 100m
              memory: 128Mi
            limits:
              cpu: 500m
              memory: 256Mi
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $metaName }}
  namespace: larakube-shared
  labels:{!! $labelsFor('meta') !!}
spec:
  selector:
    app: {{ $metaName }}
  ports:
    - port: 8080
      targetPort: 8080
      name: http
---
@if($noPlex)
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $storagePvc }}
  namespace: larakube-shared
  labels:{!! $labelsFor('storage') !!}
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: {{ $storageSize ?? '5Gi' }}
---
@endif
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $storageName }}
  namespace: larakube-shared
  labels:{!! $labelsFor('storage') !!}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $storageName }}
  template:
    metadata:
      labels:
        app: {{ $storageName }}{!! $podLabelsFor('storage') !!}
    spec:
      containers:
        - name: supabase-storage
          image: {{ $tool->vendor()->image('storage') }}
          ports:
            - containerPort: 5000
              name: http
          env:
            - name: ANON_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: anon-key
            - name: SERVICE_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: service-role-key
            - name: POSTGREST_URL
              value: "http://{{ $restName }}:3000"
            # What Storage verifies request JWTs with (upstream's name for it).
            - name: AUTH_JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: jwt-secret
            - name: STORAGE_PUBLIC_URL
              value: "https://{{ $host }}"
            - name: REQUEST_ALLOW_X_FORWARDED_PATH
              value: "true"
            - name: PGRST_JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: jwt-secret
            - name: DB_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: DATABASE_URL
              value: "postgres://supabase_storage_admin:$(DB_PASSWORD){{ "@".$dbHost }}:5432/postgres"
            - name: FILE_SIZE_LIMIT
              value: "52428800"
            - name: STORAGE_BACKEND
              value: "{{ $noPlex ? 'file' : 's3' }}"
@if($noPlex)
            - name: FILE_STORAGE_BACKEND_PATH
              value: /var/lib/storage
@else
            - name: GLOBAL_S3_BUCKET
              value: "{{ $s3Bucket }}"
            - name: GLOBAL_S3_ENDPOINT
              value: "{{ $s3Endpoint }}"
            - name: GLOBAL_S3_PROTOCOL
              value: http
            - name: GLOBAL_S3_FORCE_PATH_STYLE
              value: "true"
            - name: AWS_ACCESS_KEY_ID
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: s3-access-key
            - name: AWS_SECRET_ACCESS_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: s3-secret-key
            - name: AWS_DEFAULT_REGION
              value: "{{ $s3Region ?? 'us-east-1' }}"
@endif
            - name: TENANT_ID
              value: stub
            - name: REGION
              value: stub
            - name: GLOBAL_S3_PREFIX
              value: ""
            - name: ENABLE_IMAGE_TRANSFORMATION
              value: "{{ ($withImgproxy ?? true) ? 'true' : 'false' }}"
@if($withImgproxy ?? true)
            - name: IMGPROXY_URL
              value: "http://{{ $names->name('imgproxy') }}:5001"
@endif
          resources:
            requests:
              cpu: 100m
              memory: 256Mi
            limits:
              cpu: 500m
              memory: 512Mi
@if($noPlex)
          volumeMounts:
            - name: storage-data
              mountPath: /var/lib/storage
      volumes:
        - name: storage-data
          persistentVolumeClaim:
            claimName: {{ $storagePvc }}
@endif
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $storageName }}
  namespace: larakube-shared
  labels:{!! $labelsFor('storage') !!}
spec:
  selector:
    app: {{ $storageName }}
  ports:
    - port: 5000
      targetPort: 5000
      name: http
---
# Studio's saved SQL snippets.
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $snippetsPvc }}
  namespace: larakube-shared
  labels:{!! $labelsFor('studio') !!}
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 256Mi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $studioName }}
  namespace: larakube-shared
  labels:{!! $labelsFor('studio') !!}
spec:
  replicas: 1
  # Its volumes attach to one node: stop the old pod before starting the new one.
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $studioName }}
  template:
    metadata:
      labels:
        app: {{ $studioName }}{!! $podLabelsFor('studio') !!}
    spec:
      containers:
        - name: supabase-studio
          image: {{ $tool->vendor()->image('studio') }}
          ports:
            - containerPort: 3000
              name: http
          env:
            # Next.js binds to $HOSTNAME, which Kubernetes sets to the pod name.
            - name: HOSTNAME
              value: "0.0.0.0"
            - name: STUDIO_PG_META_URL
              value: "http://{{ $metaName }}:8080"
            - name: POSTGRES_PORT
              value: "5432"
            - name: POSTGRES_HOST
              value: {{ $dbHost }}
            - name: POSTGRES_DB
              value: postgres
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: POSTGRES_USER_READ_WRITE
              value: postgres
            - name: PG_META_CRYPTO_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: pg-meta-crypto-key
            - name: PGRST_DB_SCHEMAS
              value: "public,graphql_public"
            - name: PGRST_DB_MAX_ROWS
              value: "1000"
            - name: PGRST_DB_EXTRA_SEARCH_PATH
              value: public
            # No Logflare/Vector in this stack, so Studio's log pages stay off.
            - name: ENABLED_FEATURES_LOGS_ALL
              value: "false"
            - name: SNIPPETS_MANAGEMENT_FOLDER
              value: /app/snippets
@if($withFunctions ?? true)
            - name: EDGE_FUNCTIONS_MANAGEMENT_FOLDER
              value: /app/edge-functions
@endif
            - name: DEFAULT_ORGANIZATION_NAME
              value: LaraKube
            - name: DEFAULT_PROJECT_NAME
              value: Default Project
            - name: SUPABASE_URL
              value: "http://{{ $names->deployment() }}:8000"
            - name: SUPABASE_PUBLIC_URL
              value: "https://{{ $host }}"
            - name: SUPABASE_ANON_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: anon-key
            - name: SUPABASE_SERVICE_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: service-role-key
            - name: AUTH_JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: jwt-secret
            - name: SUPABASE_PUBLISHABLE_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: publishable-key
            - name: SUPABASE_SECRET_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: secret-key
          resources:
            requests:
              cpu: 200m
              memory: 512Mi
            limits:
              cpu: 1000m
              memory: 1Gi
          volumeMounts:
            - name: snippets
              mountPath: /app/snippets
@if($withFunctions ?? true)
            - name: functions
              mountPath: /app/edge-functions
              readOnly: true
@endif
      volumes:
        - name: snippets
          persistentVolumeClaim:
            claimName: {{ $snippetsPvc }}
@if($withFunctions ?? true)
        - name: functions
          persistentVolumeClaim:
            claimName: {{ $names->volume('functions') }}
@endif
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $studioName }}
  namespace: larakube-shared
  labels:{!! $labelsFor('studio') !!}
spec:
  selector:
    app: {{ $studioName }}
  ports:
    - port: 3000
      targetPort: 3000
      name: http
