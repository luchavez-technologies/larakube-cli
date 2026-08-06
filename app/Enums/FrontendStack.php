<?php

namespace App\Enums;

use App\Contracts\HasCommandOptions;
use App\Contracts\HasKubernetesFiles;
use App\Contracts\HasLabel;
use App\Contracts\HasPodName;
use App\Contracts\HasSelectOptions;
use App\Data\ConfigData;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum FrontendStack: string implements HasCommandOptions, HasKubernetesFiles, HasLabel, HasPodName, HasSelectOptions
{
    use ProvidesCommandOptions, ProvidesSelectOptions;

    public function getPodName(?ConfigData $config = null): string
    {
        return 'node';
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::REACT => 'React',
            self::VUE => 'Vue',
            self::SVELTE => 'Svelte',
            self::LIVEWIRE => 'Livewire',
        };
    }

    public static function getCommandOptionArrays(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[] = [
                'name' => $case->value,
                'description' => "Use {$case->getLabel()} frontend",
            ];
        }

        return $options;
    }

    public function echoPackage(): ?string
    {
        return match ($this) {
            self::REACT => '@laravel/echo-react',
            self::VUE => '@laravel/echo-vue',
            default => null,
        };
    }

    public function getOptionFlag(): string
    {
        return "--$this->value";
    }

    public function requiresNodePod(): bool
    {
        return match ($this) {
            self::LIVEWIRE => false,
            default => true,
        };
    }

    public function updateK8s(ConfigData $config): void
    {
        if (! $this->requiresNodePod()) {
            return;
        }

        $k8sPath = $config->getK8sPath();
        $viewName = $this->getWorkloadViewName();

        if ($viewName) {
            $content = view($viewName, ['config' => $config, 'stack' => $this])->render();
            file_put_contents("$k8sPath/{$this->getWorkloadYamlDestination()}", $content);
        }

        $networkView = $this->getNetworkViewName();
        if ($networkView) {
            $content = view($networkView, ['config' => $config, 'stack' => $this])->render();
            file_put_contents("$k8sPath/{$this->getNetworkYamlDestination()}", $content);
        }
    }

    public function getWorkloadViewName(): ?string
    {
        return $this->requiresNodePod() ? 'k8s.node.deployment' : null;
    }

    public function getWorkloadYamlDestination(): ?string
    {
        return $this->requiresNodePod() ? 'base/node-deployment.yaml' : null;
    }

    public function getNetworkViewName(): ?string
    {
        return $this->requiresNodePod() ? 'k8s.node.ingress' : null;
    }

    public function getNetworkYamlDestination(): ?string
    {
        return $this->requiresNodePod() ? 'base/node-ingress.yaml' : null;
    }

    public function getStorageViewName(): ?string
    {
        return null;
    }

    public function getStorageYamlDestination(): ?string
    {
        return null;
    }

    public function getPatchViewName(): ?string
    {
        return null;
    }

    public function getPatchYamlDestination(): ?string
    {
        return null;
    }

    public function getK8sDeploymentArgs(): string
    {
        return '[]';
    }

    public function getManifestFiles(?ConfigData $config = null): array
    {
        if (! $this->requiresNodePod()) {
            return [];
        }

        return [
            'base' => ['node-deployment.yaml', 'node-ingress.yaml'],
        ];
    }

    case REACT = 'react';
    case VUE = 'vue';
    case SVELTE = 'svelte';
    case LIVEWIRE = 'livewire';
}
