{{--
    Opt-in S3-compatible remote state, activated by LARAKUBE_TOFU_STATE_BUCKET +
    LARAKUBE_TOFU_STATE_ENDPOINT (+ optional LARAKUBE_TOFU_STATE_REGION).
    Backend credentials come from AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY in
    the environment. Native S3 locking (use_lockfile) requires OpenTofu >= 1.10
    (or Terraform >= 1.10) — the headless job image pins its tofu version.
--}}
terraform {
  backend "s3" {
    bucket = "{{ $bucket }}"
    key    = "tofu-state/{{ $stack }}/terraform.tfstate"
    region = "{{ $region }}"

    endpoints = {
      s3 = "{{ $endpoint }}"
    }

    use_lockfile = true

    # S3-compatible (non-AWS) endpoints — DO Spaces et al.
    skip_credentials_validation = true
    skip_requesting_account_id  = true
    skip_metadata_api_check     = true
    skip_region_validation      = true
    skip_s3_checksum            = true
  }
}
