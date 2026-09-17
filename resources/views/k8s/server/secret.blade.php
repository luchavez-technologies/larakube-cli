{{-- The local preview's runtime env, from the project .env. Only ever rendered
     into the gitignored local overlay. --}}
apiVersion: v1
kind: Secret
metadata:
  name: {{ $envSecret }}
type: Opaque
stringData:
@foreach($secrets as $key => $value)
  {{ $key }}: {!! json_encode((string) $value) !!}
@endforeach
