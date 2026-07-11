<?php

namespace App\Commands;

use App\Traits\CapturesPassthroughArgs;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class NodeCommand extends Command
{
    use CapturesPassthroughArgs, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'node {commands* : The npm or node command to run} {--environment=local : The environment to target}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run npm or node commands inside the Kubernetes Node pod';

    public function __construct()
    {
        parent::__construct();

        $this->ignoreValidationErrors();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        ['command' => $nodeCommand, 'options' => $opts] = $this->capturePassthroughArgs('node');

        $service = 'node';
        $config = $this->getProjectConfig(getcwd());
        if ($config && (! $config->getFrontend() || ! $config->getFrontend()->requiresNodePod())) {
            $service = 'web';
        }

        return $this->call('exec', [
            'commands' => [$nodeCommand],
            '--service' => $service,
            '--environment' => $opts['environment'],
        ]);
    }
}
