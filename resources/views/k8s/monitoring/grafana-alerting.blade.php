---
# ── Grafana alerting provisioning ────────────────────────────────────────────
# Native Grafana alerting (no Alertmanager): email contact point + policy +
# Traefik-metrics rules. Delivery needs Grafana's SMTP env wired:
#   larakube mail:wire monitor
# (pins Stalwart's 587 STARTTLS listener — Grafana's Go mailer cannot do the
# 465 implicit-TLS handshake Stalwart's submissions port expects).
apiVersion: v1
kind: ConfigMap
metadata:
  name: grafana-alerting
  namespace: larakube-shared
data:
  contact-points.yaml: |
    apiVersion: 1
    contactPoints:
      - orgId: 1
        name: Email
        receivers:
          - uid: email-to-eman
            type: email
            settings:
              addresses: eman@luchtech.dev
  notification-policies.yaml: |
    apiVersion: 1
    policies:
      - orgId: 1
        policy:
          receiver: Email
          group_by: ['grafana_folder', 'alertname']
          group_wait: 30s
          group_interval: 5m
          repeat_interval: 4h
  alert-rules.yaml: |
    apiVersion: 1
    groups:
      - orgId: 1
        name: Web Traffic
        folder: LaraKube
        interval: 1m
        rules:
          - uid: web-high-5xx-rate
            title: High 5xx Rate
            condition: B
            for: 5m
            labels:
              severity: warning
            annotations:
              summary: "Web traffic 5xx rate is high"
              description: "5xx responses are above 5% of all traffic over the last 5 minutes."
            data:
              - refId: A
                relativeTimeRange:
                  from: 300
                  to: 0
                datasourceUid: prometheus
                model:
                  refId: A
                  expr: 100 * sum(rate(traefik_service_requests_total{code=~"5.."}[5m])) / clamp_min(sum(rate(traefik_service_requests_total[5m])), 1e-6)
                  instant: true
              - refId: B
                datasourceUid: __expr__
                model:
                  refId: B
                  type: threshold
                  expression: A
                  conditions:
                    - evaluator:
                        type: gt
                        params: [5]
                      operator:
                        type: and
          - uid: web-slow-p95
            title: High p95 Latency
            condition: B
            for: 10m
            labels:
              severity: warning
            annotations:
              summary: "Web traffic p95 latency is high"
              description: "p95 response time is above 500ms over the last 5 minutes."
            data:
              - refId: A
                relativeTimeRange:
                  from: 300
                  to: 0
                datasourceUid: prometheus
                model:
                  refId: A
                  expr: histogram_quantile(0.95, sum by (le) (rate(traefik_service_request_duration_seconds_bucket[5m])))
                  instant: true
              - refId: B
                datasourceUid: __expr__
                model:
                  refId: B
                  type: threshold
                  expression: A
                  conditions:
                    - evaluator:
                        type: gt
                        params: [0.5]
                      operator:
                        type: and
          - uid: web-traffic-drop
            title: Web Traffic Drop
            condition: B
            for: 10m
            labels:
              severity: critical
            annotations:
              summary: "Web traffic has dropped"
              description: "Traffic is below 0.1 requests/sec for 10 minutes — possible outage or ingress failure."
            data:
              - refId: A
                relativeTimeRange:
                  from: 600
                  to: 0
                datasourceUid: prometheus
                model:
                  refId: A
                  expr: sum(rate(traefik_service_requests_total[10m]))
                  instant: true
              - refId: B
                datasourceUid: __expr__
                model:
                  refId: B
                  type: threshold
                  expression: A
                  conditions:
                    - evaluator:
                        type: lt
                        params: [0.1]
                      operator:
                        type: and
