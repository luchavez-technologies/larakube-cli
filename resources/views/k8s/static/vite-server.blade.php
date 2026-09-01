    server: {
        host: '0.0.0.0',
        port: {{ $devPort }},
        strictPort: true,
        // Vite 6+ refuses any Host header that isn't localhost, so the
        // Traefik-proxied hostname must be declared here or every request is
        // rejected with "Blocked request. This host is not allowed."
        allowedHosts: ['{{ $appHost }}'],
        cors: true,
        hmr: {
            // HMR rides wss on 443 because Traefik terminates TLS at the edge;
            // the dev server itself still speaks plain HTTP inside the pod.
            host: '{{ $appHost }}',
            protocol: 'wss',
            clientPort: 443,
        },
        watch: {
            // inotify does not reliably cross the hostPath/VirtioFS boundary on
            // macOS, so the watcher polls rather than silently missing edits.
            usePolling: true,
            interval: 300,
        },
    },
