{{-- DigitalOcean Kubernetes (DOKS) managed cluster.
     Rendered by cloud:create into ~/.larakube/tofu/<stack>/main.tf.
     The do_token is supplied at runtime via TF_VAR_do_token (never written here).

     NOTE: The default node pool is defined as a separate
     digitalocean_kubernetes_node_pool resource (not an inline node_pool block)
     so that Tofu owns and explicitly destroys it — and therefore its underlying
     Droplets — before the cluster itself is torn down. Using the inline block
     causes the Droplets to survive a `tofu destroy` because DO's async cluster
     DELETE does not guarantee synchronous Droplet cleanup. --}}
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

# Pin to a currently-supported patch of the chosen minor (e.g. "1.31.").
data "digitalocean_kubernetes_versions" "current" {
  version_prefix = "{{ $versionPrefix ?? '' }}"
}

resource "digitalocean_kubernetes_cluster" "larakube" {
  name    = "{{ $clusterName }}"
  region  = "{{ $region }}"
  version = data.digitalocean_kubernetes_versions.current.latest_version
  ha      = {{ !empty($ha) ? 'true' : 'false' }}

  tags = ["larakube", "larakube-managed"]

  # A DOKS cluster requires exactly one default node pool defined inline.
  # We set node_count = 1 here as a placeholder — the real workload pool
  # is the separate digitalocean_kubernetes_node_pool resource below, which
  # Tofu will destroy explicitly (along with its Droplets) before the cluster.
  node_pool {
    name       = "{{ $clusterName }}-default"
    size       = "{{ $size }}"
    node_count = 1
    tags       = ["larakube", "larakube-managed", "larakube-default-pool"]
  }
}

# Explicit node pool so Tofu tracks and destroys the Droplets itself.
# This is the pool that actually runs workloads.
resource "digitalocean_kubernetes_node_pool" "workers" {
  cluster_id = digitalocean_kubernetes_cluster.larakube.id

  name       = "{{ $clusterName }}-pool"
  size       = "{{ $size }}"
  node_count = {{ (int) ($nodeCount ?? 2) }}
  tags       = ["larakube", "larakube-managed", "larakube-worker-pool"]
}

output "context" {
  value = "do-{{ $region }}-{{ $clusterName }}"
}

output "cluster_id" {
  value = digitalocean_kubernetes_cluster.larakube.id
}

output "endpoint" {
  value = digitalocean_kubernetes_cluster.larakube.endpoint
}

# Raw kubeconfig for the cluster — consumed by cloud:create to merge locally.
output "kubeconfig" {
  value     = digitalocean_kubernetes_cluster.larakube.kube_config[0].raw_config
  sensitive = true
}
