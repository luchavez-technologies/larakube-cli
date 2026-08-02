- name: flyway-migrate
  image: {{ $config->getName() }}:latest
  imagePullPolicy: IfNotPresent
  command:
    - sh
    - -c
    - java -jar app.jar --flyway.enabled=true || true
  envFrom:
    - configMapRef:
        name: {{ $config->getName() }}-springboot-config
    - secretRef:
        name: {{ $config->getName() }}-springboot-secrets
