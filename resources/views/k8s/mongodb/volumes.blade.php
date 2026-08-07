@if(($environment ?? 'local') === 'local')
apiVersion: v1
kind: PersistentVolume
metadata:
  name: {{ $config->getName() }}-mongodb-pv
  labels:
    larakube-project: {{ $config->getName() }}
spec:
  capacity:
    storage: 5Gi
  accessModes:
    - ReadWriteOnce
  persistentVolumeReclaimPolicy: Retain
  storageClassName: ""
  hostPath:
    path: {{ $config->getPath() }}/.infrastructure/volume_data/mongodb
    type: DirectoryOrCreate
---
@endif
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $config->getName() }}-mongodb-pvc
spec:
  accessModes:
    - {{ $config->getStrategy() === \App\Enums\DeploymentStrategy::SINGLE_NODE ? 'ReadWriteOnce' : 'ReadWriteMany' }}
@if(($environment ?? 'local') === 'local')
  storageClassName: ""
  volumeName: {{ $config->getName() }}-mongodb-pv
@endif
  resources:
    requests:
      storage: 5Gi
