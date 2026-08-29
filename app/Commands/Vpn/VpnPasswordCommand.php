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
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class VpnPasswordCommand extends Command
{
    use InteractsWithClusterContext, InteractsWithProjectConfig, InteractsWithVpn, LaraKubeOutput, ReadsClusterSecrets, RequiresFlagsWhenNonInteractive;

    protected $signature = 'vpn:password
        {environment=local : Environment whose NetBird VPN to target}
        {--email=    : Embedded IdP user to reset (defaults to the account vpn:init created)}
        {--password= : New password (auto-generated if omitted)}
        {--force     : Skip the confirmation prompt}
        {--context=  : Target a specific kube-context}';

    protected $description = 'Reset an embedded IdP user\'s password — the credential the NetBird dashboard logs in with';

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

        // Installs from before 2026-08-28 never stored admin-email, so there is
        // nothing to default to — say which flag fixes it rather than failing
        // on an empty --email.
        $email = (string) ($this->option('email') ?: $this->readClusterSecretKey($kubectl, $ns, $this->vpnName('vpn-management-secrets', $kubectl), 'admin-email'));

        if ($email === '') {
            throw new MissingFlagException('email', 'which embedded IdP user to reset', 'larakube vpn:password production --email=you@example.com');
        }

        if (! $this->option('force')) {
            $this->laraKubeNewLine();
            $this->line("  <fg=yellow>⚠ This immediately invalidates {$email}'s current dashboard password.</>");
            $this->line('  Anyone signed into the NetBird dashboard with it is logged out. Peers and the');
            $this->line('  CLI are unaffected — they authenticate with setup keys and the stored PAT.');
            $this->newLine();

            if (! confirm(label: "Reset the dashboard password for '{$email}'?", default: false)) {
                $this->laraKubeInfo('Password reset cancelled.');

                return 0;
            }
        }

        $password = (string) ($this->option('password') ?: Str::password(24));
        $this->registerSecret($password);

        // --password-file - reads stdin, deliberately NOT --password: the flag
        // form would put the new credential in the container's process list,
        // visible to anything that can read /proc inside that pod.
        $changed = $this->withSpin("Updating {$email}'s password...", fn () => Process::run(
            'printf %s '.escapeshellarg($password)
            ." | {$kubectl} exec -i deploy/".$this->vpnName('vpn-management', $kubectl)." -n {$ns} --"
            .' /go/bin/netbird-mgmt admin user change-password'
            .' --email '.escapeshellarg($email)
            .' --password-file -',
        )->successful());

        if (! $changed) {
            $this->laraKubeError('Could not change the password — check that the user exists in the embedded IdP.');
            $mgmt = $this->vpnName('vpn-management', $kubectl);
            $this->line("  <fg=gray>List them with</> <fg=blue>kubectl exec deploy/{$mgmt} -n {$ns} -- /go/bin/netbird-mgmt admin user --help</>");

            return 1;
        }

        // Merge-patch, never create --dry-run|apply: vpn-secrets also holds the
        // PAT and setup key, and a full recreate would drop them. Keeping the
        // stored copy in step is the whole point of this command — a hand-rolled
        // `netbird-mgmt admin user change-password` leaves it stale, and then
        // vpn:init prints a password that no longer works.
        $patched = $this->withSpin('Recording it in vpn-secrets...', fn () => Process::run(
            "{$kubectl} patch secret ".$this->vpnName('vpn-management-secrets', $kubectl)." -n {$ns} --type=merge -p "
            .escapeshellarg((string) json_encode(['data' => [
                'admin-email' => base64_encode($email),
                'admin-password' => base64_encode($password),
            ]], JSON_THROW_ON_ERROR)),
        )->successful());

        if (! $patched) {
            $this->laraKubeWarn('Password changed, but vpn-secrets could not be updated — the stored copy is now stale. Re-run this command once kubectl access is working.');
        }

        $host = $this->resolveVpnHostReadOnly($env, $config);

        $this->laraKubeNewLine();
        $this->laraKubeInfo("✅ Dashboard password updated for {$email}.");
        $this->newLine();
        if ($host !== null) {
            $this->line("  <fg=gray>Dashboard:</>       <fg=blue>https://{$host}</>");
        }
        $this->line("  <fg=gray>Login:</>           <fg=blue>{$email}</> / <fg=blue>{$password}</>");
        $this->newLine();

        return 0;
    }
}
