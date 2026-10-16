<?php

namespace App\Services\Tools;

use App\Commands\Analytics\AnalyticsInitCommand;
use App\Commands\Bulwark\BulwarkInitCommand;
use App\Commands\Chatwoot\ChatwootInitCommand;
use App\Commands\Data\DataInitCommand;
use App\Commands\Documenso\DocumensoInitCommand;
use App\Commands\ExternalDns\ExternalDnsInitCommand;
use App\Commands\Flow\FlowInitCommand;
use App\Commands\Forgejo\ForgejoInitCommand;
use App\Commands\GlitchTip\GlitchTipInitCommand;
use App\Commands\Grafana\GrafanaInitCommand;
use App\Commands\Headlamp\HeadlampInitCommand;
use App\Commands\Kuma\KumaInitCommand;
use App\Commands\Kutt\KuttInitCommand;
use App\Commands\LiveKit\LiveKitInitCommand;
use App\Commands\Matrix\MatrixInitCommand;
use App\Commands\Metabase\MetabaseInitCommand;
use App\Commands\NetBird\NetBirdInitCommand;
use App\Commands\Ocis\OcisInitCommand;
use App\Commands\OpenBao\OpenBaoInitCommand;
use App\Commands\Outline\OutlineInitCommand;
use App\Commands\Penpot\PenpotInitCommand;
use App\Commands\Planka\PlankaInitCommand;
use App\Commands\Resume\ResumeInitCommand;
use App\Commands\Sendrec\SendrecInitCommand;
use App\Commands\Stalwart\StalwartInitCommand;
use App\Commands\Teable\TeableInitCommand;
use App\Commands\Tool\AbstractToolInitCommand;
use App\Commands\Twenty\TwentyInitCommand;
use App\Commands\Vaultwarden\VaultwardenInitCommand;
use App\Commands\Yopass\YopassInitCommand;
use App\Commands\Zitadel\ZitadelInitCommand;
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
            MatrixInitCommand::class => new class($tool) extends MatrixInitCommand {},
            HeadlampInitCommand::class => new class($tool) extends HeadlampInitCommand {},
            DataInitCommand::class => new class($tool) extends DataInitCommand
            {
                protected function resolveEngine(): string
                {
                    return $this->tool()->value;
                }
            },
            PenpotInitCommand::class => new class($tool) extends PenpotInitCommand {},
            ExternalDnsInitCommand::class => new class($tool) extends ExternalDnsInitCommand {},
            DocumensoInitCommand::class => new class($tool) extends DocumensoInitCommand {},
            OcisInitCommand::class => new class($tool) extends OcisInitCommand
            {
                protected function resolveEngine(): string
                {
                    return $this->tool()->value;
                }
            },
            GlitchTipInitCommand::class => new class($tool) extends GlitchTipInitCommand {},
            FlowInitCommand::class => new class($tool) extends FlowInitCommand
            {
                protected function resolveEngine(): string
                {
                    return $this->tool()->value;
                }
            },
            ForgejoInitCommand::class => new class($tool) extends ForgejoInitCommand {},
            ChatwootInitCommand::class => new class($tool) extends ChatwootInitCommand {},
            KuttInitCommand::class => new class($tool) extends KuttInitCommand {},
            StalwartInitCommand::class => new class($tool) extends StalwartInitCommand {},
            LiveKitInitCommand::class => new class($tool) extends LiveKitInitCommand {},
            MetabaseInitCommand::class => new class($tool) extends MetabaseInitCommand {},
            GrafanaInitCommand::class => new class($tool) extends GrafanaInitCommand {},
            OutlineInitCommand::class => new class($tool) extends OutlineInitCommand {},
            VaultwardenInitCommand::class => new class($tool) extends VaultwardenInitCommand {},
            YopassInitCommand::class => new class($tool) extends YopassInitCommand {},
            PlankaInitCommand::class => new class($tool) extends PlankaInitCommand {},
            SendrecInitCommand::class => new class($tool) extends SendrecInitCommand {},
            ResumeInitCommand::class => new class($tool) extends ResumeInitCommand {},
            OpenBaoInitCommand::class => new class($tool) extends OpenBaoInitCommand {},
            TeableInitCommand::class => new class($tool) extends TeableInitCommand {},
            ZitadelInitCommand::class => new class($tool) extends ZitadelInitCommand {},
            TwentyInitCommand::class => new class($tool) extends TwentyInitCommand {},
            KumaInitCommand::class => new class($tool) extends KumaInitCommand {},
            NetBirdInitCommand::class => new class($tool) extends NetBirdInitCommand {},
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
            ClusterTool::EXTERNAL_DNS => ExternalDnsInitCommand::class,
            ClusterTool::FORGEJO => ForgejoInitCommand::class,
            ClusterTool::GLITCHTIP => GlitchTipInitCommand::class,
            ClusterTool::GRAFANA => GrafanaInitCommand::class,
            ClusterTool::HEADLAMP => HeadlampInitCommand::class,
            ClusterTool::KUMA => KumaInitCommand::class,
            ClusterTool::KUTT => KuttInitCommand::class,
            ClusterTool::LIVEKIT => LiveKitInitCommand::class,
            ClusterTool::MATRIX => MatrixInitCommand::class,
            ClusterTool::METABASE => MetabaseInitCommand::class,
            ClusterTool::N8N => FlowInitCommand::class,
            ClusterTool::NETBIRD => NetBirdInitCommand::class,
            ClusterTool::OCIS => OcisInitCommand::class,
            ClusterTool::OPENBAO => OpenBaoInitCommand::class,
            ClusterTool::OUTLINE => OutlineInitCommand::class,
            ClusterTool::PENPOT => PenpotInitCommand::class,
            ClusterTool::PLANKA => PlankaInitCommand::class,
            ClusterTool::PLAUSIBLE => AnalyticsInitCommand::class,
            ClusterTool::POCKETBASE => DataInitCommand::class,
            ClusterTool::WORDPRESS => DataInitCommand::class,
            ClusterTool::RESUME => ResumeInitCommand::class,
            ClusterTool::SENDREC => SendrecInitCommand::class,
            ClusterTool::STALWART => StalwartInitCommand::class,
            ClusterTool::TEABLE => TeableInitCommand::class,
            ClusterTool::TWENTY => TwentyInitCommand::class,
            ClusterTool::UMAMI => AnalyticsInitCommand::class,
            ClusterTool::VAULTWARDEN => VaultwardenInitCommand::class,
            ClusterTool::WINDMILL => FlowInitCommand::class,
            ClusterTool::YOPASS => YopassInitCommand::class,
            ClusterTool::ZITADEL => ZitadelInitCommand::class,
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
