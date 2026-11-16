<?php

namespace App\Support;

use App\Contracts\HasImages;
use App\Enums\ClusterTool;
use Throwable;

/**
 * The images this CLI build would install for a tool, by repository, so a
 * running image can be compared with what an update would bring.
 *
 * Read from the vendor's images() and from literal `image:` lines in the tool's
 * templates. Tags built from template variables can't be read statically, so a
 * repository missing here means "unknown", never "unchanged".
 */
final class ToolImagePins
{
    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /** @return array<string, string> repository => pinned image reference */
    public static function for(ClusterTool $tool): array
    {
        if (isset(self::$cache[$tool->value])) {
            return self::$cache[$tool->value];
        }

        $references = [];

        try {
            $vendor = $tool->vendor();

            if ($vendor instanceof HasImages) {
                $references = array_values($vendor->images());
            }
        } catch (Throwable) {
            // A tool without a vendor class still has its templates.
        }

        foreach (glob(resource_path('views/k8s/'.$tool->value).'/*.blade.php') ?: [] as $file) {
            preg_match_all('/^\s*image:\s*["\']?([^\s"\'{}]+:[^\s"\'{}]+)["\']?\s*$/m', (string) file_get_contents($file), $matches);
            array_push($references, ...$matches[1]);
        }

        $pins = [];

        foreach ($references as $reference) {
            $pins[self::repository($reference)] = $reference;
        }

        return self::$cache[$tool->value] = $pins;
    }

    /** `docker.io/library/postgres:17@sha256:…` and `postgres:17` are both `postgres`. */
    public static function repository(string $reference): string
    {
        $reference = preg_replace('/@.*$/', '', $reference) ?? $reference;
        $slash = strrpos($reference, '/');
        $colon = strrpos($reference, ':');

        if ($colon !== false && ($slash === false || $colon > $slash)) {
            $reference = substr($reference, 0, $colon);
        }

        $reference = preg_replace('#^(docker\.io|index\.docker\.io|registry-1\.docker\.io)/#', '', $reference) ?? $reference;

        return preg_replace('#^library/#', '', $reference) ?? $reference;
    }
}
