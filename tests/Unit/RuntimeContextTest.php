<?php

use App\Facades\State;
use App\Services\RuntimeContext;

test('RuntimeContext is bound as a singleton in the Laravel container', function (): void {
    $instance1 = app(RuntimeContext::class);
    $instance2 = app(RuntimeContext::class);

    expect($instance1)->toBeInstanceOf(RuntimeContext::class)
        ->and($instance1)->toBe($instance2);
});

test('State facade proxies to the RuntimeContext singleton', function (): void {
    State::setLastError('test-error-message');
    expect(State::lastError())->toBe('test-error-message')
        ->and(app(RuntimeContext::class)->lastError())->toBe('test-error-message');

    State::setJsonMode(true);
    expect(State::isJsonMode())->toBeTrue();

    State::setHeaderRendered(true);
    expect(State::isHeaderRendered())->toBeTrue();
});

test('State facade manages transient credentials in-memory without persistence', function (): void {
    State::setTransientDoToken('dop_v1_example');
    State::setTransientHetznerToken('hetzner_example');
    State::setTransientCloudflareToken('cf_example');
    State::setTransientGcpAccount('user@example.com');
    State::setTransientGcpProject('project-123');
    State::setTransientGcpCredentials('/path/to/creds.json');
    State::setTransientAwsProfile('dev-profile');
    State::setTransientAwsRegion('us-west-2');
    State::setTransientAwsAccessKeyId('AKIAEXAMPLE');
    State::setTransientAwsSecretAccessKey('secretkeyexample');

    expect(State::transientDoToken())->toBe('dop_v1_example')
        ->and(State::transientHetznerToken())->toBe('hetzner_example')
        ->and(State::transientCloudflareToken())->toBe('cf_example')
        ->and(State::transientGcpAccount())->toBe('user@example.com')
        ->and(State::transientGcpProject())->toBe('project-123')
        ->and(State::transientGcpCredentials())->toBe('/path/to/creds.json')
        ->and(State::transientAwsProfile())->toBe('dev-profile')
        ->and(State::transientAwsRegion())->toBe('us-west-2')
        ->and(State::transientAwsAccessKeyId())->toBe('AKIAEXAMPLE')
        ->and(State::transientAwsSecretAccessKey())->toBe('secretkeyexample');
});

test('State facade supports arbitrary transient key-value pairs', function (): void {
    State::setTransient('custom_key', 'custom_value');

    expect(State::hasTransient('custom_key'))->toBeTrue()
        ->and(State::getTransient('custom_key'))->toBe('custom_value');

    State::setTransient('custom_key', null);
    expect(State::hasTransient('custom_key'))->toBeFalse()
        ->and(State::getTransient('custom_key'))->toBeNull();
});

test('State facade registers secrets with minimum 8 character threshold', function (): void {
    State::registerSecret('short');
    expect(State::registeredSecrets())->not->toHaveKey('short');

    State::registerSecret('super-secret-token');
    expect(State::registeredSecrets())->toHaveKey('super-secret-token');
});

test('flush clears all state back to defaults', function (): void {
    State::setLastError('error');
    State::setTransientDoToken('token');
    State::registerSecret('secret-token-123');

    State::flush();

    expect(State::lastError())->toBeNull()
        ->and(State::transientDoToken())->toBeNull()
        ->and(State::registeredSecrets())->toBeEmpty();
});
