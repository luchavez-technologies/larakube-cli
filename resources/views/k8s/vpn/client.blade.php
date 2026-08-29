@php($sfx = ($instance ?? '') !== '' ? '-'.$instance : '')
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: vpn-client-storage{{ $sfx }}
  namespace: larakube-vpn
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 128Mi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: vpn-client{{ $sfx }}
  namespace: larakube-vpn
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: vpn-client{{ $sfx }}
  template:
    metadata:
      labels:
        app: vpn-client{{ $sfx }}
    spec:
      containers:
        - name: client
          image: netbirdio/netbird:0.77.1
          securityContext:
            capabilities:
              add: ["NET_ADMIN"]
          env:
            - name: NB_MANAGEMENT_URL
              value: "http://vpn-management{{ $sfx }}:80"
            - name: NB_SETUP_KEY
              valueFrom:
                secretKeyRef:
                  name: vpn-management-secrets{{ $sfx }}
                  key: setup-key
          volumeMounts:
            - name: data
              mountPath: /etc/netbird
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
            claimName: vpn-client-storage{{ $sfx }}
        - name: resolver-config
          configMap:
            name: vpn-resolver-config{{ $sfx }}
