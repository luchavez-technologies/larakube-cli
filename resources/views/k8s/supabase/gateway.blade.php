@php
    $tool = \App\Enums\ClusterTool::SUPABASE;
    $names = \App\Data\ToolInstance::forInstance($tool, $instance);
    $gatewayName = $names->deployment();
    $configName = $names->name('envoy-config');
    $secretName = $names->secret();
    $labels = '';
    foreach ($names->labels('gateway') as $k => $v) {
        $labels .= "\n    {$k}: {$v}";
    }
    $podLabels = str_replace("\n    ", "\n        ", $labels);

    $cdsRaw = (string) file_get_contents(resource_path('stubs/supabase/envoy/cds.yaml'));
    $cds = strtr($cdsRaw, [
        'address: auth' => 'address: '.$names->name('auth'),
        'address: rest' => 'address: '.$names->name('rest'),
        'address: realtime-dev.supabase-realtime' => 'address: '.$names->name('realtime'),
        'address: storage' => 'address: '.$names->name('storage'),
        'address: functions' => 'address: '.$names->name('functions'),
        'address: meta' => 'address: '.$names->name('meta'),
        'address: studio' => 'address: '.$names->name('studio'),
    ]);
    $envoyYaml = (string) file_get_contents(resource_path('stubs/supabase/envoy/envoy.yaml'));
    $ldsTemplate = (string) file_get_contents(resource_path('stubs/supabase/envoy/lds.template.yaml'));
@endphp
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ $configName }}
  namespace: larakube-shared
  labels:{!! $labels !!}
data:
  envoy.yaml: |
@foreach(explode("\n", $envoyYaml) as $line)
    {!! $line !!}
@endforeach
  cds.yaml: |
@foreach(explode("\n", $cds) as $line)
    {!! $line !!}
@endforeach
  lds.template.yaml: |
@foreach(explode("\n", $ldsTemplate) as $line)
    {!! $line !!}
@endforeach
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $gatewayName }}
  namespace: larakube-shared
  labels:{!! $labels !!}
spec:
  replicas: 1
  selector:
    matchLabels:
      app: {{ $gatewayName }}
  template:
    metadata:
      labels:
        app: {{ $gatewayName }}{!! $podLabels !!}
    spec:
      containers:
        - name: supabase-envoy
          image: {{ $tool->vendor()->image('gateway') }}
          command:
            - sh
            - -c
            - |
              cp /etc/envoy-templates/envoy.yaml /etc/envoy/envoy.yaml
              cp /etc/envoy-templates/cds.yaml /etc/envoy/cds.yaml
              PASSWORD_HASH=$(printf '%s' "${DASHBOARD_PASSWORD}" | openssl sha1 -binary | openssl base64)
              DASHBOARD_BASIC_AUTH="${DASHBOARD_USERNAME}:{SHA}${PASSWORD_HASH}"
              sed -e "s|\${ANON_KEY}|${ANON_KEY}|g" \
                  -e "s|\${ANON_KEY_ASYMMETRIC}|${ANON_KEY_ASYMMETRIC:-}|g" \
                  -e "s|\${SERVICE_ROLE_KEY}|${SERVICE_ROLE_KEY}|g" \
                  -e "s|\${SERVICE_ROLE_KEY_ASYMMETRIC}|${SERVICE_ROLE_KEY_ASYMMETRIC:-}|g" \
                  -e "s|\${SUPABASE_PUBLISHABLE_KEY}|${SUPABASE_PUBLISHABLE_KEY}|g" \
                  -e "s|\${SUPABASE_SECRET_KEY}|${SUPABASE_SECRET_KEY}|g" \
                  -e "s|\${SUPABASE_PUBLIC_URL}|${SUPABASE_PUBLIC_URL}|g" \
                  -e "s|\${DASHBOARD_BASIC_AUTH}|${DASHBOARD_BASIC_AUTH}|g" \
                  /etc/envoy-templates/lds.template.yaml > /etc/envoy/lds.yaml
              exec envoy -c /etc/envoy/envoy.yaml
          ports:
            - containerPort: 8000
              name: http
          env:
            - name: ANON_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: anon-key
            - name: SERVICE_ROLE_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: service-role-key
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
            - name: DASHBOARD_USERNAME
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: dashboard-username
            - name: DASHBOARD_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: {{ $secretName }}
                  key: dashboard-password
            - name: SUPABASE_PUBLIC_URL
              value: "https://{{ $host }}"
          resources:
            requests:
              cpu: 100m
              memory: 128Mi
            limits:
              cpu: 500m
              memory: 256Mi
          volumeMounts:
            - name: envoy-templates
              mountPath: /etc/envoy-templates
              readOnly: true
            - name: envoy-runtime
              mountPath: /etc/envoy
      volumes:
        - name: envoy-templates
          configMap:
            name: {{ $configName }}
        - name: envoy-runtime
          emptyDir: {}
---
apiVersion: v1
kind: Service
metadata:
  name: {{ $gatewayName }}
  namespace: larakube-shared
  labels:{!! $labels !!}
spec:
  selector:
    app: {{ $gatewayName }}
  ports:
    - port: 8000
      targetPort: 8000
      name: http
