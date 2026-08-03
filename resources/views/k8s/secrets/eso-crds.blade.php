apiVersion: apiextensions.k8s.io/v1
kind: CustomResourceDefinition
metadata:
  name: secretstores.external-secrets.io
spec:
  group: external-secrets.io
  names:
    kind: SecretStore
    listKind: SecretStoreList
    plural: secretstores
    singular: secretstore
  scope: Namespaced
  versions:
  - name: v1beta1
    served: true
    storage: true
    schema:
      openAPIV3Schema:
        type: object
        x-kubernetes-preserve-unknown-fields: true
    subresources:
      status: {}
---
apiVersion: apiextensions.k8s.io/v1
kind: CustomResourceDefinition
metadata:
  name: clustersecretstores.external-secrets.io
spec:
  group: external-secrets.io
  names:
    kind: ClusterSecretStore
    listKind: ClusterSecretStoreList
    plural: clustersecretstores
    singular: clustersecretstore
  scope: Cluster
  versions:
  - name: v1beta1
    served: true
    storage: true
    schema:
      openAPIV3Schema:
        type: object
        x-kubernetes-preserve-unknown-fields: true
    subresources:
      status: {}
---
apiVersion: apiextensions.k8s.io/v1
kind: CustomResourceDefinition
metadata:
  name: externalsecrets.external-secrets.io
spec:
  group: external-secrets.io
  names:
    kind: ExternalSecret
    listKind: ExternalSecretList
    plural: externalsecrets
    singular: externalsecret
  scope: Namespaced
  versions:
  - name: v1beta1
    served: true
    storage: true
    schema:
      openAPIV3Schema:
        type: object
        x-kubernetes-preserve-unknown-fields: true
    subresources:
      status: {}
---
apiVersion: apiextensions.k8s.io/v1
kind: CustomResourceDefinition
metadata:
  name: clusterexternalsecrets.external-secrets.io
spec:
  group: external-secrets.io
  names:
    kind: ClusterExternalSecret
    listKind: ClusterExternalSecretList
    plural: clusterexternalsecrets
    singular: clusterexternalsecret
  scope: Cluster
  versions:
  - name: v1beta1
    served: true
    storage: true
    schema:
      openAPIV3Schema:
        type: object
        x-kubernetes-preserve-unknown-fields: true
    subresources:
      status: {}
---
apiVersion: apiextensions.k8s.io/v1
kind: CustomResourceDefinition
metadata:
  name: pushsecrets.external-secrets.io
spec:
  group: external-secrets.io
  names:
    kind: PushSecret
    listKind: PushSecretList
    plural: pushsecrets
    singular: pushsecret
  scope: Namespaced
  versions:
  - name: v1alpha1
    served: true
    storage: true
    schema:
      openAPIV3Schema:
        type: object
        x-kubernetes-preserve-unknown-fields: true
    subresources:
      status: {}
