{{-- nfs-subdir-external-provisioner: hands out RWX PVs from the NFS server's
     export under the larakube-nfs StorageClass. Applied AFTER the server is
     serving (see CloudProvisionNfsCommand).

     The provisioner mounts the share via a PersistentVolume (not an in-tree
     `nfs:` volume) specifically so we can set `mountOptions: [nfsvers=4.1]` — an
     in-tree nfs volume can't, and without a pinned version the kernel's NFS
     negotiation hangs the mount on some managed clusters (DOKS), leaving the pod
     stuck in ContainerCreating.

     Data safety: archiveOnDelete renames (not deletes) a removed PVC's data;
     reclaimPolicy is Retain when --retain is passed. --}}
apiVersion: v1
kind: ServiceAccount
metadata:
  name: nfs-provisioner
  namespace: nfs
---
kind: ClusterRole
apiVersion: rbac.authorization.k8s.io/v1
metadata:
  name: nfs-provisioner-runner
rules:
  - apiGroups: [""]
    resources: ["nodes"]
    verbs: ["get", "list", "watch"]
  - apiGroups: [""]
    resources: ["persistentvolumes"]
    verbs: ["get", "list", "watch", "create", "delete"]
  - apiGroups: [""]
    resources: ["persistentvolumeclaims"]
    verbs: ["get", "list", "watch", "update"]
  - apiGroups: ["storage.k8s.io"]
    resources: ["storageclasses"]
    verbs: ["get", "list", "watch"]
  - apiGroups: [""]
    resources: ["events"]
    verbs: ["create", "update", "patch"]
---
kind: ClusterRoleBinding
apiVersion: rbac.authorization.k8s.io/v1
metadata:
  name: run-nfs-provisioner
subjects:
  - kind: ServiceAccount
    name: nfs-provisioner
    namespace: nfs
roleRef:
  kind: ClusterRole
  name: nfs-provisioner-runner
  apiGroup: rbac.authorization.k8s.io
---
kind: Role
apiVersion: rbac.authorization.k8s.io/v1
metadata:
  name: leader-locking-nfs-provisioner
  namespace: nfs
rules:
  - apiGroups: [""]
    resources: ["endpoints"]
    verbs: ["get", "list", "watch", "create", "update", "patch"]
---
kind: RoleBinding
apiVersion: rbac.authorization.k8s.io/v1
metadata:
  name: leader-locking-nfs-provisioner
  namespace: nfs
subjects:
  - kind: ServiceAccount
    name: nfs-provisioner
    namespace: nfs
roleRef:
  kind: Role
  name: leader-locking-nfs-provisioner
  apiGroup: rbac.authorization.k8s.io
---
apiVersion: v1
kind: PersistentVolume
metadata:
  name: nfs-provisioner-root
  labels:
    app: nfs-provisioner
spec:
  capacity:
    storage: 1Mi
  accessModes:
    - ReadWriteMany
  persistentVolumeReclaimPolicy: Retain
  storageClassName: ""
  mountOptions:
    - nfsvers=4.1
  nfs:
    server: nfs-server.nfs.svc.cluster.local
    path: /
---
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: nfs-provisioner-root
  namespace: nfs
spec:
  accessModes:
    - ReadWriteMany
  storageClassName: ""
  volumeName: nfs-provisioner-root
  resources:
    requests:
      storage: 1Mi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: nfs-provisioner
  namespace: nfs
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels:
      app: nfs-provisioner
  template:
    metadata:
      labels:
        app: nfs-provisioner
    spec:
      serviceAccountName: nfs-provisioner
      containers:
        - name: nfs-provisioner
          image: registry.k8s.io/sig-storage/nfs-subdir-external-provisioner:v4.0.2
          env:
            - name: PROVISIONER_NAME
              value: {{ \App\Support\Labels::NFS_PROVISIONER }}
            - name: NFS_SERVER
              value: nfs-server.nfs.svc.cluster.local
            - name: NFS_PATH
              value: /
          resources:
            requests:
              cpu: 20m
              memory: 32Mi
          volumeMounts:
            - name: nfs-root
              mountPath: /persistentvolumes
      volumes:
        - name: nfs-root
          persistentVolumeClaim:
            claimName: nfs-provisioner-root
---
apiVersion: storage.k8s.io/v1
kind: StorageClass
metadata:
  name: {{ \App\Data\ConfigData::NFS_STORAGE_CLASS }}
provisioner: {{ \App\Support\Labels::NFS_PROVISIONER }}
parameters:
  archiveOnDelete: "{{ $archiveOnDelete }}"
reclaimPolicy: {{ $reclaimPolicy }}
allowVolumeExpansion: true
mountOptions:
  - nfsvers=4.1
