<?php

namespace App\Commands\Cloud;

use App\Data\StackData;
use App\Facades\State;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithOpenTofu;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use LaravelZero\Framework\Commands\Command;

/**
 * Move a Google Cloud VPS off the shared "larakube" firewall tag onto its own. The new tag is added
 * before rules move and the old one removed last, so the server's ports never close. Each step
 * applies only a plan proven to change tags and nothing else. Safe to re-run.
 */
class CloudFirewallIsolateCommand extends Command
{
    use EmitsJsonOutput, InteractsWithOpenTofu, LaraKubeOutput, ReadsCommandOptions;

    private const PLAN_FILE = 'firewall-isolate.tfplan';

    protected $signature = 'cloud:firewall:isolate
        {stack : The Google Cloud VPS stack}
        {--dry-run : Check the first step and change nothing}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Give a Google Cloud VPS its own firewall tag so its rules cannot reach other servers';

    /** @var array<string, mixed> */
    private array $result = [];

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $exit = $this->isolate();

        if (State::isJsonMode()) {
            $this->jsonOutput($exit === 0
                ? array_merge(['success' => true], $this->result, ['error' => null])
                : ['success' => false, 'error' => State::lastError() ?? 'The firewall was not changed.']);
        }

        return $exit;
    }

    public static function firewallTag(string $stack): string
    {
        return 'larakube-'.substr(md5($stack), 0, 16);
    }

    /**
     * The config after each step: add the new tag, move the rules, drop the shared tag.
     *
     * @return list<string>
     */
    public static function steps(string $config, string $tag): array
    {
        $added = str_contains($config, "\"{$tag}\"")
            ? $config
            : preg_replace('/(tags\s*=\s*\[[^\]]*"larakube-vps",)/', "$1 \"{$tag}\",", $config, 1);
        $moved = preg_replace('/(target_tags\s*=\s*\[)"larakube"(\])/', "$1\"{$tag}\"$2", (string) $added);
        $removed = preg_replace('/(?<!target_)(tags\s*=\s*\[)"larakube",\s*/', '$1', (string) $moved, 1);

        return [(string) $added, (string) $moved, (string) $removed];
    }

    /**
     * Whether a `tofu show -json` plan only changes instance tags and firewall target tags in place.
     *
     * @param  array<string, mixed>  $plan
     */
    public static function planOnlyChangesTags(array $plan): bool
    {
        $allowed = ['google_compute_instance' => 'tags', 'google_compute_firewall' => 'target_tags'];

        foreach ($plan['resource_changes'] ?? [] as $change) {
            $actions = $change['change']['actions'] ?? [];

            if ($actions === ['no-op']) {
                continue;
            }

            $attribute = $allowed[$change['type'] ?? ''] ?? null;
            if ($actions !== ['update'] || $attribute === null) {
                return false;
            }

            $before = (array) ($change['change']['before'] ?? []);
            foreach ((array) ($change['change']['after'] ?? []) as $key => $value) {
                if ($key !== $attribute && ! self::unchanged($before[$key] ?? null, $value)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Plans leave computed nested values out of `after`, so only the values it states are compared. */
    private static function unchanged(mixed $before, mixed $after): bool
    {
        if (! is_array($after)) {
            return $before === $after;
        }

        // The Google provider plans an unset map as an empty one.
        if ($after === []) {
            return $before === null || $before === [];
        }

        if (! is_array($before) || (array_is_list($after) && count($after) !== count($before))) {
            return false;
        }

        foreach ($after as $key => $value) {
            if (! self::unchanged($before[$key] ?? null, $value)) {
                return false;
            }
        }

        return true;
    }

    private function isolate(): int
    {
        $this->renderHeader();

        $name = (string) $this->argument('stack');
        $stack = $this->getGlobalConfig()->findStack($name);

        if (! $stack instanceof StackData || $stack->provider !== 'gcp' || $stack->kind !== 'vps') {
            $this->laraKubeError("'{$name}' is not a registered Google Cloud VPS.");

            return 1;
        }

        $file = home_path(".larakube/tofu/{$stack->name}/main.tf");
        if (! is_file($file)) {
            $this->laraKubeError("No OpenTofu config for '{$stack->name}' on this computer.");

            return 1;
        }

        $bin = $this->resolveTofuBinary();
        if ($bin === null) {
            $this->laraKubeError('OpenTofu is required.');

            return 1;
        }

        if ($stack->account) {
            State::setTransientGcpAccount($stack->account);
        }
        if ($stack->projectId) {
            State::setTransientGcpProject($stack->projectId);
        }

        if (! $this->tofuInit($bin, $stack->name)) {
            $this->laraKubeError('tofu init failed, so nothing was changed.');

            return 1;
        }

        $tag = self::firewallTag($stack->name);
        $original = (string) file_get_contents($file);
        $changed = 0;

        foreach (self::steps($original, $tag) as $index => $next) {
            $current = (string) file_get_contents($file);
            if ($next === $current) {
                continue;
            }

            file_put_contents($file, $next);

            if (! $this->planIsSafe($bin, $stack->name)) {
                file_put_contents($file, $current);
                @unlink(dirname($file).'/'.self::PLAN_FILE);
                $this->laraKubeError('The plan would change more than firewall tags, so this step was not applied.');

                return 1;
            }

            if ($this->flag('dry-run')) {
                file_put_contents($file, $original);
                @unlink(dirname($file).'/'.self::PLAN_FILE);
                $this->result = ['stackName' => $stack->name, 'tag' => $tag, 'dryRun' => true];
                $this->laraKubeInfo('The first step is safe. Nothing was changed.');

                return 0;
            }

            $applied = $this->runTofu($bin, $stack->name, 'apply', ['-input=false', self::PLAN_FILE])['code'] === 0;
            @unlink(dirname($file).'/'.self::PLAN_FILE);

            if (! $applied) {
                file_put_contents($file, $current);
                $this->laraKubeError('Step '.($index + 1).' of 3 failed. The server keeps its current rules, and this can be re-run.');

                return 1;
            }

            $changed++;
        }

        $this->result = ['stackName' => $stack->name, 'tag' => $tag, 'stepsApplied' => $changed];
        $this->laraKubeInfo($changed === 0 ? 'This server already has its own firewall tag.' : "✅ '{$stack->name}' now uses its own firewall tag.");

        return 0;
    }

    private function planIsSafe(array $bin, string $stack): bool
    {
        if ($this->runTofu($bin, $stack, 'plan', ['-input=false', '-refresh=false', '-out='.self::PLAN_FILE], [], capture: true)['code'] !== 0) {
            return false;
        }

        $shown = $this->runTofu($bin, $stack, 'show', ['-json', self::PLAN_FILE], [], capture: true);
        $plan = $shown['code'] === 0 ? json_decode($shown['output'], true) : null;

        return is_array($plan) && self::planOnlyChangesTags($plan);
    }
}
