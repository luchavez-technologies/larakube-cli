apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $config->getName() }}-sqlite-data
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 1Gi

