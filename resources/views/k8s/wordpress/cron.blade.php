apiVersion: batch/v1
kind: CronJob
metadata:
  name: {{ $config->getName() }}-wordpress-cron
spec:
  schedule: "*/5 * * * *"
  concurrencyPolicy: Forbid
  successfulJobsHistoryLimit: 3
  failedJobsHistoryLimit: 3
  jobTemplate:
    spec:
      template:
        metadata:
          labels:
            app: {{ $config->getName() }}-wordpress-cron
        spec:
          restartPolicy: OnFailure
          containers:
            - name: wp-cron
              image: {{ $config->getName() }}:latest
              imagePullPolicy: IfNotPresent
              command:
                - sh
                - -c
                - wp --allow-root --no-color cron event run --due-now
              envFrom:
                - configMapRef:
                    name: {{ $config->getName() }}-wordpress-config
                - secretRef:
                    name: {{ $config->getName() }}-wordpress-secrets
