<?php

namespace App\Commands\Services;

use App\Services\Project\BackingServices;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Where this project's database, cache, object storage and search run for an
 * environment, and how the app reaches them. Secrets stay hidden unless
 * `--reveal` is given.
 */
class ServicesShowCommand extends Command
{
    use EmitsJsonOutput, InteractsWithProjectConfig, LaraKubeOutput, ReadsCommandOptions;

    protected $signature = 'services:show
        {environment=local : Environment to show (local or a cloud environment)}
        {--reveal : Include passwords and keys}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = "Show a project's database, cache, storage and search, and how the app connects";

    public function handle(): int
    {
        $json = $this->flag('json');

        if ($json) {
            $this->enableJsonMode();
        }

        if (! $this->isLaraKubeProject(! $json)) {
            return $this->failWith($json, 'Not a LaraKube project.');
        }

        $config = $this->getProjectConfig();
        $environment = (string) $this->argument('environment');

        if ($config === null) {
            return $this->failWith($json, 'The project blueprint could not be read.');
        }

        $path = (string) getcwd();
        $file = $environment === 'local' ? "{$path}/.env" : "{$path}/.env.{$environment}";
        $services = (new BackingServices)->describe($config, $environment, BackingServices::readEnv($file), $this->flag('reveal'));
        $commons = in_array('commons', array_column($services, 'mode'), true);

        if ($json) {
            $this->jsonOutput(['success' => true, 'environment' => $environment, 'commons' => $commons, 'services' => $services]);

            return 0;
        }

        $this->renderHeader();

        foreach ($services as $service) {
            $this->line('  <fg=cyan;options=bold>'.$service['label'].'</> <fg=gray>'.($service['name'] ?? 'none').' · '.$service['mode'].'</>');

            if ($service['details'] !== []) {
                table(
                    headers: ['', ''],
                    rows: array_map(fn (array $row): array => [$row['label'], $row['value'] ?? ($row['secret'] ? '(hidden — use --reveal)' : '')], $service['details']),
                );
            }
        }

        return 0;
    }

    private function failWith(bool $json, string $message): int
    {
        if ($json) {
            $this->jsonOutput(['success' => false, 'error' => $message]);
        } else {
            $this->laraKubeError($message);
        }

        return 1;
    }
}
