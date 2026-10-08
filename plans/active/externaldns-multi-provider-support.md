# ExternalDNS / tls:init Multi-Provider DNS Support

## Context

LaraKube's `tool:init --tool=external-dns` and `tls:init` both hardcode Cloudflare today — not as a default with room to grow, but as the only path: `DnsInitCommand` is built directly on `InteractsWithCloudflareApi`/`ReadsStoredCloudflareTokens`, and both `zone.blade.php` and the two Traefik manifest templates (`traefik-cloud.blade.php`, `traefik-managed.blade.php`) hardcode `--provider=cloudflare` / `CF_API_TOKEN` / `CF_DNS_API_TOKEN` with no branch for anything else.

This matters because a real user base won't all be on Cloudflare DNS. Verified live (not from memory, per this repo's own tool-verification rule) that the restriction is entirely LaraKube's own wiring, not a capability gap:

- **ExternalDNS** upstream already has in-tree support for AWS Route53 (`--provider=aws`) and Google Cloud DNS (`--provider=google`), among many others.
- **Traefik's ACME engine (lego)** already supports DNS-01 challenges against both: `--dnschallenge.provider=route53` and `--dnschallenge.provider=gcloud`.

The user currently has live access to both AWS and GCP test accounts — this plan is written now so that window isn't wasted, but no code changes are included here; this is planning only.

## Target architecture

Follow the existing `RelayProvider`/`RegistryProvider` enum convention exactly — this codebase already has a proven shape for "one capability, several interchangeable providers, each with its own credential shape, onboarding copy, and manual-setup caveats." Do not invent a new pattern.

### New enum: `App\Enums\DnsProvider`

```php
enum DnsProvider: string
{
    case CLOUDFLARE = 'cloudflare';
    case ROUTE53 = 'route53';
    case GOOGLE_CLOUD_DNS = 'google';
}
```

Per-provider methods, mirroring `RelayProvider`'s shape:

- `label()` — "Cloudflare", "AWS Route 53", "Google Cloud DNS".
- `externalDnsProviderFlag()` — the **ExternalDNS** CLI provider string: `cloudflare` / `aws` / `google`.
- `legoProviderFlag()` — the **Traefik/lego** DNS-01 provider string: `cloudflare` / `route53` / `gcloud`. **These two are verified to differ for the same cloud** (lego calls it `gcloud`, ExternalDNS calls it `google`) — a classic naming trap; the enum must expose both separately, never assume they match.
- `credentialFields()` — what to prompt for: Cloudflare = one API token; Route53 = access key + secret + region (+ optional hosted zone ID); Google Cloud DNS = a service-account JSON key file + GCP project ID.
- `onboardingSteps()` — where to get those credentials (Cloudflare dashboard token creation steps already exist in `DnsInitCommand::resolveToken()`; need the AWS IAM-user-with-Route53-policy equivalent and the GCP service-account-with-`roles/dns.admin`-equivalent, each as its own numbered walkthrough).
- `secretShape()` — whether the Kubernetes Secret this provider needs is a flat key (Cloudflare: one `token` key), several flat keys (Route53: `access_key_id`, `secret_access_key`, `region`), or a mounted file (Google Cloud DNS: a `credentials.json` key, mounted as a volume, not an env var).

### Verified wiring differences per provider (this is the part that actually varies the manifests)

| | Cloudflare (today) | AWS Route 53 | Google Cloud DNS |
|---|---|---|---|
| ExternalDNS `--provider=` | `cloudflare` | `aws` | `google` |
| ExternalDNS auth | `CF_API_TOKEN` env var | `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` env vars, or a mounted `AWS_SHARED_CREDENTIALS_FILE` (ExternalDNS's own docs lead with the mounted-file form) | `GOOGLE_APPLICATION_CREDENTIALS` env var pointing at a **mounted** service-account JSON file — this one needs a real `volumes:`/`volumeMounts:` block, not just `env:`, a structural first for this template |
| ExternalDNS required IAM/role | — | `route53:ChangeResourceRecordSets` (scoped to `hostedzone/*`), `route53:ListHostedZones`, `route53:ListResourceRecordSets` (both `*`) | `roles/dns.admin` on the project (or a tighter custom role — worth a follow-up to scope this down) |
| Traefik/lego `--dnschallenge.provider=` | `cloudflare` | `route53` | `gcloud` |
| Traefik/lego auth | `CF_DNS_API_TOKEN` env var | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_REGION`, optional `AWS_HOSTED_ZONE_ID` | `GCE_PROJECT` + `GCE_SERVICE_ACCOUNT_FILE` (mounted JSON key) |

Sources checked live: [ExternalDNS AWS tutorial](https://github.com/kubernetes-sigs/external-dns/blob/6b3baec/docs/tutorials/aws.md), [Giant Swarm ExternalDNS/Route53 static creds guide](https://docs.giantswarm.io/tutorials/connectivity/external-dns/aws-route53-static-creds/), [MongoDB external-dns-no-mesh reference (Google Cloud DNS)](https://www.mongodb.com/docs/kubernetes/current/reference-architectures/multi-cluster-no-mesh/external-dns-no-mesh/), [lego route53 provider](https://dev.to/kasundsilva/lets-encrypt-dns-challenge-with-traefik-and-aws-route-53-36ak), [lego gcloud provider source](https://git.frostfs.info/pogpp/lego/src/branch/master/providers/dns/gcloud/googlecloud.go). The AWS IAM policy and GCP role should be re-verified again immediately before implementation (both can drift), and the exact least-privilege GCP role is worth narrowing beyond `roles/dns.admin` rather than taking it as final.

## Files that need to change

1. **`app/Enums/DnsProvider.php`** (new) — as above.
2. **`app/Commands/Dns/DnsInitCommand.php`** — today's `resolveToken()` is Cloudflare-only (string in, string out). Needs to become `resolveCredentials(DnsProvider $provider)` returning a provider-shaped credential bundle, with `resolveZones()` similarly branching to AWS `ListHostedZones` / GCP `ManagedZones.list` instead of only `cloudflareListZones()`. The group/multi-zone-per-credential model, conflict detection (`checkForConflicts()`), and owner-ID derivation are all provider-agnostic already and should not need to change.
3. **`app/Traits/InteractsWithCloudflareApi.php`** — stays Cloudflare-only; add sibling traits `InteractsWithRoute53Api`/`InteractsWithGoogleCloudDnsApi` for zone discovery, rather than overloading one trait with three clouds' SDKs.
4. **`resources/views/k8s/dns/zone.blade.php`** — parameterize `--provider={{ $provider }}`; branch the `env:` block per provider, and add a `volumes:`/`volumeMounts:` block for the Google Cloud DNS case (service-account JSON mounted from a Secret) — this is the one template structural change, not just a new env var.
5. **`app/Commands/Tls/TlsInitCommand.php`** — same `resolveToken()` → `resolveCredentials()` generalization; it currently reuses `cloudflareListZones()`/`cloudflareCanWriteDns()` to validate the zone before issuing certs, which needs the same per-provider branch.
6. **`resources/views/k8s/traefik-cloud.blade.php`, `traefik-managed.blade.php`** — swap the hardcoded `dnschallenge.provider=cloudflare` line for `{{ $legoProvider }}`, and branch the credential `env:` block (plus a volume mount for GCP, same as #4).
7. **OpenBao sync** — per this repo's own hard rule, any new credential class (AWS keys, GCP service-account JSON) must go through `pushClusterSecret()`/`syncClusterSecretToNamespace()` the same way the Cloudflare token already does if OpenBao is bootstrapped. Don't ship a parallel path that skips the vault.

## Explicitly out of scope for the first pass

- **DigitalOcean** — ExternalDNS dropped in-tree DO support; it's webhook-only now (a separate Deployment, not a flag). Heavier lift, defer.
- **Azure DNS** — both ExternalDNS and lego support it, but the user doesn't currently have an Azure test account; no urgency.
- Narrowing the GCP IAM role below `roles/dns.admin` — flag it, don't block the first working version on it.

## Suggested phasing

1. **Route53 first.** Servers can already be provisioned on AWS (`StackCatalog`/`CloudProvider` already models AWS), so a user with an AWS-hosted server plausibly already has AWS credentials on hand — the credential-collection UX has the shortest path to "it just works." Build `DnsProvider`, generalize `DnsInitCommand`/`TlsInitCommand`, add the Route53 branch only, ship and test end-to-end against the user's real AWS account first.
2. **Google Cloud DNS second**, once the provider abstraction is proven — this is the one requiring the new volume-mount manifest shape, so landing it second means that structural change is the only new thing in that pass, not tangled up with the enum refactor too.
3. Both phases: a manual test plan against the user's real accounts before calling it done — create a real hosted zone/managed zone, run `tool:init --tool=external-dns` against it, confirm a DNS record actually lands, then `tls:init` against the same zone and confirm a real Let's Encrypt cert issues via DNS-01 (not just that the command exits 0).

## Open question to resolve before implementation

Should `tls:init` and `external-dns:init` be forced onto the *same* provider/credential for a given zone, or could a cluster reasonably use Route53 for ExternalDNS but Cloudflare for cert issuance on the same domain? Today's single-provider (Cloudflare) design doesn't have to answer this. Recommend: same provider per zone, for the same reason `DnsInitCommand`'s own docblock gives for one-owner-ID-per-zone — two different credentials touching the same zone's DNS is exactly the kind of split-brain this codebase has already been burned by once (see the zone-conflict detection `checkForConflicts()` already guards against).
