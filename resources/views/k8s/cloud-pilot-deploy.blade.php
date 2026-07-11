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
    name: 🔨 Build & Push
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write

    steps:
      - name: 🛰 Checkout repository
        uses: actions/checkout@v6

      - name: 🔍 Resolve & Verify Secrets
        id: secrets
        run: |
          # Robust resolution for KUBECONFIG
          FINAL_KUBE="{!! $secrets['k_env'] !!}"

          # Robust resolution for ENV_FILE
          FINAL_ENV="{!! $secrets['e_env'] !!}"

          if [ -z "$FINAL_KUBE" ]; then
            echo "::error::{{ $upperEnv }}_KUBECONFIG is missing! Run 'larakube cloud:configure:gha' locally."
            exit 1
          fi

          if [ -z "$FINAL_ENV" ]; then
            echo "::error::{{ $upperEnv }}_ENV_FILE_BASE64 is missing! Run 'larakube cloud:configure:gha' locally."
            exit 1
          fi

          # Securely export using Heredoc to prevent truncation/mangling
          echo "E_DATA<<EOF" >> $GITHUB_ENV
          echo "$FINAL_ENV" >> $GITHUB_ENV
          echo "EOF" >> $GITHUB_ENV

          echo "✅ All secrets resolved successfully."

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

      - name: 🛡 Create .env file
        run: |
          echo "$E_DATA" | base64 -d > .env

      - name: 💎 Inject VITE vars for asset baking
        run: |
          echo "VITE_APP_URL=https://{{ $config->getWebHost($environment) }}" >> .env
          echo "VITE_ASSET_URL=https://{{ $config->getWebHost($environment) }}" >> .env
@if($reverbHost = $config->getHost($environment, 'reverb'))
@if($config->hasFeature(\App\Enums\LaravelFeature::REVERB))
          echo "VITE_REVERB_HOST={{ $reverbHost }}" >> .env
          echo "VITE_REVERB_PORT=443" >> .env
          echo "VITE_REVERB_SCHEME=https" >> .env
          REVERB_APP_KEY=$(grep -E '^REVERB_APP_KEY=' .env | head -1 | cut -d= -f2-)
          echo "VITE_REVERB_APP_KEY=$REVERB_APP_KEY" >> .env
@endif
@endif

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
          FINAL_ENV="{!! $secrets['e_env'] !!}"

          if [ -z "$FINAL_KUBE" ]; then
            echo "::error::{{ $upperEnv }}_KUBECONFIG is missing! Run 'larakube cloud:configure:gha' locally."
            exit 1
          fi

          if [ -z "$FINAL_ENV" ]; then
            echo "::error::{{ $upperEnv }}_ENV_FILE_BASE64 is missing! Run 'larakube cloud:configure:gha' locally."
            exit 1
          fi

          echo "K_DATA<<EOF" >> $GITHUB_ENV
          echo "$FINAL_KUBE" >> $GITHUB_ENV
          echo "EOF" >> $GITHUB_ENV

          echo "E_DATA<<EOF" >> $GITHUB_ENV
          echo "$FINAL_ENV" >> $GITHUB_ENV
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

      - name: 🔑 Set Kubernetes context
        uses: azure/k8s-set-context@v4
        with:
          method: kubeconfig
          kubeconfig: {!! $gha['k_data'] !!}

      - name: 🛡 Create .env file
        run: |
          echo "$E_DATA" | base64 -d > .env

      - name: 🏗 Prepare Manifests & Deploy
        run: |
          # 1. Update ConfigMap/Secret
          kubectl create configmap laravel-config -n {{ $namespace }} --from-env-file=.env --dry-run=client -o yaml | kubectl apply -f -
          kubectl create secret generic laravel-secrets -n {{ $namespace }} --from-env-file=.env --dry-run=client -o yaml | kubectl apply -f -

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
