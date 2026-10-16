<?php

namespace App\Services\Tools;

use App\Commands\Analytics\AnalyticsInitCommand;
use App\Commands\Chat\ChatInitCommand;
use App\Commands\Chatwoot\ChatwootInitCommand;
use App\Commands\Dashboard\DashboardInitCommand;
use App\Commands\Data\DataInitCommand;
use App\Commands\Design\DesignInitCommand;
use App\Commands\Dns\DnsInitCommand;
use App\Commands\Documenso\DocumensoInitCommand;
use App\Commands\Drive\DriveInitCommand;
use App\Commands\Errors\ErrorsInitCommand;
use App\Commands\Flow\FlowInitCommand;
use App\Commands\Git\GitInitCommand;
use App\Commands\Kutt\KuttInitCommand;
use App\Commands\Mail\MailInitCommand;
use App\Commands\Meet\MeetInitCommand;
use App\Commands\Metabase\MetabaseInitCommand;
use App\Commands\Monitor\MonitorInitCommand;
use App\Commands\Notes\NotesInitCommand;
use App\Commands\Password\PasswordsInitCommand;
use App\Commands\Paste\PasteInitCommand;
use App\Commands\Planka\PlankaInitCommand;
use App\Commands\Record\RecordInitCommand;
use App\Commands\Resume\ResumeInitCommand;
use App\Commands\Secrets\SecretsInitCommand;
use App\Commands\Sheet\SheetsInitCommand;
use App\Commands\Sso\SsoInitCommand;
use App\Commands\Tool\AbstractToolInitCommand;
use App\Commands\Twenty\TwentyInitCommand;
use App\Commands\Uptime\UptimeInitCommand;
use App\Commands\Vpn\VpnInitCommand;
use App\Commands\Webmail\WebmailInitCommand;
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
            DashboardInitCommand::class => new class($tool) extends DashboardInitCommand {},
            DataInitCommand::class => new class($tool) extends DataInitCommand
            {
                protected function resolveEngine(): string
                {
                    return $this->tool()->value;
                }
            },
            DesignInitCommand::class => new class($tool) extends DesignInitCommand {},
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
            MonitorInitCommand::class => new class($tool) extends MonitorInitCommand {},
            NotesInitCommand::class => new class($tool) extends NotesInitCommand {},
            PasswordsInitCommand::class => new class($tool) extends PasswordsInitCommand {},
            PasteInitCommand::class => new class($tool) extends PasteInitCommand {},
            PlankaInitCommand::class => new class($tool) extends PlankaInitCommand {},
            RecordInitCommand::class => new class($tool) extends RecordInitCommand {},
            ResumeInitCommand::class => new class($tool) extends ResumeInitCommand {},
            SecretsInitCommand::class => new class($tool) extends SecretsInitCommand {},
            SheetsInitCommand::class => new class($tool) extends SheetsInitCommand {},
            SsoInitCommand::class => new class($tool) extends SsoInitCommand {},
            TwentyInitCommand::class => new class($tool) extends TwentyInitCommand {},
            UptimeInitCommand::class => new class($tool) extends UptimeInitCommand {},
            VpnInitCommand::class => new class($tool) extends VpnInitCommand {},
            WebmailInitCommand::class => new class($tool) extends WebmailInitCommand {},
            default => throw new LogicException("No init command is registered for {$base}."),
        };
    }

    /**
     * @return class-string<AbstractToolInitCommand>
     */
    public static function family(ClusterTool $tool): string
    {
        return match ($tool->canonicalTool()) {
            ClusterTool::BULWARK => WebmailInitCommand::class,
            ClusterTool::CHATWOOT => ChatwootInitCommand::class,
            ClusterTool::DIRECTUS => DataInitCommand::class,
            ClusterTool::DOCUMENSO => DocumensoInitCommand::class,
            ClusterTool::EXTERNAL_DNS => DnsInitCommand::class,
            ClusterTool::FORGEJO => GitInitCommand::class,
            ClusterTool::GLITCHTIP => ErrorsInitCommand::class,
            ClusterTool::GRAFANA => MonitorInitCommand::class,
            ClusterTool::HEADLAMP => DashboardInitCommand::class,
            ClusterTool::KUMA => UptimeInitCommand::class,
            ClusterTool::KUTT => KuttInitCommand::class,
            ClusterTool::LIVEKIT => MeetInitCommand::class,
            ClusterTool::MATRIX => ChatInitCommand::class,
            ClusterTool::METABASE => MetabaseInitCommand::class,
            ClusterTool::N8N => FlowInitCommand::class,
            ClusterTool::NETBIRD => VpnInitCommand::class,
            ClusterTool::OCIS => DriveInitCommand::class,
            ClusterTool::OPENBAO => SecretsInitCommand::class,
            ClusterTool::OUTLINE => NotesInitCommand::class,
            ClusterTool::PENPOT => DesignInitCommand::class,
            ClusterTool::PLANKA => PlankaInitCommand::class,
            ClusterTool::PLAUSIBLE => AnalyticsInitCommand::class,
            ClusterTool::POCKETBASE => DataInitCommand::class,
            ClusterTool::WORDPRESS => DataInitCommand::class,
            ClusterTool::RESUME => ResumeInitCommand::class,
            ClusterTool::SENDREC => RecordInitCommand::class,
            ClusterTool::STALWART => MailInitCommand::class,
            ClusterTool::TEABLE => SheetsInitCommand::class,
            ClusterTool::TWENTY => TwentyInitCommand::class,
            ClusterTool::UMAMI => AnalyticsInitCommand::class,
            ClusterTool::VAULTWARDEN => PasswordsInitCommand::class,
            ClusterTool::WINDMILL => FlowInitCommand::class,
            ClusterTool::YOPASS => PasteInitCommand::class,
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
