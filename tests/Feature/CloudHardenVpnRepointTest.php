<?php

use App\Commands\Cloud\CloudHardenCommand;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

function cloudHardenRunner(): object
{
    $command = new class extends CloudHardenCommand
    {
        public function join($user, $ip, $sshIp, $port, $keyPath, string $vpnKubectl, string $vpnNamespace, string $environment): ?string
        {
            return $this->joinVpn($user, $ip, $sshIp, $port, $keyPath, $vpnKubectl, $vpnNamespace, $environment);
        }

        public function sshIpFor(string $environment, string $ip): string
        {
            return $this->preferredSshIp($environment, $ip);
        }

        // No real waiting in tests.
        protected function pollDelay(): void {}
    };

    $input = new Symfony\Component\Console\Input\ArrayInput([]);
    $input->bind($command->getDefinition());
    $output = new Symfony\Component\Console\Output\BufferedOutput;
    $command->setInput($input);
    $command->setOutput(new Illuminate\Console\OutputStyle($input, $output));

    return $command;
}

function hardenVpnKubectl(): string
{
    return 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
}

test('joinVpn repoints the kube-context to the VPS\'s own overlay IP once it joins', function () {
    $kubectl = hardenVpnKubectl();

    Process::fake([
        "{$kubectl} get secret netbird-admin -n larakube-vpn -o jsonpath='{.data.setup-key}'" => Process::result(output: base64_encode('SETUP-KEY-123'), exitCode: 0),
        'ssh -i /key -p 22 larakube@1.2.3.4 *' => Process::result(exitCode: 0),
        'ssh -o BatchMode=yes -o StrictHostKeyChecking=no -i /key -p 22 larakube@1.2.3.4 hostname' => Process::result(output: "hello-vps\n", exitCode: 0),
        "{$kubectl} get secret netbird-admin -n larakube-vpn -o jsonpath='{.data.pat}'" => Process::result(output: base64_encode('nbp_test_pat'), exitCode: 0),
        "{$kubectl} config set-cluster 'larakube-1.2.3.4' --server='https://100.86.159.244:6443'" => Process::result(exitCode: 0),
    ]);
    Http::fake([
        'https://vpn.kube/api/peers' => Http::response([
            ['hostname' => 'hello-vps', 'ip' => '100.86.159.244'],
            ['hostname' => 'someone-else', 'ip' => '100.86.1.1'],
        ]),
    ]);

    $runner = cloudHardenRunner();
    $result = $runner->join('larakube', '1.2.3.4', '1.2.3.4', '22', '/key', $kubectl, 'larakube-vpn', 'local');

    expect($result)->toBe('100.64.0.0/10');

    Process::assertRan("{$kubectl} config set-cluster 'larakube-1.2.3.4' --server='https://100.86.159.244:6443'");
});

test('joinVpn dials the overlay IP but derives the context name from the public IP on a re-run', function () {
    // Simulates a SECOND cloud:harden run, after a prior one already
    // recorded vpnIp — preferredSshIp() would return it as $sshIp, which
    // must NOT also become what the kube-context name derives from, or a
    // re-run would look for a context that was never registered.
    $kubectl = hardenVpnKubectl();

    Process::fake([
        "{$kubectl} get secret netbird-admin -n larakube-vpn -o jsonpath='{.data.setup-key}'" => Process::result(output: base64_encode('SETUP-KEY-123'), exitCode: 0),
        'ssh -i /key -p 22 larakube@100.86.159.244 *' => Process::result(exitCode: 0),
        'ssh -o BatchMode=yes -o StrictHostKeyChecking=no -i /key -p 22 larakube@100.86.159.244 hostname' => Process::result(output: "hello-vps\n", exitCode: 0),
        "{$kubectl} get secret netbird-admin -n larakube-vpn -o jsonpath='{.data.pat}'" => Process::result(output: base64_encode('nbp_test_pat'), exitCode: 0),
        "{$kubectl} config set-cluster 'larakube-1.2.3.4' --server='https://100.86.159.244:6443'" => Process::result(exitCode: 0),
    ]);
    Http::fake([
        'https://vpn.kube/api/peers' => Http::response([
            ['hostname' => 'hello-vps', 'ip' => '100.86.159.244'],
        ]),
    ]);

    $runner = cloudHardenRunner();
    // $ip (public, for context naming) stays '1.2.3.4'; $sshIp (what's
    // actually dialed) is already the overlay IP, as preferredSshIp() would
    // return once a prior run recorded it.
    $result = $runner->join('larakube', '1.2.3.4', '100.86.159.244', '22', '/key', $kubectl, 'larakube-vpn', 'local');

    expect($result)->toBe('100.64.0.0/10');

    Process::assertRan('ssh -o BatchMode=yes -o StrictHostKeyChecking=no -i /key -p 22 larakube@100.86.159.244 hostname');
    Process::assertRan("{$kubectl} config set-cluster 'larakube-1.2.3.4' --server='https://100.86.159.244:6443'");
});

test('preferredSshIp falls back to the public IP when no environment/project is in scope', function () {
    $runner = cloudHardenRunner();

    expect($runner->sshIpFor('', '1.2.3.4'))->toBe('1.2.3.4');
});

test('preferredSshIp prefers the recorded overlay IP once cloud:harden has set one', function () {
    $dir = sys_get_temp_dir().'/larakube-harden-'.uniqid();
    mkdir($dir, 0755, true);
    $original = getcwd();
    chdir($dir);

    try {
        $config = App\Data\ConfigData::from([
            'name' => 'harden-test',
            'database' => 'sqlite',
            'environments' => ['local' => [], 'production' => []],
        ]);
        $config->setCloud('production', new App\Data\CloudData(ip: '1.2.3.4', vpnIp: '100.86.159.244'));
        $config->saveToFile($dir);

        expect(cloudHardenRunner()->sshIpFor('production', '1.2.3.4'))->toBe('100.86.159.244');
    } finally {
        chdir($original);
        exec('rm -rf '.escapeshellarg($dir));
    }
});

test('joinVpn warns instead of failing when the overlay IP can\'t be determined', function () {
    $kubectl = hardenVpnKubectl();

    Process::fake([
        "{$kubectl} get secret netbird-admin -n larakube-vpn -o jsonpath='{.data.setup-key}'" => Process::result(output: base64_encode('SETUP-KEY-123'), exitCode: 0),
        'ssh -i /key -p 22 larakube@1.2.3.4 *' => Process::result(exitCode: 0),
        'ssh -o BatchMode=yes -o StrictHostKeyChecking=no -i /key -p 22 larakube@1.2.3.4 hostname' => Process::result(output: "hello-vps\n", exitCode: 0),
        "{$kubectl} get secret netbird-admin -n larakube-vpn -o jsonpath='{.data.pat}'" => Process::result(output: base64_encode('nbp_test_pat'), exitCode: 0),
    ]);
    Http::fake([
        // The just-joined host never shows up in the peer list.
        'https://vpn.kube/api/peers' => Http::response([
            ['hostname' => 'someone-else', 'ip' => '100.86.1.1'],
        ]),
    ]);

    $runner = cloudHardenRunner();
    $result = $runner->join('larakube', '1.2.3.4', '1.2.3.4', '22', '/key', $kubectl, 'larakube-vpn', 'local');

    // Still returns the CIDR — a failed repoint never blocks the VPN allow-rule.
    expect($result)->toBe('100.64.0.0/10');
});
