<?php

namespace App\Commands\Mail;

use App\Data\ConfigData;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithStalwartApi;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

/**
 * Break-glass recovery for the mail server's automation credential.
 *
 * The CLI normally authenticates to Stalwart with a least-privilege API key
 * (mail-secrets/api-key). If that key is lost, revoked, or corrupted — or the
 * account owning it was disturbed — every mail command loses access. This
 * command rotates it: authenticating as the pinned recovery admin (the only
 * credential guaranteed to work regardless of the API key or an OIDC directory),
 * it destroys the old keys and mints a fresh one, overwriting the single source
 * of truth — mail-secrets/api-key. There is deliberately no second copy: the key
 * is CLI-internal and cheaply regenerable, so a mirror (e.g. in the secrets backend) would
 * only ever drift out of sync with no consumer to notice.
 *
 * This is the ONLY command that deliberately uses the recovery admin during
 * normal operation — everything else has moved to the API key. Keep the recovery
 * credential safe (it's your fire extinguisher), and this command is how you use
 * it.
 */
class MailRecoverCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithMail, InteractsWithStalwartApi, LaraKubeOutput;

    protected $signature = 'mail:recover
        {environment=local : Environment whose mail server to target}
        {--force     : Skip the confirmation prompt}
        {--context=  : Target a specific kube-context}';

    protected $description = 'Re-mint the CLI automation API key via the recovery admin (break-glass)';

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

        $kubectl = $this->mailKubectl($context);
        $ns = $this->mailNamespace();

        if (! $this->isMailInstalled($kubectl, $ns)) {
            $this->laraKubeError('Stalwart is not installed. Run `larakube mail:init` first.');

            return 1;
        }

        if ($this->readMailSecret($kubectl, $ns, 'admin-password') === null) {
            $this->laraKubeError('No recovery-admin credential found in mail-secrets — cannot recover. The mail server may need STALWART_RECOVERY_ADMIN restored (see `larakube mail:init`).');

            return 1;
        }

        if (! $this->option('force')) {
            $this->laraKubeNewLine();
            $this->line('  <fg=yellow>This rotates the CLI automation API key using the recovery admin.</>');
            $this->line('  Any tool or script still using the old key stops working until re-read.');
            $this->newLine();

            if (! confirm(label: 'Re-mint the automation API key now?', default: false)) {
                $this->laraKubeInfo('Recovery cancelled.');

                return 0;
            }
        }

        $key = null;
        $this->withSpin('Re-minting automation API key via recovery admin...', function () use (&$key, $kubectl, $ns): void {
            // Force recovery-admin auth: the API key we're replacing may be the
            // very thing that's broken, so we must not try to use it.
            $key = $this->withStalwartRecoveryAuth(fn () => $this->stalwartResetApiKey($kubectl, $ns));
        });

        if ($key === null) {
            $this->laraKubeError('Could not re-mint the API key. Check the recovery-admin credential and that a domain is configured in Stalwart.');

            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Automation API key re-minted and stored.');
        $this->line('  <fg=gray>The CLI now authenticates to Stalwart with the fresh key.</>');
        $this->newLine();

        return 0;
    }
}
