{{-- The Matrix↔LiveKit bridge. It belongs to neither service on its own: it
     exists only while `meet:wire --tool=chat` says the two are connected, which
     is why it lives here rather than in either tool's own manifest. --}}
@php
    $__tplHash = substr(hash_file('sha256', resource_path('views/k8s/meet/lk-jwt.blade.php')), 0, 12);
@endphp
# lk-jwt-service implements POST /sfu/get at its own root, but Element Call
# always calls {livekit_service_url}/sfu/get — so the /jwt prefix that routes
# here has to be stripped before the request reaches the pod.
apiVersion: traefik.io/v1alpha1
kind: Middleware
metadata:
  name: meet-jwt-stripprefix
  namespace: larakube-shared
spec:
  stripPrefix:
    prefixes:
      - /jwt
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: meet-lk-jwt
  namespace: larakube-shared
  labels:
    app: meet-lk-jwt
    app.kubernetes.io/part-of: meet
spec:
  replicas: 1
  selector:
    matchLabels:
      app: meet-lk-jwt
  template:
    metadata:
      labels:
        app: meet-lk-jwt
      annotations:
        larakube.io/config-checksum: "{{ substr(hash('sha256', $meetHost.$chatHost.$livekitApiKey.$livekitApiSecret.$__tplHash), 0, 16) }}"
    spec:
      containers:
        - name: lk-jwt
          image: ghcr.io/element-hq/lk-jwt-service:0.5.0
          env:
            - name: LIVEKIT_URL
              # Handed straight back to the browser as the room connection URL,
              # not just used for this pod's own server-side calls — so it has
              # to be the public address, never cluster-internal DNS.
              value: "wss://{{ $meetHost }}"
            - name: LIVEKIT_KEY
              value: "{{ $livekitApiKey }}"
            - name: LIVEKIT_SECRET
              value: "{{ $livekitApiSecret }}"
            - name: LIVEKIT_FULL_ACCESS_HOMESERVERS
              value: "{{ $chatHost }}"
          resources:
            requests:
              memory: 32Mi
              cpu: 10m
            limits:
              memory: 128Mi
              cpu: 200m
          ports:
            - containerPort: 8080
---
apiVersion: v1
kind: Service
metadata:
  name: meet-lk-jwt
  namespace: larakube-shared
spec:
  selector:
    app: meet-lk-jwt
  ports:
    - name: http
      port: 8080
      targetPort: 8080
