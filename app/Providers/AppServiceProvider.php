<?php

namespace App\Providers;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\ServiceProvider;
use Phar;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Ensure view cache directory exists
        $viewCachePath = config('view.compiled');
        if ($viewCachePath && ! is_dir($viewCachePath)) {
            @mkdir($viewCachePath, 0755, true);
        }

        // Hide/Remove commands from binary for clean DX
        if (Phar::running() !== '') {
            Artisan::starting(function ($artisan) {
                $toHide = [
                    // AI Boilerplate
                    'make:agent',
                    'make:agent-middleware',
                    'make:tool',

                    // MCP Boilerplate
                    'make:mcp-prompt',
                    'make:mcp-resource',
                    'make:mcp-server',
                    'make:mcp-tool',
                    'mcp:inspector',

                    // Laravel Generators
                    'make:command',
                    'make:factory',
                    'make:model',
                    'make:test',

                    // Spatie Data
                    'make:data',
                    'data:cache-structures',
                ];

                foreach ($toHide as $name) {
                    $artisan->add(new class($name) extends SymfonyCommand
                    {
                        public function __construct(string $name)
                        {
                            parent::__construct($name);
                            $this->setHidden(true);
                        }

                        protected function execute(InputInterface $input, OutputInterface $output): int
                        {
                            $output->writeln('This command is disabled in the binary.');

                            return 0;
                        }
                    });
                }
            });
        }
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->register(LaravelDataServiceProvider::class);
    }
}
