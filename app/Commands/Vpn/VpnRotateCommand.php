<?php

namespace App\Commands\Vpn;

use App\Http\Integrations\Netbird\NetbirdConnector;
use App\Http\Integrations\Netbird\Requests\CreatePersonalAccessTokenRequest;
use App\Http\Integrations\Netbird\Requests\CreateSetupKeyRequest;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithVpn;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsClusterSecrets;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class VpnRotateCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithProjectConfig, InteractsWithVpn, LaraKubeOutput, ReadsClusterSecrets;

    /** Days of life for the replacement credentials — matches vpn:init's own cap. */
    protected const LIFETIME_DAYS = 365;

    protected $signature = 'vpn:rotate
        {environment=local : Environment whose NetBird credentials to rotate}
        {--force    : Skip the confirmation prompt}
        {--context= : Target a specific kube-context}';

    protected $description = 'Mint a fresh NetBird PAT + setup key before the current ones expire';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $config = $this->getProjectConfig();
        $kubectl = $this->vpnKubectl($this->resolveVpnContext($env, $config));
        $ns = $this->vpnNamespace();

        if (! $this->isVpnInstalled($kubectl, $ns)) {
            $this->laraKubeError('NetBird is not installed. Run `larakube vpn:init` first.');

            return 1;
        }

        $host = $this->resolveVpnHostReadOnly($env, $config);
        $pat = $this->fetchVpnPat($kubectl, $ns);

        // The whole point of this command is that it can only run while the
        // CURRENT token is still valid — it mints its replacement with it. Once
        // the PAT has expired there is no API path back in, and recovery is the
        // dashboard or `netbird-mgmt admin` inside the pod.
        if ($host === null || $pat === null) {
            $this->laraKubeError('No NetBird host or PAT found — nothing to rotate from.');
            $this->line('  <fg=gray>If the PAT has already expired, mint a new one from the dashboard and store it with</> <fg=blue>larakube vpn:setup-key</><fg=gray>.</>');

            return 1;
        }

        if (! $this->option('force')) {
            $this->laraKubeNewLine();
            $this->line('  <fg=yellow>⚠ This replaces the stored PAT and setup key.</>');
            $this->line('  Already-enrolled peers keep working — a setup key only authorises NEW');
            $this->line('  enrolments — but anything holding a copy of the OLD key (a teammate mid-setup)');
            $this->line('  should be re-issued one.');
            $this->newLine();

            if (! confirm(label: 'Rotate the NetBird credentials?', default: false)) {
                $this->laraKubeInfo('Rotation cancelled.');

                return 0;
            }
        }

        $connector = NetbirdConnector::make($host, $pat);

        // Resolves the larakube-cli service user by name when NetBird does not
        // flag a service-user token as `is_current` — see vpnCurrentUserId().
        $userId = $this->vpnCurrentUserId($host, $pat);

        if ($userId === null) {
            $this->laraKubeError('Could not identify which user the stored PAT belongs to — it may already be expired or revoked.');
            $this->line('  <fg=gray>Recover by minting a PAT in the dashboard (Team → Users → your user → Access Tokens), then</>');
            $this->line('  <fg=blue>  larakube vpn:setup-key '.$env.' --pat=…</>');

            return 1;
        }

        $newPat = null;
        $this->withSpin('Minting a new PAT...', function () use (&$newPat, $connector, $userId): void {
            $response = $connector->send(CreatePersonalAccessTokenRequest::make(
                $userId,
                'larakube-'.now()->format('Ymd'),
                self::LIFETIME_DAYS,
            ));

            // The plaintext token is returned ONCE, on create. Every later read
            // is redacted, so a miss here is unrecoverable — bail rather than
            // half-rotate.
            $newPat = $response->failed() ? null : ($response->json('plain_token') ?? $response->json('personal_access_token.plain_token'));
        });

        if ($newPat === null) {
            $this->laraKubeError('Could not mint a replacement PAT — nothing was changed.');

            return 1;
        }
        $this->registerSecret($newPat);

        // Mint the setup key with the NEW PAT: that proves it works before the
        // old one is overwritten, rather than discovering it is broken later.
        $newKey = null;
        $this->withSpin('Minting a new setup key...', function () use (&$newKey, $host, $newPat): void {
            $response = NetbirdConnector::make($host, $newPat)
                ->send(CreateSetupKeyRequest::make('larakube', self::LIFETIME_DAYS * 86400, 0));

            $newKey = $response->failed() ? null : $response->json('key');
        });

        if ($newKey === null) {
            $this->laraKubeError('Minted a PAT but could not mint a setup key with it — the credentials Secret is unchanged, so the old credentials still work.');

            return 1;
        }
        $this->registerSecret($newKey);

        // The setup key is Secret-only; the PAT writes through to OpenBao first
        // when a KV sync owns it, or ESO would put the old value back within 60s.
        $patched = $this->withSpin('Storing the new credentials...', function () use ($kubectl, $ns, $newPat, $newKey, $env): bool {
            $ok = Process::run(
                "{$kubectl} patch secret ".$this->vpnName('vpn-management-secrets', $kubectl)." -n {$ns} --type=merge -p "
                .escapeshellarg((string) json_encode(['data' => [
                    'setup-key' => base64_encode($newKey),
                ]], JSON_THROW_ON_ERROR)),
            )->successful();

            return $this->persistVpnPat($kubectl, $newPat, $env) && $ok;
        });

        if (! $patched) {
            $this->laraKubeError('New credentials were minted but could not be stored in the credentials Secret.');
            $this->line('  <fg=gray>They exist in NetBird now, so store them before they are lost — the PAT is only ever shown once.</>');

            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ NetBird credentials rotated.');
        $this->newLine();
        $this->line('  <fg=gray>Both valid for</> <fg=blue>'.self::LIFETIME_DAYS.' days</> <fg=gray>— rotate again before</> <fg=blue>'.now()->addDays(self::LIFETIME_DAYS)->toDateString().'</><fg=gray>.</>');
        $this->line('  <fg=gray>The old PAT is still live in NetBird; revoke it from the dashboard once you have confirmed this one works.</>');
        $this->newLine();

        return 0;
    }
}
