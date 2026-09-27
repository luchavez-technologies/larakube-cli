@php
    // Names from ToolInstance (ADR 0021), same derivation as shared.blade.php.
    $instance = ($instance ?? '') !== ''
        ? $instance
        : \App\Enums\ClusterTool::VPN->instanceSlugFromHost(\App\Data\ToolInstance::normalizeHost((string) ($host ?? '')));
    $names = \App\Data\ToolInstance::forInstance(\App\Enums\ClusterTool::VPN, $instance);

    $client = $names->deployment('client');
    $clientPvc = $names->volume('storage', 'client');
    $resolverConfig = $names->configMap('resolver', 'client');
    $mgmt = $names->deployment();
    $credentials = $names->secret();
    $clientLabels = $names->labels('client');
@endphp
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $clientPvc }}
  namespace: {{ $names->namespace() }}
  labels:
@foreach($clientLabels as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: {{ $volumeSize($clientPvc, '128Mi', false) }}
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ $client }}
  namespace: {{ $names->namespace() }}
  labels:
    app: {{ $client }}
@foreach($clientLabels as $key => $value)
    {{ $key }}: {{ $value }}
@endforeach
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: {{ $client }}
  template:
    metadata:
      labels:
        app: {{ $client }}
@foreach($clientLabels as $key => $value)
        {{ $key }}: {{ $value }}
@endforeach
    spec:
      containers:
        - name: client
          image: netbirdio/netbird:0.77.1
          securityContext:
            capabilities:
              add: ["NET_ADMIN"]
          env:
            - name: NB_MANAGEMENT_URL
              value: "http://{{ $mgmt }}:80"
            - name: NB_SETUP_KEY
              valueFrom:
                secretKeyRef:
                  name: {{ $credentials }}
                  key: setup-key
          volumeMounts:
            {{-- /var/lib/netbird, NOT /etc/netbird. 0.77 keeps the peer's
                 identity here -- default.json holds the key, alongside
                 state.json and active_profile.json. /etc/netbird was empty on a
                 live pod (2026-08-30), so the PVC persisted nothing and every
                 restart registered a BRAND NEW peer: a fresh overlay address,
                 the previous peer orphaned and disconnected forever, and any
                 split-DNS record pointing at an address that no longer
                 answers. --}}
            - name: data
              mountPath: /var/lib/netbird
        - name: ingress-proxy
          image: alpine/socat:1.8.1.3
          command: ["/bin/sh", "-c"]
          args:
            - |
              HOST="traefik.traefik.svc.cluster.local"
              nslookup $HOST > /dev/null 2>&1 || HOST="traefik.kube-system.svc.cluster.local"
              socat TCP-LISTEN:80,fork,reuseaddr TCP:$HOST:80 &
              socat TCP-LISTEN:443,fork,reuseaddr TCP:$HOST:443 &
              wait
        {{-- Split-DNS for VPN-only hosts. Lives on this pod because the
             gateway peer's overlay address is the only address a remote peer
             can route to, and a nameserver group can only point at one. --}}
        - name: resolver
          image: coredns/coredns:1.14.6
          args: ["-conf", "/etc/coredns/Corefile"]
          ports:
            - containerPort: 5353
              protocol: UDP
              name: dns
          volumeMounts:
            - name: resolver-config
              mountPath: /etc/coredns
          readinessProbe:
            tcpSocket:
              port: 5353
            initialDelaySeconds: 5
            periodSeconds: 10
          resources:
            requests:
              memory: 32Mi
              cpu: 10m
            limits:
              memory: 128Mi
      volumes:
        - name: data
          persistentVolumeClaim:
            claimName: {{ $clientPvc }}
        - name: resolver-config
          configMap:
            name: {{ $resolverConfig }}
