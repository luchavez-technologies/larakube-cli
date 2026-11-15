apiVersion: v1
kind: PersistentVolume
metadata:
  name: {{ $config->getName() }}-laravel-storage-pv
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
    path: "{{ $config->getPath() }}/storage"
    type: DirectoryOrCreate
@if($config->hasDatabase(\App\Enums\DatabaseDriver::SQLITE))
---
apiVersion: v1
kind: PersistentVolume
metadata:
  name: {{ $config->getName() }}-laravel-data-pv
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
    path: "{{ $config->getPath() }}/.infrastructure/volume_data"
    type: DirectoryOrCreate
@endif
