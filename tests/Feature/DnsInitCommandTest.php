<?php

use App\Data\GlobalConfigData;
use App\State;
use Illuminate\Support\Facades\Process;

test('dns:init prompts for token and deploys on cloud', function () {
    $config = GlobalConfigData::load();
    $config->setCloudflareToken(null);
    $config->save();
    State::$transientCloudflareToken = null;

    Process::fake([
        '*kubectl*create namespace larakube-shared*' => Process::result(0),
        '*kubectl*create secret generic cloudflare-api-token*' => Process::result(0),
        '*kubectl*apply -f -*' => Process::result(0),
    ]);

    $this->artisan('dns:init prod')
        ->expectsQuestion('Cloudflare API Token', 'fake-cf-token')
        ->expectsOutputToContain('Ensuring namespace larakube-shared')
        ->expectsOutputToContain('Syncing Cloudflare token')
        ->expectsOutputToContain('ExternalDNS deployed successfully')
        ->assertExitCode(0);
});

test('dns:init fails gracefully when targeted at local environment', function () {
    $this->artisan('dns:init local')
        ->expectsOutputToContain('ExternalDNS is only supported on cloud environments')
        ->assertExitCode(1);
});

test('dns:init remove tears down the external dns stack', function () {
    Process::fake([
        '*kubectl*delete --ignore-not-found -f -*' => Process::result(0),
    ]);

    $this->artisan('dns:init prod --remove')
        ->expectsOutputToContain('Removing ExternalDNS resources')
        ->expectsOutputToContain('ExternalDNS removed')
        ->assertExitCode(0);
});
