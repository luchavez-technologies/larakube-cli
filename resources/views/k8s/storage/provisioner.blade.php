apiVersion: snapshot.storage.k8s.io/v1
kind: VolumeSnapshotClass
metadata:
  name: csi-do-snapclass
driver: do.csi.digitalocean.com
deletionPolicy: Delete
