<?php

/**
 * Tests for InteractsWithHosts' Process-backed read-only queries
 * (resolveIngressIp, dnsmasqCoversKube). The privileged/interactive paths
 * (sudo cp /etc/hosts, PowerShell RunAs elevation for the Windows hosts
 * file) are left as raw exec()/passthru() — a different migration shape,
 * same as ConfiguresCloudEnvironment's SSH passthru() — and belong in a
 * real-machine smoke test, not here.
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
    // No /etc/resolver/<tld> (macOS) or /etc/dnsmasq.d/larakube.conf (Linux) in
    // this sandbox, so this short-circuits before ever touching Process — a
    // real positive-path assertion needs the actual marker file/dnsmasq
    // config present, which is a real-machine smoke-test concern.
    expect(hostsProcessHelper()->dnsmasqCovers('kube'))->toBeFalse();
});
