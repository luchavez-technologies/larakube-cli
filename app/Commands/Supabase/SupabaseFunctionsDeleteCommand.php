<?php

namespace App\Commands\Supabase;

use App\Exceptions\MissingFlagException;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithSupabaseFunctions;
use App\Traits\LaraKubeOutput;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

use LaravelZero\Framework\Commands\Command;

/**
 * Delete Edge Functions from a self-hosted Supabase: removes their folders from Edge Runtime's functions
 * volume and restarts it. Deploying only adds or replaces folders, so this is the only way one goes away.
 * Edge Runtime's own `main` router can't be deleted.
 */
class SupabaseFunctionsDeleteCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithSupabaseFunctions, LaraKubeOutput, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment;

    protected $signature = 'supabase:functions:delete
        {environment=local : Environment Supabase is installed in}
        {--domain= : The Supabase instance (its host); omit when there is only one}
        {--function=* : The function to delete (repeatable)}
        {--context= : Target a specific kube-context}
        {--force : Delete without asking}';

    protected $description = 'Delete Edge Functions from a self-hosted Supabase';

    public function handle(): int
    {
        $this->renderHeader();

        $target = $this->resolveFunctionsTarget();
        if (is_string($target)) {
            $this->laraKubeError($target);

            return 1;
        }

        if (! $target['enabled']) {
            $this->laraKubeError('Edge Functions are turned off for this Supabase, so there are none to delete.');

            return 1;
        }

        $deployed = $this->deployedFunctionFolders($target);
        if (is_string($deployed)) {
            $this->laraKubeError($deployed);

            return 1;
        }

        $names = $this->functionsToDelete(array_map('strval', array_keys($deployed)));
        if ($names === null) {
            return 1;
        }

        if (! $this->option('force')) {
            if ($this->cannotPrompt()) {
                throw new MissingFlagException('force', 'confirm deleting '.implode(', ', $names), '--force');
            }

            if (! confirm('Delete '.implode(', ', $names).'? Their URLs stop working.', default: false)) {
                $this->laraKubeInfo('Nothing deleted.');

                return 0;
            }
        }

        $this->laraKubeStep('Deleting '.implode(', ', $names));
        $quoted = implode(' ', array_map('escapeshellarg', $names));
        $delete = Process::timeout(60)->run(
            "{$target['kubectl']} exec deploy/{$target['deployment']} -n {$target['namespace']} -c ".self::FUNCTIONS_CONTAINER.' -- '
            .'sh -c '.escapeshellarg('cd '.self::FUNCTIONS_DIR." && rm -rf -- {$quoted}"),
        );

        if (! $delete->successful()) {
            $this->laraKubeError('Deleting the functions failed: '.trim($delete->errorOutput() ?: $delete->output()));

            return 1;
        }

        // Edge Runtime caches workers, so a deleted function could keep answering until it restarts.
        $this->laraKubeStep('Restarting Edge Runtime');
        $restart = Process::timeout(180)->run(
            "{$target['kubectl']} rollout restart deployment/{$target['deployment']} -n {$target['namespace']}"
            ." && {$target['kubectl']} rollout status deployment/{$target['deployment']} -n {$target['namespace']} --timeout=150s",
        );

        if (! $restart->successful()) {
            $this->laraKubeError('The functions were deleted, but Edge Runtime did not come back up: '.trim($restart->errorOutput() ?: $restart->output()));

            return 1;
        }

        $this->laraKubeInfo('Deleted '.implode(', ', $names).'.');

        return 0;
    }

    /**
     * The functions named by --function, or picked from what's deployed when it's left out.
     *
     * @param  list<string>  $deployed
     * @return list<string>|null
     */
    private function functionsToDelete(array $deployed): ?array
    {
        $names = array_values(array_unique(array_filter((array) $this->option('function'), fn ($name): bool => is_string($name) && $name !== '')));

        if ($names === []) {
            if ($this->cannotPrompt()) {
                throw new MissingFlagException('function', 'which Edge Function to delete', '--function=hello');
            }

            if ($deployed === []) {
                $this->laraKubeInfo('No Edge Functions deployed, so there is nothing to delete.');

                return null;
            }

            $names = array_values(array_map('strval', multiselect('Which functions should be deleted?', $deployed, required: true)));
        }

        if (in_array('main', $names, true)) {
            $this->laraKubeError("'main' is Edge Runtime's own router and can't be deleted.");

            return null;
        }

        $missing = array_values(array_diff($names, $deployed));
        if ($missing !== []) {
            $this->laraKubeError('No Edge Function named '.implode(', ', $missing).' on this Supabase.');

            return null;
        }

        return $names;
    }
}
