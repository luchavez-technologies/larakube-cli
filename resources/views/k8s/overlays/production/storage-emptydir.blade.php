{{-- Multi-node: swap the shared app-storage volume for a per-pod emptyDir so app
     pods (web + workers) spread across nodes instead of fighting over one
     ReadWriteOnce volume. JSON6902 because strategic-merge can't replace a
     volume's source. The `test` op makes kustomize fail loudly if `storage` ever
     stops being volumes[0], rather than silently replacing the wrong volume.
     Targeted by name (Deployment) in the overlay kustomization — never the
     service pods (postgres/redis/seaweedfs), whose data volumes must stay PVCs. --}}
- op: test
  path: /spec/template/spec/volumes/0/name
  value: storage
- op: replace
  path: /spec/template/spec/volumes/0
  value:
    name: storage
    emptyDir: {}
