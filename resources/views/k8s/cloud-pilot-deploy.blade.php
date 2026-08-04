name: LaraKube Cloud Pilot (Deploy to {{ $environment }})

on:
  push:
    branches: [ "{{ $branch }}" ]
  workflow_dispatch:

env:
  REGISTRY_HOST: {!! $gha['registry_host'] !!}
  IMAGE_NAME: {!! $gha['image_name'] !!}
  REGISTRY_PROVIDER: {!! $gha['registry_provider'] !!}

jobs:
  build:
@if(! $audit['skip'])
    name: 🔨 Audit, Build & Push
@else
    name: 🔨 Build & Push
@endif
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write

    steps:
      - name: 🛰 Checkout repository
        uses: actions/checkout@v6
@if($audit['gitleaks'])
        with:
          # Full history: Gitleaks scans commits, not just the working tree, so
          # a shallow clone would quietly narrow the secret gate to one commit.
          fetch-depth: 0
@endif

      - name: 🔍 Resolve & Verify Secrets
        id: secrets
        run: |
          # Robust resolution for KUBECONFIG
          FINAL_KUBE="{!! $secrets['k_env'] !!}"

          if [ -z "$FINAL_KUBE" ]; then
            echo "::error::{{ $upperEnv }}_KUBECONFIG is missing! Run 'larakube cloud:configure:gha' locally."
            exit 1
          fi

          echo "✅ All secrets resolved successfully."
@if(! $audit['skip'])

      # ── Phase 1: Security Audit (gates the build) ──────────────────────
@if($audit['gitleaks'])
      # Runs the Gitleaks binary rather than gitleaks/gitleaks-action, which
      # requires a paid licence on organisation-owned repos and hard-fails
      # without one. The scanner itself is MIT-licensed — only the Action
      # wrapper is gated — so this is the same check with no licence key.
      - name: 🔑 Gitleaks (secret gate)
        run: |
          docker run --rm -v "$GITHUB_WORKSPACE:/repo" ghcr.io/gitleaks/gitleaks:latest \
            detect --source=/repo --redact --no-banner --exit-code=1
@endif

      - name: 🐘 Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '{{ $config->getPhpVersion()->value }}'
          extensions: {{ implode(', ', array_unique(array_merge(['ctype', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre', 'pdo', 'session', 'tokenizer', 'xml', 'zip'], $config->getAllPhpExtensions()))) }}
          tools: composer:v2

      - name: 📋 Cache Composer dependencies
        uses: actions/cache@v5
        with:
          path: vendor
          key: {!! $gha['composer_cache_key'] !!}
          restore-keys: composer-

      - name: 📦 Install Composer dependencies
        run: composer install --optimize-autoloader --no-interaction --no-progress --ignore-platform-reqs

      - name: 🟢 Setup Node.js
        uses: actions/setup-node@v6
        with:
          node-version: '22'
          cache: '{{ $config->getPackageManager()->value }}'

      - name: 🛠 Install Node dependencies
        run: {!! $config->getPackageManager()->installCommand() !!}

@if($audit['dependencyAudit'])

      - name: 🧪 Dependency audit (Composer & NPM)
        run: |
          composer audit
          npm audit --audit-level={{ $audit['auditLevel'] }}
@endif
@if($audit['semgrep'])

      - name: 🛡 Semgrep (SAST, ERROR-only gate)
        run: |
          pip install semgrep
          semgrep scan --config=auto --severity=ERROR --error
@endif
@if($audit['trivy'])

      - name: 🗂 Cache Trivy DB
        uses: actions/cache@v4
        with:
          path: ~/.cache/trivy
          key: {!! $gha['trivy_cache_key'] !!}
          restore-keys: {!! $gha['trivy_restore_key'] !!}

      - name: 📁 Trivy filesystem scan (non-blocking)
        uses: aquasecurity/trivy-action@master
        with:
          scan-type: 'fs'
          format: 'table'
          exit-code: '0'
          ignore-unfixed: true
@endif
@if($audit['withTests'])

      - name: 🛡 Create .env file (public/build vars only)
        run: |
          {!! $publicEnvScript !!}

      - name: 🧪 Application tests
        run: php artisan test
@endif
@if($config->usesWayfinder())

      - name: 🏎 Generate Wayfinder files
        run: php artisan wayfinder:generate --with-form
@endif
@if(! $audit['withTests'])

      - name: 🛡 Create .env file (public/build vars only)
        run: |
          {!! $publicEnvScript !!}
@endif

      # ── Phase 2: Build locally (no push yet) ───────────────────────────
      - name: 🔧 Set up Docker Buildx
        uses: docker/setup-buildx-action@v4

      - name: 🔐 Log in to Container Registry
@if($gha['registry_provider'] === 'ghcr')
        uses: docker/login-action@v4
        with:
          registry: ghcr.io
          username: {!! $gha['actor'] !!}
          password: {!! $gha['token'] !!}
@else
        uses: docker/login-action@v4
        with:
          registry: {{ $gha['registry_host'] }}
          username: {!! $gha['registry_user'] !!}
          password: {!! $gha['registry_password'] !!}
@endif

@if($audit['trivy'])
      # Built locally first so the artifact can be scanned before it is
      # published — the load/scan/push split exists purely for that gate, so it
      # collapses to a single push below when the image scan is off.
      - name: 🐳 Build application image (load, do not push)
        uses: docker/build-push-action@v7
        with:
          context: .
          file: Dockerfile.php
          load: true
          push: false
          tags: {!! $gha['image_sha'] !!}
          target: deploy
          secret-files: |
            dotenv=.env
          cache-from: type=gha
          cache-to: type=gha,mode=max

      # ── Phase 3: Artifact security gate ────────────────────────────────
      - name: 🚦 Trivy image scan ({{ $audit['failOn'] }} gate)
        uses: aquasecurity/trivy-action@master
        with:
          image-ref: {!! $gha['image_sha'] !!}
          format: 'table'
          exit-code: '1'
          ignore-unfixed: true
          severity: '{{ $audit['failOn'] }}'

      # ── Phase 4: Ship (only if every gate cleared) ─────────────────────
      - name: 🚀 Push verified image
        uses: docker/build-push-action@v7
        with:
          context: .
          file: Dockerfile.php
          push: true
          tags: {!! $gha['image_latest'] !!},{!! $gha['image_sha'] !!}
          target: deploy
          secret-files: |
            dotenv=.env
          cache-from: type=gha
          cache-to: type=gha,mode=max
@else

      - name: 🐳 Build and push application image
        uses: docker/build-push-action@v7
        with:
          context: .
          file: Dockerfile.php
          push: true
          tags: {!! $gha['image_latest'] !!},{!! $gha['image_sha'] !!}
          target: deploy
          secret-files: |
            dotenv=.env
          cache-from: type=gha
          cache-to: type=gha,mode=max
@endif
@else

      - name: 🔐 Log in to Container Registry
@if($gha['registry_provider'] === 'ghcr')
        uses: docker/login-action@v4
        with:
          registry: ghcr.io
          username: {!! $gha['actor'] !!}
          password: {!! $gha['token'] !!}
@else
        uses: docker/login-action@v4
        with:
          registry: {{ $gha['registry_host'] }}
          username: {!! $gha['registry_user'] !!}
          password: {!! $gha['registry_password'] !!}
@endif

      - name: 🐘 Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '{{ $config->getPhpVersion()->value }}'
          extensions: {{ implode(', ', array_unique(array_merge(['ctype', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre', 'pdo', 'session', 'tokenizer', 'xml', 'zip'], $config->getAllPhpExtensions()))) }}
          tools: composer:v2

      - name: 📋 Cache Composer dependencies
        uses: actions/cache@v5
        with:
          path: vendor
          key: {!! $gha['composer_cache_key'] !!}
          restore-keys: composer-

      - name: 📦 Install Composer dependencies
        run: composer install --optimize-autoloader --no-interaction --no-progress --ignore-platform-reqs

      - name: 🟢 Setup Node.js
        uses: actions/setup-node@v6
        with:
          node-version: '22'
          cache: '{{ $config->getPackageManager()->value }}'

      - name: 🛠 Install Node dependencies
        run: {!! $config->getPackageManager()->installCommand() !!}
@if($config->usesWayfinder())

      - name: 🏎 Generate Wayfinder files
        run: php artisan wayfinder:generate --with-form
@endif

      - name: 🛡 Create .env file (public/build vars only)
        run: |
          {!! $publicEnvScript !!}

      - name: 🔧 Set up Docker Buildx
        uses: docker/setup-buildx-action@v4

      - name: 🐳 Build & Push Application Image
        uses: docker/build-push-action@v7
        with:
          context: .
          file: Dockerfile.php
          push: true
          tags: {!! $gha['image_latest'] !!},{!! $gha['image_sha'] !!}
          target: deploy
          secret-files: |
            dotenv=.env
          cache-from: type=gha
          cache-to: type=gha,mode=max
@endif

  deploy:
    name: 🚀 Deploy
    runs-on: ubuntu-latest
    needs: build
    permissions:
      contents: read

    steps:
      - name: 🛰 Checkout repository
        uses: actions/checkout@v6
        with:
          sparse-checkout: .infrastructure

      - name: 🔍 Resolve & Verify Secrets
        run: |
          FINAL_KUBE="{!! $secrets['k_env'] !!}"

          if [ -z "$FINAL_KUBE" ]; then
            echo "::error::{{ $upperEnv }}_KUBECONFIG is missing! Run 'larakube cloud:configure:gha' locally."
            exit 1
          fi

          echo "K_DATA<<EOF" >> $GITHUB_ENV
          echo "$FINAL_KUBE" >> $GITHUB_ENV
          echo "EOF" >> $GITHUB_ENV

      - name: 🕵️ Inspect Cluster Target
        run: |
          TARGET_URL=$(echo "$K_DATA" | grep "server:" | awk '{print $2}')
          echo "🚀 Deployment Target Cluster: $TARGET_URL"

          if [[ "$TARGET_URL" == *"127.0.0.1"* ]] || [[ "$TARGET_URL" == *"localhost"* ]]; then
            echo "::error::🚨 FATAL: Kubeconfig is targeting LOCALHOST ($TARGET_URL)!"
            echo "::error::This usually happens if your local context was active during secret upload."
            echo "::error::FIX: Run 'larakube cloud:configure:gha' again and ensure the CLI extracts your remote context."
            exit 1
          fi

@if($vpnHost ?? null)
      - name: 🔌 Connect to NetBird VPN
        run: |
          curl -fsSL https://pkgs.netbird.io/install.sh | sh
          sudo netbird up --management-url https://{{ $vpnHost }} --setup-key {!! $secrets['vpn_key'] !!}
          for i in $(seq 1 30); do
            sudo netbird status | grep -q "Management: Connected" && break
            sleep 2
          done
          sudo netbird status | grep -q "Management: Connected" || { echo "::error::Failed to connect to NetBird VPN — the k3s API is VPN-only and unreachable without it."; exit 1; }
@endif

      - name: 🔑 Set Kubernetes context
        uses: azure/k8s-set-context@v4
        with:
          method: kubeconfig
          kubeconfig: {!! $gha['k_data'] !!}

      - name: 🛡 Create .env file (public/build vars only)
        run: |
          {!! $publicEnvScript !!}

      - name: 🔒 Verify runtime secrets were pushed
        run: |
          # laravel-secrets now only ever comes from `larakube dotenv:push`, run
          # from a developer's own machine — this workflow never holds runtime
          # credentials, only the public/build subset above. Fail fast with a
          # clear fix instead of letting every pod CrashLoop on a missing
          # envFrom source a few minutes from now.
          if ! kubectl get secret laravel-secrets -n {{ $namespace }} >/dev/null 2>&1; then
            echo "::error::'laravel-secrets' is missing in '{{ $namespace }}'. Run 'larakube dotenv:push {{ $environment }}' from your machine before deploying."
            exit 1
          fi

      - name: 🏗 Prepare Manifests & Deploy
        run: |
          # 1. Update ConfigMap (public/build config only — runtime secrets are
          #    dotenv:push's job, verified above, never this workflow's).
          kubectl create configmap laravel-config -n {{ $namespace }} --from-env-file=.env --dry-run=client -o yaml | kubectl apply -f -

          # 2. Deploy via Kustomize. The namespace already exists (created at
          #    `cloud:configure:gha` time), and this runner uses a NAMESPACE-SCOPED
          #    credential — so strip the cluster-scoped Namespace doc, which the
          #    scoped ServiceAccount can't apply.
          cd .infrastructure/k8s/overlays/{{ $environment }}
          kubectl kustomize . | sed "s|image: {{ $appName }}:{{ $environment }}-latest|image: {!! $gha['image_sha'] !!}|g" | awk 'function flush(){if(!drop&&doc!=""){printf "%s",doc} doc="";drop=0} /^---[ \t\r]*$/{flush();print;next} {doc=doc $0 "\n"; if($0 ~ /^kind:[ \t]+Namespace[ \t\r]*$/)drop=1} END{flush()}' | kubectl apply -f -

          # 3. Wait for rollouts
@foreach(['web', 'horizon', 'queues', 'reverb'] as $name)
@php($feature = \App\Enums\LaravelFeature::fromPodName($name))
@if($name === 'web' || ($feature && $config->hasFeature($feature)))
          kubectl rollout status deployment/{{ $name }} -n {{ $namespace }} --timeout=300s
@endif
@endforeach
