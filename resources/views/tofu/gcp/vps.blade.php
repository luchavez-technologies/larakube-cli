{{-- Google Cloud Platform VPS stack: Compute Engine VM + VPC Ingress Firewall.
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

# Automatically ensure the Compute Engine API is active (idempotent, won't disable on teardown).
resource "google_project_service" "compute" {
  service            = "compute.googleapis.com"
  disable_on_destroy = false
}

# A reserved address, not an ephemeral one: changing the machine type stops the VM, and GCP may
# release an ephemeral IP on stop. The k3s API certificate, kubeconfig and DNS all name this IP.
resource "google_compute_address" "larakube" {
  name   = "{{ $dropletName }}-ip"
  region = "{{ $region }}"

  depends_on = [google_project_service.compute]
}

resource "google_compute_instance" "larakube" {
  name         = "{{ $dropletName }}"
  machine_type = "{{ $size }}"
  zone         = "{{ $zone ?? ($region . '-a') }}"

  allow_stopping_for_update = true

  tags = ["larakube", "larakube-vps", "http-server", "https-server"]

  boot_disk {
    auto_delete = true
    initialize_params {
      image = "{{ $image ?? 'ubuntu-os-cloud/ubuntu-2404-lts-amd64' }}"
      size  = {{ $diskSize ?? 30 }}
    }
  }

  network_interface {
    network = "default"
    access_config {
      nat_ip = google_compute_address.larakube.address
    }
  }

  metadata = {
    # Disable OS Login to permit direct root SSH key authentication for K3s automation
    enable-oslogin = "FALSE"
    ssh-keys       = "root:{{ $sshPubKey }}"
@if(isset($sshPort) && (int) $sshPort !== 22)
    "startup-script" = <<-EOF
#!/bin/bash
@include('tofu.partials.ssh-port-script')
    EOF
@endif
  }

  depends_on = [google_project_service.compute]
}

resource "google_compute_firewall" "larakube_ingress" {
  name    = "{{ $dropletName }}-fw-ingress"
  network = "default"

  # SSH — restricted to admin CIDR when provided, else open.
  allow {
    protocol = "tcp"
    ports    = ["{{ $sshPort ?? 22 }}"]
  }

  # k3s API (6443) — restricted to admin CIDR when provided, else open.
  allow {
    protocol = "tcp"
    ports    = ["6443"]
  }

  source_ranges = [{!! trim(str_replace([', "::/0"', '"::/0",', '"::/0"', '[', ']'], '', $sshSources)) !!}]
  target_tags   = ["larakube"]

  depends_on = [google_project_service.compute]
}

# HTTP / HTTPS (80, 443) — open for Traefik ingress + ACME HTTP-01 challenges.
resource "google_compute_firewall" "larakube_http" {
  name    = "{{ $dropletName }}-fw-http"
  network = "default"

  allow {
    protocol = "tcp"
    ports    = ["80", "443"]
  }

  source_ranges = ["0.0.0.0/0"]
  target_tags   = ["larakube"]

  depends_on = [google_project_service.compute]
}

output "ip" {
  value = google_compute_address.larakube.address
}

output "id" {
  value = google_compute_instance.larakube.id
}
