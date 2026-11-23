<?php

namespace App\Commands\Cloud;

use App\Facades\State;
use App\Traits\DiscoversUnfinishedStacks;
use App\Traits\EmitsJsonOutput;
use App\Traits\ForgetsStacks;
use App\Traits\InteractsWithOpenTofu;
use App\Traits\InteractsWithRemoteSsh;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesDevBoxTunnel;
use App\Traits\ReadsCommandOptions;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

/**
 * Tear down an OpenTofu-provisioned stack (droplet or DOKS cluster) and forget it
 * from the global registry. The counterpart to `cloud:create`. Distinct from
 * `cloud:nuke`, which wipes an app's resources but leaves the infra running.
 */
class CloudDestroyCommand extends Command
{
    use DiscoversUnfinishedStacks, EmitsJsonOutput, ForgetsStacks, InteractsWithOpenTofu, InteractsWithRemoteSsh, LaraKubeOutput, ManagesDevBoxTunnel, ReadsCommandOptions;

    protected $signature = 'cloud:destroy
        {stack? : The stack name to destroy. Omit to pick from the registry.}
        {--force : Skip the confirmation prompt}
        {--provider= : Provider of a stack destroyed from remote state (gcp)}
        {--json : Emit one machine-readable JSON result on stdout}
        {--do-token= : DigitalOcean API token for this run only}
        {--hetzner-token= : Hetzner Cloud API token}
        {--aws-profile= : AWS CLI profile name}
        {--aws-region= : AWS region for this run only}
        {--aws-access-key-id= : AWS Access Key ID for this run only}
        {--aws-secret-access-key= : AWS Secret Access Key for this run only}
        {--gcp-account= : Google Cloud account email}
        {--gcp-project= : Google Cloud project ID}
        {--gcp-credentials= : Path to Google Cloud Service Account JSON key for this run only}';

    protected $description = 'Destroy an OpenTofu-provisioned stack (droplet or DOKS cluster) and remove it from the registry';

    /** @var array<string, mixed> */
    private array $result = [];

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $exit = getenv('LARAKUBE_TOFU_STATE_ID') ? $this->destroyFromState() : $this->destroyRegistered();

        if (State::isJsonMode()) {
            $this->jsonOutput($exit === 0
                ? array_merge(['success' => true], $this->result, ['error' => null])
                : ['success' => false, 'error' => State::lastError() ?? 'Destroy did not complete.']);
        }

        return $exit;
    }

    /**
     * Destroy from remote state alone, for a fresh job container with no local registry or
     * main.tf. Reports how many resources state held: zero means an earlier run already finished,
     * or the state ID is wrong, so the caller must not read it as proof of a deletion.
     */
    private function destroyFromState(): int
    {
        $this->renderHeader();

        $stack = (string) $this->argument('stack');
        $missing = match (true) {
            preg_match('/^[a-z0-9][a-z0-9-]*$/', $stack) !== 1 => 'a valid stack name',
            $this->option('provider') !== 'gcp' => '--provider=gcp',
            ! $this->option('force') => '--force',
            ! $this->option('gcp-project') => '--gcp-project',
            ! $this->option('gcp-credentials') || ! is_file((string) $this->option('gcp-credentials')) => '--gcp-credentials pointing at a key file',
            ! getenv('LARAKUBE_TOFU_STATE_BUCKET') || ! getenv('LARAKUBE_TOFU_STATE_ENDPOINT') => 'LARAKUBE_TOFU_STATE_BUCKET and LARAKUBE_TOFU_STATE_ENDPOINT',
            ! getenv('LARAKUBE_TOFU_PASSPHRASE') => 'LARAKUBE_TOFU_PASSPHRASE',
            default => null,
        };

        if ($missing !== null) {
            $this->laraKubeError("Destroying from remote state needs {$missing}.");

            return 1;
        }

        $bin = $this->resolveTofuBinary();
        if ($bin === null || ! $bin['isOpenTofu']) {
            $this->laraKubeError('Destroying from remote state needs OpenTofu, because the state is encrypted.');

            return 1;
        }

        State::setTransientGcpProject((string) $this->option('gcp-project'));
        State::setTransientGcpCredentials((string) $this->option('gcp-credentials'));

        $this->writeTofuFiles($stack, ['main.tf' => view('tofu.gcp.destroy-from-state')->render()]);

        if (! $this->tofuInit($bin, $stack)) {
            $this->laraKubeError('tofu init failed, so nothing was destroyed.');

            return 1;
        }

        $before = $this->tofuStateResources($bin, $stack);
        if ($before === null) {
            $this->laraKubeError('Could not read the remote state, so nothing was destroyed.');

            return 1;
        }

        if ($before !== 0 && ! $this->tofuDestroy($bin, $stack)) {
            $this->laraKubeError('tofu destroy failed. Its state is kept, so this can be retried.');

            return 1;
        }

        if ($this->tofuStateResources($bin, $stack) !== 0) {
            $this->laraKubeError('tofu destroy finished, but resources are still recorded in state.');

            return 1;
        }

        $this->result = ['stackName' => $stack, 'resourcesDestroyed' => $before];
        $this->laraKubeInfo("✅ Destroyed '{$stack}' from remote state ({$before} resources).");

        return 0;
    }

    private function tofuStateResources(array $bin, string $stack): ?int
    {
        $listed = $this->runTofu($bin, $stack, 'state', ['list'], [], capture: true);

        if ($listed['code'] !== 0) {
            return null;
        }

        return count(array_filter(preg_split('/\R/', $listed['output']) ?: [], fn (string $line): bool => trim($line) !== '' && ! str_starts_with(trim($line), 'data.')));
    }

    private function destroyRegistered(): int
    {
        $this->renderHeader();

        $stacks = $this->getGlobalConfig()->getStacks();
        $unfinished = $this->getUnfinishedStacks();

        if (empty($stacks) && empty($unfinished)) {
            $this->laraKubeWarn('No stacks or unfinished setups found. (Nothing was created via cloud:create on this machine.)');

            return 0;
        }

        $allOptions = [];
        foreach ($stacks as $k => $s) {
            $allOptions[$k] = $s->name.'  ('.$s->kind.', '.($s->region ?? '?').($s->ip ? ', '.$s->ip : '').')';
        }
        foreach ($unfinished as $k => $s) {
            $allOptions[$k] = $s->name.'  ('.$s->kind.' - unfinished setup in ~/.larakube/tofu'.($s->ip ? ', '.$s->ip : '').')';
        }

        $name = $this->argument('stack') ?: select(
            label: 'Which stack do you want to DESTROY?',
            options: $allOptions,
        );

        $stack = $this->getGlobalConfig()->findStack($name) ?? ($unfinished[$name] ?? null);
        if (! $stack) {
            $dir = home_path('.larakube/tofu/'.$name);
            if (is_dir($dir)) {
                $stack = $this->synthesizeUnfinishedStack($name, $dir);
            }
        }

        if (! $stack) {
            $this->laraKubeError("No registered stack or unfinished setup named '{$name}'.");

            return 1;
        }

        if ($flagDo = $this->option('do-token')) {
            State::setTransientDoToken($flagDo);
        } elseif ($stack->provider === 'do' && $stack->account) {
            $acc = collect($this->getGlobalConfig()->getCloudAccounts('do'))->firstWhere('id', $stack->account);
            if ($acc) {
                State::setTransientDoToken($acc['token']);
            }
        }

        if ($flagHetzner = $this->option('hetzner-token')) {
            State::setTransientHetznerToken($flagHetzner);
        } elseif ($stack->provider === 'hetzner' && $stack->account) {
            $acc = collect($this->getGlobalConfig()->getCloudAccounts('hetzner'))->firstWhere('id', $stack->account);
            if ($acc) {
                State::setTransientHetznerToken($acc['token']);
            }
        }

        if ($flagProfile = $this->option('aws-profile')) {
            State::setTransientAwsProfile($flagProfile);
        } elseif ($stack->provider === 'aws' && $stack->account) {
            State::setTransientAwsProfile($stack->account);
        }

        if ($flagRegion = $this->option('aws-region')) {
            State::setTransientAwsRegion($flagRegion);
        }

        if ($flagAccessKey = $this->option('aws-access-key-id')) {
            State::setTransientAwsAccessKeyId($flagAccessKey);
        }

        if ($flagSecretKey = $this->option('aws-secret-access-key')) {
            State::setTransientAwsSecretAccessKey($flagSecretKey);
        }

        if ($flagCreds = $this->option('gcp-credentials')) {
            State::setTransientGcpCredentials($flagCreds);
        }

        if ($flagAccount = $this->option('gcp-account')) {
            State::setTransientGcpAccount($flagAccount);
            $gcloud = \App\Support\Os::quoteExecutable(\App\Enums\CliTool::GCLOUD->resolveBinary() ?? 'gcloud');
            Process::run("{$gcloud} config set account ".escapeshellarg(State::transientGcpAccount()).\App\Support\Os::silenceStderr());
        } elseif ($stack->provider === 'gcp' && $stack->account) {
            State::setTransientGcpAccount($stack->account);
            $gcloud = \App\Support\Os::quoteExecutable(\App\Enums\CliTool::GCLOUD->resolveBinary() ?? 'gcloud');
            Process::run("{$gcloud} config set account ".escapeshellarg($stack->account).\App\Support\Os::silenceStderr());
        }

        if ($flagProject = $this->option('gcp-project')) {
            State::setTransientGcpProject($flagProject);
        } elseif ($stack->provider === 'gcp' && $stack->projectId) {
            State::setTransientGcpProject($stack->projectId);
        }

        $bin = $this->ensureTofu();
        if (! $bin) {
            return 1;
        }

        $this->laraKubeInfo("Cloud Destroy: '{$stack->name}' ({$stack->kind}".($stack->ip ? ', '.$stack->ip : '').')');
        $this->warn('⚠ WARNING: This permanently deletes the droplet/cluster and ALL data on it.');

        // Surface still-bound environments so we don't yank infra out from under a
        // live deploy. Best-effort — only bindings recorded on this machine appear.
        if (! empty($stack->bindings)) {
            $this->laraKubeWarn('These environments are still bound to this stack:');
            foreach ($stack->bindings as $b) {
                $this->line("    <fg=yellow>{$b}</>");
            }
        }
        $this->newLine();

        if (! $this->option('force') && ! confirm("Type-confirm: really destroy '{$stack->name}'? This cannot be undone.", false)) {
            $this->laraKubeInfo('Destroy cancelled.');

            return 0;
        }

        if (! $this->tofuStateExists($stack->name)) {
            $this->laraKubeWarn('No Tofu state found for this stack — removing the registry entry only.');
            $this->forgetStackEverywhere($stack);

            return 0;
        }

        $this->laraKubeInfo('Running tofu destroy...');
        if (! $this->tofuDestroy($bin, $stack->name)) {
            $this->laraKubeError('tofu destroy failed — leaving the registry entry so you can retry.');

            return 1;
        }

        // The cluster is gone, so its context is only a dead entry in ~/.kube/config.
        $this->forgetStackEverywhere($stack);
        $this->laraKubeInfo("✅ Destroyed and unregistered '{$stack->name}'.");

        return 0;
    }
}
