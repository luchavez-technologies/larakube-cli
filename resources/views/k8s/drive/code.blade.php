{{-- Collabora Online Development Edition — the WOPI app that oCIS's
     collaboration bridge brokers. CODE is the free rolling build (MPLv2
     source; binaries add their own terms), positioned by Collabora for home
     use and small teams with no SLA — see plans/active for the licensing and
     concurrency detail behind choosing it over the ONLYOFFICE lineage. --}}
apiVersion: apps/v1
kind: Deployment
metadata:
  name: drive-code
  namespace: larakube-shared
  labels:
    app: drive-code
    larakube-tool: drive-code
spec:
  replicas: 1
  selector:
    matchLabels:
      app: drive-code
  template:
    metadata:
      labels:
        app: drive-code
    spec:
      containers:
        - name: code
          image: {{ $codeImage }}
          env:
            # Traefik terminates TLS, so CODE serves plain HTTP; ssl.termination
            # tells it the hop it cannot see was secure, otherwise it builds
            # http:// URLs the browser then blocks as mixed content.
            - name: DONT_GEN_SSL_CERT
              value: "YES"
            - name: extra_params
              value: "--o:ssl.enable=false --o:ssl.termination=true --o:welcome.enable=false --o:net.frame_ancestors={{ $host }}"
            - name: username
              value: "admin"
            - name: password
              valueFrom:
                secretKeyRef:
                  name: drive-office-secrets
                  key: code-admin-password
          ports:
            - containerPort: 9980
          readinessProbe:
            httpGet:
              path: /hosting/discovery
              port: 9980
            initialDelaySeconds: 10
            periodSeconds: 10
          livenessProbe:
            httpGet:
              path: /hosting/discovery
              port: 9980
            initialDelaySeconds: 60
            periodSeconds: 30
          resources:
            requests:
              cpu: "100m"
              memory: "512Mi"
            limits:
              memory: "1536Mi"
---
apiVersion: v1
kind: Service
metadata:
  name: drive-code
  namespace: larakube-shared
spec:
  selector:
    app: drive-code
  ports:
    - port: 80
      targetPort: 9980
---
@include('k8s.drive.ingress', ['engine' => 'code', 'host' => $officeHost])
