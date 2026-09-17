name: LaraKube Cloud Pilot (Deploy to {{ $environment }})

on:
  push:
    branches: [ "{{ $branch }}" ]
  workflow_dispatch:

env:
  REGISTRY_HOST: {!! $gha['registry_host'] !!}
  IMAGE_NAME: {!! $gha['image_name'] !!}
  REGISTRY_PROVIDER: {!! $gha['registry_provider'] !!}

{{-- A server app that is neither PHP nor static (Next.js today): the image
     builds from the framework's own Dockerfile, the cloud overlay carries the
     runtime Secret, and the rollout waits on the framework's own Deployment.
     Forgejo ignores `permissions:` and warns about it on every run. --}}
@php($framework = $config->framework)
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
        run: |
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
@if($audit['dependencyAudit'] && $framework->usesNpm())

      - name: 🟢 Setup Node.js
        uses: actions/setup-node@v7
        with:
          node-version: '24'

      - name: 🧪 Dependency audit (NPM)
        run: npm audit --audit-level={{ $audit['auditLevel'] }}
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

      - name: 🛡 Create .env file (browser-bundle vars only)
        run: |
          touch .env
@if($publicEnvScript !== '')
          {!! $publicEnvScript !!}
@endif

@include('k8s.ci.podman-build', [
    'dockerfile' => $framework->dockerfile(),
    'target' => $framework->buildTarget(),
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
@if($framework->isServerApp())

      - name: 🔒 Verify runtime secrets were pushed
        run: |
          # This app's runtime env only ever comes from `larakube dotenv:push`,
          # run from a developer's machine; the workflow never holds it.
          if ! kubectl get secret laravel-secrets -n {{ $namespace }} >/dev/null 2>&1; then
            echo "::error::'laravel-secrets' is missing in '{{ $namespace }}'. Run 'larakube dotenv:push {{ $environment }}' from your machine before deploying."
            exit 1
          fi
@endif

      - name: 🏗 Deploy
        run: |
          # Kustomize at the pushed digest. This runner holds a NAMESPACE-SCOPED
          # credential, so the cluster-scoped Namespace doc is stripped.
          cd .infrastructure/k8s/overlays/{{ $environment }}
          kubectl kustomize . | sed "s|image: {{ $appName }}:{{ $environment }}-latest|image: {!! $gha['image_ref'] !!}|g" | awk 'function flush(){if(!drop&&doc!=""){printf "%s",doc} doc="";drop=0} /^---[ \t\r]*$/{flush();print;next} {doc=doc $0 "\n"; if($0 ~ /^kind:[ \t]+Namespace[ \t\r]*$/)drop=1} END{flush()}' | kubectl apply -f -

          kubectl rollout status deployment/{{ $framework->workloadName($appName) }} -n {{ $namespace }} --timeout=300s
