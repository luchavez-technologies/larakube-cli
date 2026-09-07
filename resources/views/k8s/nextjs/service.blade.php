apiVersion: v1
kind: Service
metadata:
  name: {{ $resourceName }}
spec:
  selector:
    app: {{ $resourceName }}
  ports:
    - protocol: TCP
      port: 3000
      targetPort: 3000
  type: ClusterIP
