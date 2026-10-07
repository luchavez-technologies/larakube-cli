<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

test('keys from the environment are saved as the default profile and never read from flags', function (): void {
    $home = home_path();
    putenv('AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE');
    putenv('AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
    Process::fake(['*' => Process::result(exitCode: 1)]);

    expect(Artisan::call('cloud:credentials', ['--provider' => 'aws', '--region' => 'us-east-1', '--json' => true]))->toBe(0)
        ->and(json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true))->toBe(['success' => true, 'provider' => 'aws', 'region' => 'us-east-1', 'verified' => null])
        ->and(file_get_contents("{$home}/.aws/credentials"))->toContain('aws_access_key_id = AKIAIOSFODNN7EXAMPLE')
        ->and(file_get_contents("{$home}/.aws/config"))->toContain('region = us-east-1');

    putenv('AWS_ACCESS_KEY_ID');
    putenv('AWS_SECRET_ACCESS_KEY');
});

test('without keys in the environment nothing is written', function (): void {
    $home = home_path();
    putenv('AWS_ACCESS_KEY_ID');
    putenv('AWS_SECRET_ACCESS_KEY');

    expect(Artisan::call('cloud:credentials', ['--provider' => 'aws', '--region' => 'us-east-1', '--json' => true]))->toBe(1)
        ->and(file_exists("{$home}/.aws/credentials"))->toBeFalse();
});

test('keys can be saved under a named profile', function (): void {
    $home = home_path();
    putenv('AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE');
    putenv('AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
    Process::fake(['*' => Process::result(exitCode: 1)]);

    expect(Artisan::call('cloud:credentials', ['--provider' => 'aws', '--region' => 'us-east-1', '--profile' => 'staging', '--json' => true]))->toBe(0)
        ->and(json_decode(Arr::last(array_filter(explode("\n", trim(Artisan::output())))), true))->toBe(['success' => true, 'provider' => 'aws', 'region' => 'us-east-1', 'verified' => null, 'profile' => 'staging'])
        ->and(file_get_contents("{$home}/.aws/credentials"))->toContain('[staging]')
        ->and(file_get_contents("{$home}/.aws/config"))->toContain('[profile staging]');

    putenv('AWS_ACCESS_KEY_ID');
    putenv('AWS_SECRET_ACCESS_KEY');
});
