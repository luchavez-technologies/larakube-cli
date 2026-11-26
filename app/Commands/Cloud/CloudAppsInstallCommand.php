<?php

namespace App\Commands\Cloud;

use App\Enums\ClusterTool;
use App\Services\Tools\InitOption;
use App\Services\Tools\ToolInitSpec;
use App\Traits\EmitsJsonOutput;
use App\Traits\InstallsForLaraKubeCloud;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Installs one app for LaraKube Cloud, with a short-lived kubeconfig it hands to the runner. Only
 * the apps listed here can be installed this way, and each gets only the flags its own *:init
 * accepts, so a request can't reach any other command or flag.
 */
class CloudAppsInstallCommand extends Command
{
    use EmitsJsonOutput, InstallsForLaraKubeCloud, LaraKubeOutput;

    /** Apps LaraKube Cloud can install. Each goes through tool:init, Plex Commons through plex:init. */
    public const APPS = [
        'plex',
        'openbao',
        'n8n',
        'pocketbase',
        'wordpress',
        'erpnext',
        'kuma',
        'vaultwarden',
        'outline',
        'umami',
        'yopass',
        'planka',
        'kutt',
        'teable',
        'documenso',
        'resume',
        'supabase',
        'directus',
        'windmill',
        'twenty',
        'grafana',
        'forgejo',
        'metabase',
        'glitchtip',
        'penpot',
        'chatwoot',
        'sendrec',
        'zitadel',
        'stalwart',
        'bulwark',
        'netbird',
        'headlamp',
        'livekit',
        'ocis',
        'matrix',
    ];

    /** Shared Commons services Cloud may ask for. */
    private const PLEX_SERVICES = ['postgres', 'redis', 'mariadb', 'seaweedfs'];

    protected $signature = 'cloud:apps:install
        {app : The app to install}
        {--kubeconfig= : Kubeconfig with a short-lived token for this cluster}
        {--domain= : Host or base domain for the app}
        {--admin-email= : Email for the first admin account, for apps that create one}
        {--db= : Storage choice, for apps that offer one}
        {--plex-services= : Commons services for plex, comma-separated}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Install an app for LaraKube Cloud with a short-lived kubeconfig';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        if ($json) {
            $this->enableJsonMode();
        }

        $app = (string) $this->argument('app');
        $result = ['success' => false, 'app' => $app, 'host' => null, 'error' => null];

        try {
            in_array($app, self::APPS, true) || throw new InvalidArgumentException("LaraKube Cloud cannot install {$app}.");
            $command = $app === 'plex' ? 'plex:init' : 'tool:init';
            $context = $this->useKubeconfig((string) $this->option('kubeconfig'));
            $arguments = $this->initArguments($app, $context);
            $result['host'] = $arguments['--domain'] ?? null;

            $output = new BufferedOutput;
            $exit = Artisan::call($command, $arguments, $output);
            $result['success'] = $exit === 0;
            $result['error'] = $exit === 0 ? null : $this->lastLines($output->fetch());
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        $result['success']
            ? $this->laraKubeInfo("✅ Installed {$app}.")
            : $this->laraKubeError((string) $result['error']);

        if ($json) {
            $this->jsonOutput($result);
        }

        return $result['success'] ? 0 : 1;
    }

    /** @return array<string, mixed> */
    private function initArguments(string $app, string $context): array
    {
        $arguments = ['environment' => 'production', '--context' => $context, '--no-interaction' => true];

        if ($app === 'plex') {
            $services = array_values(array_filter(array_map(trim(...), explode(',', (string) $this->option('plex-services')))));
            $unknown = array_diff($services, self::PLEX_SERVICES);
            if ($services === [] || $unknown !== []) {
                throw new InvalidArgumentException('Choose Commons services from: '.implode(', ', self::PLEX_SERVICES).'.');
            }

            return [...$arguments, '--services' => implode(',', $services), '--confirm-commons-restart' => true];
        }

        $accepted = array_map(fn (InitOption $option): string => $option->name, ToolInitSpec::for(ClusterTool::from($app)));
        $arguments['--tool'] = $app;
        // The Install click in LaraKube Cloud is the user's confirmation, as Desktop's is: a runner
        // cannot answer the prompt, so an app that adds a service to a running Commons would fail.
        $arguments['--confirm-commons-restart'] = true;
        if (in_array('force', $accepted, true)) {
            $arguments['--force'] = true;
        }

        $domain = strtolower(trim((string) $this->option('domain')));
        if ($domain !== '' && in_array('domain', $accepted, true)) {
            if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1) {
                throw new InvalidArgumentException("{$domain} is not a valid host.");
            }
            $arguments['--domain'] = $domain;
        }

        $email = trim((string) $this->option('admin-email'));
        if ($email !== '' && in_array('admin-email', $accepted, true)) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('The admin email is not valid.');
            }
            $arguments['--admin-email'] = $email;
        }

        $db = trim((string) $this->option('db'));
        if (in_array('db', $accepted, true)) {
            $choices = collect(ToolInitSpec::for(ClusterTool::from($app)))->firstWhere('name', 'db')?->choices ?? [];
            $db = $db === '' ? (string) array_key_first($choices) : $db;
            if (! array_key_exists($db, $choices)) {
                throw new InvalidArgumentException("{$app} does not offer the {$db} storage choice.");
            }
            $arguments['--db'] = $db;
        }

        return $arguments;
    }
}
