<?php

namespace App\Enums;

/**
 * Outbound SMTP smart-hosts Stalwart can hand delivery off to instead of
 * connecting to recipient MX servers directly — a fresh VPS IP has no sender
 * reputation and most cloud providers block outbound port 25 anyway, so a
 * relay is the realistic path to inbox delivery.
 *
 * Both current providers default to an ALTERNATE submission port, not the
 * "standard" 587: DigitalOcean (LaraKube's primary cloud target) null-routes
 * outbound 25/465/587 network-wide for anti-spam — a block that sits ABOVE the
 * droplet's cloud firewall, so no egress rule can override it. Brevo leaves 2525
 * open; SES offers 2587. Both verified reachable from a DO droplet.
 */
enum RelayProvider: string
{
    public function label(): string
    {
        return match ($this) {
            self::BREVO => 'Brevo',
            self::SES => 'Amazon SES',
        };
    }

    /**
     * Default SMTP relay hostname. SES endpoints are region-scoped
     * (email-smtp.<region>.amazonaws.com), so pass the chosen region; Brevo's
     * host is fixed and ignores it.
     */
    public function defaultHost(?string $region = null): string
    {
        return match ($this) {
            self::BREVO => 'smtp-relay.brevo.com',
            self::SES => 'email-smtp.'.($region ?: 'us-east-1').'.amazonaws.com',
        };
    }

    /**
     * Whether this provider's endpoint depends on an AWS-style region — drives
     * a region prompt in mail:relay and how defaultHost() is built.
     */
    public function requiresRegion(): bool
    {
        return match ($this) {
            self::BREVO => false,
            self::SES => true,
        };
    }

    /**
     * Default SMTP submission port (STARTTLS, not implicit TLS) — an alternate
     * port that survives DigitalOcean's outbound 25/465/587 block. Override with
     * --port on hosts where DO has lifted the block or that aren't on DO.
     */
    public function defaultPort(): int
    {
        return match ($this) {
            self::BREVO => 2525,
            self::SES => 2587,
        };
    }

    /** Whether the port expects implicit TLS (465-style) vs STARTTLS. */
    public function implicitTls(): bool
    {
        return match ($this) {
            self::BREVO => false,
            self::SES => false,
        };
    }

    /** Label for the SMTP login prompt — providers vary on what this actually is. */
    public function usernameLabel(): string
    {
        return match ($this) {
            // NOT the dashboard/account email — that only logs you into Brevo.
            // The relay authenticates with the dedicated "Login" shown on
            // SMTP & API → SMTP settings, which looks like <id>@smtp-brevo.com.
            self::BREVO => 'Brevo SMTP login — the "Login" on SMTP & API → SMTP settings (e.g. 9a1b2c@smtp-brevo.com), NOT your account email',
            // SES SMTP credentials are DISTINCT from your AWS access key — they're
            // generated in SES → SMTP settings → Create SMTP credentials.
            self::SES => 'SES SMTP username (SES console → SMTP settings → Create SMTP credentials; starts with AKIA…, NOT your AWS access key)',
        };
    }

    /** Label for the SMTP credential prompt. */
    public function secretLabel(): string
    {
        return match ($this) {
            self::BREVO => 'Brevo SMTP key (SMTP & API → SMTP in the Brevo dashboard)',
            self::SES => 'SES SMTP password (shown once when you create the SMTP credentials; NOT your AWS secret access key)',
        };
    }

    /**
     * Manual setup steps LaraKube can't automate for this provider — printed
     * after wiring so deliverability doesn't silently fail on an
     * unauthenticated domain (or a sandboxed account).
     *
     * @return array<int, string>
     */
    public function manualSteps(string $domain): array
    {
        return match ($this) {
            self::BREVO => [
                "Add and verify '{$domain}' as a sender domain in Brevo (Senders, Domains & Dedicated IPs → Domains).",
                'Brevo will give you SPF/DKIM TXT records — add them in Cloudflare DNS for the domain.',
                'Skipping this leaves outbound mail sent as this domain unauthenticated, which most inboxes will spam-filter or reject.',
            ],
            self::SES => [
                "Verify '{$domain}' in SES → Identities → Create identity → Domain (enable Easy DKIM; add the 3 CNAME records it gives you in Cloudflare).",
                'New SES accounts are in the SANDBOX: you can only send to VERIFIED recipient addresses (verify one under Identities to test right away).',
                'To send to anyone, request production access (SES → Account dashboard → Request production access) — a free self-service form, usually approved within 24h.',
                'Make sure the SES region you picked matches where you verified the domain — identities are per-region.',
            ],
        };
    }

    /**
     * DNS records this provider injected during domain authentication that
     * LaraKube can't delete for the user (the provider added them via its own
     * Cloudflare integration / one-click flow, outside ExternalDNS + Stalwart).
     * Printed by `mail:relay --remove` so cleanup isn't guesswork. Deliberately
     * keyed off record NAMES (stable) with a value hint, and always ends by
     * naming the Stalwart records to KEEP so nobody deletes their mail server.
     *
     * @return array<int, string>
     */
    public function removalDnsSteps(string $domain): array
    {
        return match ($this) {
            self::BREVO => [
                "In Cloudflare DNS for {$domain}, delete the records Brevo's one-click integration added (all point at *.brevo.com / *.brevosend.com):",
                '  • CNAME  brevo1._domainkey   (→ b1.….dkim.brevo.com)',
                '  • CNAME  brevo2._domainkey   (→ b2.….dkim.brevo.com)',
                '  • CNAME  send                (→ send-….brand.brevosend.com — the branded tracking subdomain)',
                '  • TXT    send                (v=spf1 include:spf.brevo.com -all)',
                '  • TXT    @ (root)            (brevo-code:… — the domain-verification token)',
                'Then in Brevo → Senders, Domains & Dedicated IPs → Domains, delete/unauthenticate the domain so it does not re-add them.',
                "KEEP your Stalwart records: root SPF 'v=spf1 mx -all', _dmarc, the vN-rsa / vN-ed25519 DKIM selectors, and mail A/MX/MTA-STS.",
            ],
            self::SES => [
                "In Cloudflare DNS for {$domain}, delete the records you added for SES:",
                '  • the 3 Easy-DKIM CNAMEs (…_domainkey → ….dkim.amazonses.com)',
                '  • any custom MAIL FROM records (MX → feedback-smtp.<region>.amazonses.com, TXT SPF include:amazonses.com)',
                'Then remove the identity in SES → Identities so it stops expecting them.',
                "KEEP your Stalwart records: root SPF 'v=spf1 mx -all', _dmarc, the vN-rsa / vN-ed25519 DKIM selectors, and mail A/MX/MTA-STS.",
            ],
        };
    }

    /**
     * Where to get this provider's credentials before LaraKube can prompt for
     * them — printed BEFORE the username/key prompt so the user isn't asked
     * to paste something they haven't been told how to obtain yet.
     *
     * @return array<int, string>
     */
    public function onboardingSteps(): array
    {
        return match ($this) {
            self::BREVO => [
                "Sign up free at https://www.brevo.com if you don't already have an account (no card required).",
                'Open SMTP & API (account menu, top right) → the SMTP tab.',
                'Copy the "Login" shown there (looks like <id>@smtp-brevo.com — NOT your account email) and generate/copy an SMTP key. The Login is what mail:relay asks for first; the SMTP key is the password it asks for next.',
            ],
            self::SES => [
                'In the AWS console open Amazon SES and pick your region (top-right) — note it, mail:relay asks for it next.',
                'SES → SMTP settings → Create SMTP credentials. AWS creates an IAM user and shows an SMTP username (AKIA…) + password ONCE — copy both now.',
                'The SMTP username is what mail:relay asks for after the region; the SMTP password is the credential prompt after that (NOT your AWS access key/secret).',
            ],
        };
    }

    /**
     * One-line pricing disclosure, printed alongside onboardingSteps() so cost
     * is stated before credentials are requested, not discovered later.
     * Providers change pricing; this is a floor to set expectations, not a
     * guarantee — point users at the live page for exact current numbers.
     */
    public function pricingNote(): string
    {
        return match ($this) {
            self::BREVO => 'Free plan: 300 emails/day, no expiry, no card required. Paid plans only if you outgrow that — current limits/pricing at brevo.com/pricing.',
            self::SES => 'Pay-as-you-go: ~$0.10 per 1,000 emails (no free-forever tier off-EC2). No subscription; leaving the sandbox is a free access request — aws.amazon.com/ses/pricing.',
        };
    }

    case BREVO = 'brevo';
    case SES = 'ses';
}
