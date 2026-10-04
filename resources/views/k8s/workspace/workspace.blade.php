apiVersion: v1
kind: Namespace
metadata:
  name: {{ $namespace }}
  labels:
    larakube-workspace: {{ $name }}
  annotations:
    larakube.dev/workspace-repo: {!! json_encode($repo, JSON_UNESCAPED_SLASHES) !!}
    larakube.dev/workspace-branch: {!! json_encode($branch, JSON_UNESCAPED_SLASHES) !!}
    larakube.dev/workspace-size: {!! json_encode($size, JSON_UNESCAPED_SLASHES) !!}
---
apiVersion: v1
kind: ServiceAccount
metadata:
  name: workspace
  namespace: {{ $namespace }}
automountServiceAccountToken: false
---
apiVersion: v1
kind: ResourceQuota
metadata:
  name: workspace
  namespace: {{ $namespace }}
spec:
  hard:
    requests.memory: {{ $memory }}
    limits.memory: {{ $memory }}
    requests.cpu: "{{ $cpu }}"
    limits.cpu: "{{ $cpu }}"
    persistentvolumeclaims: "2"
    requests.storage: {{ $storage }}
    pods: "2"
    services.loadbalancers: "0"
    services.nodeports: "0"
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: home
  namespace: {{ $namespace }}
spec:
  accessModes: [ReadWriteOnce]
  resources:
    requests:
      storage: {{ $storage }}
---
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: workspace
  namespace: {{ $namespace }}
spec:
  podSelector: {}
  policyTypes: [Ingress, Egress]
  ingress:
    - from:
        - namespaceSelector:
            matchLabels:
              kubernetes.io/metadata.name: kube-system
      ports:
        - port: 8080
  egress:
    - to:
        - namespaceSelector:
            matchLabels:
              kubernetes.io/metadata.name: kube-system
      ports:
        - { port: 53, protocol: UDP }
        - { port: 53, protocol: TCP }
    - to:
        - ipBlock:
            cidr: 0.0.0.0/0
            except: [10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16]
      ports:
        - { port: 22, protocol: TCP }
        - { port: 80, protocol: TCP }
        - { port: 443, protocol: TCP }
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: workspace
  namespace: {{ $namespace }}
  labels:
    app: workspace
    larakube-workspace: {{ $name }}
spec:
  replicas: {{ $replicas }}
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: workspace
  template:
    metadata:
      labels:
        app: workspace
        larakube-workspace: {{ $name }}
    spec:
      serviceAccountName: workspace
      automountServiceAccountToken: false
      enableServiceLinks: false
      securityContext:
        runAsUser: 1000
        runAsGroup: 1000
        fsGroup: 1000
      containers:
        - name: editor
          image: {{ $image }}
          imagePullPolicy: IfNotPresent
          command: ["/bin/bash", "-c"]
          args:
            - |
              set -u
              mkdir -p ~/.ssh && chmod 700 ~/.ssh
              cp /etc/workspace/deploy-key ~/.ssh/id_ed25519 && chmod 600 ~/.ssh/id_ed25519
              ssh-keyscan -t ed25519,rsa github.com gitlab.com bitbucket.org >> ~/.ssh/known_hosts 2>/dev/null
              git config --global user.name "$GIT_AUTHOR_NAME"
              git config --global user.email "$GIT_AUTHOR_EMAIL"
              if [ -n "$WORKSPACE_REPO" ] && [ ! -d ~/project/.git ]; then
                ssh_url="$(printf '%s' "${WORKSPACE_REPO%.git}" | sed -E 's#^https://([^/]+)/(.+)$#git@\1:\2.git#')"
                git clone "$ssh_url" ~/project || git clone "$WORKSPACE_REPO" ~/project || echo "clone failed: add the deploy key to the repository, then resume the workspace"
                if [ -d ~/project/.git ]; then
                  cd ~/project
                  git remote set-url origin "$ssh_url"
                  git checkout "$WORKSPACE_BRANCH" 2>/dev/null || git checkout -b "$WORKSPACE_BRANCH"
                  default="$(git symbolic-ref --short refs/remotes/origin/HEAD 2>/dev/null | sed 's#^origin/##')"
                  printf '#!/bin/sh\nwhile read l ls r rs; do case "$rs" in refs/heads/%s) echo "Workspaces push to a branch, not %s." >&2; exit 1;; esac; done\n' "${default:-main}" "${default:-main}" > .git/hooks/pre-push
                  chmod +x .git/hooks/pre-push
                fi
              fi
              mkdir -p ~/project
              exec code-server --bind-addr 0.0.0.0:8080 --auth password ~/project
          env:
            - name: HOME
              value: /home/coder
            - name: PASSWORD
              valueFrom:
                secretKeyRef: { name: workspace, key: password }
            - name: WORKSPACE_REPO
              value: {!! json_encode($repo, JSON_UNESCAPED_SLASHES) !!}
            - name: WORKSPACE_BRANCH
              value: {!! json_encode($branch, JSON_UNESCAPED_SLASHES) !!}
            - name: GIT_AUTHOR_NAME
              value: {!! json_encode($gitName, JSON_UNESCAPED_SLASHES) !!}
            - name: GIT_AUTHOR_EMAIL
              value: {!! json_encode($gitEmail, JSON_UNESCAPED_SLASHES) !!}
          ports:
            - containerPort: 8080
          readinessProbe:
            httpGet: { path: /healthz, port: 8080 }
            periodSeconds: 3
          resources:
            requests:
              memory: {{ $requestMemory }}
              cpu: 250m
            limits:
              memory: {{ $memory }}
              cpu: "{{ $cpu }}"
          securityContext:
            allowPrivilegeEscalation: false
            capabilities:
              drop: [ALL]
          volumeMounts:
            - { name: home, mountPath: /home/coder }
            - { name: key, mountPath: /etc/workspace, readOnly: true }
      volumes:
        - name: home
          persistentVolumeClaim: { claimName: home }
        - name: key
          secret:
            secretName: workspace
            defaultMode: 288
---
apiVersion: v1
kind: Service
metadata:
  name: workspace
  namespace: {{ $namespace }}
spec:
  selector:
    app: workspace
  ports:
    - port: 8080
      targetPort: 8080
