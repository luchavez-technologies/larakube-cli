<?php

namespace App\Commands\Tls;

use App\Enums\DnsProvider;
use App\Exceptions\MissingFlagException;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithCloudflareApi;
use App\Traits\InteractsWithGoogleCloudDnsApi;
use App\Traits\InteractsWithRoute53Api;
use App\Traits\LaraKubeOutput;
use App\Traits\ProvisionsK3sNode;
use App\Traits\ReadsStoredDnsCredentials;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

/**
 * Switch a cloud cluster's Let's Encrypt certificates to a DNS-01 challenge,
 * so hosts keep renewing even when HTTP-01 can't complete (a proxied/orange-
 * cloud Cloudflare host) or a wildcard certificate is wanted (HTTP-01 can
 * never issue one).
 *
 * Unrelated to ExternalDNS apart from the credential: a credential
 * `tool:init --tool=external-dns` stored is offered for reuse, and one is asked for when it
 * never ran.
 */
class TlsInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithCloudflareApi, InteractsWithGoogleCloudDnsApi, InteractsWithRoute53Api,
        LaraKubeOutput, ProvisionsK3sNode, ReadsStoredDnsCredentials, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment;

    protected $signature = 'tls:init
        {environment? : The cloud environment whose cluster gets the DNS challenge}
        {--context=  : Target a specific kube-context}
        {--provider= : Which DNS backend proves control — cloudflare (default), route53, or google}
        {--group=    : Reuse the credential of this tool:init --tool=external-dns group}
        {--aws-access-key-id=     : IAM access key ID with Route53 permissions. Or set AWS_ACCESS_KEY_ID}
        {--aws-secret-access-key= : IAM secret access key. Or set AWS_SECRET_ACCESS_KEY}
        {--aws-region=            : AWS region. Default us-east-1, or set AWS_DEFAULT_REGION}
        {--gcp-project=           : Google Cloud project ID for Cloud DNS}
        {--gcp-credentials=       : Path to Google service account JSON key file}
        {--force     : Skip the confirmation prompt}';

    protected $description = 'Issue Let\'s Encrypt certificates through a DNS-01 challenge (Cloudflare, Route53, or Google Cloud DNS), so proxied/wildcard hosts keep renewing';

    public function handle(): int
    {
        $this->renderHeader();

        $env = $this->resolveToolEnvironment('TLS');

        if ($env === 'local') {
            $this->laraKubeError('Local clusters use the LaraKube Local CA, not Let\'s Encrypt. tls:init is for cloud environments.');

            return 1;
        }

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        if ($context === null || $context === '') {
            $this->laraKubeError("No kube-context resolved for '{$env}'. Pass --context= or run `larakube cloud:init` first.");

            return 1;
        }

        $kubectl = Kubectl::forContext($context)->prefix();

        if (! $this->traefikInstalledOnContext($context)) {
            $this->laraKubeError("Traefik isn't installed on this cluster. Run `larakube cloud:init {$env}` first.");

            return 1;
        }

        $provider = DnsProvider::from((string) ($this->option('provider') ?: DnsProvider::CLOUDFLARE->value));

        if ($provider === DnsProvider::ROUTE53 && ! $this->route53Available()) {
            $this->laraKubeError('The AWS CLI is required to manage Route53 zones. Run `larakube setup --tools=aws` first.');

            return 1;
        }

        if ($provider === DnsProvider::GOOGLE && ! $this->googleCloudDnsAvailable()) {
            $this->laraKubeError('The Google Cloud CLI (gcloud) is required to manage Google Cloud DNS zones. Run `gcloud components install` or install the Google Cloud SDK.');

            return 1;
        }

        $resolved = $this->resolveCredential($provider, $kubectl);
        if ($resolved === null) {
            return 1;
        }
        [$credential, $source] = $resolved;

        $zones = match ($provider) {
            DnsProvider::CLOUDFLARE => $this->cloudflareListZones($credential['token']),
            DnsProvider::ROUTE53 => $this->route53ListZones($this->route53Env($credential)),
            DnsProvider::GOOGLE => $this->googleCloudDnsListZones($credential),
        };

        if ($zones === []) {
            $this->laraKubeError("This credential sees no zones. Check that it is valid and scoped correctly for {$provider->label()}.");

            return 1;
        }

        $hosts = $this->letsEncryptHosts($this->clusterIngresses($kubectl));
        $uncovered = array_values(array_filter($hosts, fn (string $host) => $this->zoneForHost($host, $zones) === null));

        if ($uncovered !== []) {
            $this->laraKubeError('These hosts get Let\'s Encrypt certificates but are outside every zone this credential can see:');
            foreach ($uncovered as $host) {
                $this->line("  <fg=red>•</> {$host}");
            }
            $this->line('  <fg=gray>After the switch they could never renew. Give the credential access to their zones and try again.</>');
            $this->line('  <fg=gray>Zones it sees:</> '.implode(', ', $zones));

            return 1;
        }

        if (! $this->verifyDnsWriteAccess($provider, $credential, $zones, $hosts)) {
            return 1;
        }

        if (! $this->confirmDestructive([
            "Traefik on '{$env}' will get Let's Encrypt certificates through {$provider->label()} DNS:",
            "Credential: {$source}. Zones: ".implode(', ', $zones).'.',
            count($hosts).' host(s) covered. Existing certificates are kept and renew through DNS when due.',
            'Traefik restarts: expect a few seconds of errors on every site.',
        ])) {
            return 0;
        }

        // Traefik runs in its own namespace and can't read a Secret elsewhere,
        // so even a reused tool:init --tool=external-dns credential is copied — on stdin, never argv
        // (putSecret() builds the manifest and pipes it, same as every other credential Secret).
        $stored = Kubectl::fromPrefix($kubectl)->putSecret('traefik', $provider->traefikAcmeSecretName(), $credential);

        if (! $stored->ok) {
            $this->laraKubeError("Could not store the {$provider->label()} credential for Traefik. See the output above.");

            return 1;
        }

        $isManaged = $this->traefikIsManaged($kubectl);
        $ip = '';
        if (! $isManaged) {
            $resolvedIp = $this->resolveTraefikIngressIp($context);
            if ($resolvedIp === null) {
                $this->laraKubeError("Could not determine this cluster's ingress IP. Pass --context= for the right cluster.");

                return 1;
            }
            $ip = $resolvedIp;
        }

        if (! $this->deployTraefik($context, $ip, force: true, dnsChallenge: true, dnsProvider: $provider)) {
            $this->laraKubeError('Traefik did not come back up with the DNS challenge. See the output above.');
            $this->line("  <fg=gray>The credential Secret is stored, so the next</> <fg=blue>larakube traefik:setup {$env}</> <fg=gray>renders the DNS challenge too.</>");

            return 1;
        }

        $this->newLine();
        $this->laraKubeInfo("✅ Let's Encrypt on '{$env}' now uses the {$provider->label()} DNS challenge.");
        $this->line('  <fg=gray>Proxied/wildcard hosts keep renewing from here on.</>');
        $this->line("  <fg=gray>Check it any time with</> <fg=blue>larakube tls:show {$env}</><fg=gray>.</>");
        $this->newLine();

        return 0;
    }

    /**
     * The credential to use and a label for where it came from.
     *
     * @return array{0: array<string, string>, 1: string}|null
     */
    protected function resolveCredential(DnsProvider $provider, string $kubectl): ?array
    {
        return match ($provider) {
            DnsProvider::CLOUDFLARE => $this->resolveCloudflareCredential($kubectl),
            DnsProvider::ROUTE53 => $this->resolveRoute53Credential($kubectl),
            DnsProvider::GOOGLE => $this->resolveGoogleCredential($kubectl),
        };
    }

    /**
     * @return array{0: array<string, string>, 1: string}|null
     */
    protected function resolveCloudflareCredential(string $kubectl): ?array
    {
        $stored = array_filter(
            $this->storedDnsCredentials($kubectl, 'larakube-shared'),
            fn (array $entry): bool => $entry['provider'] === DnsProvider::CLOUDFLARE,
        );
        $group = (string) ($this->option('group') ?? '');

        if ($group !== '') {
            if (! isset($stored[$group])) {
                $this->laraKubeError("No tool:init --tool=external-dns Cloudflare credential is stored for group '{$group}'.");
                if ($stored !== []) {
                    $this->line('  <fg=gray>Stored groups:</> '.implode(', ', array_keys($stored)));
                }

                return null;
            }

            return [$stored[$group]['data'], "reused from tool:init --tool=external-dns ({$group})"];
        }

        if (count($stored) === 1) {
            $slug = (string) array_key_first($stored);

            if ($this->cannotPrompt() || confirm("Reuse the Cloudflare token tool:init --tool=external-dns stored for '{$slug}'?")) {
                return [$stored[$slug]['data'], "reused from tool:init --tool=external-dns ({$slug})"];
            }
        } elseif (count($stored) > 1) {
            if ($this->cannotPrompt()) {
                throw new MissingFlagException(
                    'group',
                    'which tool:init --tool=external-dns group\'s Cloudflare token to reuse ('.implode(', ', array_keys($stored)).')',
                    '--group='.array_key_first($stored),
                );
            }

            $slug = (string) select(
                label: 'Which tool:init --tool=external-dns Cloudflare token should Traefik use?',
                options: array_combine(array_keys($stored), array_keys($stored)),
            );

            return [$stored[$slug]['data'], "reused from tool:init --tool=external-dns ({$slug})"];
        }

        $fromEnv = (string) getenv(self::TLS_TOKEN_ENV);
        if ($fromEnv !== '') {
            return [['token' => $fromEnv], 'from '.self::TLS_TOKEN_ENV];
        }

        if ($this->cannotPrompt()) {
            $this->laraKubeError('No Cloudflare token to use. Set '.self::TLS_TOKEN_ENV.' for non-interactive runs.');

            return null;
        }

        if ($stored === []) {
            $this->newLine();
            $this->line('  <fg=gray>No credential from</> <fg=blue>tool:init --tool=external-dns</> <fg=gray>on this cluster, and that\'s fine: ExternalDNS isn\'t needed.</>');
            $this->line('  <fg=gray>Your DNS records can stay hand-managed. Traefik only writes short-lived</>');
            $this->line('  <fg=gray>_acme-challenge TXT records while it proves control of a domain.</>');
        }
        $this->newLine();
        $this->line('  <fg=gray>Create a Cloudflare API token with the "Edit zone DNS" template, covering every</>');
        $this->line('  <fg=gray>zone this cluster serves (Zone → Zone → Read, Zone → DNS → Edit):</>');
        $this->line('  <fg=blue>https://dash.cloudflare.com/profile/api-tokens</>');
        $this->newLine();

        return [['token' => (string) password(label: 'Cloudflare API token', required: true)], 'entered for tls:init'];
    }

    /**
     * @return array{0: array<string, string>, 1: string}|null
     */
    protected function resolveRoute53Credential(string $kubectl): ?array
    {
        $accessKeyId = (string) ($this->option('aws-access-key-id') ?: getenv('AWS_ACCESS_KEY_ID') ?: '');
        $secretAccessKey = (string) ($this->option('aws-secret-access-key') ?: getenv('AWS_SECRET_ACCESS_KEY') ?: '');

        if ($accessKeyId !== '' && $secretAccessKey !== '') {
            $region = (string) ($this->option('aws-region') ?: getenv('AWS_DEFAULT_REGION') ?: 'us-east-1');

            return [['access_key_id' => $accessKeyId, 'secret_access_key' => $secretAccessKey, 'region' => $region], 'from flags/environment'];
        }

        $stored = array_filter(
            $this->storedDnsCredentials($kubectl, 'larakube-shared'),
            fn (array $entry): bool => $entry['provider'] === DnsProvider::ROUTE53,
        );
        $group = (string) ($this->option('group') ?? '');

        if ($group !== '') {
            if (! isset($stored[$group])) {
                $this->laraKubeError("No tool:init --tool=external-dns Route53 credential is stored for group '{$group}'.");
                if ($stored !== []) {
                    $this->line('  <fg=gray>Stored groups:</> '.implode(', ', array_keys($stored)));
                }

                return null;
            }

            return [$stored[$group]['data'], "reused from tool:init --tool=external-dns ({$group})"];
        }

        if (count($stored) === 1) {
            $slug = (string) array_key_first($stored);

            if ($this->cannotPrompt() || confirm("Reuse the Route53 credential tool:init --tool=external-dns stored for '{$slug}'?")) {
                return [$stored[$slug]['data'], "reused from tool:init --tool=external-dns ({$slug})"];
            }
        } elseif (count($stored) > 1) {
            if ($this->cannotPrompt()) {
                throw new MissingFlagException(
                    'group',
                    'which tool:init --tool=external-dns group\'s Route53 credential to reuse ('.implode(', ', array_keys($stored)).')',
                    '--group='.array_key_first($stored),
                );
            }

            $slug = (string) select(
                label: 'Which tool:init --tool=external-dns Route53 credential should Traefik use?',
                options: array_combine(array_keys($stored), array_keys($stored)),
            );

            return [$stored[$slug]['data'], "reused from tool:init --tool=external-dns ({$slug})"];
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException(
                'aws-access-key-id',
                'the AWS access key ID and secret access key for Route53 (--aws-access-key-id= and --aws-secret-access-key=)',
                'larakube tls:init production --provider=route53 --aws-access-key-id=… --aws-secret-access-key=…',
            );
        }

        $this->newLine();
        foreach (DnsProvider::ROUTE53->onboardingSteps() as $step) {
            $this->line("  <fg=gray>{$step}</>");
        }
        $this->newLine();

        $keyId = (string) text(label: 'AWS Access Key ID', placeholder: 'AKIA...', required: true);
        $secret = (string) password(label: 'AWS Secret Access Key', required: true);
        $region = (string) text(label: 'AWS Region', default: 'us-east-1', required: true);

        $this->registerSecret($secret);

        return [['access_key_id' => $keyId, 'secret_access_key' => $secret, 'region' => $region], 'entered for tls:init'];
    }

    /**
     * @return array{0: array<string, string>, 1: string}|null
     */
    protected function resolveGoogleCredential(string $kubectl): ?array
    {
        $project = (string) ($this->option('gcp-project') ?: getenv('GCP_PROJECT_ID') ?: getenv('GCLOUD_PROJECT') ?: '');
        $credentialsPath = (string) ($this->option('gcp-credentials') ?: getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: '');
        $json = '';
        if ($credentialsPath !== '' && file_exists($credentialsPath)) {
            $json = (string) file_get_contents($credentialsPath);
        } elseif (($envJson = getenv('GCP_SERVICE_ACCOUNT_JSON')) && trim((string) $envJson) !== '') {
            $json = trim((string) $envJson);
        }

        if ($json !== '') {
            if ($project === '') {
                $decoded = json_decode($json, true);
                if (is_array($decoded) && ! empty($decoded['project_id'])) {
                    $project = (string) $decoded['project_id'];
                }
            }
            if ($project !== '') {
                return [['credentials.json' => $json, 'project_id' => $project], 'from flags/environment'];
            }
        }

        $stored = array_filter(
            $this->storedDnsCredentials($kubectl, 'larakube-shared'),
            fn (array $entry): bool => $entry['provider'] === DnsProvider::GOOGLE,
        );
        $group = (string) ($this->option('group') ?? '');

        if ($group !== '') {
            if (! isset($stored[$group])) {
                $this->laraKubeError("No tool:init --tool=external-dns Google credential is stored for group '{$group}'.");
                if ($stored !== []) {
                    $this->line('  <fg=gray>Stored groups:</> '.implode(', ', array_keys($stored)));
                }

                return null;
            }

            return [$stored[$group]['data'], "reused from tool:init --tool=external-dns ({$group})"];
        }

        if (count($stored) === 1) {
            $slug = (string) array_key_first($stored);

            if ($this->cannotPrompt() || confirm("Reuse the Google Cloud DNS credential tool:init --tool=external-dns stored for '{$slug}'?")) {
                return [$stored[$slug]['data'], "reused from tool:init --tool=external-dns ({$slug})"];
            }
        } elseif (count($stored) > 1) {
            if ($this->cannotPrompt()) {
                throw new MissingFlagException(
                    'group',
                    'which tool:init --tool=external-dns group\'s Google credential to reuse ('.implode(', ', array_keys($stored)).')',
                    '--group='.array_key_first($stored),
                );
            }

            $slug = (string) select(
                label: 'Which tool:init --tool=external-dns Google credential should Traefik use?',
                options: array_combine(array_keys($stored), array_keys($stored)),
            );

            return [$stored[$slug]['data'], "reused from tool:init --tool=external-dns ({$slug})"];
        }

        if ($this->cannotPrompt()) {
            throw new MissingFlagException(
                'gcp-credentials',
                'the Google Cloud Service Account JSON credentials file path (--gcp-credentials=)',
                'larakube tls:init production --provider=google --gcp-credentials=… --gcp-project=…',
            );
        }

        $this->newLine();
        foreach (DnsProvider::GOOGLE->onboardingSteps() as $step) {
            $this->line("  <fg=gray>{$step}</>");
        }
        $this->newLine();

        $path = (string) text(label: 'Path to Service Account JSON key file', required: true);
        if (! file_exists($path)) {
            $this->laraKubeError("File not found: {$path}");

            return null;
        }

        $content = (string) file_get_contents($path);
        $decoded = json_decode($content, true);
        $defaultProject = is_array($decoded) && ! empty($decoded['project_id']) ? (string) $decoded['project_id'] : '';
        $chosenProject = (string) text(label: 'GCP Project ID', default: $defaultProject, required: true);

        return [['credentials.json' => $content, 'project_id' => $chosenProject], 'entered for tls:init'];
    }

    /**
     * Create and delete a test TXT record in every zone the certificates need.
     *
     * @param  array<string, string>  $credential
     * @param  array<string, string>  $zones  [zoneId => zoneName]
     * @param  list<string>  $hosts
     */
    protected function verifyDnsWriteAccess(DnsProvider $provider, array $credential, array $zones, array $hosts): bool
    {
        $needed = array_unique(array_filter(array_map(fn (string $host) => $this->zoneForHost($host, $zones), $hosts)));
        if ($needed === []) {
            $needed = [reset($zones)];
        }

        foreach ($needed as $zone) {
            $zoneId = (string) array_search($zone, array_map('strtolower', $zones), true);

            $canWrite = match ($provider) {
                DnsProvider::CLOUDFLARE => $this->cloudflareCanWriteDns($credential['token'], $zoneId, $zone),
                DnsProvider::ROUTE53 => $this->route53CanWriteDns($this->route53Env($credential), $zoneId, $zone),
                DnsProvider::GOOGLE => $this->googleCloudDnsCanWriteDns($credential, $zoneId, $zone),
            };

            if (! $canWrite) {
                $this->laraKubeError("The credential can read {$zone} but not write its DNS records.");

                return false;
            }
        }

        return true;
    }
}
