# Implementation Plan: `mail:dns` — Relay DNS Automation & Cloudflare Integration

## Goal Description
Partner domains (like `nexa-web.site`) are onboarded onto Stalwart with automated DNS/TLS via `mail:domain`. However, when relaying outbound mail via Amazon SES (or Brevo), AWS SES requires domain identity verification via 3 Easy DKIM CNAME records (and optional Custom MAIL FROM MX/SPF records) published to Cloudflare DNS.

Because operators do not have direct access to the partner's Cloudflare UI, and Stalwart only manages its own inbound/local records (MX, SPF, Stalwart DKIM, DMARC, ACME challenge), we will build `larakube mail:dns` to manage and publish relay records (SES CNAMEs, Custom MAIL FROM, Brevo verification) into Cloudflare DNS using the zone's Cloudflare API token (which is auto-resolved from Stalwart's configured `x:DnsServer`).

We will also clearly document the distinction between:
- **`mail:dkim`**: Inspects and prunes Stalwart's internal DKIM signing keys (e.g. removing Ed25519 to enforce RSA-only and avoid 554 duplicate header bounces).
- **`mail:dns`**: Writes/manages external relay DNS records (AWS SES Easy DKIM CNAMEs, Brevo SPF/DKIM, Custom MAIL FROM) on Cloudflare DNS.

---

## User Review Required
> [!NOTE]
> `mail:dns` will automatically fetch the zone's Cloudflare API token directly from Stalwart's internal `x:DnsServer` object (via JMAP API) if `--cloudflare-token` is not explicitly passed. If no DnsServer is found in Stalwart for that zone, it prompts for the token.

> [!IMPORTANT]
> The command will support both guided interactive presets for AWS SES / Brevo as well as generic CNAME/TXT record additions.

---

## Proposed Changes

### 1. Cloudflare & DNS Traits Layer
#### [MODIFY] [`InteractsWithCloudflareApi.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Traits/InteractsWithCloudflareApi.php)
- Add `cloudflareUpsertCnameRecord(string $zoneId, string $token, string $name, string $target, int $ttl = 120, bool $proxied = false): bool`
- Add `cloudflareUpsertMxRecord(string $zoneId, string $token, string $name, string $target, int $priority = 10, int $ttl = 120): bool`
- Support idempotent lookup and update/creation for CNAME and MX records.

#### [MODIFY] [`InteractsWithStalwartApi.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Traits/InteractsWithStalwartApi.php)
- Add `stalwartGetZoneCloudflareToken(string $kubectl, string $ns, string $zone): ?string` to extract the `secret.secret` string from the domain's associated `x:DnsServer`.

---

### 2. CLI Command Layer
#### [NEW] [`cli/app/Commands/Mail/MailDnsCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Mail/MailDnsCommand.php)
Implement `mail:dns` with signature:
```bash
larakube mail:dns
    {environment=local : Environment whose mail server to target}
    {--context= : Target a specific kube-context}
    {--zone= : Domain name in Cloudflare (e.g. nexa-web.site)}
    {--cloudflare-token= : Cloudflare API token (auto-resolved from Stalwart if omitted)}
    {--provider= : Preset provider records ("ses" or "brevo")}
    {--ses-tokens= : Comma-separated list of 3 SES DKIM tokens (e.g. token1,token2,token3)}
    {--mail-from= : Custom MAIL FROM subdomain prefix (e.g. "bounce")}
```
Workflow:
1. Resolves environment, kubectl context, and verifies Stalwart is installed.
2. Resolves target `--zone` (prompted or flag).
3. Resolves Cloudflare API token (auto-read from Stalwart DnsServer or prompted).
4. For AWS SES:
   - Accepts either the 3 CNAME token strings (or full record names/values from AWS console).
   - Writes the 3 CNAMEs: `<token>._domainkey.<zone>` ➔ `<token>.dkim.amazonses.com`.
   - If Custom MAIL FROM is specified (e.g., `bounce.<zone>`):
     - Adds `MX` record: `bounce.<zone>` ➔ `feedback-smtp.<region>.amazonses.com` (Priority 10).
     - Adds `TXT` record: `bounce.<zone>` ➔ `v=spf1 include:amazonses.com ~all`.
5. For Brevo:
   - Injects Brevo DKIM and tracking records if desired.
6. Renders summary table of records created/updated in Cloudflare.

---

### 3. Documentation & Guides
#### [MODIFY] Outline Documentation: `Partner Domain Onboarding`
- Add step in the onboarding runbook for relay verification:
  ```bash
  larakube mail:dns production --zone=partner.example --provider=ses
  ```
- Explain how to copy the 3 DKIM tokens from AWS SES and let `mail:dns` push them to Cloudflare without needing partner UI credentials.

---

## Verification Plan

### Automated Tests
Run Pest test suite and code quality checks:
```bash
./php vendor/bin/pest tests/Feature/MailDnsCommandTest.php
./php vendor/bin/pint --test
./php vendor/bin/phpstan analyse
```

### Manual Verification
1. Run `larakube mail:dns production --zone=nexa-web.site --provider=ses` with the 3 tokens from the AWS SES screenshot:
   - `65lm7r4at5ch4rgd5xi3hoas7nb2g6fs`
   - `x4hdqel6easijxhc6hzs3e62ohj5napr`
   - `jehlj3uzgrp5nlh6hjrgvd6zfugk43heu`
2. Verify in Cloudflare (or via `dig`) that the 3 CNAMEs are live:
   `dig 65lm7r4at5ch4rgd5xi3hoas7nb2g6fs._domainkey.nexa-web.site CNAME +short`
3. Check AWS SES Console until status flips from `Verification pending` to `Verified`.
