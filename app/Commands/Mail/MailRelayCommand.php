<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Enums\RelayProvider;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class MailRelayCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput;

    protected $signature = 'mail:relay
        {provider?    : Outbound relay provider — "brevo" (omit to choose interactively)}
        {--context=   : Target a specific kube-context}
        {--env=       : Environment whose Stalwart install to configure (default: local)}
        {--username=  : SMTP login for the relay (skips the prompt)}
        {--api-key=   : SMTP credential/API key for the relay (skips the prompt)}
        {--region=    : AWS region for SES (e.g. us-east-1); prompted if omitted}
        {--port=      : Override the relay submission port (default: provider default, e.g. Brevo=2525, SES=2587)}
        {--remove     : Revert outbound delivery to direct MX and remove the relay route}';

    protected $description = "Route Stalwart's outbound mail through an SMTP relay (Brevo, ...) for real-world deliverability";

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) ($this->option('env') ?: 'local');

        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $kubectl = $this->mailKubectl($context);
        $ns = $this->mailNamespace();

        if (! $this->isMailInstalled($kubectl, $ns)) {
            $this->laraKubeError('Stalwart is not installed. Run `larakube mail:init` first.');

            return 1;
        }

        $provider = $this->resolveProvider(forRemoval: (bool) $this->option('remove'));
        if ($provider === null) {
            return 1;
        }

        return $this->option('remove')
            ? $this->removeRelay($kubectl, $ns, $env, $config, $provider)
            : $this->configureRelay($kubectl, $ns, $env, $config, $provider);
    }

    protected function configureRelay(string $kubectl, string $ns, string $env, ?ConfigData $config, RelayProvider $provider): int
    {
        $cachedUsername = $this->readNamedSecret($kubectl, $ns, 'mail-relay', 'username');
        $cachedPassword = $this->readNamedSecret($kubectl, $ns, 'mail-relay', 'password');

        // Only show onboarding/pricing when we're actually about to prompt —
        // stay quiet on scripted runs (--username/--api-key) and re-runs that
        // reuse cached credentials.
        $willPrompt = ! $this->option('username') && $cachedUsername === null
            || ! $this->option('api-key') && $cachedPassword === null;

        if ($willPrompt) {
            $this->newLine();
            $this->line("  <fg=yellow>Before you continue — get your {$provider->label()} credentials:</>");
            foreach ($provider->onboardingSteps() as $i => $step) {
                $this->line('    '.($i + 1).". {$step}");
            }
            $this->newLine();
            $this->line("  <fg=gray>Pricing:</> {$provider->pricingNote()}");
            $this->newLine();
        }

        // Region comes before credentials for SES — the SES SMTP endpoint is
        // region-scoped, and the onboarding text tells the user to note it first.
        $region = '';
        if ($provider->requiresRegion()) {
            $cachedRegion = $this->readNamedSecret($kubectl, $ns, 'mail-relay', 'region');
            $region = (string) ($this->option('region') ?: $cachedRegion ?: text(
                label: "AWS region for {$provider->label()} (where you verified your domain)",
                placeholder: 'us-east-1',
                default: $cachedRegion ?: 'us-east-1',
                required: true,
            ));
        }

        $username = (string) ($this->option('username') ?: $cachedUsername ?: text(
            label: $provider->usernameLabel(),
            required: true,
        ));

        $apiKey = (string) ($this->option('api-key') ?: $cachedPassword ?: password(
            label: $provider->secretLabel(),
            required: true,
        ));

        $this->withSpin('Caching relay credentials...', function () use ($kubectl, $ns, $provider, $username, $apiKey, $region) {
            Process::run(
                "{$kubectl} create secret generic mail-relay -n {$ns} "
                .'--from-literal=provider='.escapeshellarg($provider->value).' '
                .'--from-literal=username='.escapeshellarg($username).' '
                .'--from-literal=password='.escapeshellarg($apiKey).' '
                .'--from-literal=region='.escapeshellarg($region).' '
                ."--dry-run=client -o yaml | {$kubectl} apply -f -",
            );
        });

        $relayHost = $provider->defaultHost($region ?: null);
        $port = $this->option('port') !== null ? (int) $this->option('port') : $provider->defaultPort();

        $routeId = null;
        $this->withSpin("Wiring the {$provider->label()} relay into Stalwart...", function () use (&$routeId, $kubectl, $ns, $provider, $relayHost, $port, $username, $apiKey) {
            $routeId = $this->stalwartUpsertRelayRoute(
                $kubectl,
                $ns,
                $provider->value,
                $relayHost,
                $port,
                $provider->implicitTls(),
                $username,
                $apiKey,
            );
        });

        if ($routeId === null) {
            $this->laraKubeError('Failed to create the relay route in Stalwart. Check the admin API connection.');

            return 1;
        }

        $wired = false;
        $this->withSpin('Pointing outbound delivery at the relay...', function () use (&$wired, $kubectl, $ns, $provider) {
            $wired = $this->stalwartSetOutboundRoute($kubectl, $ns, $provider->value);
        });

        if (! $wired) {
            $this->laraKubeError('Route was created but Stalwart refused the outbound strategy update.');

            return 1;
        }

        $host = $this->resolveMailHostReadOnly($env, $config);
        $domain = $host ? $this->relayDomain($host) : null;

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Outbound mail now relays through {$provider->label()}.");
        $this->newLine();
        $this->line("  <fg=gray>Relay:</> <fg=blue>{$relayHost}:{$port}</>");
        $this->line("  <fg=gray>Login:</> <fg=blue>{$username}</>");

        if ($domain) {
            $this->newLine();
            $this->line('  <fg=yellow>Before mail actually delivers:</>');
            foreach ($provider->manualSteps($domain) as $i => $step) {
                $this->line('    '.($i + 1).". {$step}");
            }
        }
        $this->newLine();

        return 0;
    }

    protected function removeRelay(string $kubectl, string $ns, string $env, ?ConfigData $config, RelayProvider $provider): int
    {
        $route = $this->stalwartFindRoute($kubectl, $ns, $provider->value);

        if ($route === null) {
            $this->laraKubeInfo("No {$provider->label()} relay route is configured.");

            return 0;
        }

        $this->withSpin('Reverting to direct MX delivery...', fn () => $this->stalwartSetOutboundRoute($kubectl, $ns, 'mx'));
        $this->withSpin("Removing the {$provider->label()} relay route...", fn () => $this->stalwartDeleteRoute($kubectl, $ns, $route['id']));

        Process::run("{$kubectl} delete secret mail-relay -n {$ns} --ignore-not-found");

        $this->laraKubeInfo("Outbound mail now delivers directly via MX again ({$provider->label()} relay removed).");

        // LaraKube can't delete the DNS records the provider added via its own
        // Cloudflare integration — point the user straight at them so they
        // aren't left orphaned in the zone.
        $host = $this->resolveMailHostReadOnly($env, $config);
        $domain = $host ? $this->relayDomain($host) : null;
        if ($domain) {
            $this->newLine();
            $this->line("  <fg=yellow>Clean up the DNS records {$provider->label()} added:</>");
            foreach ($provider->removalDnsSteps($domain) as $step) {
                $this->line("    {$step}");
            }
            $this->newLine();
        }

        return 0;
    }

    protected function resolveProvider(bool $forRemoval = false): ?RelayProvider
    {
        $slug = (string) ($this->argument('provider') ?: '');
        if ($slug !== '') {
            $provider = RelayProvider::tryFrom($slug);
            if ($provider === null) {
                $this->laraKubeError("Unknown relay provider '{$slug}'. Available: ".implode(', ', array_column(RelayProvider::cases(), 'value')));

                return null;
            }

            return $provider;
        }

        $cases = RelayProvider::cases();
        if (count($cases) === 1) {
            return $cases[0];
        }

        $options = [];
        foreach ($cases as $case) {
            $options[$case->value] = $case->label();
        }

        return RelayProvider::from(select(
            label: $forRemoval ? 'Which relay would you like to remove?' : 'Which relay provider?',
            options: $options,
        ));
    }

    protected function readNamedSecret(string $kubectl, string $ns, string $secret, string $key): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret {$secret} -n {$ns} -o jsonpath='{.data.{$key}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    /** Best-effort mail domain (drops the leftmost "mail." label). */
    protected function relayDomain(string $host): string
    {
        $parts = explode('.', $host);

        return count($parts) > 2 ? implode('.', array_slice($parts, 1)) : $host;
    }
}
