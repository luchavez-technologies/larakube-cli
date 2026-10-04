<?php

namespace App\Services\Workspace;

use App\Enums\AppFramework;
use App\Enums\WorkspaceRuntime;
use InvalidArgumentException;

/**
 * What a workspace is made of, in one place: the sizes it can have, the image it
 * runs, its names, and the manifest. Commands and GUIs read this and own no copy.
 */
class WorkspaceSpec
{
    /** code-server release the image installs. */
    public const CODE_SERVER_VERSION = '4.140.0';

    /** The password and deploy key live in this Secret in the workspace's namespace. */
    public const SECRET = 'workspace';

    /**
     * Memory is the hard limit; the request is what the scheduler reserves.
     * Figures come from the measured spike: 1 GB for a small app, 2 to 2.5 GB for a real one.
     *
     * @return array<string, array{label: string, memory: string, requestMemory: string, cpu: string, storage: string}>
     */
    public static function sizes(): array
    {
        return [
            'small' => ['label' => 'Small: 2 GB RAM, 1 CPU (a small app)', 'memory' => '2Gi', 'requestMemory' => '768Mi', 'cpu' => '1', 'storage' => '10Gi'],
            'standard' => ['label' => 'Standard: 4 GB RAM, 2 CPU (a real app)', 'memory' => '4Gi', 'requestMemory' => '1536Mi', 'cpu' => '2', 'storage' => '20Gi'],
        ];
    }

    public static function defaultSize(): string
    {
        return 'standard';
    }

    public static function namespaceFor(string $name): string
    {
        return 'ws-'.$name;
    }

    public static function image(WorkspaceRuntime $runtime, string $version): string
    {
        return "larakube/workspace:{$runtime->value}{$version}-cs".self::CODE_SERVER_VERSION;
    }

    /** The image and its build base both come from the runtime; an unknown version is refused. */
    public static function validVersion(WorkspaceRuntime $runtime, string $version): bool
    {
        return in_array($version, $runtime->versions(), true);
    }

    /** Lowercase letters, digits and dashes; short enough for a namespace and a host label. */
    public static function validName(string $name): bool
    {
        return preg_match('/^[a-z0-9]([a-z0-9-]{0,28}[a-z0-9])?$/', $name) === 1;
    }

    public static function validRepo(string $repo): bool
    {
        return preg_match('#^(https://[A-Za-z0-9.-]+/[\w.\-/]+|git@[A-Za-z0-9.-]+:[\w.\-/]+|ssh://[\w.-]+@[A-Za-z0-9.-]+(:\d{1,5})?/[\w.\-/]+)$#', $repo) === 1;
    }

    /** The SSH port a repository URL asks for when it is not one the policy already allows, such as a self-hosted Forgejo on 2222. */
    public static function gitPort(string $repo): ?int
    {
        if (preg_match('#^ssh://[^@/]+@[^:/]+:(\d{1,5})/#', $repo, $match) !== 1) {
            return null;
        }

        return in_array((int) $match[1], [22, 80, 443], true) ? null : (int) $match[1];
    }

    public static function validBranch(string $branch): bool
    {
        return preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$#', $branch) === 1 && ! str_contains($branch, '..');
    }

    public function dockerfile(WorkspaceRuntime $runtime, string $version): string
    {
        return view('workspace.dockerfile', [
            'base' => $runtime->baseImage($version),
            'codeServerVersion' => self::CODE_SERVER_VERSION,
            'installNode' => $runtime->needsNode(),
            'phpExtensions' => $runtime->phpExtensions(),
        ])->render();
    }

    /**
     * @param  array{name: string, repo: string, branch: string, size: string, gitName: string, gitEmail: string, framework: AppFramework, runtime: WorkspaceRuntime, runtimeVersion: string, replicas?: int}  $workspace
     */
    public function manifest(array $workspace): string
    {
        $sizes = self::sizes();

        if (! isset($sizes[$workspace['size']])) {
            throw new InvalidArgumentException("Unknown workspace size '{$workspace['size']}'.");
        }

        return view('k8s.workspace.workspace', [
            'name' => $workspace['name'],
            'namespace' => self::namespaceFor($workspace['name']),
            'repo' => $workspace['repo'],
            'branch' => $workspace['branch'],
            'size' => $workspace['size'],
            'gitName' => $workspace['gitName'],
            'gitEmail' => $workspace['gitEmail'],
            'replicas' => $workspace['replicas'] ?? 1,
            'gitPort' => self::gitPort($workspace['repo']),
            'framework' => $workspace['framework']->value,
            'runtime' => $workspace['runtime']->value,
            'runtimeVersion' => $workspace['runtimeVersion'],
            'devPorts' => array_column($workspace['framework']->devPorts(), 'port'),
            'image' => self::image($workspace['runtime'], $workspace['runtimeVersion']),
            ...$sizes[$workspace['size']],
        ])->render();
    }
}
