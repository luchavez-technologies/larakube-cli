---
# ── Grafana dashboard provisioning ───────────────────────────────────────────
# Web Traffic Overview: HTTP traffic for every app behind Traefik, from
# traefik_service_* metrics. The `service` dashboard variable filters to a
# single app; default (All) shows the whole fleet.
apiVersion: v1
kind: ConfigMap
metadata:
  name: grafana-dashboards
  namespace: larakube-shared
data:
  dashboards.yaml: |
    apiVersion: 1
    providers:
      - name: larakube
        orgId: 1
        folder: LaraKube
        type: file
        disableDeletion: false
        allowUiUpdates: false
        updateIntervalSeconds: 30
        options:
          path: /etc/grafana/provisioning/dashboards
  web-traffic-overview.json: |
@verbatim
    {
      "uid": "web-traffic-overview",
      "title": "Web Traffic Overview",
      "tags": ["larakube", "traffic"],
      "timezone": "browser",
      "schemaVersion": 39,
      "version": 1,
      "refresh": "30s",
      "time": { "from": "now-6h", "to": "now" },
      "templating": {
        "list": [
          {
            "name": "service",
            "type": "query",
            "datasource": { "type": "prometheus", "uid": "prometheus" },
            "definition": "label_values(traefik_service_requests_total, service)",
            "query": { "query": "label_values(traefik_service_requests_total, service)", "refId": "StandardVariableQuery" },
            "includeAll": true,
            "multi": true,
            "allValue": ".*",
            "sort": 1,
            "refresh": 1
          }
        ]
      },
      "panels": [
        {
          "id": 1,
          "type": "timeseries",
          "title": "Request Rate",
          "description": "Total HTTP requests per second across all apps, or for the selected service.",
          "gridPos": { "x": 0, "y": 0, "w": 12, "h": 6 },
          "datasource": { "type": "prometheus", "uid": "prometheus" },
          "fieldConfig": {
            "defaults": { "unit": "reqps", "custom": { "drawStyle": "line", "lineWidth": 2, "fillOpacity": 10 } },
            "overrides": []
          },
          "options": { "legend": { "displayMode": "list", "placement": "bottom", "showLegend": false }, "tooltip": { "mode": "multi" } },
          "targets": [
            { "refId": "A", "datasource": { "type": "prometheus", "uid": "prometheus" }, "expr": "sum(rate(traefik_service_requests_total{service=~\"$service\"}[$__rate_interval]))", "legendFormat": "all services" }
          ]
        },
        {
          "id": 2,
          "type": "timeseries",
          "title": "Requests by Service",
          "description": "Request rate split per app (Traefik service). Shows which app is handling what share of traffic.",
          "gridPos": { "x": 12, "y": 0, "w": 12, "h": 6 },
          "datasource": { "type": "prometheus", "uid": "prometheus" },
          "fieldConfig": {
            "defaults": { "unit": "reqps", "custom": { "drawStyle": "line", "lineWidth": 1, "fillOpacity": 30, "stacking": { "mode": "normal" } } },
            "overrides": []
          },
          "options": { "legend": { "displayMode": "list", "placement": "bottom", "showLegend": true }, "tooltip": { "mode": "multi" } },
          "targets": [
            { "refId": "A", "datasource": { "type": "prometheus", "uid": "prometheus" }, "expr": "sum by (service) (rate(traefik_service_requests_total{service=~\"$service\"}[$__rate_interval]))", "legendFormat": "{{service}}" }
          ]
        },
        {
          "id": 3,
          "type": "timeseries",
          "title": "Status Codes",
          "description": "Request rate grouped by HTTP status code (2xx, 3xx, 4xx, 5xx). Spikes in the 4xx/5xx series point at client errors or broken apps.",
          "gridPos": { "x": 0, "y": 6, "w": 8, "h": 6 },
          "datasource": { "type": "prometheus", "uid": "prometheus" },
          "fieldConfig": {
            "defaults": { "unit": "reqps", "custom": { "drawStyle": "line", "lineWidth": 1, "fillOpacity": 30, "stacking": { "mode": "normal" } } },
            "overrides": [
              { "matcher": { "id": "byName", "options": "5xx" }, "properties": [{ "id": "color", "value": { "fixedColor": "#e24d42", "mode": "fixed" } }] }
            ]
          },
          "options": { "legend": { "displayMode": "list", "placement": "bottom", "showLegend": true }, "tooltip": { "mode": "multi" } },
          "targets": [
            { "refId": "A", "datasource": { "type": "prometheus", "uid": "prometheus" }, "expr": "sum by (code) (rate(traefik_service_requests_total{service=~\"$service\"}[$__rate_interval]))", "legendFormat": "{{code}}" }
          ]
        },
        {
          "id": 4,
          "type": "timeseries",
          "title": "5xx Error Rate",
          "description": "Percentage of responses returning a 5xx status. Above 5% sustained means the app is failing — cross-check with the Web Traffic alert.",
          "gridPos": { "x": 8, "y": 6, "w": 8, "h": 6 },
          "datasource": { "type": "prometheus", "uid": "prometheus" },
          "fieldConfig": {
            "defaults": {
              "unit": "percent",
              "custom": { "drawStyle": "line", "lineWidth": 2, "fillOpacity": 10 },
              "thresholds": { "mode": "absolute", "steps": [ { "color": "green", "value": null }, { "color": "yellow", "value": 1 }, { "color": "red", "value": 5 } ] }
            },
            "overrides": []
          },
          "options": { "legend": { "displayMode": "list", "placement": "bottom", "showLegend": false }, "tooltip": { "mode": "multi" } },
          "targets": [
            { "refId": "A", "datasource": { "type": "prometheus", "uid": "prometheus" }, "expr": "100 * sum(rate(traefik_service_requests_total{service=~\"$service\", code=~\"5..\"}[$__rate_interval])) / clamp_min(sum(rate(traefik_service_requests_total{service=~\"$service\"}[$__rate_interval])), 1e-6)", "legendFormat": "5xx rate" }
          ]
        },
        {
          "id": 5,
          "type": "timeseries",
          "title": "p95 Latency",
          "description": "95th percentile response time. 95% of requests complete faster than this value.",
          "gridPos": { "x": 16, "y": 6, "w": 8, "h": 6 },
          "datasource": { "type": "prometheus", "uid": "prometheus" },
          "fieldConfig": {
            "defaults": { "unit": "s", "custom": { "drawStyle": "line", "lineWidth": 2, "fillOpacity": 10 } },
            "overrides": []
          },
          "options": { "legend": { "displayMode": "list", "placement": "bottom", "showLegend": false }, "tooltip": { "mode": "multi" } },
          "targets": [
            { "refId": "A", "datasource": { "type": "prometheus", "uid": "prometheus" }, "expr": "histogram_quantile(0.95, sum by (le) (rate(traefik_service_request_duration_seconds_bucket{service=~\"$service\"}[$__rate_interval])))", "legendFormat": "p95" }
          ]
        },
        {
          "id": 6,
          "type": "timeseries",
          "title": "p99 Latency",
          "description": "99th percentile response time — the slowest 1% of requests. Tail latency that pages users.",
          "gridPos": { "x": 0, "y": 12, "w": 8, "h": 6 },
          "datasource": { "type": "prometheus", "uid": "prometheus" },
          "fieldConfig": {
            "defaults": { "unit": "s", "custom": { "drawStyle": "line", "lineWidth": 2, "fillOpacity": 10 } },
            "overrides": []
          },
          "options": { "legend": { "displayMode": "list", "placement": "bottom", "showLegend": false }, "tooltip": { "mode": "multi" } },
          "targets": [
            { "refId": "A", "datasource": { "type": "prometheus", "uid": "prometheus" }, "expr": "histogram_quantile(0.99, sum by (le) (rate(traefik_service_request_duration_seconds_bucket{service=~\"$service\"}[$__rate_interval])))", "legendFormat": "p99" }
          ]
        },
        {
          "id": 7,
          "type": "bargauge",
          "title": "Top Services by Requests",
          "description": "The 10 busiest apps by current request rate.",
          "gridPos": { "x": 8, "y": 12, "w": 8, "h": 6 },
          "datasource": { "type": "prometheus", "uid": "prometheus" },
          "fieldConfig": {
            "defaults": { "unit": "reqps", "color": { "mode": "palette-classic" } },
            "overrides": []
          },
          "options": { "orientation": "horizontal", "displayMode": "gradient", "showUnfilled": true },
          "targets": [
            { "refId": "A", "datasource": { "type": "prometheus", "uid": "prometheus" }, "expr": "topk(10, sum by (service) (rate(traefik_service_requests_total{service=~\"$service\"}[$__rate_interval])))", "legendFormat": "{{service}}" }
          ]
        },
        {
          "id": 8,
          "type": "table",
          "title": "Error Rate by Service",
          "description": "Current 5xx percentage per app, so a single failing app stands out from a healthy fleet.",
          "gridPos": { "x": 16, "y": 12, "w": 8, "h": 6 },
          "datasource": { "type": "prometheus", "uid": "prometheus" },
          "fieldConfig": {
            "defaults": { "unit": "percent", "custom": { "align": "auto", "cellOptions": { "type": "auto" } } },
            "overrides": []
          },
          "options": { "showHeader": true },
          "targets": [
            { "refId": "A", "datasource": { "type": "prometheus", "uid": "prometheus" }, "expr": "100 * sum by (service) (rate(traefik_service_requests_total{service=~\"$service\", code=~\"5..\"}[$__rate_interval])) / clamp_min(sum by (service) (rate(traefik_service_requests_total{service=~\"$service\"}[$__rate_interval])), 1e-6)", "legendFormat": "__auto", "instant": true }
          ]
        }
      ]
    }
@endverbatim
