    server: {
        cors: true,
        origin: 'https://{{ $viteHost }}',
        hmr: {
            host: '{{ $viteHost }}',
        },
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        watch: {
            ignored: ['**/.infrastructure/volume_data/**'],
        },
    },
