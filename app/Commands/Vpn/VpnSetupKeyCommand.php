<?php

namespace App\Commands\Vpn;

use App\Exceptions\MissingFlagException;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithVpn;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsClusterSecrets;
use App\Traits\RequiresFlagsWhenNonInteractive;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;

use LaravelZero\Framework\Commands\Command;

class VpnSetupKeyCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithProjectConfig, InteractsWithVpn, LaraKubeOutput, ReadsClusterSecrets, RequiresFlagsWhenNonInteractive;

    protected $signature = 'vpn:setup-key
        {environment=local : Environment whose NetBird VPN to target}
        {--key=          : The setup key to store (prompted when neither credential is given)}
        {--pat=          : Personal Access Token to store — points the CLI at the same account}
        {--no-reenroll   : Store the key only — leave the in-cluster gateway on its current identity}
        {--force         : Skip the confirmation prompt}
        {--context=      : Target a specific kube-context}';

    protected $description = "Adopt an account's NetBird credentials — store its setup key/PAT and re-enrol the gateway";

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

        $key = (string) $this->option('key');
        $newPat = (string) $this->option('pat');

        // One concern, two halves: both answer "which NetBird account does this
        // cluster belong to". The setup key re-homes the gateway; the PAT
        // re-points every CLI operation (vpn:grant, vpn:users, sso:wire vpn).
        // Adopting only one leaves the two pointing at different accounts.
        if ($key === '' && $newPat === '') {
            if ($this->cannotPrompt()) {
                throw new MissingFlagException('key', 'the setup key to store', 'larakube vpn:setup-key production --key=…');
            }

            // password() not text(): these are credentials, and text() echoes
            // them into terminal scrollback and shell history.
            $key = (string) password(label: 'Setup key', required: true);
            $newPat = (string) password(label: 'Personal Access Token (leave empty to keep the current one)');
        }

        if ($key !== '') {
            $this->registerSecret($key);
        }
        if ($newPat !== '') {
            $this->registerSecret($newPat);
        }

        // Nothing to re-enrol with when only the PAT is being adopted.
        $reenroll = $key !== '' && ! $this->option('no-reenroll');

        if ($reenroll && ! $this->option('force')) {
            $this->laraKubeNewLine();
            $this->line('  <fg=yellow>⚠ The in-cluster gateway will re-enrol with this key.</>');
            $this->line('  It joins whichever account issued the key and gets a NEW NetBird IP, so its');
            $this->line('  old peer entry goes stale and anything pinned to the old address — a hosts');
            $this->line('  file entry, a DNS nameserver group — needs updating.');
            $this->newLine();

            if (! confirm(label: 'Re-enrol the gateway now?', default: false)) {
                $this->laraKubeInfo('Cancelled — nothing was changed.');

                return 0;
            }
        }

        // Merge-patch: the credentials Secret also holds the admin credentials and whichever
        // of these two is not being replaced — a create --dry-run|apply drops them.
        // The PAT goes through persistVpnPat() so it reaches OpenBao when a KV
        // sync owns that key; patching the Secret alone would be reverted in 60s.
        $stored = $this->withSpin('Storing credentials...', function () use ($kubectl, $ns, $key, $newPat, $env): bool {
            $ok = true;

            if ($key !== '') {
                $ok = Process::run(
                    "{$kubectl} patch secret ".$this->vpnName('vpn-management-secrets', $kubectl)." -n {$ns} --type=merge -p "
                    .escapeshellarg((string) json_encode(['data' => ['setup-key' => base64_encode($key)]], JSON_THROW_ON_ERROR)),
                )->successful();
            }

            if ($newPat !== '') {
                $ok = $this->persistVpnPat($kubectl, $newPat, $env) && $ok;
            }

            return $ok;
        });

        if (! $stored) {
            $this->laraKubeError('Could not write into vpn-secrets — check kubectl access and re-run.');

            return 1;
        }

        if (! $reenroll) {
            $this->laraKubeNewLine();
            $this->laraKubeInfo('✅ Credentials stored. The gateway keeps its current identity.');
            $this->newLine();

            return 0;
        }

        // Swapping NB_SETUP_KEY alone does nothing: the daemon finds its existing
        // config.json on the vpn-client-storage PVC and keeps the identity it
        // already has. Removing that file is what makes it enrol afresh.
        $cleared = $this->withSpin('Clearing the gateway identity...', fn () => Process::run(
            "{$kubectl} exec deploy/".$this->vpnName('vpn-client', $kubectl)." -n {$ns} -c client -- rm -f /etc/netbird/config.json",
        )->successful());

        if (! $cleared) {
            $this->laraKubeError('Stored the key, but could not clear the gateway identity — it would restart onto its OLD account.');
            $this->line('  <fg=gray>Re-run once the pod is reachable; nothing has changed on the gateway yet.</>');

            return 1;
        }

        $restarted = $this->withSpin('Re-enrolling the gateway...', function () use ($kubectl, $ns) {
            Process::run("{$kubectl} rollout restart deployment/".$this->vpnName('vpn-client', $kubectl)." -n {$ns}");

            return Process::timeout(180)->run("{$kubectl} rollout status deployment/".$this->vpnName('vpn-client', $kubectl)." -n {$ns} --timeout=170s")->successful();
        });

        if (! $restarted) {
            $this->laraKubeError('The VPN gateway did not become Ready — check `kubectl get pods -n '.$ns.'`.');

            return 1;
        }

        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Gateway re-enrolled with the new setup key.');
        $this->newLine();
        $this->line('  <fg=gray>Confirm its new address in the dashboard under Peers, or with</> <fg=blue>larakube vpn:users '.$env.'</><fg=gray>.</>');
        $this->line('  <fg=gray>The address changes on re-enrolment — update anything pinned to the old one.</>');

        if ($newPat === '') {
            // The classic half-adopted state: the gateway moves accounts while
            // every CLI call keeps talking to the old one, silently.
            $this->newLine();
            $this->line('  <fg=yellow>⚠ The stored PAT was not changed.</> <fg=gray>If this key came from a different account,</>');
            $this->line('  <fg=gray>  vpn:grant/vpn:users/sso:wire still target the OLD one. Adopt its token too:</>');
            $this->line('  <fg=blue>  larakube vpn:setup-key '.$env.' --pat=…</> <fg=gray>(Dashboard → Team → Users → your user → Access Tokens)</>');
        }

        $this->newLine();

        return 0;
    }
}
