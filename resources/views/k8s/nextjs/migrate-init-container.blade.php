- name: prisma-migrate
  image: {{ $config->getName() }}:latest
  imagePullPolicy: IfNotPresent
  command:
    - sh
    - -c
    - npx prisma migrate deploy
  envFrom:
    - configMapRef:
        name: {{ $config->getName() }}-nextjs-config
    - secretRef:
        name: {{ $config->getName() }}-nextjs-secrets
