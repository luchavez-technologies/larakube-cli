<?php

namespace App\Enums;

/**
 * Which DNS backend `tool:init --tool=external-dns` and `tls:init` manage records on/prove
 * control through. Cloudflare was the only path for a long time — not a
 * capability gap upstream (ExternalDNS already supports Route53 in-tree, and
 * Traefik's ACME engine, lego, already supports the Route53 DNS-01 challenge
 * natively), purely LaraKube's own wiring. GoDaddy was evaluated and left out:
 * its ExternalDNS support sits at Alpha stability (community-provided, not
 * maintainer-tested) — not stable enough to ship per this repo's own
 * tool-verification standard, even though Traefik/lego's side would be fine.
 *
 * `externalDnsProviderFlag()` and `legoProviderFlag()` are verified to differ
 * for the same cloud (lego calls Google's provider `gcloud`; ExternalDNS
 * calls it `google`) — never assume the two match for a future provider.
 */
enum DnsProvider: string
{
    public function label(): string
    {
        return match ($this) {
            self::CLOUDFLARE => 'Cloudflare',
            self::ROUTE53 => 'AWS Route 53',
        };
    }

    /** The ExternalDNS `--provider=` CLI value. */
    public function externalDnsProviderFlag(): string
    {
        return match ($this) {
            self::CLOUDFLARE => 'cloudflare',
            self::ROUTE53 => 'aws',
        };
    }

    /** The Traefik/lego `--certificatesresolvers.letsencrypt.acme.dnschallenge.provider=` value. */
    public function legoProviderFlag(): string
    {
        return match ($this) {
            self::CLOUDFLARE => 'cloudflare',
            self::ROUTE53 => 'route53',
        };
    }

    /**
     * The `tool:init --tool=external-dns`-stored credential Secret name prefix — the rest of the
     * name is the group slug. Cloudflare's prefix is unchanged from before
     * this enum existed, so no migration is needed for clusters already
     * running it.
     */
    public function credentialSecretPrefix(): string
    {
        return match ($this) {
            self::CLOUDFLARE => 'cloudflare-token-',
            self::ROUTE53 => 'route53-credential-',
        };
    }

    public function credentialSecretName(string $slug): string
    {
        return $this->credentialSecretPrefix().$slug;
    }

    /**
     * The Secret data keys this provider's credential needs, in prompt order.
     *
     * @return list<string>
     */
    public function credentialSecretKeys(): array
    {
        return match ($this) {
            self::CLOUDFLARE => ['token'],
            self::ROUTE53 => ['access_key_id', 'secret_access_key', 'region'],
        };
    }

    /** Traefik's own DNS-challenge credential Secret name, in the `traefik` namespace. */
    public function traefikAcmeSecretName(): string
    {
        return match ($this) {
            self::CLOUDFLARE => 'traefik-acme-cloudflare',
            self::ROUTE53 => 'traefik-acme-route53',
        };
    }

    /**
     * Where to get this provider's credentials, printed before the prompt so
     * a user isn't asked to paste something they haven't been told how to get.
     *
     * @return list<string>
     */
    public function onboardingSteps(): array
    {
        return match ($this) {
            self::CLOUDFLARE => [
                'Create a Cloudflare API token scoped to the zone(s) you want managed:',
                '  1. https://dash.cloudflare.com/profile/api-tokens',
                '  2. Create Token → Create Custom Token',
                '  3. Permissions: Zone · DNS · Edit (add Zone · Zone · Read too, for discovery)',
                '  4. Zone Resources: Include · one row per zone this instance should manage',
            ],
            self::ROUTE53 => [
                'Create an IAM user (or role) with a policy granting:',
                '  route53:ListHostedZones, route53:ListResourceRecordSets (Resource: *)',
                '  route53:ChangeResourceRecordSets (Resource: arn:aws:route53:::hostedzone/*)',
                'IAM console → Users → Create user → Attach policies directly → create/attach the above.',
                'Security credentials tab → Create access key → "Application running outside AWS".',
            ],
        };
    }
    case CLOUDFLARE = 'cloudflare';
    case ROUTE53 = 'route53';
}
