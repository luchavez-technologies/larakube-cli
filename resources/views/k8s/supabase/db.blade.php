@php
    $tool = \App\Enums\ClusterTool::SUPABASE;
    $names = \App\Data\ToolInstance::forInstance($tool, $instance);
    $dbName = $names->name('db');
    $dbPvc = $names->volume('db');
    $pgsodiumPvc = $names->volume('pgsodium');
    $configName = $names->name('db-init');
    $secretName = $names->secret();
    $labels = '';
    foreach ($names->labels('db') as $k => $v) {
        $labels .= "\n    {$k}: {$v}";
    }
    $podLabels = str_replace("\n    ", "\n        ", $labels);
    $sqlFiles = ['_supabase.sql', 'jwt.sql', 'logs.sql', 'pooler.sql', 'realtime.sql', 'roles.sql', 'webhooks.sql'];
@endphp
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $dbPvc }}
  namespace: larakube-shared
  labels:{!! $labels !!}
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: {{ $dbStorageSize ?? '5Gi' }}
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $pgsodiumPvc }}
  namespace: larakube-shared
  labels:{!! $labels !!}
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: 256Mi
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ $configName }}
  namespace: larakube-shared
  labels:{!! $labels !!}
data:
@foreach($sqlFiles as $file)
  {{ $file }}: |
@foreach(explode("\n", (string) file_get_contents(resource_path('stubs/supabase/db/'.$file))) as $line)
    {!! $line !!}
@endforeach
@endforeach
---
apiVersion: apps/v1
kind: StatefulSet
metadata:
  name: {{ $dbName }}
  namespace: larakube-shared
  labels:{!! $labels !!}
spec:
  serviceName: {{ $dbName }}
  replicas: 1
  selector:
    matchLabels:
      app: {{ $dbName }}
  template:
    metadata:
      labels:
        app: {{ $dbName }}{!! $podLabels !!}
    spec:
      containers:
        - name: supabase-db
          image: {{ $tool->vendor()->image('db') }}
          ports:
            - containerPort: 5432
              name: postgres
          env:
            - name: POSTGRES_HOST
              value: /var/run/postgresql
            - name: POSTGRES_PORT
              value: "5432"
            - name: POSTGRES_DB
              value: postgres
            - name: POSTGRES_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: db-password
            - name: JWT_SECRET
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: jwt-secret
            - name: JWT_EXP
              value: "3600"
          resources:
            requests:
              cpu: 500m
              memory: 1Gi
            limits:
              cpu: 2000m
              memory: 2Gi
          readinessProbe:
            exec:
              command: ["pg_isready", "-U", "postgres", "-h", "localhost"]
            initialDelaySeconds: 10
            periodSeconds: 5
          volumeMounts:
            - name: db-data
              mountPath: /var/lib/postgresql/data
            - name: pgsodium-config
              mountPath: /etc/postgresql-custom
            - name: db-init
              mountPath: /docker-entrypoint-initdb.d/migrations/99-roles.sql
              subPath: roles.sql
            - name: db-init
              mountPath: /docker-entrypoint-initdb.d/migrations/99-realtime.sql
              subPath: realtime.sql
            - name: db-init
              mountPath: /docker-entrypoint-initdb.d/init-scripts/98-webhooks.sql
              subPath: webhooks.sql
            - name: db-init
              mountPath: /docker-entrypoint-initdb.d/init-scripts/99-jwt.sql
              subPath: jwt.sql
            - name: db-init
              mountPath: /docker-entrypoint-initdb.d/init-scripts/99-logs.sql
              subPath: logs.sql
            - name: db-init
              mountPath: /docker-entrypoint-initdb.d/init-scripts/99-pooler.sql
              subPath: pooler.sql
            - name: db-init
              mountPath: /docker-entrypoint-initdb.d/init-scripts/97-_supabase.sql
              subPath: _supabase.sql
      volumes:
        - name: db-data
          persistentVolumeClaim:
            claimName: {{ $dbPvc }}
        - name: pgsodium-config
          persistentVolumeClaim:
            claimName: {{ $pgsodiumPvc }}
        - name: db-init
          configMap:
            name: {{ $configName }}
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $dbName }}
  namespace: larakube-shared
  labels:{!! $labels !!}
spec:
  selector:
    app: {{ $dbName }}
  ports:
    - port: 5432
      targetPort: 5432
      name: postgres
