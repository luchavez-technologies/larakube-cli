apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization

namespace: {{ $namespace }}

{{-- `larakube up --preview` applies THIS overlay in addition to its parent, not
     instead of it: the local cluster then runs the production workload — same
     image, same Caddy, same Caddyfile, same headers and SPA fallback — next to
     the dev server, at `preview.{project}.{tld}`.

     It exists because everything in the serving layer — try_files, the cache
     headers, compression, the security headers — executes ONLY in production
     otherwise. The dev server answers those requests itself, so a Caddyfile bug
     is invisible until it ships.

     Its own resource names (`web-preview`) are what let both run at once, so
     you can hold the built bundle and the hot-reloading one side by side. --}}
resources:
  - caddy.yaml
