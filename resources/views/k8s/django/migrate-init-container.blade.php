- name: django-migrate
  image: {{ $config->getName() }}:latest
  imagePullPolicy: IfNotPresent
  command:
    - sh
    - -c
    - python manage.py migrate --noinput
  envFrom:
    - configMapRef:
        name: {{ $config->getName() }}-django-config
    - secretRef:
        name: {{ $config->getName() }}-django-secrets
