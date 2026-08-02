- name: go-migrate
  image: {{ $config->getName() }}:latest
  imagePullPolicy: IfNotPresent
  command:
    - sh
    - -c
    - migrate -path /migrations -database "$DATABASE_URL" up || true
  envFrom:
    - configMapRef:
        name: {{ $config->getName() }}-gin-config
    - secretRef:
        name: {{ $config->getName() }}-gin-secrets
