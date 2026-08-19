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
        // Note: the dev TLD is deliberately NOT View::share()'d. A CLI is a
        // long-lived process and `config:tld` mutates the global TLD then chains
        // `larakube up` in the SAME process, so a boot-time snapshot would render
        // stale (the old TLD) during that reapply. Templates that need the TLD are
        // passed it explicitly, resolved fresh from GlobalConfigData::load() at the
        // point of use (see ManagesCompanions::deployCompanion(), the
        // SharedClusterService reconcile, etc.).

        // Ensure view cache directory exists
        $viewCachePath = config('view.compiled');
        if ($viewCachePath && ! is_dir($viewCachePath)) {
            @mkdir($viewCachePath, 0755, true);
        }

        // The PHAR compiled-view dir is namespaced per build (see config/view.php).
        // Sweep stale sibling caches from previous builds so /tmp doesn't grow.
        if (Phar::running() !== '' && is_string($viewCachePath) && str_contains($viewCachePath, 'larakube-views-')) {
            foreach (glob(dirname($viewCachePath).'/larakube-views-*') ?: [] as $stale) {
                if ($stale !== $viewCachePath && is_dir($stale)) {
                    array_map('unlink', glob("$stale/*") ?: []);
                    @rmdir($stale);
                }
            }
        }

        // Hide/Remove commands from binary for clean DX
        if (Phar::running() !== '') {
            Artisan::starting(function ($artisan): void {
                $toHide = [
                    // AI Boilerplate
                    'make:agent',
                    'make:agent-middleware',
                    'make:tool',

                    // MCP Boilerplate
                    'make:mcp-app-resource',
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
