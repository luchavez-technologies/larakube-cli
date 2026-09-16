      # ── Connect to the cluster ─────────────────────────────────────────
      - name: 🛰 Checkout repository
        uses: actions/checkout@v7
        with:
          sparse-checkout: .infrastructure

      - name: 🔍 Resolve & Verify Secrets
        run: |
          FINAL_KUBE="{!! $secrets['k_env'] !!}"

          if [ -z "$FINAL_KUBE" ]; then
            echo "::error::{{ $upperEnv }}_KUBECONFIG is missing! Run 'larakube cloud:configure {{ $environment }} --only=ci' locally."
            exit 1
          fi

          echo "K_DATA<<EOF" >> $GITHUB_ENV
          echo "$FINAL_KUBE" >> $GITHUB_ENV
          echo "EOF" >> $GITHUB_ENV

      - name: 🕵️ Inspect Cluster Target
        shell: bash
        run: |
          TARGET_URL=$(echo "$K_DATA" | grep "server:" | awk '{print $2}')
          echo "🚀 Deployment Target Cluster: $TARGET_URL"

          if [[ "$TARGET_URL" == *"127.0.0.1"* ]] || [[ "$TARGET_URL" == *"localhost"* ]]; then
            echo "::error::🚨 FATAL: Kubeconfig is targeting LOCALHOST ($TARGET_URL)!"
            echo "::error::This usually happens if your local context was active during secret upload."
            echo "::error::FIX: Run 'larakube cloud:configure {{ $environment }} --only=ci' again and ensure the CLI extracts your remote context."
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
        uses: azure/k8s-set-context@v5
        with:
          method: kubeconfig
          kubeconfig: {!! $gha['k_data'] !!}

      - name: ☸️ Set up kubectl
        uses: azure/setup-kubectl@v5
{{-- The deploy job's opening steps, shared by every GitHub-Actions-format
     workflow: fetch the manifests, load the namespace-scoped kubeconfig, refuse
     a localhost target, join the VPN when the cluster needs it, and install
     kubectl (not every runner image ships it). Rendered views are ltrim()med,
     so this file must open with the indentation-insensitive YAML comment above. --}}