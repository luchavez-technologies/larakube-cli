<?php

namespace App\Commands;

use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class ContextRestoreCommand extends Command
{
    use LaraKubeOutput;

    protected $signature = 'context:restore {--force : Skip the confirmation prompt}';

    protected $description = 'Restore a previous ~/.kube/config snapshot (pick from a dated list)';

    public function handle(): int
    {
        $this->renderHeader();

        $home = home_path();
        $config = $home.'/.kube/config';

        // Our dated snapshots + the legacy auto-backups (cloud:init / context:import).
        $backups = array_values(array_filter(array_merge(
            glob($home.'/.larakube/kube-backups/config-*.bak') ?: [],
            glob($home.'/.kube/config.bak.*') ?: [],
        ), 'is_file'));

        if (empty($backups)) {
            $this->laraKubeWarn('No kubeconfig backups found.');
            $this->line('  Create one with <fg=yellow>larakube context:backup</>.');

            return 0;
        }

        // Newest first, labelled with a human date (from the file's mtime).
        usort($backups, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $options = [];
        foreach ($backups as $path) {
            $options[$path] = basename($path).'  —  '.date('M j, Y g:ia', (int) filemtime($path));
        }

        $choice = select(label: 'Which backup do you want to restore?', options: $options);

        if (! $this->option('force') && ! confirm('Restore '.basename($choice).' over your current ~/.kube/config?', false)) {
            $this->laraKubeInfo('Cancelled.');

            return 0;
        }

        // Safety: snapshot the CURRENT config first, so the restore is itself reversible.
        if (is_file($config)) {
            $dir = $home.'/.larakube/kube-backups';
            if (! is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            $safety = $dir.'/config-'.date('Y-m-d_His').'-prerestore.bak';
            copy($config, $safety);
            $this->line('  <fg=gray>Saved your current config to</> '.$safety);
        } elseif (! is_dir(dirname($config))) {
            mkdir(dirname($config), 0700, true);
        }

        copy($choice, $config);
        @chmod($config, 0600);

        $this->laraKubeInfo('✅ Restored '.basename($choice).' → ~/.kube/config');
        $current = trim(Process::run('kubectl config current-context')->output());
        if ($current !== '') {
            $this->line('  <fg=gray>Current context:</> <fg=cyan>'.$current.'</>');
        }

        return 0;
    }
}
