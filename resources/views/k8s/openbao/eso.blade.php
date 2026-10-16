@php
    $namespace = $namespace ?? 'larakube-secrets';
    $esoVersion = 'v2.11.0';
    $esoImage = "oci.external-secrets.io/external-secrets/external-secrets:{$esoVersion}";
@endphp
{{--
    Vendored from the official release bundle
    (https://github.com/external-secrets/external-secrets/releases/download/{{ '{{ $esoVersion }}' }}/external-secrets.yaml),
    reconciled by hand: every hardcoded `namespace: default` became
    {{ '{{ $namespace }}' }}, and the three optional aggregate-to-view/edit/admin
    ClusterRoles (convenience RBAC for humans/other tooling, not needed for ESO's
    own operation) were dropped to keep this to what's functionally required.

    The bundle ships a ValidatingWebhookConfiguration for SecretStore/
    ClusterSecretStore/ExternalSecret with `failurePolicy` defaulting to `Fail`
    (the admissionregistration.k8s.io/v1 API default when unset). Skipping the
    webhook + cert-controller here would silently reject every `kubectl apply`
    of an ExternalSecret/SecretStore manifest across every tool the moment the
    webhook config exists but nothing serves it — this is why the deployment is
    three Deployments (controller, webhook, cert-controller), not one.

    ExternalSecret/SecretStore are served as v1 only; v1beta1 exists in the CRDs
    but is not served, so every manifest this CLI writes must be v1. Generators
    (VaultDynamicSecret and friends) stay on generators.external-secrets.io/v1alpha1.
--}}
# Source: external-secrets/templates/cert-controller-serviceaccount.yaml
apiVersion: v1
kind: ServiceAccount
metadata:
  name: external-secrets-cert-controller
  namespace: {{ $namespace }}
  labels:
    
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets-cert-controller
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
---
# Source: external-secrets/templates/serviceaccount.yaml
apiVersion: v1
kind: ServiceAccount
metadata:
  name: external-secrets
  namespace: {{ $namespace }}
  labels:
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
---
# Source: external-secrets/templates/webhook-serviceaccount.yaml
apiVersion: v1
kind: ServiceAccount
metadata:
  name: external-secrets-webhook
  namespace: {{ $namespace }}
  labels:
    
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets-webhook
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
---
# Source: external-secrets/templates/webhook-secret.yaml
apiVersion: v1
kind: Secret
metadata:
  name: external-secrets-webhook
  namespace: {{ $namespace }}
  labels:
    
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets-webhook
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
    external-secrets.io/component: webhook
---
# Source: external-secrets/templates/cert-controller-rbac.yaml
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: external-secrets-cert-controller
  labels:
    
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets-cert-controller
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
rules:
  - apiGroups:
    - "apiextensions.k8s.io"
    resources:
    - "customresourcedefinitions"
    verbs:
    - "get"
    - "list"
    - "watch"
  - apiGroups:
    - "apiextensions.k8s.io"
    resources:
    - "customresourcedefinitions"
    resourceNames:
    - "externalsecrets.external-secrets.io"
    - "secretstores.external-secrets.io"
    - "clustersecretstores.external-secrets.io"
    verbs:
    - "update"
    - "patch"
  - apiGroups:
    - "admissionregistration.k8s.io"
    resources:
    - "validatingwebhookconfigurations"
    verbs:
    - "list"
    - "watch"
    - "get"
  - apiGroups:
    - "admissionregistration.k8s.io"
    resources:
    - "validatingwebhookconfigurations"
    resourceNames:
    - "secretstore-validate"
    - "externalsecret-validate"
    verbs:
    - "update"
    - "patch"
  - apiGroups:
    - ""
    resources:
    - "endpoints"
    verbs:
    - "list"
    - "get"
    - "watch"
  - apiGroups:
    - "discovery.k8s.io"
    resources:
    - "endpointslices"
    verbs:
    - "list"
    - "get"
    - "watch"
  - apiGroups:
    - ""
    resources:
    - "events"
    verbs:
    - "create"
    - "patch"
  - apiGroups:
    - ""
    resources:
    - "secrets"
    verbs:
    - "get"
    - "list"
    - "watch"
  - apiGroups:
    - ""
    resources:
    - "secrets"
    resourceNames:
    - "external-secrets-webhook"
    verbs:
    - "update"
    - "patch"
  - apiGroups:
    - "coordination.k8s.io"
    resources:
    - "leases"
    verbs:
    - "get"
    - "create"
    - "update"
    - "patch"
---
# Source: external-secrets/templates/rbac.yaml
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: external-secrets-controller
  labels:
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
rules:
  - apiGroups:
    - "external-secrets.io"
    resources:
    - "secretstores"
    - "clustersecretstores"
    - "externalsecrets"
    - "clusterexternalsecrets"
    - "pushsecrets"
    - "clusterpushsecrets"
    verbs:
    - "get"
    - "list"
    - "watch"
  - apiGroups:
    - "external-secrets.io"
    resources:
    - "externalsecrets"
    - "externalsecrets/status"
    - "externalsecrets/finalizers"
    - "secretstores"
    - "secretstores/status"
    - "secretstores/finalizers"
    - "clustersecretstores"
    - "clustersecretstores/status"
    - "clustersecretstores/finalizers"
    - "clusterexternalsecrets"
    - "clusterexternalsecrets/status"
    - "clusterexternalsecrets/finalizers"
    - "pushsecrets"
    - "pushsecrets/status"
    - "pushsecrets/finalizers"
    - "clusterpushsecrets"
    - "clusterpushsecrets/status"
    - "clusterpushsecrets/finalizers"
    verbs:
    - "get"
    - "update"
    - "patch"
  - apiGroups:
    - "generators.external-secrets.io"
    resources:
    - "generatorstates"
    verbs:
    - "get"
    - "list"
    - "watch"
    - "create"
    - "update"
    - "patch"
    - "delete"
    - "deletecollection"
  - apiGroups:
    - "generators.external-secrets.io"
    resources:
    - "acraccesstokens"
    - "cloudsmithaccesstokens"
    - "clustergenerators"
    - "ecrauthorizationtokens"
    - "fakes"
    - "gcraccesstokens"
    - "githubaccesstokens"
    - "gitlabdeploytokens"
    - "quayaccesstokens"
    - "passwords"
    - "sshkeys"
    - "stssessiontokens"
    - "uuids"
    - "vaultdynamicsecrets"
    - "webhooks"
    - "grafanas"
    - "mfas"
    - "beyondtrustworkloadcredentialsdynamicsecrets"
    verbs:
    - "get"
    - "list"
    - "watch"
  - apiGroups:
    - ""
    resources:
    - "serviceaccounts"
    - "namespaces"
    verbs:
    - "get"
    - "list"
    - "watch"
  - apiGroups:
    - ""
    resources:
    - "namespaces"
    verbs:
    - "update"
    - "patch"
  - apiGroups:
    - ""
    resources:
    - "configmaps"
    verbs:
    - "get"
    - "list"
    - "watch"
  - apiGroups:
    - ""
    resources:
    - "secrets"
    verbs:
    - "get"
    - "list"
    - "watch"
    - "create"
    - "update"
    - "delete"
    - "patch"
  - apiGroups:
    - ""
    resources:
    - "serviceaccounts/token"
    verbs:
    - "create"
  - apiGroups:
    - ""
    resources:
    - "events"
    verbs:
    - "create"
    - "patch"
  - apiGroups:
    - "external-secrets.io"
    resources:
    - "externalsecrets"
    verbs:
    - "create"
    - "update"
    - "delete"
  - apiGroups:
    - "external-secrets.io"
    resources:
    - "pushsecrets"
    verbs:
    - "create"
    - "update"
    - "delete"
---
# Source: external-secrets/templates/cert-controller-rbac.yaml
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: external-secrets-cert-controller
  labels:
    
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets-cert-controller
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: external-secrets-cert-controller
subjects:
  - name: external-secrets-cert-controller
    namespace: {{ $namespace }}
    kind: ServiceAccount
---
# Source: external-secrets/templates/rbac.yaml
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: external-secrets-controller
  labels:
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: external-secrets-controller
subjects:
  - name: external-secrets
    namespace: {{ $namespace }}
    kind: ServiceAccount
---
# Source: external-secrets/templates/rbac.yaml
apiVersion: rbac.authorization.k8s.io/v1
kind: Role
metadata:
  name: external-secrets-leaderelection
  namespace: {{ $namespace }}
  labels:
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
rules:
  - apiGroups:
    - ""
    resources:
    - "configmaps"
    resourceNames:
    - "external-secrets-controller"
    verbs:
    - "get"
    - "update"
    - "patch"
  - apiGroups:
    - ""
    resources:
    - "configmaps"
    verbs:
    - "create"
  - apiGroups:
    - "coordination.k8s.io"
    resources:
    - "leases"
    verbs:
    - "get"
    - "create"
    - "update"
    - "patch"
---
# Source: external-secrets/templates/rbac.yaml
apiVersion: rbac.authorization.k8s.io/v1
kind: RoleBinding
metadata:
  name: external-secrets-leaderelection
  namespace: {{ $namespace }}
  labels:
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: Role
  name: external-secrets-leaderelection
subjects:
  - kind: ServiceAccount
    name: external-secrets
    namespace: {{ $namespace }}
---
# Source: external-secrets/templates/webhook-service.yaml
apiVersion: v1
kind: Service
metadata:
  name: external-secrets-webhook
  namespace: {{ $namespace }}
  labels:
    
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets-webhook
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
    external-secrets.io/component: webhook
  
spec:
  type: ClusterIP
  ports:
  - port: 443
    targetPort: webhook
    protocol: TCP
    name: webhook
  selector:
    app.kubernetes.io/name: external-secrets-webhook
    app.kubernetes.io/instance: external-secrets
---
# Source: external-secrets/templates/cert-controller-deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: external-secrets-cert-controller
  namespace: {{ $namespace }}
  labels:
    
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets-cert-controller
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
spec:
  replicas: 1
  revisionHistoryLimit: 10
  selector:
    matchLabels:
      app.kubernetes.io/name: external-secrets-cert-controller
      app.kubernetes.io/instance: external-secrets
  template:
    metadata:
      labels:
        
        helm.sh/chart: external-secrets-v2.11.0
        app.kubernetes.io/name: external-secrets-cert-controller
        app.kubernetes.io/instance: external-secrets
        app.kubernetes.io/version: "v2.11.0"
        app.kubernetes.io/managed-by: Helm
    spec:
      serviceAccountName: external-secrets-cert-controller
      automountServiceAccountToken: true
      hostNetwork: false
      containers:
        - name: cert-controller
          securityContext:
            allowPrivilegeEscalation: false
            capabilities:
              drop:
              - ALL
            readOnlyRootFilesystem: true
            runAsNonRoot: true
            runAsUser: 1000
            seccompProfile:
              type: RuntimeDefault
          image: {{ $esoImage }}
          imagePullPolicy: IfNotPresent
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
            - containerPort: 8081
              protocol: TCP
              name: ready
          readinessProbe:
            httpGet:
              port: ready
              path: /readyz
            initialDelaySeconds: 20
            periodSeconds: 5
            timeoutSeconds: 5
            failureThreshold: 3
            successThreshold: 1
---
# Source: external-secrets/templates/deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: external-secrets
  namespace: {{ $namespace }}
  labels:
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
spec:
  replicas: 1
  revisionHistoryLimit: 10
  selector:
    matchLabels:
      app.kubernetes.io/name: external-secrets
      app.kubernetes.io/instance: external-secrets
  template:
    metadata:
      labels:
        helm.sh/chart: external-secrets-v2.11.0
        app.kubernetes.io/name: external-secrets
        app.kubernetes.io/instance: external-secrets
        app.kubernetes.io/version: "v2.11.0"
        app.kubernetes.io/managed-by: Helm
    spec:
      serviceAccountName: external-secrets
      automountServiceAccountToken: true
      hostNetwork: false
      containers:
        - name: external-secrets
          securityContext:
            allowPrivilegeEscalation: false
            capabilities:
              drop:
              - ALL
            readOnlyRootFilesystem: true
            runAsNonRoot: true
            runAsUser: 1000
            seccompProfile:
              type: RuntimeDefault
          image: {{ $esoImage }}
          imagePullPolicy: IfNotPresent
          args:
          - --concurrent=1
          - --metrics-addr=:8080
          - --loglevel=info
          - --zap-time-encoding=epoch
          ports:
            - containerPort: 8080
              protocol: TCP
              name: metrics
          resources:
            requests:
              cpu: 10m
              memory: 32Mi
      dnsPolicy: ClusterFirst
---
# Source: external-secrets/templates/webhook-deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: external-secrets-webhook
  namespace: {{ $namespace }}
  labels:
    
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets-webhook
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
spec:
  replicas: 1
  revisionHistoryLimit: 10
  selector:
    matchLabels:
      app.kubernetes.io/name: external-secrets-webhook
      app.kubernetes.io/instance: external-secrets
  template:
    metadata:
      labels:
        
        helm.sh/chart: external-secrets-v2.11.0
        app.kubernetes.io/name: external-secrets-webhook
        app.kubernetes.io/instance: external-secrets
        app.kubernetes.io/version: "v2.11.0"
        app.kubernetes.io/managed-by: Helm
    spec:
      hostNetwork: false
      serviceAccountName: external-secrets-webhook
      automountServiceAccountToken: true
      containers:
        - name: webhook
          securityContext:
            allowPrivilegeEscalation: false
            capabilities:
              drop:
              - ALL
            readOnlyRootFilesystem: true
            runAsNonRoot: true
            runAsUser: 1000
            seccompProfile:
              type: RuntimeDefault
          image: {{ $esoImage }}
          imagePullPolicy: IfNotPresent
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
            - containerPort: 8081
              protocol: TCP
              name: ready
          readinessProbe:
            httpGet:
              port: ready
              path: /readyz
            initialDelaySeconds: 20
            periodSeconds: 5
            timeoutSeconds: 5
            failureThreshold: 3
            successThreshold: 1
          volumeMounts:
            - name: certs
              mountPath: /tmp/certs
              readOnly: true
      volumes:
        - name: certs
          secret:
            secretName: external-secrets-webhook
---
# Source: external-secrets/templates/validatingwebhook.yaml
apiVersion: admissionregistration.k8s.io/v1
kind: ValidatingWebhookConfiguration
metadata:
  name: secretstore-validate
  labels:
    
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets-webhook
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
    external-secrets.io/component: webhook
webhooks:
- name: "validate.secretstore.external-secrets.io"
  rules:
  - apiGroups:   ["external-secrets.io"]
    apiVersions: ["v1"]
    operations:  ["CREATE", "UPDATE", "DELETE"]
    resources:   ["secretstores"]
    scope:       "Namespaced"
  clientConfig:
    service:
      namespace: {{ $namespace }}
      name: external-secrets-webhook
      path: /validate-external-secrets-io-v1-secretstore
  admissionReviewVersions: ["v1", "v1beta1"]
  sideEffects: None
  timeoutSeconds: 5
  failurePolicy: Fail

- name: "validate.clustersecretstore.external-secrets.io"
  rules:
  - apiGroups:   ["external-secrets.io"]
    apiVersions: ["v1"]
    operations:  ["CREATE", "UPDATE", "DELETE"]
    resources:   ["clustersecretstores"]
    scope:       "Cluster"
  clientConfig:
    service:
      namespace: {{ $namespace }}
      name: external-secrets-webhook
      path: /validate-external-secrets-io-v1-clustersecretstore
  admissionReviewVersions: ["v1", "v1beta1"]
  sideEffects: None
  timeoutSeconds: 5
  failurePolicy: Fail
---
# Source: external-secrets/templates/validatingwebhook.yaml
apiVersion: admissionregistration.k8s.io/v1
kind: ValidatingWebhookConfiguration
metadata:
  name: externalsecret-validate
  labels:
    
    helm.sh/chart: external-secrets-v2.11.0
    app.kubernetes.io/name: external-secrets-webhook
    app.kubernetes.io/instance: external-secrets
    app.kubernetes.io/version: "v2.11.0"
    app.kubernetes.io/managed-by: Helm
    external-secrets.io/component: webhook
webhooks:
- name: "validate.externalsecret.external-secrets.io"
  rules:
  - apiGroups:   ["external-secrets.io"]
    apiVersions: ["v1"]
    operations:  ["CREATE", "UPDATE", "DELETE"]
    resources:   ["externalsecrets"]
    scope:       "Namespaced"
  clientConfig:
    service:
      namespace: {{ $namespace }}
      name: external-secrets-webhook
      path: /validate-external-secrets-io-v1-externalsecret
  admissionReviewVersions: ["v1", "v1beta1"]
  sideEffects: None
  timeoutSeconds: 5
  failurePolicy: Fail
