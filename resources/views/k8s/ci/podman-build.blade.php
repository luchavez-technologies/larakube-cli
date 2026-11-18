      # ── Build & ship ───────────────────────────────────────────────────
      - name: 🐳 Prepare container builder
        shell: bash
        run: |
          if [ -n "${CONTAINER_HOST:-}" ] || (command -v podman >/dev/null 2>&1 && ! command -v docker >/dev/null 2>&1); then
            if ! command -v podman >/dev/null 2>&1; then
              apt-get update -qq
              apt-get install -y -qq --no-install-recommends podman-remote
              ln -sf "$(command -v podman-remote)" /usr/local/bin/podman
            fi
            echo "BUILDER=podman" >> "$GITHUB_ENV"
            podman version
          elif command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
            echo "BUILDER=docker" >> "$GITHUB_ENV"
            docker --version
          elif command -v podman >/dev/null 2>&1; then
            echo "BUILDER=podman" >> "$GITHUB_ENV"
            podman version
          else
            echo "::error::No container builder (Docker or Podman) is available on this runner."
            exit 1
          fi

      - name: 🔐 Log in to Container Registry
        shell: bash
        env:
          REGISTRY_USER: {!! $registryUser !!}
          REGISTRY_PASSWORD: {!! $registryPassword !!}
        run: echo "$REGISTRY_PASSWORD" | "$BUILDER" login -u "$REGISTRY_USER" --password-stdin "$REGISTRY_HOST"

      - name: 🐳 Build application image
        shell: bash
        run: |
          # Image references must be lowercase; repository names often are not.
          IMAGE="${REGISTRY_HOST}/${IMAGE_NAME,,}"
          echo "IMAGE=$IMAGE" >> "$GITHUB_ENV"
          if [ "$BUILDER" = "podman" ]; then
            podman build --layers \
              --file {{ $dockerfile }} \
@if($target)
              --target {{ $target }} \
@endif
              --secret id=dotenv,src=.env \
              --tag "$IMAGE:$GITHUB_SHA" \
              --tag "$IMAGE:latest" \
              .
          else
            DOCKER_BUILDKIT=1 docker build \
              --file {{ $dockerfile }} \
@if($target)
              --target {{ $target }} \
@endif
              --secret id=dotenv,src=.env \
              --tag "$IMAGE:$GITHUB_SHA" \
              --tag "$IMAGE:latest" \
              .
          fi
@if($trivy)

      # ── Artifact security gate ─────────────────────────────────────────
      - name: 📦 Export image for scanning
        shell: bash
        run: $BUILDER save -o image.tar "$IMAGE:$GITHUB_SHA"

      - name: 🚦 Trivy image scan ({{ $failOn }} gate)
        shell: bash
        run: |
          case "$(uname -m)" in aarch64|arm64) ARCH=ARM64 ;; *) ARCH=64bit ;; esac
          curl -sSfL "https://github.com/aquasecurity/trivy/releases/download/v0.74.0/trivy_0.74.0_Linux-${ARCH}.tar.gz" | tar -xz -C /tmp trivy
          /tmp/trivy image --quiet --input image.tar --ignore-unfixed --severity {{ $failOn }} --exit-code 1
@endif

      # ── Ship (only if every gate cleared) ──────────────────────────────
      - name: 🚀 Push image
        id: push
        shell: bash
        run: |
          if [ "$BUILDER" = "podman" ]; then
            podman push --digestfile digest "$IMAGE:$GITHUB_SHA"
            podman push "$IMAGE:latest"
            echo "ref=$IMAGE@$(cat digest)" >> "$GITHUB_OUTPUT"
          else
            docker push "$IMAGE:$GITHUB_SHA"
            docker push "$IMAGE:latest"
            REF=$(docker inspect --format='@{{index .RepoDigests 0}}' "$IMAGE:$GITHUB_SHA" 2>/dev/null || true)
            if [ -z "$REF" ]; then
              REF="$IMAGE:$GITHUB_SHA"
            fi
            echo "ref=$REF" >> "$GITHUB_OUTPUT"
          fi
{{-- Build, optionally scan, and push the image with Podman — the same shape as
     cloud:deploy's Podman path. One set of steps for GitHub-hosted runners
     (Podman preinstalled) and the Forgejo runner (a remote client of its Podman
     sidecar via CONTAINER_HOST). The push step exposes `ref` (image@digest) so
     the deploy job pins the immutable digest, never a mutable tag.

     Rendered views are ltrim()med, so this file must open with a line whose
     indentation doesn't matter — the YAML comment above.

     Expects: $dockerfile, $target (nullable), $trivy, $failOn, $registryUser,
     $registryPassword (GitHub expressions). --}}