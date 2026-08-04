<?php

/**
 * Tests for InteractsWithHosts' Process-backed read-only queries
 * (resolveIngressIp, dnsmasqCoversKube) and writeToEtcHosts() (the sudo cp
 * /etc/hosts step, now Process-backed too). The PowerShell RunAs elevation
 * path for the Windows hosts file (syncWindowsHostsFile()) still calls real
 * Command output methods (printWindowsHostsManualHelp()'s $this->line())
 * that a bare class here can't provide, and belongs in a real-machine smoke
 * test regardless.
 */

use App\Traits\InteractsWithHosts;
use Illuminate\Support\Facades\Process;

function hostsProcessHelper(): object
{
    return new class
    {
        use InteractsWithHosts;

        public function ingressIp(): string
        {
            return $this->resolveIngressIp();
        }

        public function dnsmasqCovers(?string $tld = null): bool
        {
            return $this->dnsmasqCoversKube($tld);
        }

        public function writeHosts(string $content): bool
        {
            return $this->writeToEtcHosts($content);
        }
    };
}

test('resolveIngressIp prefers the LoadBalancer IP over the node InternalIP', function () {
    Process::fake([
        "kubectl get svc traefik -n traefik -o jsonpath='{.status.loadBalancer.ingress[0].ip}'" => '203.0.113.10',
    ]);

    expect(hostsProcessHelper()->ingressIp())->toBe('203.0.113.10');
});

test('resolveIngressIp falls back to the node InternalIP when there is no LoadBalancer IP', function () {
    Process::fake([
        "kubectl get svc traefik -n traefik -o jsonpath='{.status.loadBalancer.ingress[0].ip}'" => Process::result(output: '', exitCode: 1),
        "kubectl get nodes -o jsonpath='{.items[0].status.addresses[?(@.type==\"InternalIP\")].address}'" => '172.31.5.2',
    ]);

    expect(hostsProcessHelper()->ingressIp())->toBe('172.31.5.2');
});

test('resolveIngressIp falls back to 127.0.0.1 when neither is available', function () {
    Process::fake([
        "kubectl get svc traefik -n traefik -o jsonpath='{.status.loadBalancer.ingress[0].ip}'" => Process::result(output: '', exitCode: 1),
        "kubectl get nodes -o jsonpath='{.items[0].status.addresses[?(@.type==\"InternalIP\")].address}'" => Process::result(output: '', exitCode: 1),
    ]);

    expect(hostsProcessHelper()->ingressIp())->toBe('127.0.0.1');
});

test('dnsmasqCoversKube is false when the platform-specific dnsmasq marker file is absent', function () {
    $tld = 'nonexistent-tld-'.uniqid();
    expect(hostsProcessHelper()->dnsmasqCovers($tld))->toBeFalse();
});

test('writeToEtcHosts stages content in a random tempnam path, not a hardcoded predictable one', function () {
    Process::fake([
        'sudo cp *' => Process::result(exitCode: 0),
    ]);

    expect(hostsProcessHelper()->writeHosts("127.0.0.1 test.kube\n"))->toBeTrue();

    Process::assertRan(function ($process) {
        // Must target /etc/hosts, and must NOT be the old fixed filename —
        // a predictable /tmp path is exactly the symlink-race this fixed.
        return str_starts_with($process->command, 'sudo cp ')
            && str_ends_with($process->command, ' /etc/hosts')
            && ! str_contains($process->command, "'".sys_get_temp_dir().'/larakube_hosts'."'");
    });
});

test('writeToEtcHosts returns false when sudo cp fails', function () {
    Process::fake([
        'sudo cp *' => Process::result(exitCode: 1),
    ]);

    expect(hostsProcessHelper()->writeHosts("127.0.0.1 test.kube\n"))->toBeFalse();
});
