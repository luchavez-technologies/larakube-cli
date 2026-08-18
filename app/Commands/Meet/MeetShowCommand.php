<?php

namespace App\Commands\Meet;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;
use App\Traits\InteractsWithMeet;

class MeetShowCommand extends AbstractToolShowCommand
{
    use InteractsWithMeet;

    protected function tool(): ClusterTool
    {
        return ClusterTool::MEET;
    }

    /**
     * Adds the wired consumers. Which apps hold a key is the thing you actually
     * need when debugging "my app can't connect" — secrets stay masked.
     *
     * @return array<int, array<int, string>>
     */
    protected function rows(?string $host, string $env, string $kubectl, string $instance = ''): array
    {
        $rows = parent::rows($host, $env, $kubectl, $instance);

        if ($host !== null) {
            $rows[] = ['Signaling', "wss://{$host}"];
        }

        // The bootstrap key is an implementation detail of "LiveKit refuses to
        // start with no keys" — listing it would read as a consumer you wired.
        $registry = $this->meetConsumers($this->readMeetKeys($kubectl, $this->meetNamespace()));

        if ($registry === []) {
            $rows[] = ['Consumers', "<fg=gray>none — run meet:wire {$env} --tool=chat</>"];

            return $rows;
        }

        foreach ($registry as $consumer => $creds) {
            $rows[] = [
                "Consumer: {$consumer}",
                "key {$creds['key']} · rooms {$creds['roomPrefix']}*"
                    .(($creds['webhookUrl'] ?? null) !== null ? " · webhook {$creds['webhookUrl']}" : ''),
            ];
        }

        return $rows;
    }
}
