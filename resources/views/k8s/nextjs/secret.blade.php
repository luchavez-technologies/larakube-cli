{{-- Connection strings the app + Prisma read via envFrom. DATABASE_URL and
     REDIS_URL always; DB_* only on the self-hosted path, where the DB pod reads
     them too. Rendered into the (gitignored) local overlay and the cloud
     overlays; values come from the project .env written at scaffold time. --}}
apiVersion: v1
kind: Secret
metadata:
  name: {{ $config->getName() }}-nextjs-secrets
type: Opaque
stringData:
@foreach($secrets as $key => $value)
  {{ $key }}: "{{ $value }}"
@endforeach
