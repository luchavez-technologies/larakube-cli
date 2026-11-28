<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Enums\DnsProvider;
use App\Services\Kubectl;
use App\Services\SshKeyInspector;
use App\Support\CloudInstallerTrust;
use App\Support\SshPublicKey;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use Throwable;

/**
 * Shared post-provisioning steps for single-node and cloud servers (`cloud:create` and
 * `cloud:init`): validating and handing over SSH access to approved public keys, binding the
 * short-lived LaraKube Cloud installer role, and wiring optional DNS records + DNS-01 TLS.
 */
trait HandsOverCloudServer
{
    /**
     * Fields for the --json result, filled in as the flow learns them.
     *
     * @var array<string, mixed>
     */
    protected array $result = [];

    /**
     * The validated --authorized-key values as "type base64", empty when none were given, false
     * when any is unusable. Comments are dropped because keys are written into HCL and a script.
     *
     * @return list<string>|false
     */
    protected function resolveAuthorizedKeys(string $provider, ?ConfigData $config): array|false
    {
        $given = array_values(array_filter(array_map(trim(...), (array) $this->flag('authorized-key')), fn (string $key): bool => $key !== ''));

        if ($given === []) {
            return [];
        }

        if (! in_array($provider, ['gcp', 'do', 'hetzner', 'aws', 'custom'], true) || $config !== null) {
            $this->laraKubeError('--authorized-key is only supported for a VPS created outside a project.');

            return false;
        }

        $keys = [];
        foreach ($given as $raw) {
            $key = $this->normalizeAuthorizedKey($raw);
            if ($key === null) {
                $this->laraKubeError('--authorized-key must be a single OpenSSH public key (ed25519, ECDSA or RSA). Private keys are rejected.');

                return false;
            }
            $keys[$key] = $key;
        }

        return array_values($keys);
    }

    protected function normalizeAuthorizedKey(string $raw): ?string
    {
        return SshPublicKey::normalize($raw);
    }

    protected function resolveCloudInstallerTrust(): bool
    {
        try {
            $this->installerTrust = CloudInstallerTrust::fromOptions(
                $this->option('cloud-installer-issuer'),
                $this->option('cloud-installer-audience'),
                $this->option('cloud-installer-subject'),
            );

            return true;
        } catch (InvalidArgumentException $e) {
            $this->laraKubeError($e->getMessage());

            return false;
        }
    }

    /**
     * Gives the trusted installer identity admin on this cluster. The API server already trusts its
     * tokens (written before k3s was installed); this binding is what grants it anything, and deleting
     * it is how Cloud's install access is revoked.
     */
    protected function bindCloudInstallers(string $context): void
    {
        $applied = Process::input(CloudInstallerTrust::bindingManifest())
            ->run(Kubectl::forContext($context)->prefix().' apply -f -');

        $this->result['installer'] = [
            'ready' => $applied->successful(),
            'audience' => $this->installerTrust?->audience,
            'error' => $applied->successful() ? null : 'The installer role binding could not be applied.',
        ];

        $applied->successful()
            ? $this->laraKubeInfo('✅ LaraKube Cloud can install apps here with short-lived tokens. Revoke with: kubectl delete clusterrolebinding '.CloudInstallerTrust::BINDING)
            : $this->laraKubeWarn('LaraKube Cloud install access was not set up: the role binding could not be applied.');
    }

    protected function resolvePublicKey(string $effectiveKeyPath, ?string $originalKeyPath = null): string
    {
        foreach (array_unique(array_filter([$originalKeyPath, $effectiveKeyPath])) as $candidate) {
            if (is_file($candidate.'.pub')) {
                $pub = trim((string) file_get_contents($candidate.'.pub'));
                if ($pub !== '') {
                    return $pub;
                }
            }
        }

        return (string) ((new SshKeyInspector)->derivePublicKey($effectiveKeyPath) ?? '');
    }

    /**
     * @param  list<string>  $authorizedKeys
     */
    protected function unapprovedProvisioningBlob(string $sshPubKey, array $authorizedKeys): ?string
    {
        $provisioningBlob = explode(' ', trim($sshPubKey))[1] ?? '';
        $blobs = array_map(fn (string $key): string => explode(' ', $key)[1], $authorizedKeys);

        if ($provisioningBlob === '' || in_array($provisioningBlob, $blobs, true)) {
            $this->laraKubeError('The provisioning key cannot also be an approved key, because it is removed after the build.');

            return null;
        }

        return $provisioningBlob;
    }

    protected function applyAndVerifySshHandOver(string $ip, int $sshPort, string $keyPath, string $script): bool
    {
        if (! $this->runRemoteCommand('larakube', $ip, (string) $sshPort, $keyPath, $script)) {
            $this->laraKubeError('The approved keys did not reach the server. It still uses the provisioning key.');

            return false;
        }

        if ($this->testSsh('larakube', $ip, $sshPort, $keyPath) || $this->testSsh('root', $ip, $sshPort, $keyPath)) {
            $this->laraKubeError('The provisioning key can still log in, so the server was not handed over cleanly.');

            return false;
        }

        $this->laraKubeInfo('✅ The approved keys now own SSH access. The provisioning key no longer works.');

        return true;
    }

    /**
     * Give the server to the approved keys directly over SSH (without touching OpenTofu or VM
     * metadata) and prove the provisioning key is gone.
     *
     * @param  list<string>  $authorizedKeys
     */
    protected function handOverDirectSshAccess(string $sshPubKey, string $ip, int $sshPort, string $keyPath, array $authorizedKeys): bool
    {
        $this->laraKubeInfo('Handing SSH access to the approved public keys...');

        $provisioningBlob = $this->unapprovedProvisioningBlob($sshPubKey, $authorizedKeys);
        if ($provisioningBlob === null) {
            return false;
        }

        return $this->applyAndVerifySshHandOver($ip, $sshPort, $keyPath, $this->writeSshAccessScript($authorizedKeys, $provisioningBlob));
    }

    /**
     * The optional DNS and TLS steps after a VPS or managed cluster is up: DNS records for every tool
     * and SSL through the DNS challenge. A person at a terminal is asked; a
     * scripted run (Desktop) opts in with --dns-provider= (or --cloudflare) and credentials in the
     * environment. Neither step can fail the server, which already exists: a
     * problem is reported with how to finish it later.
     */
    protected function connectDnsAndTls(string $environment, string $context): void
    {
        $interactive = ! $this->flag('no-interaction');
        $providerSlug = (string) ($this->option('dns-provider') ?: ($this->flag('cloudflare') ? 'cloudflare' : ''));
        $asked = $providerSlug !== '';

        if (! $interactive && ! $asked) {
            return;
        }

        $provider = $asked ? DnsProvider::tryFrom($providerSlug) : null;

        if ($interactive && $provider === null) {
            if (! confirm('Would you like to automate DNS records and SSL certificates for this cluster?', default: true)) {
                return;
            }

            $chosen = select(
                label: 'Which DNS provider would you like to use?',
                options: [
                    DnsProvider::CLOUDFLARE->value => DnsProvider::CLOUDFLARE->label(),
                    DnsProvider::ROUTE53->value => DnsProvider::ROUTE53->label(),
                    DnsProvider::GOOGLE->value => DnsProvider::GOOGLE->label(),
                ],
                default: DnsProvider::CLOUDFLARE->value,
            );
            $provider = DnsProvider::from((string) $chosen);
        }

        if ($provider === null) {
            return;
        }

        if (! $interactive && $provider === DnsProvider::CLOUDFLARE && getenv('LARAKUBE_DNS_GROUPS') !== false) {
            $this->connectDnsGroups($environment, $context);

            return;
        }

        if (! $interactive && $provider === DnsProvider::CLOUDFLARE) {
            $token = trim((string) (getenv('LARAKUBE_CLOUDFLARE_TOKEN') ?: ''));
            if ($token === '') {
                $this->laraKubeWarn('--cloudflare was given but no Cloudflare token is set, so DNS and SSL were left for later.');
                $this->result['dns'] = ['provider' => $provider->value, 'records' => null, 'tls' => null, 'error' => 'No Cloudflare token was set.'];

                return;
            }
        }

        $skipDns = (bool) $this->flag('without-dns');
        $skipTls = (bool) $this->flag('without-tls');

        $dnsArgs = ['--tool' => 'external-dns', 'environment' => $environment, '--context' => $context];
        $tlsArgs = ['environment' => $environment, '--context' => $context];

        if ($provider !== DnsProvider::CLOUDFLARE || $this->option('dns-provider')) {
            $dnsArgs['--provider'] = $provider->value;
            $tlsArgs['--provider'] = $provider->value;
        }

        if (! $interactive || $this->flag('force')) {
            $dnsArgs['--force'] = true;
            $tlsArgs['--force'] = true;
        }

        $steps = [];
        if (! $skipDns) {
            $steps['dns'] = [
                'label' => "{$provider->label()} DNS records",
                'command' => 'tool:init',
                'arguments' => $dnsArgs,
                'question' => "Would you like to automate DNS records with {$provider->label()} for this cluster?",
                'default' => true,
            ];
        }
        if (! $skipTls) {
            $steps['ssl'] = [
                'label' => "SSL through the {$provider->label()} DNS challenge",
                'command' => 'tls:init',
                'arguments' => $tlsArgs,
                'question' => "Would you like to enable the {$provider->label()} DNS challenge for SSL certificates (so proxied hosts keep renewing)?",
                'default' => true,
            ];
        }

        // Reported in --json so a caller such as LaraKube Cloud knows which steps finished. null = not attempted.
        $outcome = ['provider' => $provider->value, 'records' => null, 'tls' => null, 'error' => null];

        foreach ($steps as $key => $step) {
            $wanted = $interactive ? ($asked || confirm($step['question'], default: $step['default'])) : true;

            if (! $wanted) {
                continue;
            }

            $field = $key === 'dns' ? 'records' : 'tls';

            try {
                $succeeded = $this->call($step['command'], $step['arguments']) === 0;
                $outcome[$field] = $succeeded;

                if (! $succeeded) {
                    $outcome['error'] ??= "{$step['label']} did not finish.";
                    $this->laraKubeWarn("{$step['label']} did not finish. The server is ready; finish it later with larakube {$step['command']} --context={$context}.");
                }
            } catch (Throwable $e) {
                $outcome[$field] = false;
                $outcome['error'] ??= "{$step['label']} could not be set up: {$e->getMessage()}";
                $this->laraKubeWarn("{$step['label']} could not be set up: {$e->getMessage()}");
                $this->laraKubeLine('  <fg=gray>The server is ready. Finish this later from its page in LaraKube Desktop, or with</> <fg=yellow>larakube '.$step['command'].'</><fg=gray>.</>');
            }
        }

        $this->result['dns'] = $outcome;
    }

    /**
     * Several Cloudflare accounts on one server: one ExternalDNS group per account, from
     * LARAKUBE_DNS_GROUPS ([{"group": "...", "token": "..."}]). DNS-01 SSL can use only one of them,
     * because Traefik takes one set of credentials per DNS provider; --dns-tls-group picks it.
     * Like the single-token path, nothing here can fail the server, which already exists.
     */
    protected function connectDnsGroups(string $environment, string $context): void
    {
        $groups = json_decode((string) getenv('LARAKUBE_DNS_GROUPS'), true);
        $tlsGroup = (string) ($this->option('dns-tls-group') ?? '');
        $outcome = ['provider' => DnsProvider::CLOUDFLARE->value, 'groups' => [], 'tls' => null, 'tls_group' => $tlsGroup !== '' ? $tlsGroup : null, 'error' => null];

        if (! is_array($groups) || $groups === []) {
            $outcome['error'] = 'LARAKUBE_DNS_GROUPS is not a list of groups.';
            $this->result['dns'] = $outcome;
            $this->laraKubeWarn($outcome['error']);

            return;
        }

        $tokens = [];
        foreach ($groups as $entry) {
            $group = is_array($entry) ? (string) ($entry['group'] ?? '') : '';
            $token = is_array($entry) ? trim((string) ($entry['token'] ?? '')) : '';

            if (preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $group) !== 1 || $token === '' || isset($tokens[$group])) {
                $outcome['groups'][] = ['group' => $group, 'records' => false, 'error' => 'Each group needs a unique lowercase name and a token.'];
                $outcome['error'] ??= 'A DNS group was invalid.';

                continue;
            }

            $tokens[$group] = $token;
            putenv("LARAKUBE_CLOUDFLARE_TOKEN={$token}");

            try {
                $succeeded = $this->call('tool:init', ['--tool' => 'external-dns', 'environment' => $environment, '--context' => $context, '--provider' => 'cloudflare', '--group' => $group, '--force' => true]) === 0;
                $outcome['groups'][] = ['group' => $group, 'records' => $succeeded, 'error' => $succeeded ? null : 'ExternalDNS did not finish.'];
            } catch (Throwable $e) {
                $outcome['groups'][] = ['group' => $group, 'records' => false, 'error' => $e->getMessage()];
            }

            if (! end($outcome['groups'])['records']) {
                $outcome['error'] ??= "DNS records for {$group} did not finish.";
                $this->laraKubeWarn("DNS records for {$group} did not finish. The server is ready; finish it later with larakube tool:init --tool=external-dns --group={$group} --context={$context}.");
            }
        }

        if ($tlsGroup !== '' && ! $this->flag('without-tls')) {
            if (! isset($tokens[$tlsGroup])) {
                $outcome['tls'] = false;
                $outcome['error'] ??= "The SSL group {$tlsGroup} is not one of the DNS groups.";
            } else {
                putenv("LARAKUBE_CLOUDFLARE_TOKEN={$tokens[$tlsGroup]}");

                try {
                    $outcome['tls'] = $this->call('tls:init', ['environment' => $environment, '--context' => $context, '--provider' => 'cloudflare', '--group' => $tlsGroup, '--force' => true]) === 0;
                } catch (Throwable $e) {
                    $outcome['tls'] = false;
                    $outcome['error'] ??= "SSL through the DNS challenge could not be set up: {$e->getMessage()}";
                }

                if ($outcome['tls'] === false) {
                    $outcome['error'] ??= 'SSL through the DNS challenge did not finish.';
                    $this->laraKubeWarn("SSL through the DNS challenge did not finish. Finish it later with larakube tls:init --group={$tlsGroup} --context={$context}.");
                }
            }
        }

        putenv('LARAKUBE_CLOUDFLARE_TOKEN');
        $this->result['dns'] = $outcome;
    }

    /**
     * Backward-compatible alias for connectDnsAndTls.
     */
    protected function connectCloudflare(string $environment, string $context): void
    {
        $this->connectDnsAndTls($environment, $context);
    }
}
