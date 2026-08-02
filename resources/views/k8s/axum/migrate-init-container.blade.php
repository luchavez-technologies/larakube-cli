- name: sqlx-migrate
  image: {{ $config->getName() }}:latest
  imagePullPolicy: IfNotPresent
  command:
    - sh
    - -c
    - sqlx migrate run || true
  envFrom:
    - configMapRef:
        name: {{ $config->getName() }}-axum-config
    - secretRef:
        name: {{ $config->getName() }}-axum-secrets
