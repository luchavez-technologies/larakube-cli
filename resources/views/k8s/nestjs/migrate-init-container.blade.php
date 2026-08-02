- name: prisma-migrate
  image: {{ $config->getName() }}:latest
  imagePullPolicy: IfNotPresent
  command:
    - sh
    - -c
    - npx prisma migrate deploy || true
  envFrom:
    - configMapRef:
        name: {{ $config->getName() }}-nestjs-config
    - secretRef:
        name: {{ $config->getName() }}-nestjs-secrets
