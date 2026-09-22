{{-- Google Kubernetes Engine (GKE) managed cluster.
     Rendered by cloud:create into ~/.larakube/tofu/<stack>/main.tf.
     Credentials are passed via environment variables (never written directly into HCL). --}}
terraform {
  required_providers {
    google = {
      source  = "hashicorp/google"
      version = "~> 6.0"
    }
  }
}

variable "gcp_project_id" {
  type        = string
  description = "Google Cloud Project ID"
}

variable "gcp_credentials" {
  type        = string
  default     = null
  sensitive   = true
  description = "Optional service account JSON key content"
}

provider "google" {
  project     = var.gcp_project_id
  region      = "{{ $region }}"
  credentials = var.gcp_credentials
}

# Automatically ensure Compute Engine and GKE APIs are active (idempotent, won't disable on teardown).
resource "google_project_service" "compute" {
  service            = "compute.googleapis.com"
  disable_on_destroy = false
}

resource "google_project_service" "container" {
  service            = "container.googleapis.com"
  disable_on_destroy = false
}

data "google_client_config" "default" {}

resource "google_container_cluster" "larakube" {
  name     = "{{ $clusterName }}"
  location = "{{ $zone ?? ($region . '-a') }}"

  # Zonal single-pool deployment minimizes resource costs and control-plane fees for workshops
  initial_node_count  = {{ (int) ($nodeCount ?? 2) }}
  deletion_protection = false

  node_config {
    machine_type = "{{ $size }}"
    disk_size_gb = 30
    oauth_scopes = [
      "https://www.googleapis.com/auth/cloud-platform",
    ]
    tags = ["larakube", "larakube-managed"]
  }

  depends_on = [
    google_project_service.compute,
    google_project_service.container,
  ]
}

output "context" {
  value = "gke_${var.gcp_project_id}_{{ $zone ?? ($region . '-a') }}_{{ $clusterName }}"
}

output "cluster_name" {
  value = google_container_cluster.larakube.name
}

output "endpoint" {
  value = google_container_cluster.larakube.endpoint
}

# Raw kubeconfig for the cluster — consumed by cloud:create to merge locally.
output "kubeconfig" {
  value     = <<-EOT
apiVersion: v1
clusters:
- cluster:
    certificate-authority-data: ${google_container_cluster.larakube.master_auth[0].cluster_ca_certificate}
    server: https://${google_container_cluster.larakube.endpoint}
  name: gke_${var.gcp_project_id}_{{ $zone ?? ($region . '-a') }}_{{ $clusterName }}
contexts:
- context:
    cluster: gke_${var.gcp_project_id}_{{ $zone ?? ($region . '-a') }}_{{ $clusterName }}
    user: gke_${var.gcp_project_id}_{{ $zone ?? ($region . '-a') }}_{{ $clusterName }}
  name: gke_${var.gcp_project_id}_{{ $zone ?? ($region . '-a') }}_{{ $clusterName }}
current-context: gke_${var.gcp_project_id}_{{ $zone ?? ($region . '-a') }}_{{ $clusterName }}
kind: Config
preferences: {}
users:
- name: gke_${var.gcp_project_id}_{{ $zone ?? ($region . '-a') }}_{{ $clusterName }}
  user:
    token: ${data.google_client_config.default.access_token}
EOT
  sensitive = true
}
