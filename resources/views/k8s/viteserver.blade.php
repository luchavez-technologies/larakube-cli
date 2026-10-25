    server: {
        cors: true,
        origin: 'https://{{ $viteHost }}',
        hmr: {
            host: '{{ $viteHost }}',
        },
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        // Vite 6+ refuses any Host header that isn't localhost, so the
        // Traefik-proxied hostname must be declared here or every request is
        // rejected with "Blocked request. This host is not allowed."
        allowedHosts: ['{{ $viteHost }}'],
        watch: {
            ignored: ['**/.infrastructure/volume_data/**'],
        },
    },