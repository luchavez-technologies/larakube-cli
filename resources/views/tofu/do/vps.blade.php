{{-- DigitalOcean VPS stack: droplet + SSH key + Cloud Firewall.
     Rendered by cloud:create into ~/.larakube/tofu/<stack>/main.tf.
     The do_token is supplied at runtime via TF_VAR_do_token (never written here). --}}
terraform {
  required_providers {
    digitalocean = {
      source  = "digitalocean/digitalocean"
      version = "~> 2.0"
    }
  }
}

variable "do_token" {
  type      = string
  sensitive = true
}

provider "digitalocean" {
  token = var.do_token
}

{{-- DO rejects re-uploading a public key that's already on the account, so we
     look up existing keys and only create when ours isn't there. Either way the
     droplet references the key by its (locally-computed) fingerprint. --}}
data "digitalocean_ssh_keys" "all" {}

locals {
  pubkey_fingerprint  = "{{ $keyFingerprint }}"
  key_already_present = length([
    for k in data.digitalocean_ssh_keys.all.ssh_keys : k.fingerprint
    if k.fingerprint == "{{ $keyFingerprint }}"
  ]) > 0
}

resource "digitalocean_ssh_key" "larakube" {
  count      = local.key_already_present ? 0 : 1
  name       = "{{ $sshKeyName }}"
  public_key = "{{ $sshPubKey }}"
}

locals {
  ssh_fingerprint = local.key_already_present ? local.pubkey_fingerprint : digitalocean_ssh_key.larakube[0].fingerprint
}

resource "digitalocean_droplet" "larakube" {
  name        = "{{ $dropletName }}"
  region      = "{{ $region }}"
  size        = "{{ $size }}"
  resize_disk = {{ ($resizeDisk ?? false) ? 'true' : 'false' }}
  image       = "{{ $image ?? 'ubuntu-24-04-x64' }}"
  ssh_keys    = [local.ssh_fingerprint]
  tags        = ["larakube"]
@if(isset($sshPort) && (int) $sshPort !== 22)
  user_data   = <<-EOF
                #!/bin/bash
                sed -i 's/^#\?Port .*/Port {{ $sshPort }}/' /etc/ssh/sshd_config
                grep -qxF 'Port {{ $sshPort }}' /etc/ssh/sshd_config || echo 'Port {{ $sshPort }}' >> /etc/ssh/sshd_config
                if [ -d /etc/ssh/sshd_config.d ]; then
                  sed -i 's/^#\?Port .*/Port {{ $sshPort }}/' /etc/ssh/sshd_config.d/*.conf 2>/dev/null || true
                fi
                if systemctl is-active --quiet ssh.socket 2>/dev/null; then
                  systemctl disable --now ssh.socket 2>/dev/null || true
                  systemctl enable --now ssh.service 2>/dev/null || true
                fi
                systemctl reload ssh || systemctl restart ssh || systemctl restart sshd || true
                EOF
@endif
}

resource "digitalocean_firewall" "larakube" {
  name        = "{{ $dropletName }}-fw"
  droplet_ids = [digitalocean_droplet.larakube.id]

  # SSH — restricted to the admin CIDR when provided, else open.
  inbound_rule {
    protocol         = "tcp"
    port_range       = "{{ $sshPort ?? 22 }}"
    source_addresses = [{!! $sshSources !!}]
  }

  # HTTP / HTTPS — open (Traefik + ACME HTTP-01).
  inbound_rule {
    protocol         = "tcp"
    port_range       = "80"
    source_addresses = ["0.0.0.0/0", "::/0"]
  }
  inbound_rule {
    protocol         = "tcp"
    port_range       = "443"
    source_addresses = ["0.0.0.0/0", "::/0"]
  }

  # k3s API (6443) — restricted to the admin CIDR when provided, else open.
  inbound_rule {
    protocol         = "tcp"
    port_range       = "6443"
    source_addresses = [{!! $apiSources !!}]
  }

  # Allow all outbound.
  outbound_rule {
    protocol              = "tcp"
    port_range            = "1-65535"
    destination_addresses = ["0.0.0.0/0", "::/0"]
  }
  outbound_rule {
    protocol              = "udp"
    port_range            = "1-65535"
    destination_addresses = ["0.0.0.0/0", "::/0"]
  }
  outbound_rule {
    protocol              = "icmp"
    destination_addresses = ["0.0.0.0/0", "::/0"]
  }
}

output "ip" {
  value = digitalocean_droplet.larakube.ipv4_address
}

output "id" {
  value = digitalocean_droplet.larakube.id
}
