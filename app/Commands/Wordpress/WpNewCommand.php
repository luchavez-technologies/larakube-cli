<?php

namespace App\Commands\Wordpress;

use LaravelZero\Framework\Commands\Command;

class WpNewCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'wp:new
                            {name? : The name of the WordPress site}
                            {--fast : Skip wizard and use ideal defaults}';

    /**
     * The console command description.
     */
    protected $description = 'Alias for wordpress:new — scaffold a new WordPress (Bedrock) project with Kubernetes infrastructure';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $parameters = [];

        if (! empty($this->argument('name'))) {
            $parameters['name'] = $this->argument('name');
        }

        if ($this->option('fast')) {
            $parameters['--fast'] = true;
        }

        return $this->call('wordpress:new', $parameters);
    }
}
