{{-- Hetzner Cloud VPS stack: server + SSH key + Cloud Firewall.
     Rendered by cloud:create into ~/.larakube/tofu/<stack>/main.tf.
     The hcloud_token is supplied at runtime via TF_VAR_hcloud_token / HCLOUD_TOKEN (never written here). --}}
terraform {
  required_providers {
    hcloud = {
      source  = "hetznercloud/hcloud"
      version = "~> 1.48"
    }
  }
}

variable "hcloud_token" {
  type      = string
  sensitive = true
}

provider "hcloud" {
  token = var.hcloud_token
}

{{-- Look up existing keys in Hetzner Cloud project and reuse matching public key --}}
data "hcloud_ssh_keys" "all" {}

locals {
  matching_keys = [
    for k in data.hcloud_ssh_keys.all.ssh_keys : k.id
    if trimspace(k.public_key) == trimspace("{{ $sshPubKey }}")
  ]
  key_already_present = length(local.matching_keys) > 0
}

resource "hcloud_ssh_key" "larakube" {
  count      = local.key_already_present ? 0 : 1
  name       = "{{ $sshKeyName }}"
  public_key = "{{ $sshPubKey }}"
}

locals {
  ssh_key_id = local.key_already_present ? local.matching_keys[0] : hcloud_ssh_key.larakube[0].id
}

resource "hcloud_firewall" "larakube" {
  name = "{{ $dropletName }}-fw"

  # SSH — restricted to the admin CIDR when provided, else open.
  rule {
    direction  = "in"
    protocol   = "tcp"
    port       = "22"
    source_ips = [{!! $sshSources !!}]
  }

  # HTTP / HTTPS — open (Traefik + ACME HTTP-01).
  rule {
    direction  = "in"
    protocol   = "tcp"
    port       = "80"
    source_ips = ["0.0.0.0/0", "::/0"]
  }
  rule {
    direction  = "in"
    protocol   = "tcp"
    port       = "443"
    source_ips = ["0.0.0.0/0", "::/0"]
  }

  # k3s API (6443) — restricted to the admin CIDR when provided, else open.
  rule {
    direction  = "in"
    protocol   = "tcp"
    port       = "6443"
    source_ips = [{!! $apiSources !!}]
  }

  # Outbound rules — allow all egress traffic.
  rule {
    direction       = "out"
    protocol        = "tcp"
    port            = "any"
    destination_ips = ["0.0.0.0/0", "::/0"]
  }
  rule {
    direction       = "out"
    protocol        = "udp"
    port            = "any"
    destination_ips = ["0.0.0.0/0", "::/0"]
  }
  rule {
    direction       = "out"
    protocol        = "icmp"
    destination_ips = ["0.0.0.0/0", "::/0"]
  }
}

resource "hcloud_server" "larakube" {
  name         = "{{ $dropletName }}"
  server_type  = "{{ $size }}"
  image        = "{{ $image ?? 'ubuntu-24.04' }}"
  location     = "{{ $region }}"
  ssh_keys     = [local.ssh_key_id]
  firewall_ids = [hcloud_firewall.larakube.id]
  labels = {
    managed_by = "larakube"
  }
}

output "ip" {
  value = hcloud_server.larakube.ipv4_address
}

output "id" {
  value = hcloud_server.larakube.id
}
