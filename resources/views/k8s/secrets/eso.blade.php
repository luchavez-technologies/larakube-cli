@php
    $namespace = $namespace ?? 'larakube-secrets';
    $esoVersion = 'v0.16.2';
    $esoImage = "oci.external-secrets.io/external-secrets/external-secrets:{$esoVersion}";
@endphp
{{--
    Vendored from the official release bundle
    (https://github.com/external-secrets/external-secrets/releases/download/{{ $esoVersion }}/external-secrets.yaml),
    reconciled by hand: every hardcoded `namespace: default` became
    {{ '{{ $namespace }}' }}, and the three optional aggregate-to-view/edit/admin
    ClusterRoles (convenience RBAC for humans/other tooling, not needed for ESO's
    own operation) were dropped to keep this to what's functionally required.

    v0.16.2+ ships a ValidatingWebhookConfiguration for SecretStore/
    ClusterSecretStore/ExternalSecret with `failurePolicy` defaulting to `Fail`
    (the admissionregistration.k8s.io/v1 API default when unset — confirmed via
    the official manifest itself). Skipping the webhook + cert-controller here
    would silently reject every `kubectl apply` of an ExternalSecret/SecretStore
    manifest across every tool the moment the webhook config exists but nothing
    serves it — this is why the deployment grew from one controller Deployment
    (v0.11.0) to three (controller, webhook, cert-controller).
--}}
apiVersion: v1
kind: ServiceAccount
metadata:
  name: external-secrets
  namespace: {{ $namespace }}
---
apiVersion: v1
kind: ServiceAccount
metadata:
  name: external-secrets-webhook
  namespace: {{ $namespace }}
---
apiVersion: v1
kind: ServiceAccount
metadata:
  name: external-secrets-cert-controller
  namespace: {{ $namespace }}
---
{{-- Populated by the cert-controller (PATCHed in place, not written here) — it
     also injects the matching caBundle into the two ValidatingWebhookConfiguration
     objects below via the "update"/"patch" grant scoped to their exact names in
     the cert-controller ClusterRole. --}}
apiVersion: v1
kind: Secret
metadata:
  name: external-secrets-webhook
  namespace: {{ $namespace }}
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: external-secrets-controller
rules:
  - apiGroups: ["external-secrets.io"]
    resources: ["secretstores", "clustersecretstores", "externalsecrets", "clusterexternalsecrets", "pushsecrets", "clusterpushsecrets"]
    verbs: ["get", "list", "watch"]
  - apiGroups: ["external-secrets.io"]
    resources: ["externalsecrets", "externalsecrets/status", "externalsecrets/finalizers", "secretstores", "secretstores/status", "secretstores/finalizers", "clustersecretstores", "clustersecretstores/status", "clustersecretstores/finalizers", "clusterexternalsecrets", "clusterexternalsecrets/status", "clusterexternalsecrets/finalizers", "pushsecrets", "pushsecrets/status", "pushsecrets/finalizers", "clusterpushsecrets", "clusterpushsecrets/status", "clusterpushsecrets/finalizers"]
    verbs: ["get", "update", "patch"]
  - apiGroups: ["external-secrets.io"]
    resources: ["externalsecrets"]
    verbs: ["create", "update", "delete"]
  - apiGroups: ["external-secrets.io"]
    resources: ["pushsecrets"]
    verbs: ["create", "update", "delete"]
  - apiGroups: ["generators.external-secrets.io"]
    resources: ["generatorstates"]
    verbs: ["get", "list", "watch", "create", "update", "patch", "delete", "deletecollection"]
  - apiGroups: ["generators.external-secrets.io"]
    resources: ["acraccesstokens", "clustergenerators", "ecrauthorizationtokens", "fakes", "gcraccesstokens", "githubaccesstokens", "quayaccesstokens", "passwords", "stssessiontokens", "uuids", "vaultdynamicsecrets", "webhooks", "grafanas"]
    verbs: ["get", "list", "watch"]
  - apiGroups: [""]
    resources: ["serviceaccounts", "namespaces"]
    verbs: ["get", "list", "watch"]
  - apiGroups: [""]
    resources: ["configmaps"]
    verbs: ["get", "list", "watch"]
  - apiGroups: [""]
    resources: ["secrets"]
    verbs: ["get", "list", "watch", "create", "update", "delete", "patch"]
  - apiGroups: [""]
    resources: ["serviceaccounts/token"]
    verbs: ["create"]
  - apiGroups: [""]
    resources: ["events"]
    verbs: ["create", "patch"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: external-secrets-cert-controller
rules:
  - apiGroups: ["apiextensions.k8s.io"]
    resources: ["customresourcedefinitions"]
    verbs: ["get", "list", "watch", "update", "patch"]
  - apiGroups: ["admissionregistration.k8s.io"]
    resources: ["validatingwebhookconfigurations"]
    verbs: ["list", "watch", "get"]
  - apiGroups: ["admissionregistration.k8s.io"]
    resources: ["validatingwebhookconfigurations"]
    resourceNames: ["secretstore-validate", "externalsecret-validate"]
    verbs: ["update", "patch"]
  - apiGroups: [""]
    resources: ["endpoints"]
    verbs: ["list", "get", "watch"]
  - apiGroups: [""]
    resources: ["events"]
    verbs: ["create", "patch"]
  - apiGroups: [""]
    resources: ["secrets"]
    verbs: ["get", "list", "watch", "update", "patch"]
  - apiGroups: ["coordination.k8s.io"]
    resources: ["leases"]
    verbs: ["get", "create", "update", "patch"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: external-secrets-controller
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: external-secrets-controller
subjects:
  - kind: ServiceAccount
    name: external-secrets
    namespace: {{ $namespace }}
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: external-secrets-cert-controller
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: external-secrets-cert-controller
subjects:
  - kind: ServiceAccount
    name: external-secrets-cert-controller
    namespace: {{ $namespace }}
---
apiVersion: rbac.authorization.k8s.io/v1
kind: Role
metadata:
  name: external-secrets-leaderelection
  namespace: {{ $namespace }}
rules:
  - apiGroups: [""]
    resources: ["configmaps"]
    resourceNames: ["external-secrets-controller"]
    verbs: ["get", "update", "patch"]
  - apiGroups: [""]
    resources: ["configmaps"]
    verbs: ["create"]
  - apiGroups: ["coordination.k8s.io"]
    resources: ["leases"]
    verbs: ["get", "create", "update", "patch"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: RoleBinding
metadata:
  name: external-secrets-leaderelection
  namespace: {{ $namespace }}
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: Role
  name: external-secrets-leaderelection
subjects:
  - kind: ServiceAccount
    name: external-secrets
    namespace: {{ $namespace }}
---
apiVersion: v1
kind: Service
metadata:
  name: external-secrets-webhook
  namespace: {{ $namespace }}
  labels:
    app: external-secrets-webhook
spec:
  type: ClusterIP
  ports:
    - port: 443
      targetPort: 10250
      protocol: TCP
      name: webhook
  selector:
    app: external-secrets-webhook
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: external-secrets
  namespace: {{ $namespace }}
  labels:
    app: external-secrets
spec:
  replicas: 1
  selector:
    matchLabels:
      app: external-secrets
  template:
    metadata:
      labels:
        app: external-secrets
    spec:
      serviceAccountName: external-secrets
      containers:
        - name: external-secrets
          image: {{ $esoImage }}
          args:
            - --concurrent=1
            - --metrics-addr=:8080
            - --loglevel=info
            - --zap-time-encoding=epoch
          resources:
            requests:
              cpu: 10m
              memory: 32Mi
            limits:
              cpu: 100m
              memory: 128Mi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: external-secrets-cert-controller
  namespace: {{ $namespace }}
  labels:
    app: external-secrets-cert-controller
spec:
  replicas: 1
  selector:
    matchLabels:
      app: external-secrets-cert-controller
  template:
    metadata:
      labels:
        app: external-secrets-cert-controller
    spec:
      serviceAccountName: external-secrets-cert-controller
      containers:
        - name: cert-controller
          image: {{ $esoImage }}
          args:
            - certcontroller
            - --crd-requeue-interval=5m
            - --service-name=external-secrets-webhook
            - --service-namespace={{ $namespace }}
            - --secret-name=external-secrets-webhook
            - --secret-namespace={{ $namespace }}
            - --metrics-addr=:8080
            - --healthz-addr=:8081
            - --loglevel=info
            - --zap-time-encoding=epoch
            - --enable-partial-cache=true
          ports:
            - containerPort: 8080
              protocol: TCP
              name: metrics
          readinessProbe:
            httpGet:
              port: 8081
              path: /readyz
            initialDelaySeconds: 20
            periodSeconds: 5
          resources:
            requests:
              cpu: 10m
              memory: 32Mi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: external-secrets-webhook
  namespace: {{ $namespace }}
  labels:
    app: external-secrets-webhook
spec:
  replicas: 1
  selector:
    matchLabels:
      app: external-secrets-webhook
  template:
    metadata:
      labels:
        app: external-secrets-webhook
    spec:
      serviceAccountName: external-secrets-webhook
      containers:
        - name: webhook
          image: {{ $esoImage }}
          args:
            - webhook
            - --port=10250
            - --dns-name=external-secrets-webhook.{{ $namespace }}.svc
            - --cert-dir=/tmp/certs
            - --check-interval=5m
            - --metrics-addr=:8080
            - --healthz-addr=:8081
            - --loglevel=info
            - --zap-time-encoding=epoch
          ports:
            - containerPort: 8080
              protocol: TCP
              name: metrics
            - containerPort: 10250
              protocol: TCP
              name: webhook
          readinessProbe:
            httpGet:
              port: 8081
              path: /readyz
            initialDelaySeconds: 20
            periodSeconds: 5
          volumeMounts:
            - name: certs
              mountPath: /tmp/certs
              readOnly: true
          resources:
            requests:
              cpu: 10m
              memory: 32Mi
      volumes:
        - name: certs
          secret:
            secretName: external-secrets-webhook
---
apiVersion: admissionregistration.k8s.io/v1
kind: ValidatingWebhookConfiguration
metadata:
  name: secretstore-validate
webhooks:
  - name: "validate.secretstore.external-secrets.io"
    rules:
      - apiGroups: ["external-secrets.io"]
        apiVersions: ["v1"]
        operations: ["CREATE", "UPDATE", "DELETE"]
        resources: ["secretstores"]
        scope: "Namespaced"
    clientConfig:
      service:
        namespace: {{ $namespace }}
        name: external-secrets-webhook
        path: /validate-external-secrets-io-v1-secretstore
    admissionReviewVersions: ["v1", "v1beta1"]
    sideEffects: None
    timeoutSeconds: 5
  - name: "validate.clustersecretstore.external-secrets.io"
    rules:
      - apiGroups: ["external-secrets.io"]
        apiVersions: ["v1"]
        operations: ["CREATE", "UPDATE", "DELETE"]
        resources: ["clustersecretstores"]
        scope: "Cluster"
    clientConfig:
      service:
        namespace: {{ $namespace }}
        name: external-secrets-webhook
        path: /validate-external-secrets-io-v1-clustersecretstore
    admissionReviewVersions: ["v1", "v1beta1"]
    sideEffects: None
    timeoutSeconds: 5
---
apiVersion: admissionregistration.k8s.io/v1
kind: ValidatingWebhookConfiguration
metadata:
  name: externalsecret-validate
webhooks:
  - name: "validate.externalsecret.external-secrets.io"
    rules:
      - apiGroups: ["external-secrets.io"]
        apiVersions: ["v1"]
        operations: ["CREATE", "UPDATE", "DELETE"]
        resources: ["externalsecrets"]
        scope: "Namespaced"
    clientConfig:
      service:
        namespace: {{ $namespace }}
        name: external-secrets-webhook
        path: /validate-external-secrets-io-v1-externalsecret
    admissionReviewVersions: ["v1", "v1beta1"]
    sideEffects: None
    timeoutSeconds: 5
    failurePolicy: Fail
