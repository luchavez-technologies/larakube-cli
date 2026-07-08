<?php

use App\Traits\PromotesIngressDns;
use Illuminate\Support\Facades\Process;

function ingressDnsHelper(): object
{
    return new class
    {
        use PromotesIngressDns;

        public function ip(?string $context = null): ?string
        {
            return $this->traefikLoadBalancerIp($context);
        }
    };
}

test('traefikLoadBalancerIp returns a valid IP from the current context', function () {
    Process::fake([
        "kubectl get svc -n traefik traefik -o jsonpath='{.status.loadBalancer.ingress[0].ip}'" => '203.0.113.10',
    ]);

    expect(ingressDnsHelper()->ip())->toBe('203.0.113.10');
});

test('traefikLoadBalancerIp scopes to a given context', function () {
    Process::fake([
        "kubectl --context 'larakube-1.2.3.4' get svc -n traefik traefik -o jsonpath='{.status.loadBalancer.ingress[0].ip}'" => '198.51.100.5',
    ]);

    expect(ingressDnsHelper()->ip('larakube-1.2.3.4'))->toBe('198.51.100.5');
});

test('traefikLoadBalancerIp is null when there is no LB IP yet or the value is not a valid IP', function () {
    Process::fake([
        "kubectl get svc -n traefik traefik -o jsonpath='{.status.loadBalancer.ingress[0].ip}'" => Process::result(output: '', exitCode: 1),
    ]);
    expect(ingressDnsHelper()->ip())->toBeNull();
});
