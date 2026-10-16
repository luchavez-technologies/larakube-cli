<?php

namespace App\Services\Tools;

use App\Commands\Analytics\AnalyticsInitCommand;
use App\Commands\Bulwark\BulwarkInitCommand;
use App\Commands\Chat\ChatInitCommand;
use App\Commands\Chatwoot\ChatwootInitCommand;
use App\Commands\Data\DataInitCommand;
use App\Commands\Dns\DnsInitCommand;
use App\Commands\Documenso\DocumensoInitCommand;
use App\Commands\Drive\DriveInitCommand;
use App\Commands\Errors\ErrorsInitCommand;
use App\Commands\Flow\FlowInitCommand;
use App\Commands\Git\GitInitCommand;
use App\Commands\Grafana\GrafanaInitCommand;
use App\Commands\Headlamp\HeadlampInitCommand;
use App\Commands\Kuma\KumaInitCommand;
use App\Commands\Kutt\KuttInitCommand;
use App\Commands\Mail\MailInitCommand;
use App\Commands\Meet\MeetInitCommand;
use App\Commands\Metabase\MetabaseInitCommand;
use App\Commands\Outline\OutlineInitCommand;
use App\Commands\Penpot\PenpotInitCommand;
use App\Commands\Planka\PlankaInitCommand;
use App\Commands\Resume\ResumeInitCommand;
use App\Commands\Secrets\SecretsInitCommand;
use App\Commands\Sendrec\SendrecInitCommand;
use App\Commands\Sso\SsoInitCommand;
use App\Commands\Teable\TeableInitCommand;
use App\Commands\Tool\AbstractToolInitCommand;
use App\Commands\Twenty\TwentyInitCommand;
use App\Commands\Vaultwarden\VaultwardenInitCommand;
use App\Commands\Vpn\VpnInitCommand;
use App\Commands\Yopass\YopassInitCommand;
use App\Enums\ClusterTool;
use LogicException;

/**
 * Builds the command that deploys a Cluster Tool, from the tool alone. Tools
 * of one family share their deploy logic (Plausible and Umami are both an
 * analytics stack), so the family decides the class and the tool decides what
 * it is told: its options, its description, and for the engine families which
 * engine to deploy. Adding a tool is an enum case and an entry in family().
 */
final class ToolInitCommands
{
    public static function for(ClusterTool $tool): AbstractToolInitCommand
    {
        $base = self::family($tool);

        return match ($base) {
            AnalyticsInitCommand::class => new class($tool) extends AnalyticsInitCommand {},
            ChatInitCommand::class => new class($tool) extends ChatInitCommand {},
            HeadlampInitCommand::class => new class($tool) extends HeadlampInitCommand {},
            DataInitCommand::class => new class($tool) extends DataInitCommand
            {
                protected function resolveEngine(): string
                {
                    return $this->tool()->value;
                }
            },
            PenpotInitCommand::class => new class($tool) extends PenpotInitCommand {},
            DnsInitCommand::class => new class($tool) extends DnsInitCommand {},
            DocumensoInitCommand::class => new class($tool) extends DocumensoInitCommand {},
            DriveInitCommand::class => new class($tool) extends DriveInitCommand
            {
                protected function resolveEngine(): string
                {
                    return $this->tool()->value;
                }
            },
            ErrorsInitCommand::class => new class($tool) extends ErrorsInitCommand {},
            FlowInitCommand::class => new class($tool) extends FlowInitCommand
            {
                protected function resolveEngine(): string
                {
                    return $this->tool()->value;
                }
            },
            GitInitCommand::class => new class($tool) extends GitInitCommand {},
            ChatwootInitCommand::class => new class($tool) extends ChatwootInitCommand {},
            KuttInitCommand::class => new class($tool) extends KuttInitCommand {},
            MailInitCommand::class => new class($tool) extends MailInitCommand {},
            MeetInitCommand::class => new class($tool) extends MeetInitCommand {},
            MetabaseInitCommand::class => new class($tool) extends MetabaseInitCommand {},
            GrafanaInitCommand::class => new class($tool) extends GrafanaInitCommand {},
            OutlineInitCommand::class => new class($tool) extends OutlineInitCommand {},
            VaultwardenInitCommand::class => new class($tool) extends VaultwardenInitCommand {},
            YopassInitCommand::class => new class($tool) extends YopassInitCommand {},
            PlankaInitCommand::class => new class($tool) extends PlankaInitCommand {},
            SendrecInitCommand::class => new class($tool) extends SendrecInitCommand {},
            ResumeInitCommand::class => new class($tool) extends ResumeInitCommand {},
            SecretsInitCommand::class => new class($tool) extends SecretsInitCommand {},
            TeableInitCommand::class => new class($tool) extends TeableInitCommand {},
            SsoInitCommand::class => new class($tool) extends SsoInitCommand {},
            TwentyInitCommand::class => new class($tool) extends TwentyInitCommand {},
            KumaInitCommand::class => new class($tool) extends KumaInitCommand {},
            VpnInitCommand::class => new class($tool) extends VpnInitCommand {},
            BulwarkInitCommand::class => new class($tool) extends BulwarkInitCommand {},
            default => throw new LogicException("No init command is registered for {$base}."),
        };
    }

    /**
     * @return class-string<AbstractToolInitCommand>
     */
    public static function family(ClusterTool $tool): string
    {
        return match ($tool->canonicalTool()) {
            ClusterTool::BULWARK => BulwarkInitCommand::class,
            ClusterTool::CHATWOOT => ChatwootInitCommand::class,
            ClusterTool::DIRECTUS => DataInitCommand::class,
            ClusterTool::DOCUMENSO => DocumensoInitCommand::class,
            ClusterTool::EXTERNAL_DNS => DnsInitCommand::class,
            ClusterTool::FORGEJO => GitInitCommand::class,
            ClusterTool::GLITCHTIP => ErrorsInitCommand::class,
            ClusterTool::GRAFANA => GrafanaInitCommand::class,
            ClusterTool::HEADLAMP => HeadlampInitCommand::class,
            ClusterTool::KUMA => KumaInitCommand::class,
            ClusterTool::KUTT => KuttInitCommand::class,
            ClusterTool::LIVEKIT => MeetInitCommand::class,
            ClusterTool::MATRIX => ChatInitCommand::class,
            ClusterTool::METABASE => MetabaseInitCommand::class,
            ClusterTool::N8N => FlowInitCommand::class,
            ClusterTool::NETBIRD => VpnInitCommand::class,
            ClusterTool::OCIS => DriveInitCommand::class,
            ClusterTool::OPENBAO => SecretsInitCommand::class,
            ClusterTool::OUTLINE => OutlineInitCommand::class,
            ClusterTool::PENPOT => PenpotInitCommand::class,
            ClusterTool::PLANKA => PlankaInitCommand::class,
            ClusterTool::PLAUSIBLE => AnalyticsInitCommand::class,
            ClusterTool::POCKETBASE => DataInitCommand::class,
            ClusterTool::WORDPRESS => DataInitCommand::class,
            ClusterTool::RESUME => ResumeInitCommand::class,
            ClusterTool::SENDREC => SendrecInitCommand::class,
            ClusterTool::STALWART => MailInitCommand::class,
            ClusterTool::TEABLE => TeableInitCommand::class,
            ClusterTool::TWENTY => TwentyInitCommand::class,
            ClusterTool::UMAMI => AnalyticsInitCommand::class,
            ClusterTool::VAULTWARDEN => VaultwardenInitCommand::class,
            ClusterTool::WINDMILL => FlowInitCommand::class,
            ClusterTool::YOPASS => YopassInitCommand::class,
            ClusterTool::ZITADEL => SsoInitCommand::class,
            default => throw new LogicException("{$tool->value} has no init command family."),
        };
    }

    /**
     * Whether `tool:init` can deploy the tool through this registry.
     */
    public static function has(ClusterTool $tool): bool
    {
        try {
            self::family($tool);

            return true;
        } catch (LogicException) {
            return false;
        }
    }
}
