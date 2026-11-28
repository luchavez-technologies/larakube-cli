# 0043: LaraKube Cloud Is the Source for Provider Catalogs; Non-Google Servers Trust Cloud's Platform Account

**Status:** Accepted

## Context

LaraKube Cloud is adding DigitalOcean, Hetzner, AWS and existing servers next to Google Cloud. Two questions cut across the CLI, Desktop and Cloud.

1. **Where regions, sizes and prices come from.** Today the LaraKube CLI hard-codes them in `App\Enums\CloudProvider` (`regions()`, `vpsSizes()`, `managedSizes()`, defaults), with prices written as approximations ("~$6/mo"), and also looks some up live through `aws`, `gcloud` and the DigitalOcean/Hetzner APIs. Desktop shows what the CLI says. Cloud cannot run the CLI to fill a form (it runs the CLI only in runner Jobs), so it would need its own copy. Two lists drift, and approximate prices break the rule against invented prices.
2. **Who a non-Google server trusts for app installs.** Every server Cloud creates trusts short-lived Google ID tokens for one subject, for installs (`CloudInstallerTrust`). For a Google server that subject is the team's own Google service account. A team that only uses DigitalOcean has no Google account to trust.

## Decision

1. **Cloud is the only source of provider catalogs, as it is for app templates.**
   - Cloud keeps the curated regions and sizes per provider and refreshes availability and real monthly prices daily with its own read-only provider tokens.
   - A price the provider does not give stays empty, and the UI links to the provider's pricing page instead.
   - Cloud serves the catalog at `GET /api/v1/providers/{provider}/catalog`: public data, no sign-in.
   - **The LaraKube CLI has no built-in list and does no live lookups.** `cloud:providers` downloads the catalog, caches it, and uses the last downloaded copy when Cloud is unreachable. With no copy at all it says so and asks the user to connect once.
   - Desktop gets the catalog through the CLI, unchanged.
2. **Non-Google servers trust Cloud's own platform service account for installs.** The issuer stays `https://accounts.google.com` and the audience stays unique per server (`larakube-cloud:<server id>`). The subject is the numeric ID of Cloud's platform service account for that environment (`larakube-cloud-<env>@…`). Tokens are minted by Cloud, last at most an hour, and work for one server only. Google servers keep trusting the team's own service account.

## Consequences

- One list, kept in one place, with real prices; the CLI, Desktop and Cloud always offer the same choices.
- **The CLI needs Cloud once to know any provider's choices.** After that it works from its cached copy, so a server keeps running and stays manageable if the customer leaves Cloud (works-without-Cloud still holds for everything already created).
- Cloud's platform service account becomes part of the trust of every non-Google server it creates. Rotating that account's key does not change its numeric ID, so servers keep trusting it. Replacing the account itself requires re-running the trust step on those servers (`cloud:access:connect`), which a migration command must do if it ever happens.
- A customer leaving Cloud removes the trust with `cloud:access:disconnect`, as today.
