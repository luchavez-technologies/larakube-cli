<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\Services\ReleaseVersionCalculator;

// 1. Parse CLI options
$options = getopt('', ['from::', 'to::', 'repo::', 'json']);
$fromTag = $options['from'] ?? null;
$toRef = $options['to'] ?? 'HEAD';
$repo = $options['repo'] ?? (getenv('GITHUB_REPOSITORY') ?: getenv('FORGEJO_REPOSITORY') ?: 'luchaveztech/larakube-cli');
$asJson = isset($options['json']);

// 2. Discover base tag if not provided
if (! $fromTag) {
    exec('git tag -l "v*" --sort=-v:refname', $tagOutput, $tagExit);
    if ($tagExit === 0 && ! empty($tagOutput)) {
        foreach ($tagOutput as $candidate) {
            $candidate = trim($candidate);
            if (preg_match('/^v\d+\.\d+\.\d+$/', $candidate)) {
                $fromTag = $candidate;
                break;
            }
        }
    }
}

if (! $fromTag) {
    $fromTag = 'v0.0.0';
}

// 3. Extract commits between $fromTag and $toRef
$commitRange = ($fromTag !== 'v0.0.0') ? "{$fromTag}..{$toRef}" : $toRef;
$delimiter = '---COMMIT-DELIMITER-'.bin2hex(random_bytes(8)).'---';

$gitLogCmd = sprintf(
    'git log %s --pretty=format:%%H%%x1f%%s%%x1f%%b%s',
    escapeshellarg($commitRange),
    escapeshellarg($delimiter),
);

exec($gitLogCmd, $logOutput, $logExit);
$rawLog = implode("\n", $logOutput);

$commits = [];
if (! empty(trim($rawLog))) {
    $rawCommits = explode($delimiter, $rawLog);
    foreach ($rawCommits as $raw) {
        $raw = trim($raw);
        if ($raw === '') {
            continue;
        }

        $parts = explode("\x1f", $raw, 3);
        $commits[] = [
            'hash' => $parts[0] ?? '',
            'subject' => $parts[1] ?? '',
            'body' => $parts[2] ?? '',
        ];
    }
}

// 4. Calculate next version and release notes
$calculator = new ReleaseVersionCalculator;
$result = $calculator->calculate((string) $fromTag, $commits, (string) $repo);

// 5. Output for GitHub/Forgejo Actions ($GITHUB_OUTPUT)
$githubOutput = getenv('GITHUB_OUTPUT');
if ($githubOutput && file_exists($githubOutput)) {
    $out = sprintf(
        "should_release=%s\nversion=%s\nbump_type=%s\n",
        $result['should_release'] ? 'true' : 'false',
        $result['next_version'] ?? '',
        $result['bump_type'] ?? '',
    );

    // Multiline output for release notes
    $eof = 'EOF_'.bin2hex(random_bytes(8));
    $out .= "release_notes<<{$eof}\n".$result['release_notes']."\n{$eof}\n";

    file_put_contents($githubOutput, $out, FILE_APPEND);
}

// 6. Console output
if ($asJson) {
    echo json_encode($result, JSON_PRETTY_PRINT)."\n";
} else {
    echo "Current Version: {$result['current_version']}\n";
    if ($result['should_release']) {
        echo "Next Version:    {$result['next_version']} ({$result['bump_type']})\n\n";
        echo "Release Notes:\n";
        echo "----------------------------------------\n";
        echo $result['release_notes']."\n";
        echo "----------------------------------------\n";
    } else {
        echo 'No release needed: '.($result['reason'] ?? 'No changes')."\n";
    }
}

exit(0);
