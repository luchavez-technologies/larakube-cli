apiVersion: apiextensions.k8s.io/v1
kind: CustomResourceDefinition
metadata:
  name: infisicalsecrets.secrets.infisical.com
spec:
  group: secrets.infisical.com
  names:
    kind: InfisicalSecret
    listKind: InfisicalSecretList
    plural: infisicalsecrets
    singular: infisicalsecret
  scope: Namespaced
  versions:
  - name: v1alpha1
    schema:
      openAPIV3Schema:
        description: InfisicalSecret is the Schema for the infisicalsecrets API
        properties:
          apiVersion:
            type: string
          kind:
            type: string
          metadata:
            type: object
          spec:
            properties:
              authentication:
                properties:
                  serviceToken:
                    properties:
                      secretsScope:
                        properties:
                          envSlug:
                            type: string
                          recursive:
                            type: boolean
                          secretsPath:
                            type: string
                        required:
                        - envSlug
                        - secretsPath
                        type: object
                      serviceTokenSecretReference:
                        properties:
                          secretName:
                            type: string
                          secretNamespace:
                            type: string
                        required:
                        - secretName
                        - secretNamespace
                        type: object
                    required:
                    - secretsScope
                    - serviceTokenSecretReference
                    type: object
                  universalAuth:
                    properties:
                      credentialsRef:
                        properties:
                          secretName:
                            type: string
                          secretNamespace:
                            type: string
                        required:
                        - secretName
                        - secretNamespace
                        type: object
                      secretsScope:
                        properties:
                          envSlug:
                            type: string
                          projectId:
                            type: string
                          projectSlug:
                            type: string
                          recursive:
                            type: boolean
                          secretName:
                            type: string
                          secretsPath:
                            type: string
                        required:
                        - envSlug
                        - secretsPath
                        type: object
                    required:
                    - credentialsRef
                    - secretsScope
                    type: object
                  kubernetesAuth:
                    properties:
                      identityId:
                        type: string
                      secretsScope:
                        properties:
                          envSlug:
                            type: string
                          projectId:
                            type: string
                          projectSlug:
                            type: string
                          recursive:
                            type: boolean
                          secretName:
                            type: string
                          secretsPath:
                            type: string
                        required:
                        - envSlug
                        - secretsPath
                        type: object
                      serviceAccountRef:
                        properties:
                          name:
                            type: string
                          namespace:
                            type: string
                        required:
                        - name
                        - namespace
                        type: object
                    required:
                    - identityId
                    - secretsScope
                    - serviceAccountRef
                    type: object
                type: object
              hostAPI:
                type: string
              managedKubeSecretReferences:
                items:
                  properties:
                    creationPolicy:
                      type: string
                    secretName:
                      type: string
                    secretNamespace:
                      type: string
                    template:
                      properties:
                        data:
                          additionalProperties:
                            type: string
                          type: object
                      type: object
                  required:
                  - secretName
                  - secretNamespace
                  type: object
                type: array
              managedSecretReference:
                properties:
                  creationPolicy:
                    type: string
                  secretName:
                    type: string
                  secretNamespace:
                    type: string
                  template:
                    properties:
                      data:
                        additionalProperties:
                          type: string
                        type: object
                    type: object
                required:
                - secretName
                - secretNamespace
                type: object
              resyncInterval:
                type: integer
            type: object
          status:
            type: object
        type: object
    served: true
    storage: true
    subresources:
      status: {}
---
apiVersion: apiextensions.k8s.io/v1
kind: CustomResourceDefinition
metadata:
  name: infisicalpushsecrets.secrets.infisical.com
spec:
  group: secrets.infisical.com
  names:
    kind: InfisicalPushSecret
    listKind: InfisicalPushSecretList
    plural: infisicalpushsecrets
    singular: infisicalpushsecret
  scope: Namespaced
  versions:
  - name: v1alpha1
    schema:
      openAPIV3Schema:
        description: InfisicalPushSecret is the Schema for the infisicalpushsecrets API
        properties:
          apiVersion:
            type: string
          kind:
            type: string
          metadata:
            type: object
          spec:
            properties:
              authentication:
                properties:
                  universalAuth:
                    properties:
                      credentialsRef:
                        properties:
                          secretName:
                            type: string
                          secretNamespace:
                            type: string
                        required:
                        - secretName
                        - secretNamespace
                        type: object
                    required:
                    - credentialsRef
                    type: object
                type: object
              destination:
                properties:
                  environmentSlug:
                    type: string
                  projectId:
                    type: string
                  projectSlug:
                    type: string
                  secretsPath:
                    type: string
                required:
                - environmentSlug
                - secretsPath
                type: object
              hostAPI:
                type: string
              push:
                properties:
                  secret:
                    properties:
                      secretName:
                        type: string
                      secretNamespace:
                        type: string
                    required:
                    - secretName
                    - secretNamespace
                    type: object
                type: object
              resyncInterval:
                type: string
            required:
            - destination
            - push
            type: object
          status:
            type: object
        type: object
    served: true
    storage: true
    subresources:
      status: {}
---
apiVersion: apiextensions.k8s.io/v1
kind: CustomResourceDefinition
metadata:
  name: infisicaldynamicsecrets.secrets.infisical.com
spec:
  group: secrets.infisical.com
  names:
    kind: InfisicalDynamicSecret
    listKind: InfisicalDynamicSecretList
    plural: infisicaldynamicsecrets
    singular: infisicaldynamicsecret
  scope: Namespaced
  versions:
  - name: v1alpha1
    schema:
      openAPIV3Schema:
        description: InfisicalDynamicSecret is the Schema for the infisicaldynamicsecrets API
        properties:
          apiVersion:
            type: string
          kind:
            type: string
          metadata:
            type: object
          spec:
            properties:
              authentication:
                properties:
                  universalAuth:
                    properties:
                      credentialsRef:
                        properties:
                          secretName:
                            type: string
                          secretNamespace:
                            type: string
                        required:
                        - secretName
                        - secretNamespace
                        type: object
                    required:
                    - credentialsRef
                    type: object
                type: object
              dynamicSecret:
                properties:
                  environmentSlug:
                    type: string
                  projectId:
                    type: string
                  projectSlug:
                    type: string
                  secretName:
                    type: string
                  secretsPath:
                    type: string
                required:
                - environmentSlug
                - secretName
                - secretsPath
                type: object
              hostAPI:
                type: string
              managedSecretReference:
                properties:
                  creationPolicy:
                    type: string
                  secretName:
                    type: string
                  secretNamespace:
                    type: string
                required:
                - secretName
                - secretNamespace
                type: object
            required:
            - authentication
            - dynamicSecret
            - managedSecretReference
            type: object
          status:
            type: object
        type: object
    served: true
    storage: true
    subresources:
      status: {}
---
apiVersion: apiextensions.k8s.io/v1
kind: CustomResourceDefinition
metadata:
  name: clustergenerators.secrets.infisical.com
spec:
  group: secrets.infisical.com
  names:
    kind: ClusterGenerator
    listKind: ClusterGeneratorList
    plural: clustergenerators
    singular: clustergenerator
  scope: Cluster
  versions:
  - name: v1alpha1
    schema:
      openAPIV3Schema:
        description: ClusterGenerator represents a cluster-wide generator
        properties:
          apiVersion:
            type: string
          kind:
            type: string
          metadata:
            type: object
          spec:
            properties:
              generator:
                properties:
                  passwordSpec:
                    properties:
                      allowRepeat:
                        type: boolean
                      digits:
                        type: integer
                      length:
                        type: integer
                      noUpper:
                        type: boolean
                      symbols:
                        type: integer
                    type: object
                type: object
              kind:
                enum:
                - Password
                - UUID
                type: string
            required:
            - kind
            type: object
        type: object
    served: true
    storage: true
    subresources:
      status: {}
---
apiVersion: apiextensions.k8s.io/v1
kind: CustomResourceDefinition
metadata:
  name: infisicalstaticsecrets.secrets.infisical.com
spec:
  group: secrets.infisical.com
  names:
    kind: InfisicalStaticSecret
    listKind: InfisicalStaticSecretList
    plural: infisicalstaticsecrets
    singular: infisicalstaticsecret
  scope: Namespaced
  versions:
  - name: v1beta1
    schema:
      openAPIV3Schema:
        description: InfisicalStaticSecret is the Schema for the InfisicalStaticSecret API
        properties:
          apiVersion:
            type: string
          kind:
            type: string
          metadata:
            type: object
          spec:
            properties:
              infisicalAuthRef:
                properties:
                  name:
                    type: string
                  namespace:
                    type: string
                required:
                - name
                - namespace
                type: object
              sources:
                items:
                  properties:
                    environmentSlug:
                      type: string
                    projectId:
                      type: string
                    projectSlug:
                      type: string
                    recursive:
                      type: boolean
                    secretPath:
                      type: string
                    tagSlugs:
                      items:
                        type: string
                      type: array
                  required:
                  - environmentSlug
                  - secretPath
                  type: object
                type: array
              syncOptions:
                properties:
                  instantUpdates:
                    type: boolean
                  refreshInterval:
                    type: string
                required:
                - refreshInterval
                type: object
              targets:
                items:
                  properties:
                    creationPolicy:
                      enum:
                      - Owner
                      - Orphan
                      type: string
                    kind:
                      enum:
                      - Secret
                      - ConfigMap
                      type: string
                    metadata:
                      properties:
                        annotations:
                          additionalProperties:
                            type: string
                          type: object
                        labels:
                          additionalProperties:
                            type: string
                          type: object
                      type: object
                    name:
                      type: string
                    namespace:
                      type: string
                    secretType:
                      type: string
                    template:
                      properties:
                        data:
                          x-kubernetes-preserve-unknown-fields: true
                        engineVersion:
                          enum:
                          - v1
                          type: string
                      required:
                      - data
                      - engineVersion
                      type: object
                  required:
                  - creationPolicy
                  - kind
                  - name
                  - namespace
                  type: object
                type: array
            required:
            - infisicalAuthRef
            - sources
            - syncOptions
            - targets
            type: object
          status:
            type: object
            x-kubernetes-preserve-unknown-fields: true
        type: object
    served: true
    storage: true
    subresources:
      status: {}
---
apiVersion: apiextensions.k8s.io/v1
kind: CustomResourceDefinition
metadata:
  name: infisicalauths.secrets.infisical.com
spec:
  group: secrets.infisical.com
  names:
    kind: InfisicalAuth
    listKind: InfisicalAuthList
    plural: infisicalauths
    singular: infisicalauth
  scope: Namespaced
  versions:
  - name: v1beta1
    schema:
      openAPIV3Schema:
        description: InfisicalAuth is the Schema for the InfisicalAuth API
        properties:
          apiVersion:
            type: string
          kind:
            type: string
          metadata:
            type: object
          spec:
            properties:
              infisicalConnectionRef:
                properties:
                  name:
                    type: string
                  namespace:
                    type: string
                required:
                - name
                - namespace
                type: object
              method:
                enum:
                - universal
                - kubernetes
                - aws-iam
                - azure
                - gcp-id-token
                - gcp-iam
                - ldap
                type: string
              universal:
                properties:
                  clientIdRef:
                    properties:
                      key:
                        type: string
                      name:
                        type: string
                      namespace:
                        type: string
                    required:
                    - key
                    - name
                    - namespace
                    type: object
                  clientSecretRef:
                    properties:
                      key:
                        type: string
                      name:
                        type: string
                      namespace:
                        type: string
                    required:
                    - key
                    - name
                    - namespace
                    type: object
                required:
                - clientIdRef
                - clientSecretRef
                type: object
            required:
            - infisicalConnectionRef
            - method
            type: object
          status:
            type: object
            x-kubernetes-preserve-unknown-fields: true
        type: object
    served: true
    storage: true
    subresources:
      status: {}
---
apiVersion: apiextensions.k8s.io/v1
kind: CustomResourceDefinition
metadata:
  name: infisicalconnections.secrets.infisical.com
spec:
  group: secrets.infisical.com
  names:
    kind: InfisicalConnection
    listKind: InfisicalConnectionList
    plural: infisicalconnections
    singular: infisicalconnection
  scope: Namespaced
  versions:
  - name: v1beta1
    schema:
      openAPIV3Schema:
        description: InfisicalConnection is the Schema for the infisicalconnection API
        properties:
          apiVersion:
            type: string
          kind:
            type: string
          metadata:
            type: object
          spec:
            properties:
              address:
                type: string
              tls:
                properties:
                  caCertificate:
                    properties:
                      key:
                        type: string
                      name:
                        type: string
                      namespace:
                        type: string
                    type: object
                type: object
            type: object
          status:
            type: object
            x-kubernetes-preserve-unknown-fields: true
        type: object
    served: true
    storage: true
    subresources:
      status: {}
