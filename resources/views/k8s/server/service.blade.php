@php($port = $config->framework->containerPort())
apiVersion: v1
kind: Service
metadata:
  name: {{ $resourceName }}
spec:
  selector:
    app: {{ $resourceName }}
  ports:
    - protocol: TCP
      port: {{ $port }}
      targetPort: {{ $port }}
  type: ClusterIP
