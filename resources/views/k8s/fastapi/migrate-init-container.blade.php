- name: alembic-migrate
  image: {{ $config->getName() }}:latest
  imagePullPolicy: IfNotPresent
  command:
    - sh
    - -c
    - alembic upgrade head || true
  envFrom:
    - configMapRef:
        name: {{ $config->getName() }}-fastapi-config
    - secretRef:
        name: {{ $config->getName() }}-fastapi-secrets
