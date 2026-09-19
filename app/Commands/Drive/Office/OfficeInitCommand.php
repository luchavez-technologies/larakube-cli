<?php

namespace App\Commands\Drive\Office;

use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithIngressProxy;
use App\Traits\InteractsWithTraefik;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsClusterSecrets;
use App\Traits\ResolvesToolEnvironment;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * Layer document editing onto an already-working Drive.
 *
 * Two moving parts, deployed in this order:
 *
 *   1. Collabora Online (CODE) — the editor, its own Deployment/Service/Ingress
 *      on office.{drive host}.
 *   2. oCIS's own `collaboration` service — the WOPI bridge between the two.
 *      It is NOT deployed here: it ships in the same oCIS image and has to share
 *      drive-ocis's network namespace (see the sidecar comment in
 *      k8s/drive/ocis.blade.php), so `drive:init` owns rendering it and this
 *      command re-runs that command once CODE exists. That keeps one source of
 *      truth for the oCIS manifest and means a later plain `drive:init`
 *      preserves the office layer instead of silently stripping it.
 */
class OfficeInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithClusterContext, InteractsWithIngressProxy,
        InteractsWithTraefik, LaraKubeOutput, ReadsClusterSecrets, ResolvesToolEnvironment, ResolvesToolHost,
        StreamsProcessOutput;

    /**
     * Verified against Docker Hub on 2026-09-08 (multi-arch amd64+arm64).
     * Note the tag is NOT the version Collabora's release notes print: notes say
     * "CODE 26.04.3.2", the published image carries a build suffix.
     */
    public const CODE_IMAGE = 'collabora/code:26.04.3.2.1';

    protected $signature = 'drive:office:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=   : Target a specific kube-context}
        {--domain=    : Base domain OR full host of the Drive this attaches to (defaults to the installed one)}
        {--vpn-only   : Restrict access to the editor via NetBird VPN IP whitelisting}
        {--force      : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Add Collabora Online document editing to an existing Drive (oCIS)';

    public function handle(): int
    {
        $this->renderHeader();

        $env = $this->resolveToolEnvironment(ClusterTool::DRIVE);
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = 'larakube-shared';

        if (! $this->driveIsInstalled($kubectl, $ns)) {
            $this->laraKubeError('Drive is not installed on this cluster — run `larakube drive:init` first.');

            return 1;
        }

        $host = $this->resolveToolHost(SharedClusterService::DRIVE, ClusterTool::DRIVE, $env, $kubectl);
        $officeHost = "office.{$host}";
        $isLocal = $env === 'local';

        $adminPassword = $this->readClusterSecretKey($kubectl, $ns, 'drive-office-secrets', 'code-admin-password')
            ?? Str::random(24);

        // The WOPI bridge signs its own access tokens with this. Deliberately
        // NOT drive-secrets' jwt-secret: that is oCIS's internal service JWT, a
        // different trust domain despite the similar name. The service refuses
        // to start without it ("The WOPI secret has not been set properly").
        $wopiSecret = $this->readClusterSecretKey($kubectl, $ns, 'drive-office-secrets', 'wopi-secret')
            ?? Str::random(48);

        $this->withSpin('Syncing Collabora secrets...', fn () => Kubectl::fromPrefix($kubectl)->putSecret($ns, 'drive-office-secrets', ['code-admin-password' => $adminPassword, 'wopi-secret' => $wopiSecret]));

        // Issue the local cert BEFORE the ingress exists, so the very first
        // browser hit on office.{host} is already served a trusted certificate.
        if ($isLocal) {
            $this->ensureHostCertExists($officeHost);
            $this->applyTraefikCertResources('traefik');
        }

        $manifest = view('k8s.drive.code', [
            'host' => $host,
            'officeHost' => $officeHost,
            'codeImage' => self::CODE_IMAGE,
            'codeAdminPassword' => $adminPassword,
            'isLocal' => $isLocal,
            'vpnOnly' => (bool) $this->option('vpn-only'),
            'proxied' => $this->resolveProxied($isLocal),
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-drive-code.yaml');
        file_put_contents($tmp, $manifest);

        $this->withSpin('Applying Collabora Online (CODE) manifests...', fn () => $this->runStreaming("{$kubectl} apply -f {$tmp}"));
        $temporaryDirectory->delete();

        // CODE unpacks a full LibreOffice core on first boot, so its rollout is
        // minutes-slow on a cold image pull — well past the 120s every other
        // tool here waits.
        $this->withSpin('Waiting for Collabora Online (CODE)...', fn () => $this->runStreaming(
            "{$kubectl} rollout status deploy/drive-code -n {$ns} --timeout=600s",
            610,
        ));

        $this->laraKubeInfo('Re-running drive:init to add the WOPI bridge to drive-ocis...');
        $this->newLine();

        $driveArgs = ['environment' => $env, '--force' => true];
        if ($context !== null && $context !== '') {
            $driveArgs['--context'] = $context;
        }

        $exit = $this->call('drive:init', $driveArgs);
        if ($exit !== 0) {
            $this->laraKubeError('CODE is up, but drive:init failed to wire the WOPI bridge — see the output above.');

            return $exit;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Document editing is live.');
        $this->newLine();
        $this->line("  <fg=gray>Editor:</>            <fg=blue>https://{$officeHost}</>");
        $this->line("  <fg=gray>Drive:</>             <fg=blue>https://{$host}</>");
        $this->line("  <fg=gray>CODE admin console:</> <fg=blue>https://{$officeHost}/browser/dist/admin/admin.html</>");
        $this->line('  <fg=gray>CODE admin user:</>   <fg=blue>admin</>');
        $this->line("  <fg=gray>CODE admin pass:</>   <fg=blue>{$adminPassword}</>");
        $this->newLine();

        return 0;
    }

    protected function driveIsInstalled(string $kubectl, string $ns): bool
    {
        return trim(Process::run(
            "{$kubectl} get deployment drive-ocis -n {$ns} -o name --ignore-not-found",
        )->output()) !== '';
    }
}
