<?php

namespace App\Commands\Chat;

use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithChat;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

/**
 * Switch Chat's sign-in from classic SSO (Synapse's own oidc_providers) to the
 * Matrix Authentication Service, which Element X requires. Recorded, so later
 * `chat:init` runs keep it.
 */
class ChatUseMasCommand extends Command
{
    use DeploysClusterTool, InteractsWithChat, LaraKubeOutput, StreamsProcessOutput;

    protected $signature = 'chat:use-mas
                            {environment : The environment whose Chat to switch}
                            {--context= : Target a specific kube-context}
                            {--force : Skip the confirmation}';

    protected $description = 'Switch Chat\'s sign-in to the Matrix Authentication Service (needed by Element X)';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $kubectl = Kubectl::forContext($this->resolveToolContext($env, (string) ($this->option('context') ?: '') ?: null))->prefix();
        $ns = ClusterTool::CHAT->namespace();

        if ($this->readChatWiredMas($kubectl, $ns) === null) {
            $this->laraKubeError('MAS isn\'t deployed for Chat here. Run `larakube chat:init '.$env.'` first (it deploys MAS when Zitadel is installed).');

            return 1;
        }

        if ($this->readChatAuthMode($kubectl, $ns) === 'mas' && ! $this->option('force')) {
            $this->laraKubeInfo('Chat already signs in through MAS.');

            return 0;
        }

        $this->laraKubeWarn('Synapse will stop accepting classic SSO sessions; everyone signs in again through MAS.');
        if (! $this->option('force') && ! confirm('Switch Chat to MAS sign-in now?', true)) {
            return 0;
        }

        if (! $this->activateMasAuthMode($kubectl, $ns, null)) {
            $this->laraKubeError('Could not update Synapse\'s config; nothing changed.');

            return 1;
        }

        if (! $this->kubectlStep('Waiting for Synapse...', fn () => Kubectl::fromPrefix($kubectl)->rolloutStatus($ns, 'chat-synapse', 180))) {
            return 1;
        }
        $this->laraKubeInfo('✅ Chat signs in through MAS; chat:init keeps it that way.');

        return 0;
    }
}
