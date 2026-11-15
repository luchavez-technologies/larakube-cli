apiVersion: v1
kind: Namespace
metadata:
  name: {{ $namespace }}
  labels:
    {{ \App\Support\Labels::PROJECT }}: {{ $config->getName() }}
    {{ \App\Support\Labels::MANAGED_BY }}: larakube
