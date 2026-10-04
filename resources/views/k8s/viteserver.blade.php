    server: {
        cors: true,
        origin: process.env.VITE_DEV_ORIGIN || 'https://{{ $viteHost }}',
        hmr: {
            host: process.env.VITE_HMR_HOST || '{{ $viteHost }}',
            ...(process.env.VITE_HMR_CLIENT_PORT
                ? {
                      clientPort: parseInt(process.env.VITE_HMR_CLIENT_PORT),
                      protocol: process.env.VITE_HMR_PROTOCOL || 'wss',
                  }
                : {}),
        },
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        watch: {
            ignored: ['**/.infrastructure/volume_data/**'],
        },
    },