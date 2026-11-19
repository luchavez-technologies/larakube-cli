      # Starts only once the database answers over TCP, like upstream's depends_on: db: service_healthy.
      # During first setup the image's temporary server listens on the socket only, so this also
      # waits out the init scripts instead of crash-looping against a half-initialized database.
      initContainers:
        - name: wait-for-db
          image: {{ \App\Enums\ClusterTool::SUPABASE->vendor()->image('db') }}
          command: ["sh", "-c", "until pg_isready -q -h {{ $dbHost }} -p 5432 -U postgres; do echo 'Waiting for {{ $dbHost }}…'; sleep 2; done"]
          resources:
            requests:
              cpu: 10m
              memory: 16Mi
