<?php

/**
 * guessImageFromGitRemote() parses owner/repo from the git origin remote.
 * promptRegistry()/gatherEnvironmentData() drive interactive Prompts wizards
 * and are covered end-to-end via tests/Feature/EnvEditTest.php; this covers
 * the standalone Process-backed leaf.
 */

use App\Traits\GathersEnvironmentData;
use Illuminate\Support\Facades\Process;

function gitRemoteGuesser(): object
{
    return new class
    {
        use GathersEnvironmentData;

        public function guess(string $path): string
        {
            return $this->guessImageFromGitRemote($path);
        }
    };
}

test('guessImageFromGitRemote parses owner/repo from an SSH remote', function (): void {
    Process::fake(["git -C '/proj' remote get-url origin" => "git@github.com:acme/demo.git\n"]);

    expect(gitRemoteGuesser()->guess('/proj'))->toBe('acme/demo');
});

test('guessImageFromGitRemote parses owner/repo from an HTTPS remote', function (): void {
    Process::fake(["git -C '/proj' remote get-url origin" => "https://github.com/acme/demo\n"]);

    expect(gitRemoteGuesser()->guess('/proj'))->toBe('acme/demo');
});

test('guessImageFromGitRemote is empty when there is no origin remote', function (): void {
    Process::fake(["git -C '/proj' remote get-url origin" => Process::result(output: '', exitCode: 1)]);

    expect(gitRemoteGuesser()->guess('/proj'))->toBe('');
});
