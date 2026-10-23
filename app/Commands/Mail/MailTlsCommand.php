<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Services\Kubectl;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

/**
 * Ensures Stalwart's own mail-host TLS certificate actually covers its
 * hostname (e.g. `send.luchtech.dev`) — distinct from a domain's own cert
 * (e.g. `luchtech.dev`'s, for DKIM/mail routing). Nothing sets this
 * automatically today: stalwart:init's first-run wizard and mail:domain's
 * per-domain onboarding both configure a domain's certificate management
 * without ever adding the server's own hostname to it, so the listener has
 * nothing to match a real client's SNI against and falls back to whichever
 * domain's cert happens to exist — silently breaking TLS for anything that
 * connects by the mail host's own name (Apple Mail/Thunderbird, and any
 * service sending notification email through it, e.g. Zitadel's SMTP).
 */
class MailTlsCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput;

    protected $signature = 'mail:tls
        {environment=local : Environment whose mail server to target}
        {--context=         : Target a specific kube-context}';

    protected $description = "Make sure Stalwart's own TLS certificate covers its mail hostname";

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $context = (string) $this->option('context') ?: null;
        if (! $context && $config && $env !== 'local') {
            $context = $this->environmentContextOrCurrent($config, $env);
        }

        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->mailNamespace();

        if (! $this->isMailInstalled($kubectl, $ns)) {
            $this->laraKubeError('Stalwart is not installed. Run `larakube tool:init --tool=stalwart` first.');

            return 1;
        }

        $host = $this->resolveMailHostReadOnly($env, $config, $kubectl);
        if ($host === null || $host === '') {
            $this->laraKubeError('Could not resolve the mail host for this environment.');

            return 1;
        }

        $covered = null;
        $this->withSpin(
            "Making sure {$host} is covered by a certificate...",
            function () use (&$covered, $kubectl, $ns, $host): void {
                $covered = $this->stalwartEnsureMailHostCertificate($kubectl, $ns, $host);
            },
        );

        if ($covered !== true) {
            $this->laraKubeError("Could not find a domain {$host} belongs to. Onboard its parent domain first with `larakube mail:domain` (or complete stalwart:init's first-run wizard).");

            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ {$host} is covered by a certificate.");
        $this->laraKubeLine('  New or just-added — Stalwart requests it from Let\'s Encrypt in the background.');
        $this->laraKubeLine("  Verify anytime: <fg=blue>larakube mail:check {$env}</>");

        return 0;
    }
}
