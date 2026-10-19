<?php

namespace App\Commands\Config;

use App\Data\GlobalConfigData;
use App\Traits\InteractsWithGlobalConfig;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithTrust;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class ConfigTldCommand extends Command
{
    use InteractsWithGlobalConfig, InteractsWithProjectConfig, InteractsWithTrust, LaraKubeOutput;

    protected $signature = 'config:tld
                            {tld? : The local domain TLD (e.g. test, kube, localhost)}
                            {--project : Pin this TLD for the current project only (committed in .larakube.json), instead of your global default}
                            {--clear : With --project, remove this project\'s TLD override and follow the global default again}';

    protected $description = 'Set the local domain TLD used for vanity URLs (default: test)';

    public function handle()
    {
        $this->renderHeader();

        if ($this->option('project')) {
            return $this->handleProjectTld();
        }

        $tld = $this->argument('tld');

        if ($tld === null) {
            $current = $this->getLocalTld();
            $projectConfig = $this->isLaraKubeProject(false) ? $this->getProjectConfig() : null;

            if ($projectConfig) {
                $this->line("  <fg=gray>● Global Local TLD:</> <fg=green>.{$current}</>");
                if ($projectConfig->hasLocalTld()) {
                    $this->line("  <fg=gray>● This project's TLD:</> <fg=green>.{$projectConfig->getLocalTld()}</> <fg=gray>(pinned override — see --project)</>");
                } else {
                    $this->line("  <fg=gray>● This project's TLD:</> <fg=green>.{$current}</> <fg=gray>(follows the global default)</>");
                }
            } else {
                $this->line("  <fg=gray>● Local TLD:</> <fg=green>.{$current}</>");
            }

            $this->line('');
            $this->line('  Usage: <fg=yellow>larakube config:tld <tld></>');
            $this->line('  Allowed values: <fg=gray>'.implode(', ', GlobalConfigData::ALLOWED_TLDS).'</>');

            return 0;
        }

        $tld = ltrim(strtolower(trim($tld)), '.');

        if (! in_array($tld, GlobalConfigData::ALLOWED_TLDS, true)) {
            $this->error('  Invalid TLD: '.$tld);
            $this->line('  Allowed values: '.implode(', ', GlobalConfigData::ALLOWED_TLDS));

            return 1;
        }

        $this->warnIfMacOsReservedTld($tld);

        $previous = $this->getLocalTld();

        if ($tld === $previous) {
            $this->line("  <fg=gray>● Local TLD is already set to:</> <fg=green>.{$tld}</>");

            return 0;
        }

        $this->setLocalTld($tld);

        $this->info("  ✔ Local TLD changed: .{$previous} → .{$tld}");
        $this->line('');

        if ($tld === 'localhost') {
            $this->line('  <fg=yellow>ℹ  Tip: Browsers navigate to .localhost URLs without needing https://.</>');
        } elseif ($tld !== 'kube') {
            $this->line('  <fg=yellow>ℹ  Tip: For unknown TLDs, prefix with https:// in your browser.</>');
        }

        // If dnsmasq is already installed on macOS/Linux, extend it to also cover
        // the new TLD immediately so `larakube up` doesn't fall back to /etc/hosts
        // prompts. The old TLD stays covered too — some other project may still
        // pin it via `config:tld --project`.
        if (! $this->isWsl() && $this->isDnsmasqInstalled()) {
            $this->line('');
            $this->line('  <fg=gray>dnsmasq detected — extending it to cover the new TLD too (requires sudo)...</>');
            $this->configureDnsmasq($tld);
        }

        $this->line('');
        $this->offerLocalReapply('Run <fg=yellow>larakube up</> to apply the new TLD to your local cluster.');

        return 0;
    }

    /**
     * Pin (or clear) this project's own TLD override in .larakube.json, so every
     * collaborator's local cluster routes it the same way regardless of their
     * personal global TLD (set via `config:tld` without --project).
     */
    protected function handleProjectTld(): int
    {
        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $config = $this->getProjectConfig();

        if (! $config) {
            return 1;
        }

        if ($this->option('clear')) {
            if (! $config->hasLocalTld()) {
                $this->line('  <fg=gray>● This project has no TLD override to clear.</>');

                return 0;
            }

            $config->setLocalTld(null);
            $this->saveProjectConfig(getcwd(), $config);
            $this->info('  ✔ Project TLD override cleared — now following your global default: .'.GlobalConfigData::load()->getLocalTld());

            return 0;
        }

        $tld = $this->argument('tld');

        if ($tld === null) {
            if ($config->hasLocalTld()) {
                $this->line("  <fg=gray>● Project TLD override:</> <fg=green>.{$config->getLocalTld()}</> <fg=gray>(pinned in .larakube.json)</>");
            } else {
                $this->line("  <fg=gray>● Project TLD:</> <fg=green>.{$config->getLocalTld()}</> <fg=gray>(following your global default)</>");
            }
            $this->line('');
            $this->line('  Usage: <fg=yellow>larakube config:tld <tld> --project</>');
            $this->line('  Clear: <fg=yellow>larakube config:tld --project --clear</>');

            return 0;
        }

        $tld = ltrim(strtolower(trim($tld)), '.');

        if (! in_array($tld, GlobalConfigData::ALLOWED_TLDS, true)) {
            $this->error('  Invalid TLD: '.$tld);
            $this->line('  Allowed values: '.implode(', ', GlobalConfigData::ALLOWED_TLDS));

            return 1;
        }

        $this->warnIfMacOsReservedTld($tld);

        $previous = $config->getLocalTld();
        $config->setLocalTld($tld);
        $this->saveProjectConfig(getcwd(), $config);

        $this->info("  ✔ Project TLD pinned: .{$previous} → .{$tld}");
        $this->line('  <fg=gray>This override is committed in .larakube.json and applies to every collaborator.</>');
        $this->line('');
        $this->offerLocalReapply('Run <fg=yellow>larakube up</> to apply it to your local cluster.');

        return 0;
    }

    /**
     * Changing the TLD updates the config + DNS resolver, but the cluster's
     * ingresses keep routing the old TLD until the manifests are re-applied —
     * so vanity URLs silently break (assets 404, blank pages) until the next
     * `larakube up`. Users rarely connect the dots, so offer to run that
     * re-apply now. Only meaningful inside a project (up needs one); otherwise,
     * or when declined, fall back to the printed hint.
     */
    protected function offerLocalReapply(string $declineHint): void
    {
        if (! $this->isLaraKubeProject(false)) {
            $this->line("  {$declineHint}");

            return;
        }

        if (! confirm('Apply the new TLD to your local cluster now? (runs larakube up)', false)) {
            $this->line("  {$declineHint}");

            return;
        }

        $this->newLine();
        $this->call('up', ['environment' => 'local']);
    }

    /**
     * macOS reserves the .local TLD for Bonjour/mDNS (RFC 6762). Its mDNSResponder
     * intercepts every *.local lookup and routes it to multicast DNS, deliberately
     * ignoring /etc/resolver/local — so the dnsmasq wildcard LaraKube installs
     * never resolves to 127.0.0.1, and vanity URLs silently fail to reach Traefik.
     * The TLD is still allowed (it's fine on Linux/WSL), but warn loudly on Macs.
     */
    protected function warnIfMacOsReservedTld(string $tld): void
    {
        if ($tld !== 'local' || ! $this->isDarwin()) {
            return;
        }

        $this->line('');
        $this->line('  <fg=red>⚠  Heads up: .local is reserved for Bonjour/mDNS on macOS.</>');
        $this->line('  <fg=yellow>macOS routes every *.local lookup to multicast DNS and ignores</>');
        $this->line('  <fg=yellow>/etc/resolver/local, so your vanity URLs won\'t resolve to 127.0.0.1</>');
        $this->line('  <fg=yellow>and the browser can\'t reach Traefik (the cluster itself is fine).</>');
        $this->line('  <fg=gray>Prefer .kube, .test, or .localhost on macOS instead.</>');
    }
}
