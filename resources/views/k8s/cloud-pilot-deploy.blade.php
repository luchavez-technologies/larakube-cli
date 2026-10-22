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
@if(($gha['forge'] ?? 'github') === 'github')
    permissions:
      contents: read
      packages: write
@endif
    outputs:
      image_ref: {!! $gha['push_ref_output'] !!}

    steps:
      - name: 🛰 Checkout repository
        uses: actions/checkout@v7
@if($audit['gitleaks'])
        with:
          # Full history: the secret scan reads commits, not just the working
          # tree, so a shallow clone would quietly narrow it to one commit.
          fetch-depth: 0
@endif

      - name: 🔍 Resolve & Verify Secrets
        id: secrets
        run: |
          # Robust resolution for KUBECONFIG
          FINAL_KUBE="{!! $secrets['k_env'] !!}"

          if [ -z "$FINAL_KUBE" ]; then
            echo "::error::{{ $upperEnv }}_KUBECONFIG is missing! Run 'larakube cloud:configure {{ $environment }} --only=ci' locally."
            exit 1
          fi

          echo "✅ All secrets resolved successfully."
@if(! $audit['skip'])

      # ── Phase 1: Security Audit (gates the build) ──────────────────────
@endif
@if($audit['gitleaks'])
      {{-- The MIT-licensed Gitleaks binary rather than its Action wrapper,
           which demands a paid licence on organisation-owned repos. --}}
      - name: 🔑 Gitleaks (secret gate)
        shell: bash
        run: |
          case "$(uname -m)" in aarch64|arm64) ARCH=arm64 ;; *) ARCH=x64 ;; esac
          curl -sSfL "https://github.com/gitleaks/gitleaks/releases/download/v8.30.1/gitleaks_8.30.1_linux_${ARCH}.tar.gz" | tar -xz -C /tmp gitleaks
          /tmp/gitleaks git --redact --no-banner --exit-code=1 .
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
@if($config->framework !== \App\Enums\AppFramework::WORDPRESS)

      - name: 🟢 Setup Node.js
        uses: actions/setup-node@v7
        with:
          node-version: '24'
          cache: '{{ $config->getPackageManager()->value }}'

      - name: 🛠 Install Node dependencies
        run: {!! $config->getPackageManager()->installCommand() !!}
@endif
@if($audit['dependencyAudit'])

      - name: 🧪 Dependency audit (Composer & NPM)
        run: |
          composer audit
@if($config->framework !== \App\Enums\AppFramework::WORDPRESS)
          npm audit --audit-level={{ $audit['auditLevel'] }}
@endif
@endif
@if($audit['semgrep'])

      - name: 🛡 Semgrep (SAST, ERROR-only gate)
        run: |
          # GitHub-hosted runners ship pip; the Forgejo job image (Debian) does not.
          python3 -m pip --version >/dev/null 2>&1 || { apt-get update -qq && apt-get install -y -qq --no-install-recommends python3-pip; }
          python3 -m pip install --quiet --break-system-packages semgrep
          semgrep scan --config=auto --severity=ERROR --error
@endif
@if($audit['trivy'])

      {{-- The Trivy binary rather than trivy-action, which fetches Trivy by
           checking out its GitHub repo with the job token — a Forgejo token
           GitHub rejects. --}}
      - name: 📁 Trivy filesystem scan (non-blocking)
        shell: bash
        run: |
          case "$(uname -m)" in aarch64|arm64) ARCH=ARM64 ;; *) ARCH=64bit ;; esac
          curl -sSfL "https://github.com/aquasecurity/trivy/releases/download/v0.74.0/trivy_0.74.0_Linux-${ARCH}.tar.gz" | tar -xz -C /tmp trivy
          /tmp/trivy fs --quiet --ignore-unfixed --exit-code 0 .
@endif

      - name: 🛡 Create .env file (public/build vars only)
        run: |
          touch .env
          {!! $publicEnvScript !!}
@if($audit['withTests'])

      - name: 🧪 Application tests
        run: php artisan test
@endif
@if($config->usesWayfinder())

      - name: 🏎 Generate Wayfinder files
        run: php artisan wayfinder:generate --with-form
@endif

@include('k8s.ci.podman-build', [
    'dockerfile' => 'Dockerfile.php',
    'target' => 'deploy',
    'trivy' => $audit['trivy'],
    'failOn' => $audit['failOn'],
    'registryUser' => $gha['registry_provider'] === 'ghcr' ? $gha['actor'] : $gha['registry_user'],
    'registryPassword' => $gha['registry_provider'] === 'ghcr' ? $gha['token'] : $gha['registry_password'],
])

  deploy:
    name: 🚀 Deploy
    runs-on: ubuntu-latest
    needs: build
@if(($gha['forge'] ?? 'github') === 'github')
    permissions:
      contents: read
@endif

    steps:
@include('k8s.ci.deploy-connect')

      - name: 🛡 Create .env file (public/build vars only)
        run: |
          touch .env
          {!! $publicEnvScript !!}

      - name: 🔒 Verify runtime secrets were pushed
        run: |
          # app-secrets (or legacy laravel-secrets) now only ever comes from `larakube dotenv:push`, run
          # from a developer's own machine — this workflow never holds runtime
          # credentials, only the public/build subset above. Fail fast with a
          # clear fix instead of letting every pod CrashLoop on a missing
          # envFrom source a few minutes from now.
          if ! kubectl get secret {{ $config->getSecretName() }} -n {{ $namespace }} >/dev/null 2>&1 && ! kubectl get secret {{ $config->getLegacySecretName() }} -n {{ $namespace }} >/dev/null 2>&1; then
            echo "::error::Runtime secrets missing in '{{ $namespace }}'. Run 'larakube dotenv:push {{ $environment }}' from your machine before deploying."
            exit 1
          fi

      - name: 🏗 Prepare Manifests & Deploy
        run: |
          # 1. Update ConfigMap (public/build config only — runtime secrets are
          #    dotenv:push's job, verified above, never this workflow's).
          kubectl create configmap {{ $config->getConfigMapName() }} -n {{ $namespace }} --from-env-file=.env --dry-run=client -o yaml | kubectl apply -f -

          # 2. Deploy via Kustomize at the pushed digest. The namespace already
          #    exists (created at `cloud:configure` time), and this runner uses a
          #    NAMESPACE-SCOPED credential — so strip the cluster-scoped
          #    Namespace doc, which the scoped ServiceAccount can't apply.
          cd .infrastructure/k8s/overlays/{{ $environment }}
          kubectl kustomize . | sed "s|image: {{ $appName }}:{{ $environment }}-latest|image: {!! $gha['image_ref'] !!}|g" | awk 'function flush(){if(!drop&&doc!=""){printf "%s",doc} doc="";drop=0} /^---[ \t\r]*$/{flush();print;next} {doc=doc $0 "\n"; if($0 ~ /^kind:[ \t]+Namespace[ \t\r]*$/)drop=1} END{flush()}' | kubectl apply -f -

          # 3. Wait for rollouts
@foreach(['web', 'horizon', 'queues', 'reverb'] as $name)
@php($feature = \App\Enums\LaravelFeature::fromPodName($name))
@if($name === 'web' || ($feature && $config->hasFeature($feature)))
          kubectl rollout status deployment/{{ $name }} -n {{ $namespace }} --timeout=300s
@endif
@endforeach
