apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization

namespace: {{ $namespace }}

{{-- `larakube up --preview` applies THIS overlay instead of its parent, so the
     local cluster runs the production workload — same image, same Caddy, same
     Caddyfile, same headers and SPA fallback — under the same host and the same
     resource names as the dev server. Applying either one swaps the other out
     in place, because `web` is the Deployment/Service/Ingress name on both
     sides; only the TLS issuer and the hostname differ from real production.

     It exists because everything in the serving layer — try_files, the cache
     headers, compression, the security headers — executes ONLY in production
     otherwise. The dev server answers those requests itself, so a Caddyfile bug
     is invisible until it ships. --}}
resources:
  - caddy.yaml
