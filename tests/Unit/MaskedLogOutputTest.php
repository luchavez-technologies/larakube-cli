<?php

use App\Support\Console\MaskedLogOutput;
use Symfony\Component\Console\Output\BufferedOutput;

function maskedLogOutputRun(array $lines): array
{
    $log = new BufferedOutput;
    $output = new MaskedLogOutput($log);
    foreach ($lines as $line) {
        $output->writeln($line);
    }

    return [$log->fetch(), $output->fetch()];
}

test('a credential an install prints never reaches the Job log or the stored error', function (string $line, string $secret): void {
    [$log, $captured] = maskedLogOutputRun(['  ▸ Deploying', $line, '  Done']);

    expect($log)->not->toContain($secret)->toContain('••••••')->toContain('Deploying')->toContain('Done')
        ->and($captured)->not->toContain($secret);
})->with([
    'password label' => ['  <fg=gray>Admin Password:</>  <fg=yellow>Sup3rS3cret!</>', 'Sup3rS3cret!'],
    'login pair' => ['  <fg=gray>Admin login:</>  <fg=blue>admin@example.com</> / <fg=blue>Pw-123456</>', 'Pw-123456'],
    'admin slash' => ['  <fg=gray>Grafana:</>  <fg=blue>https://grafana.example.com</>  <fg=gray>admin / Gr4f4na</>', 'Gr4f4na'],
    'token' => ['  API token: tok_abcdef123', 'tok_abcdef123'],
]);

test('a value printed alone after the line that introduces it is masked', function (): void {
    [$log] = maskedLogOutputRun(['  <fg=gray>Or use the password directly:</>', '  <fg=yellow>Lone-Secret-9</>', '  Username · database tenant']);

    expect($log)->not->toContain('Lone-Secret-9')->toContain('Username · database tenant');
});

test('ordinary progress lines pass through unchanged', function (): void {
    [$log] = maskedLogOutputRun(['  ▸ Setting up the shared Commons (postgres)', 'deployment "postgres" successfully rolled out']);

    expect($log)->toBe("  ▸ Setting up the shared Commons (postgres)\ndeployment \"postgres\" successfully rolled out\n");
});
