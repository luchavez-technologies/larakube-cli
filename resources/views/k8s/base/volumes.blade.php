apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $config->getName() }}-laravel-storage-pvc
spec:
  accessModes:
    - {{ $config->getStrategy() === \App\Enums\DeploymentStrategy::SINGLE_NODE ? 'ReadWriteOnce' : 'ReadWriteMany' }}
  resources:
    requests:
      storage: 1Gi
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $config->getName() }}-laravel-data-pvc
spec:
  accessModes:
    - {{ $config->getStrategy() === \App\Enums\DeploymentStrategy::SINGLE_NODE ? 'ReadWriteOnce' : 'ReadWriteMany' }}
  resources:
    requests:
      storage: 1Gi
