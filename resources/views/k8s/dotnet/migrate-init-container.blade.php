- name: ef-migrate
  image: {{ $config->getName() }}:latest
  imagePullPolicy: IfNotPresent
  command:
    - sh
    - -c
    - dotnet ef database update || true
  envFrom:
    - configMapRef:
        name: {{ $config->getName() }}-dotnet-config
    - secretRef:
        name: {{ $config->getName() }}-dotnet-secrets
