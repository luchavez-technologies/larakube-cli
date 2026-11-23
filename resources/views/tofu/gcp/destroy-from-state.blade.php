{{-- The provider alone: enough for `tofu destroy` to remove everything recorded in remote state
     from a fresh container that never had the original main.tf. --}}
terraform {
  required_providers {
    google = {
      source  = "hashicorp/google"
      version = "~> 6.0"
    }
  }
}

variable "gcp_project_id" {
  type = string
}

variable "gcp_credentials" {
  type      = string
  default   = null
  sensitive = true
}

provider "google" {
  project     = var.gcp_project_id
  credentials = var.gcp_credentials
}
