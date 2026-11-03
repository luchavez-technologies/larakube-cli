<?php

namespace App\Commands\Mail;

use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithMail;
use App\Traits\InteractsWithSso;
use App\Traits\InteractsWithToolRegistry;
use App\Traits\InteractsWithZitadelApi;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolEngine;
use App\Traits\ResolvesToolEnvironment;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Read-only summary of which tools are wired to Stalwart as their outbound SMTP relay.
 * Backs Desktop's Mail section and CLI operator reporting.
 */
class MailStatusCommand extends Command
{
    use DeploysClusterTool,
        EmitsJsonOutput,
        InteractsWithMail,
        InteractsWithSso,
        InteractsWithToolRegistry,
        InteractsWithZitadelApi,
        LaraKubeOutput,
        ResolvesToolEngine,
        ResolvesToolEnvironment;

    protected $signature = 'mail:status
        {environment=local : Environment to inspect}
        {--context= : Target a specific kube-context}
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Report which cluster tools are wired to Stalwart mail for outbound SMTP';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        if ($json) {
            $this->enableJsonMode();
        } else {
            $this->renderHeader();
        }

        $env = $this->resolveToolEnvironment(ClusterTool::STALWART);
        $context = $this->resolveToolContext($env, $this->option('context'));
        $kubectl = Kubectl::forContext($context)->prefix();
        $mailNs = $this->mailNamespace();

        $installed = $this->isMailInstalled($kubectl, $mailNs);

        if (! $installed) {
            if ($json) {
                $this->jsonOutput([
                    'success' => true,
                    'installed' => false,
                ]);

                return 0;
            }

            $this->laraKubeInfo('Stalwart is not deployed. Run `larakube tool:init --tool=stalwart` first.');

            return 0;
        }

        $wiredTools = $this->wiredToolsSummary($kubectl, $env);

        $wiredToolsOutput = array_map(fn (array $row) => [
            'tool' => $row['tool'],
            'slug' => $row['slug'],
            'instance' => (string) $row['instance'],
            'host' => $row['host'],
            'wired' => true,
        ], $wiredTools);

        $payload = [
            'success' => true,
            'installed' => true,
            'wiredTools' => $wiredToolsOutput,
        ];

        if ($json) {
            $this->jsonOutput($payload);

            return 0;
        }

        $this->laraKubeInfo('Stalwart mail is deployed.');

        if ($wiredToolsOutput !== []) {
            table(
                headers: ['Tool', 'Instance', 'Host', 'Wired'],
                rows: array_map(fn (array $row) => [
                    $row['tool'],
                    $row['instance'] !== '' ? $row['instance'] : '—',
                    $row['host'] ?? '—',
                    $row['wired'] ? 'yes' : 'no',
                ], $wiredToolsOutput),
            );
        }

        return 0;
    }

    /**
     * Report which tools are currently wired to Stalwart as outbound SMTP relay.
     *
     * Note: This mirrors sso:status's wired-tools shape and the "capable AND installed
     * AND secret present" three-part check, so a future per-tool wiring report in this
     * codebase reuses the same pattern instead of inventing a fourth.
     *
     * @return list<array{tool: string, slug: string, instance: string, host: string|null, wired: bool}>
     */
    protected function wiredToolsSummary(string $kubectl, string $env): array
    {
        $rows = [];
        foreach (ClusterTool::shippedCases() as $tool) {
            if (! $tool->hasMailWire()) {
                continue;
            }

            if (! $this->isToolInstalledForMail($kubectl, $tool)) {
                continue;
            }

            $host = $this->getToolHost($kubectl, $tool);
            $instance = $host !== null ? $this->resolveInstanceForDomain($kubectl, $tool, $host) : '';

            // Zitadel configures SMTP via its own Admin API rather than a
            // Kubernetes Deployment Secret (see MailWireCommand::wireTool()).
            if ($tool === ClusterTool::ZITADEL) {
                $pat = $this->readSsoSecret($kubectl, $this->ssoNamespace(), 'machine-pat');
                if ($pat === null) {
                    continue;
                }

                $ssoHost = $this->resolveSsoHostReadOnly($env, null, $kubectl);
                if ($ssoHost === null) {
                    continue;
                }

                if (! $this->zitadelHasSmtpProvider($ssoHost, $pat)) {
                    continue;
                }

                $rows[] = [
                    'tool' => $tool->getLabel(),
                    'slug' => $tool->value,
                    'instance' => $instance,
                    'host' => ($host !== null && $host !== '') ? $host : null,
                    'wired' => true,
                ];

                continue;
            }

            $engine = $tool->engines() !== [] ? $this->resolveInstanceEngine($kubectl, $tool, $instance, null) : null;
            $schema = $tool->smtpEnv($engine, $instance);
            if ($schema === null) {
                continue;
            }

            $secretName = $schema['secret'];
            $ns = $schema['namespace'];
            $knownKey = (string) (reset($schema['vars']) ?: array_key_first($schema['static'] ?? ['host' => 'host']));

            if ($this->readClusterSecretKey($kubectl, $ns, $secretName, $knownKey) === null) {
                continue;
            }

            $rows[] = [
                'tool' => $tool->getLabel(),
                'slug' => $tool->value,
                'instance' => $instance,
                'host' => ($host !== null && $host !== '') ? $host : null,
                'wired' => true,
            ];
        }

        return $rows;
    }
}
