<?php

namespace App\Commands\Supabase;

use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithSupabaseFunctions;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEnvironment;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

/**
 * Deploy Edge Functions to a self-hosted Supabase. Self-hosting has no deploy API: Edge Runtime
 * serves every folder on its functions volume at /functions/v1/<name>. This copies the chosen
 * folders onto that volume, replacing each one whole so deleted files go too, then restarts the
 * runtime so nothing stale stays cached. The runtime's own `main` router is never touched.
 */
class SupabaseFunctionsDeployCommand extends Command
{
    use DeploysClusterTool, InteractsWithClusterContext, InteractsWithSupabaseFunctions, LaraKubeOutput, ResolvesToolEnvironment;

    protected $signature = 'supabase:functions:deploy
        {environment=local : Environment Supabase is installed in}
        {--domain= : The Supabase instance (its host); omit when there is only one}
        {--path=supabase/functions : Folder holding one sub-folder per function, as the Supabase CLI lays them out}
        {--function=* : Deploy only these functions (repeatable); default is every folder in --path}
        {--context= : Target a specific kube-context}';

    protected $description = 'Deploy Edge Functions from a local folder to a self-hosted Supabase';

    public function handle(): int
    {
        $this->renderHeader();

        $path = rtrim((string) $this->option('path'), '/');
        if (! is_dir($path)) {
            $this->laraKubeError("No functions folder at {$path}. Pass --path= to the folder holding one sub-folder per function.");

            return 1;
        }

        $path = $this->functionsFolderIn($path);

        $folders = $this->foldersToDeploy($path, array_values(array_filter((array) $this->option('function'), 'is_string')));
        if ($folders === null) {
            return 1;
        }

        $target = $this->resolveFunctionsTarget();
        if (is_string($target)) {
            $this->laraKubeError($target);

            return 1;
        }

        ['kubectl' => $kubectl, 'deployment' => $deployment, 'namespace' => $namespace, 'host' => $host] = $target;

        if (! $target['enabled']) {
            $this->laraKubeError('Edge Functions are turned off for this Supabase. Re-run its install without --no-functions first.');

            return 1;
        }

        $this->laraKubeStep('Copying '.count($folders).' function folder(s)');
        $quoted = implode(' ', array_map('escapeshellarg', $folders));
        // Each folder is replaced whole, so a file deleted locally is gone on the server too.
        // COPYFILE_DISABLE keeps macOS's tar from adding ._ metadata files.
        $copy = Process::timeout(300)->run(
            'COPYFILE_DISABLE=1 tar -cf - -C '.escapeshellarg($path).' '.$quoted
            ." | {$kubectl} exec -i deploy/{$deployment} -n {$namespace} -c ".self::FUNCTIONS_CONTAINER.' -- '
            .'sh -c '.escapeshellarg('cd '.self::FUNCTIONS_DIR." && rm -rf -- {$quoted} && tar -xf -"),
        );

        if (! $copy->successful()) {
            $this->laraKubeError('Copying the functions failed: '.trim($copy->errorOutput() ?: $copy->output()));

            return 1;
        }

        $this->laraKubeStep('Restarting Edge Runtime');
        $restart = Process::timeout(180)->run(
            "{$kubectl} rollout restart deployment/{$deployment} -n {$namespace}"
            ." && {$kubectl} rollout status deployment/{$deployment} -n {$namespace} --timeout=150s",
        );

        if (! $restart->successful()) {
            $this->laraKubeError('The functions were copied, but Edge Runtime did not come back up: '.trim($restart->errorOutput() ?: $restart->output()));

            return 1;
        }

        $this->laraKubeInfo('Deployed '.count($folders).' Edge Function folder(s).');
        foreach ($folders as $folder) {
            $url = $this->functionUrl($host, $folder);
            if ($url !== null) {
                $this->line("  <fg=blue>{$url}</>");
            }
        }

        return 0;
    }

    /**
     * The folder holding the functions. Picking the project's `supabase` folder (or the project
     * itself) is an easy slip, so a `functions/` (or `supabase/functions/`) inside it is used instead,
     * unless that folder is itself a function, with its own index file.
     */
    private function functionsFolderIn(string $path): string
    {
        foreach (["{$path}/functions", "{$path}/supabase/functions"] as $candidate) {
            if (is_dir($candidate) && glob("{$candidate}/index.{ts,js,tsx,jsx,mjs}", GLOB_BRACE) === []) {
                $this->laraKubeLine("Using the functions folder inside it: {$candidate}");

                return $candidate;
            }
        }

        return $path;
    }

    /**
     * The folders to copy: every sub-folder of $path, or just the named ones. Folders starting with
     * `_` (e.g. `_shared`) always go along, since functions import from them, but serve no URL.
     *
     * @param  list<string>  $only
     * @return list<string>|null
     */
    private function foldersToDeploy(string $path, array $only): ?array
    {
        $all = array_values(array_filter(
            scandir($path) ?: [],
            fn (string $entry): bool => $entry[0] !== '.' && is_dir("{$path}/{$entry}"),
        ));

        if (in_array('main', $only, true) || ($only === [] && in_array('main', $all, true))) {
            $this->laraKubeError("A function named 'main' would replace Edge Runtime's own router. Rename it.");

            return null;
        }

        $missing = array_values(array_diff($only, $all));
        if ($missing !== []) {
            $this->laraKubeError('Not found in '.$path.': '.implode(', ', $missing));

            return null;
        }

        $folders = $only !== []
            ? array_values(array_unique([...$only, ...array_filter($all, fn (string $folder): bool => str_starts_with($folder, '_'))]))
            : $all;

        foreach ($folders as $folder) {
            if (preg_match('/^[A-Za-z0-9_-]+$/', $folder) !== 1) {
                $this->laraKubeError("'{$folder}' is not a valid function name (letters, digits, - and _ only).");

                return null;
            }
        }

        if ($folders === []) {
            $this->laraKubeError("No function folders in {$path}.");

            return null;
        }

        return $folders;
    }
}
