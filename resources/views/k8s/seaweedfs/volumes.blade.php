@if(($environment ?? 'local') === 'local')
apiVersion: v1
kind: PersistentVolume
metadata:
  name: {{ $config->getName() }}-seaweedfs-pv
  labels:
    {{ \App\Support\Labels::PROJECT }}: {{ $config->getName() }}
spec:
  capacity:
    storage: 5Gi
  accessModes:
    - ReadWriteOnce
  persistentVolumeReclaimPolicy: Retain
  storageClassName: ""
  hostPath:
    path: {{ $config->getPath() }}/.infrastructure/volume_data/seaweedfs
    type: DirectoryOrCreate
---
@endif
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: {{ $config->getName() }}-seaweedfs-pvc
spec:
  accessModes:
    - {{ $config->getStrategy() === \App\Enums\DeploymentStrategy::SINGLE_NODE ? 'ReadWriteOnce' : 'ReadWriteMany' }}
@if(($environment ?? 'local') === 'local')
  storageClassName: ""
  volumeName: {{ $config->getName() }}-seaweedfs-pv
@endif
  resources:
    requests:
      storage: 5Gi
