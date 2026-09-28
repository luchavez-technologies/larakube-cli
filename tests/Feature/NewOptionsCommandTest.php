<?php

use App\Enums\ServerVariation;
use Illuminate\Support\Facades\Artisan;

/**
 * @return array<string, array<string, mixed>>
 */
function newOptionsRunJson(): array
{
    expect(Artisan::call('new:options', ['--json' => true]))->toBe(0);

    $decoded = json_decode(trim(Artisan::output()), true);

    expect($decoded['success'])->toBeTrue();

    return collect($decoded['questions'])->keyBy('key')->all();
}

/**
 * @param  array<string, mixed>  $question
 * @return array<string, array<string, mixed>>
 */
function newOptionsByValue(array $question): array
{
    return collect($question['options'])->keyBy('value')->all();
}

test('--json lists every wizard question with the flag that answers each option', function (): void {
    $questions = newOptionsRunJson();

    expect(array_keys($questions))->toBe(['blueprints', 'server', 'php', 'os', 'frontend', 'features', 'search', 'database', 'cache', 'storage', 'packageManager'])
        ->and(newOptionsByValue($questions['database'])['postgres']['flag'])->toBe('--postgres')
        ->and(newOptionsByValue($questions['frontend'])['react']['flag'])->toBe('--react')
        ->and($questions['features']['multiple'])->toBeTrue()
        ->and($questions['frontend']['nullable'])->toBeTrue()
        ->and($questions['cache']['nullable'])->toBeFalse();
});

test('defaults are the ones new --fast fills in', function (): void {
    $questions = newOptionsRunJson();

    expect($questions['server']['default'])->toBe('frankenphp')
        ->and($questions['database']['default'])->toBe('mysql')
        ->and($questions['cache']['default'])->toBe('redis')
        ->and($questions['frontend']['default'])->toBeNull();
});

test('options the wizard hides are marked per server variation, or left out when never allowed', function (): void {
    $questions = newOptionsRunJson();

    expect(newOptionsByValue($questions['database'])['sqlite']['unavailableWith'])->toBe([ServerVariation::FRANKENPHP->value])
        ->and(newOptionsByValue($questions['features'])['octane']['unavailableWith'])->toBe([ServerVariation::FRANKENPHP->value])
        ->and(newOptionsByValue($questions['database'])['postgres']['unavailableWith'])->toBe([])
        // A new app is Laravel 13, which needs PHP 8.3+.
        ->and(array_keys(newOptionsByValue($questions['php'])))->not->toContain('8.2');
});

test('Horizon and Queues are reported as conflicting, and Scout gates its search driver', function (): void {
    $questions = newOptionsRunJson();

    expect($questions['features']['conflicts'])->toBe([['horizon', 'queues']])
        ->and($questions['search']['requiresFeature'])->toBe('scout');
});
