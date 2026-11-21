<?php

test('do/vps template renders valid HCL with restricted sources and no HTML escaping', function (): void {
    $hcl = view('tofu.do.vps', [
        'region' => 'sgp1',
        'size' => 's-1vcpu-1gb',
        'dropletName' => 'larakube-acme-prod',
        'sshKeyName' => 'larakube-acme',
        'sshPubKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1 user@host',
        'keyFingerprint' => '3b:16:aa:cc',
        'sshSources' => '"203.0.113.5/32"',
        'apiSources' => '"203.0.113.5/32"',
    ])->render();

    // Provider constraint must NOT be HTML-escaped (~> stays literal).
    expect($hcl)->toContain('version = "~> 2.0"')
        ->not->toContain('&gt;')
        ->not->toContain('&quot;');

    // Firewall sources keep their literal quotes.
    expect($hcl)->toContain('source_addresses = ["203.0.113.5/32"]')
        ->and($hcl)->toContain('port_range       = "6443"')
        ->and($hcl)->toContain('digitalocean/digitalocean');

    // Token is a variable, never a literal in the rendered HCL.
    expect($hcl)->toContain('variable "do_token"')
        ->and($hcl)->toContain('output "ip"');
});

test('do/vps template leaves API + SSH open when no admin CIDR', function (): void {
    $hcl = view('tofu.do.vps', [
        'region' => 'nyc1',
        'size' => 's-1vcpu-1gb',
        'dropletName' => 'larakube-open',
        'sshKeyName' => 'larakube-open',
        'sshPubKey' => 'ssh-ed25519 AAAA x',
        'keyFingerprint' => 'ab:cd:ef',
        'sshSources' => '"0.0.0.0/0", "::/0"',
        'apiSources' => '"0.0.0.0/0", "::/0"',
    ])->render();

    expect(substr_count($hcl, '"0.0.0.0/0", "::/0"'))->toBeGreaterThanOrEqual(4); // ssh + api + 80 + 443
});

test('do/managed template renders cluster, node pool and a sensitive kubeconfig output', function (): void {
    $hcl = view('tofu.do.managed', [
        'region' => 'sgp1',
        'clusterName' => 'larakube-acme',
        'size' => 's-2vcpu-4gb',
        'nodeCount' => 3,
        'versionPrefix' => '1.31.',
    ])->render();

    expect($hcl)->toContain('resource "digitalocean_kubernetes_cluster"')
        ->and($hcl)->toContain('node_count = 3')
        ->and($hcl)->toContain('version_prefix = "1.31."')
        ->and($hcl)->toContain('output "kubeconfig"')
        ->and($hcl)->toContain('sensitive = true')
        ->not->toContain('&gt;');
});

test('backend-s3 template renders an S3-compatible backend with per-stack key and native locking', function (): void {
    $hcl = view('tofu.backend-s3', [
        'bucket' => 'larakube-state',
        'endpoint' => 'https://nyc3.digitaloceanspaces.com',
        'region' => 'nyc3',
        'stack' => 'myapp-vps',
    ])->render();

    expect($hcl)->toContain('backend "s3"')
        ->and($hcl)->toContain('bucket = "larakube-state"')
        ->and($hcl)->toContain('key    = "tofu-state/myapp-vps/terraform.tfstate"')
        ->and($hcl)->toContain('s3 = "https://nyc3.digitaloceanspaces.com"')
        ->and($hcl)->toContain('use_lockfile = true')
        ->and($hcl)->toContain('skip_s3_checksum            = true')
        ->not->toContain('&quot;')
        ->not->toContain('&gt;');
});

test('do/vps template renders custom sshPort in firewall and user_data', function (): void {
    $hcl = view('tofu.do.vps', [
        'region' => 'sgp1',
        'size' => 's-1vcpu-1gb',
        'dropletName' => 'larakube-alt-port',
        'sshKeyName' => 'larakube-alt',
        'sshPubKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1 user@host',
        'keyFingerprint' => '3b:16:aa:cc',
        'sshSources' => '"0.0.0.0/0", "::/0"',
        'apiSources' => '"0.0.0.0/0", "::/0"',
        'sshPort' => 8443,
    ])->render();

    expect($hcl)->toContain('port_range       = "8443"')
        ->and($hcl)->toContain('user_data   = <<-EOF')
        ->and($hcl)->toContain('Port 8443');
});

test('aws/vps template renders custom sshPort in security group and user_data', function (): void {
    $hcl = view('tofu.aws.vps', [
        'region' => 'us-east-1',
        'size' => 't4g.small',
        'dropletName' => 'larakube-aws-alt',
        'sshKeyName' => 'larakube-aws-key',
        'sshPubKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1 user@host',
        'sshPort' => 8443,
    ])->render();

    expect($hcl)->toContain('from_port        = 8443')
        ->and($hcl)->toContain('to_port          = 8443')
        ->and($hcl)->toContain('Port 8443');
});

test('hetzner/vps template renders custom sshPort in firewall and user_data', function (): void {
    $hcl = view('tofu.hetzner.vps', [
        'region' => 'fsn1',
        'size' => 'cx22',
        'dropletName' => 'larakube-hetzner-alt',
        'sshKeyName' => 'larakube-hetzner-key',
        'sshPubKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1 user@host',
        'sshSources' => '"0.0.0.0/0", "::/0"',
        'apiSources' => '"0.0.0.0/0", "::/0"',
        'sshPort' => 2200,
    ])->render();

    expect($hcl)->toContain('port       = "2200"')
        ->and($hcl)->toContain('user_data    = <<-EOF')
        ->and($hcl)->toContain('Port 2200');
});

test('gcp/vps template renders custom sshPort in firewall and startup-script', function (): void {
    $hcl = view('tofu.gcp.vps', [
        'region' => 'us-central1',
        'size' => 'e2-medium',
        'dropletName' => 'larakube-gcp-alt',
        'sshPubKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1 user@host',
        'sshSources' => '"0.0.0.0/0"',
        'sshPort' => 8443,
    ])->render();

    expect($hcl)->toContain('ports    = ["8443"]')
        ->and($hcl)->toContain('"startup-script" = <<-EOF')
        ->and($hcl)->toContain('Port 8443');
});

test('GCP and AWS servers get a reserved public IP, so a resize that stops the VM keeps its address', function (): void {
    $gcp = view('tofu.gcp.vps', [
        'region' => 'us-central1', 'zone' => 'us-central1-a', 'dropletName' => 'larakube-vps-test', 'size' => 'e2-medium',
        'sshPubKey' => 'ssh-ed25519 AAAAC3Nz test', 'sshSources' => '"0.0.0.0/0"', 'apiSources' => '"0.0.0.0/0"',
    ])->render();
    $aws = view('tofu.aws.vps', [
        'region' => 'us-east-1', 'size' => 't4g.small', 'dropletName' => 'larakube-aws-alt', 'sshKeyName' => 'k', 'sshPubKey' => 'ssh-ed25519 AAAAC3Nz test',
    ])->render();

    expect($gcp)->toContain('resource "google_compute_address" "larakube"')
        ->and($gcp)->toContain('nat_ip = google_compute_address.larakube.address')
        ->and($gcp)->toContain('value = google_compute_address.larakube.address')
        ->and($aws)->toContain('resource "aws_eip" "larakube"')
        ->and($aws)->toContain('value = aws_eip.larakube.public_ip');
});

/** The heredoc body as OpenTofu hands it to the VM: `<<-EOF` strips the indentation every line shares. */
function tofuFirstBootScript(string $hcl): string
{
    preg_match('/<<-EOF\n(.*?)\n\s*EOF/s', $hcl, $match);
    $lines = explode("\n", $match[1] ?? '');
    $indent = min(array_map(fn (string $line): int => strlen($line) - strlen(ltrim($line, ' ')), array_filter($lines, fn (string $line): bool => trim($line) !== '')));

    return implode("\n", array_map(fn (string $line): string => substr($line, $indent), $lines));
}

test('a custom SSH port reaches sshd the way Ubuntu applies it under socket activation, on every provider', function (string $template, array $vars): void {
    $hcl = view($template, $vars + [
        'region' => 'r1', 'size' => 's1', 'zone' => 'r1-a', 'dropletName' => 'alt-port', 'sshKeyName' => 'alt-key',
        'sshPubKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1 user@host', 'keyFingerprint' => '3b:16',
        'sshSources' => '"0.0.0.0/0"', 'apiSources' => '"0.0.0.0/0"', 'sshPort' => 8443,
    ])->render();

    $script = tofuFirstBootScript($hcl);

    // A shebang the VM can run, and the port applied by daemon-reload + restart of ssh.socket (LP #2069041).
    expect(strtok($script, "\n"))->toBe('#!/bin/bash')
        ->and($script)->toContain('Port 8443')
        ->and($script)->toContain('systemctl daemon-reload')
        ->and($script)->toContain('systemctl restart ssh.socket')
        ->and($script)->not->toContain('disable --now ssh.socket');
})->with([
    'DigitalOcean' => ['tofu.do.vps', []],
    'Google Cloud' => ['tofu.gcp.vps', []],
    'AWS' => ['tofu.aws.vps', []],
    'Hetzner' => ['tofu.hetzner.vps', []],
]);
