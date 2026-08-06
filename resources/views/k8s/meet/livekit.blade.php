{{-- Folded into the config-checksum below so a template-text-only edit (no
     variable changed) still forces a running pod to restart — subPath-mounted
     Secrets don't hot-reload, and hashing only the input variables misses
     changes to literal strings in this file. --}}
@php
    $__tplHash = substr(hash_file('sha256', resource_path('views/k8s/meet/livekit.blade.php')), 0, 12);

    $registry = $consumers ?? [];
    ksort($registry);
    // The exact string the checksum is taken over. Sorted above so an unchanged
    // registry always serializes identically — unstable ordering would roll the
    // SFU (dropping every live call) on unrelated re-runs.
    $registryJson = (string) json_encode($registry, JSON_UNESCAPED_SLASHES);

    // LiveKit accepts ONE webhook signing key but many URLs, and delivers every
    // event to every URL. Signing with a consumer's own key means only that
    // consumer can verify — so webhooks are wired only while exactly one
    // consumer wants them. See docs/decisions/0009-*.md.
    $webhookConsumers = array_filter($registry, fn (array $c) => ($c['webhookUrl'] ?? null) !== null);
    $webhookSigner = count($webhookConsumers) === 1 ? reset($webhookConsumers) : null;
@endphp
apiVersion: v1
kind: Secret
metadata:
  name: meet-livekit-config
  namespace: larakube-shared
type: Opaque
stringData:
  livekit.yaml: |
    port: 7880
    rtc:
      tcp_port: 7881
      udp_port: 7882
      use_external_ip: true
    keys:
@forelse($registry as $consumer => $creds)
      # {{ $consumer }} (rooms: {{ $creds['roomPrefix'] }}*)
      "{{ $creds['key'] }}": "{{ $creds['secret'] }}"
@empty
      {}
@endforelse
@if($webhookSigner)
    webhook:
      api_key: "{{ $webhookSigner['key'] }}"
      urls:
        - "{{ $webhookSigner['webhookUrl'] }}"
@endif
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: meet-livekit
  namespace: larakube-shared
  labels:
    app: meet-livekit
    app.kubernetes.io/part-of: meet
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: meet-livekit
  template:
    metadata:
      labels:
        app: meet-livekit
      annotations:
        {{-- The registry is in the hash because livekit.yaml bakes every key in:
             without it, wiring a consumer rewrites the Secret and the pod keeps
             serving the old key set, silently rejecting the new credentials. --}}
        larakube.io/config-checksum: "{{ substr(hash('sha256', $registryJson.$host.$__tplHash), 0, 16) }}"
    spec:
      containers:
        - name: livekit
          image: livekit/livekit-server:v1.13.5
          args: ["--config", "/etc/livekit.yaml"]
          {{-- No CPU limit: an SFU's CPU scales with forwarded streams and
               throttling it drops media. Request reserves scheduling weight. --}}
          resources:
            requests:
              memory: 128Mi
              cpu: 100m
            limits:
              memory: 512Mi
          ports:
            - containerPort: 7880
            - { containerPort: 7881, protocol: TCP, @if($hostPort ?? true)hostPort: 7881, @endif name: rtc-tcp }
            - { containerPort: 7882, protocol: UDP, @if($hostPort ?? true)hostPort: 7882, @endif name: rtc-udp }
          volumeMounts:
            - name: config
              mountPath: /etc/livekit.yaml
              subPath: livekit.yaml
      volumes:
        - name: config
          secret:
            secretName: meet-livekit-config
---
# Signaling only (WS/HTTP) — always ClusterIP, fronted by the Traefik Ingress.
apiVersion: v1
kind: Service
metadata:
  name: meet-livekit
  namespace: larakube-shared
spec:
  selector:
    app: meet-livekit
  ports:
    - name: http
      port: 7880
      targetPort: 7880
    - name: rtc-tcp
      port: 7881
      targetPort: 7881
---
# RTC media (single-port UDP mux). hostPort on a single-node VPS where klipper
# binds it directly; a real LoadBalancer on managed K8s.
apiVersion: v1
kind: Service
metadata:
  name: meet-livekit-rtc
  namespace: larakube-shared
spec:
  selector:
    app: meet-livekit
  ports:
    - name: rtc-udp
      protocol: UDP
      port: 7882
      targetPort: 7882
  type: {{ ($hostPort ?? true) ? 'ClusterIP' : 'LoadBalancer' }}
