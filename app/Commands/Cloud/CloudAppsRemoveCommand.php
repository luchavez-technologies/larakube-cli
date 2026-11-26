<?php

namespace App\Commands\Cloud;

use App\Enums\ClusterTool;
use App\Support\Console\MaskedLogOutput;
use App\Traits\EmitsJsonOutput;
use App\Traits\InstallsForLaraKubeCloud;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use LaravelZero\Framework\Commands\Command;
use Throwable;

/**
 * Removes one app for LaraKube Cloud, the way Desktop's Remove button does: the app's own
 * {tool}:remove, for the instance at --domain when the app can run more than once. Only apps
 * LaraKube Cloud can install can be removed this way. Plex Commons is removed per service, not here.
 */
class CloudAppsRemoveCommand extends Command
{
    use EmitsJsonOutput, InstallsForLaraKubeCloud, LaraKubeOutput;

    protected $signature = 'cloud:apps:remove
        {app : The app to remove}
        {--kubeconfig= : Kubeconfig with a short-lived token for this cluster}
        {--domain= : The app\'s host, for an app that can run more than once}
        {--purge : Also destroy its data. Irreversible.}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Remove an app for LaraKube Cloud with a short-lived kubeconfig';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        if ($json) {
            $this->enableJsonMode();
        }

        $app = (string) $this->argument('app');
        $result = ['success' => false, 'app' => $app, 'host' => null, 'error' => null];

        try {
            ($app !== 'plex' && in_array($app, CloudAppsInstallCommand::APPS, true))
                || throw new InvalidArgumentException("LaraKube Cloud cannot remove {$app}.");
            $tool = ClusterTool::from($app);
            $context = $this->useKubeconfig((string) $this->option('kubeconfig'));

            $arguments = ['environment' => 'production', '--context' => $context, '--force' => true, '--no-interaction' => true];
            $domain = strtolower(trim((string) $this->option('domain')));
            if ($domain !== '' && $tool->supportsMultipleInstances()) {
                if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1) {
                    throw new InvalidArgumentException("{$domain} is not a valid host.");
                }
                $arguments['--domain'] = $domain;
                $result['host'] = $domain;
            }
            if ($this->option('purge')) {
                $arguments['--purge'] = true;
            }

            // Streams to the Job log (stderr) as it runs, credentials masked.
            $output = new MaskedLogOutput($this->output);
            $exit = Artisan::call($tool->removeCommand(), $arguments, $output);
            $result['success'] = $exit === 0;
            $result['error'] = $exit === 0 ? null : $this->lastLines($output->fetch(), 'The removal did not finish.');
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        $result['success']
            ? $this->laraKubeInfo("✅ Removed {$app}.")
            : $this->laraKubeError((string) $result['error']);

        if ($json) {
            $this->jsonOutput($result);
        }

        return $result['success'] ? 0 : 1;
    }
}
