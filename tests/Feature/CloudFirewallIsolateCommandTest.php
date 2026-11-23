<?php

use App\Commands\Cloud\CloudFirewallIsolateCommand as Isolate;

function firewallIsolateConfig(): string
{
    return <<<'HCL'
resource "google_compute_instance" "larakube" {
  tags = ["larakube", "larakube-vps", "http-server", "https-server"]
}
resource "google_compute_firewall" "a" {
  target_tags   = ["larakube"]
}
resource "google_compute_firewall" "b" {
  target_tags   = ["larakube"]
}
HCL;
}

function firewallIsolateChange(string $type, array $actions, array $before, array $after): array
{
    return ['type' => $type, 'change' => compact('actions', 'before', 'after')];
}

test('the steps add the new tag before moving rules and drop the shared tag last', function (): void {
    [$added, $moved, $removed] = Isolate::steps(firewallIsolateConfig(), 'larakube-abc');

    expect($added)->toContain('tags = ["larakube", "larakube-vps", "larakube-abc", "http-server"')
        ->toContain('target_tags   = ["larakube"]')
        ->and($moved)->toContain('tags = ["larakube", "larakube-vps", "larakube-abc"')
        ->not->toContain('target_tags   = ["larakube"]')
        ->and(substr_count($moved, 'target_tags   = ["larakube-abc"]'))->toBe(2)
        ->and($removed)->toContain('  tags = ["larakube-vps", "larakube-abc", "http-server", "https-server"]')
        ->and(substr_count($removed, 'target_tags   = ["larakube-abc"]'))->toBe(2);
});

test('the steps change nothing on a server that is already isolated', function (): void {
    $done = Isolate::steps(firewallIsolateConfig(), 'larakube-abc')[2];

    expect(Isolate::steps($done, 'larakube-abc'))->toBe([$done, $done, $done]);
});

test('a plan that only changes tags is safe', function (): void {
    expect(Isolate::planOnlyChangesTags(['resource_changes' => [
        firewallIsolateChange('google_compute_instance', ['update'], ['name' => 'x', 'tags' => ['a']], ['name' => 'x', 'tags' => ['a', 'b']]),
        firewallIsolateChange('google_compute_firewall', ['update'], ['name' => 'y', 'target_tags' => ['a']], ['name' => 'y', 'target_tags' => ['b']]),
        firewallIsolateChange('google_compute_address', ['no-op'], [], []),
        firewallIsolateChange('google_compute_instance', ['update'], ['boot_disk' => [['size' => 30, 'kms' => 'computed', 'resource_manager_tags' => null]], 'tags' => ['a']], ['boot_disk' => [['size' => 30, 'resource_manager_tags' => []]], 'tags' => ['b']]),
    ]]))->toBeTrue();
});

test('a plan touching anything else is refused', function (array $change): void {
    expect(Isolate::planOnlyChangesTags(['resource_changes' => [$change]]))->toBeFalse();
})->with([
    'replace' => [firewallIsolateChange('google_compute_instance', ['delete', 'create'], [], [])],
    'delete' => [firewallIsolateChange('google_compute_firewall', ['delete'], [], [])],
    'create' => [firewallIsolateChange('google_compute_firewall', ['create'], [], [])],
    'metadata' => [firewallIsolateChange('google_compute_instance', ['update'], ['metadata' => ['a' => '1']], ['metadata' => ['a' => '2']])],
    'nested disk size' => [firewallIsolateChange('google_compute_instance', ['update'], ['boot_disk' => [['size' => 30]]], ['boot_disk' => [['size' => 50]]])],
    'cleared map' => [firewallIsolateChange('google_compute_instance', ['update'], ['labels' => ['team' => 'a']], ['labels' => []])],
    'open source range' => [firewallIsolateChange('google_compute_firewall', ['update'], ['source_ranges' => ['10.0.0.0/8']], ['source_ranges' => ['0.0.0.0/0']])],
    'other resource' => [firewallIsolateChange('google_compute_address', ['update'], ['a' => 1], ['a' => 2])],
]);
