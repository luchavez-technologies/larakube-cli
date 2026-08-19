<?php

/**
 * contextOverrideOr() is the fix for a real incident (2026-08-01): a "local"
 * plex:join landed on the production droplet because plex:leave/destroy/
 * remove had no --context flag and silently fell back to whatever kubectl's
 * CURRENT context happened to be — which another concurrently-running CLI
 * tool was flipping. --context must always win when the calling command
 * defines it, for both local and cloud environments.
 */

use App\Data\CloudData;
use App\Data\ConfigData;
use App\Traits\ResolvesEnvironmentContext;

function contextOverrideHelper(?string $contextOption): object
{
    return new class($contextOption)
    {
        use ResolvesEnvironmentContext;

        public function __construct(private ?string $contextOption) {}

        public function option($key)
        {
            return $key === 'context' ? $this->contextOption : null;
        }

        public function resolve(ConfigData $config, string $environment): ?string
        {
            return $this->contextOverrideOr($config, $environment);
        }
    };
}

test('local env with no --context resolves to null (current kubectl context)', function (): void {
    $config = ConfigData::from(['blueprints' => []]);

    expect(contextOverrideHelper(null)->resolve($config, 'local'))->toBeNull();
});

test('local env with --context uses the override instead of null', function (): void {
    $config = ConfigData::from(['blueprints' => []]);

    expect(contextOverrideHelper('orbstack')->resolve($config, 'local'))->toBe('orbstack');
});

test('cloud env with no --context resolves to the saved cloud context', function (): void {
    $config = ConfigData::from(['blueprints' => []]);
    $config->setCloud('production', new CloudData(ip: '159.89.205.239'));

    expect(contextOverrideHelper(null)->resolve($config, 'production'))->toBe('larakube-159.89.205.239');
});

test('cloud env with --context uses the override instead of the saved cloud context', function (): void {
    $config = ConfigData::from(['blueprints' => []]);
    $config->setCloud('production', new CloudData(ip: '159.89.205.239'));

    expect(contextOverrideHelper('some-other-context')->resolve($config, 'production'))->toBe('some-other-context');
});
