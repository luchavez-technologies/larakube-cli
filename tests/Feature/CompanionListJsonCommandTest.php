<?php

/**
 * `companion:list --json` is how Desktop's companion credentials panel reads
 * this project's installed companion connection details — the same data
 * `up`/`companion:list`'s table prints, shaped for a caller that can't read a
 * rendered Prompts table. Scoped to cwd, like every other project command.
 */

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

beforeEach(function (): void {
    $this->temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $this->tempDir = $this->temporaryDirectory->path();
    $this->originalDir = getcwd();
    chdir($this->tempDir);
});

afterEach(function (): void {
    chdir($this->originalDir);
    $this->temporaryDirectory->delete();
});

function saveCompanionListConfig(string $dir): void
{
    $config = ConfigData::from([
        'name' => 'companion-list-test',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'postgres',
        'environments' => ['local' => []],
    ]);
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    $config->setPath($dir);
    $config->saveToFile($dir);
}

test('companion:list --json returns an empty companions list outside a LaraKube project', function (): void {
    $exit = Artisan::call('companion:list --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($output['success'])->toBeTrue()
        ->and($output['companions'])->toBe([]);
});

test('companion:list --json returns Mailpit unconditionally and pgAdmin once installed', function (): void {
    saveCompanionListConfig($this->tempDir);

    Process::fake([
        '*get deployment/pgadmin*' => Process::result(output: 'deployment.apps/pgadmin', exitCode: 0),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*get configmap*' => Process::result(output: '', exitCode: 1),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(output: ''),
    ]);

    $exit = Artisan::call('companion:list --json');
    $output = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);

    $names = array_column($output['companions'], 'companion');
    expect($names)->toContain('Mailpit')->toContain('pgAdmin');

    $mailpit = collect($output['companions'])->firstWhere('companion', 'Mailpit');
    expect($mailpit['url'])->toBe('https://mailpit.test')
        ->and($mailpit['url'])->not->toContain('<fg=');
});
