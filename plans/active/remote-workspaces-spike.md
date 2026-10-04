# Remote workspaces: Step 0 spike (GCP, throwaway)

**Goal:** measure one hand-built workspace so the design rests on numbers, not guesses. Decides server sizing guidance, whether a VPN-only editor is practical, and what the pod needs. Context: `project_remote_workspaces_plan` (memory); the workspace runs on the developer's own server.

**Server:** one new GCP VPS, `ws-spike`, `e2-standard-2` (2 vCPU, 8 GB), us-central1, made with `cloud:create`. Paid from the user's free credits (about $0.07/hour). Destroyed at the end with `cloud:destroy`. The existing `gcp-test-vps` is left alone.

**Steps**
1. Create the server; record baseline RAM (`free`, `kubectl top`) with k3s idle.
2. A `ws-spike` namespace: PVC, Deployment with an editor image (code-server) plus a PHP/Composer/Node/git dev image, a Service and an Ingress.
3. Clone a small Laravel app (public repo), `composer install`, `npm install`, run `artisan serve` and `vite`; open the app through the ingress.
4. Measure: idle and active RAM (pod and node), peak during installs, cold start from scale 0, time to a usable editor.
5. Check access: editor reachable only by an auth token or the cluster's port-forward, not public; note what SSO or VPN would need.
6. Write the numbers and surprises into this file; destroy the server.

**Rules:** this is an experiment on a throwaway server, so hand-made manifests are fine here. Nothing from it ships; anything that becomes a feature is rebuilt as CLI commands and templates. No secrets from the user's machines go on the server.

**Done when:** numbers recorded below, server destroyed, and a go/no-go on Step 1.

## Results
(to fill in)
