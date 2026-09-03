  vite: {
    server: {
      // Astro runs Vite underneath, and Vite 6+ rejects any Host header that
      // is not localhost — so without this every request through Traefik is
      // answered "Blocked request. This host is not allowed."
      allowedHosts: ['{{ $appHost }}'],
      hmr: {
        // Traefik terminates TLS at the edge; the dev server itself speaks
        // plain HTTP inside the pod.
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
  },
