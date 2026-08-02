- name: lucid-migrate
  image: {{ $config->getName() }}:latest
  imagePullPolicy: IfNotPresent
  command:
    - sh
    - -c
    - node ace migration:run --force || true
  envFrom:
    - configMapRef:
        name: {{ $config->getName() }}-adonisjs-config
    - secretRef:
        name: {{ $config->getName() }}-adonisjs-secrets
