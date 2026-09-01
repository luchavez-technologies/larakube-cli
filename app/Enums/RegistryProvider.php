<?php

namespace App\Enums;

enum RegistryProvider: string
{
    public function label(): string
    {
        return match ($this) {
            self::GHCR => 'GitHub Container Registry (GHCR)',
            self::DOCKERHUB => 'Docker Hub',
            self::GITLAB => 'GitLab Container Registry',
            self::FORGEJO => 'Forgejo Container Registry',
        };
    }

    public function registryHost(): string
    {
        return match ($this) {
            self::GHCR => 'ghcr.io',
            self::DOCKERHUB => 'docker.io',
            self::GITLAB => 'registry.gitlab.com',
            self::FORGEJO => 'git.dev.test',
        };
    }

    public function defaultImagePath(string $githubRepo): string
    {
        return match ($this) {
            self::GHCR => $githubRepo,
            self::DOCKERHUB => $githubRepo,
            self::GITLAB => $githubRepo,
            self::FORGEJO => $githubRepo,
        };
    }

    public function isGitLabNative(): bool
    {
        return $this === self::GITLAB;
    }

    case GHCR = 'ghcr';
    case DOCKERHUB = 'dockerhub';
    case GITLAB = 'gitlab';
    case FORGEJO = 'forgejo';
}
