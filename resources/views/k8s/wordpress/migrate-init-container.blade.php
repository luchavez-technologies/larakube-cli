- name: wp-db-update
  image: {{ $config->getName() }}:latest
  imagePullPolicy: IfNotPresent
  command:
    - sh
    - -c
    - |
      wp --allow-root core update-db --no-color 2>/dev/null || true
  envFrom:
    - configMapRef:
        name: {{ $config->getName() }}-wordpress-config
    - secretRef:
        name: {{ $config->getName() }}-wordpress-secrets
