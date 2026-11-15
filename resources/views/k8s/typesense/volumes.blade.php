@if(($environment ?? 'local') === 'local')
apiVersion: v1
kind: PersistentVolume
metadata:
  name: {{ $config->getName() }}-typesense-pv
  labels:
    {{ \App\Support\Labels::PROJECT }}: {{ $config->getName() }}
spec:
  capacity:
    storage: 1Gi
  accessModes:
    - ReadWriteOnce
  persistentVolumeReclaimPolicy: Retain
  storageClassName: ""
  hostPath:
    path: {{ $config->getPath() }}/.infrastructure/volume_data/typesense
    type: DirectoryOrCreate
---
@endif
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $config->getName() }}-typesense-pvc
spec:
  accessModes:
    - {{ $config->getStrategy() === \App\Enums\DeploymentStrategy::SINGLE_NODE ? 'ReadWriteOnce' : 'ReadWriteMany' }}
@if(($environment ?? 'local') === 'local')
  storageClassName: ""
  volumeName: {{ $config->getName() }}-typesense-pv
@endif
  resources:
    requests:
      storage: 1Gi
