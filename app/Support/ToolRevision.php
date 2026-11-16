<?php

namespace App\Support;

use App\Enums\ClusterTool;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * What a tool's manifests look like in this CLI build, as a short hash: its
 * template directory (`resources/views/k8s/<tool>`) plus its vendor class,
 * where image pins live. Stamped on each install (`Labels::REVISION`) and
 * compared by `tool:outdated`, so an image bump, a manifest change or a fix
 * inside a template all count as "update available" — even on a `local` dev
 * build, whose version string can't tell builds apart.
 *
 * Shared partials outside the tool's own directory aren't included: a change
 * there is rare and shows up on that tool's next update anyway.
 */
final class ToolRevision
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function for(ClusterTool $tool): string
    {
        return self::$cache[$tool->value] ??= self::compute($tool);
    }

    private static function compute(ClusterTool $tool): string
    {
        $context = hash_init('sha256');
        hash_update($context, $tool->value);

        $directory = resource_path('views/k8s/'.$tool->value);

        if (is_dir($directory)) {
            $files = iterator_to_array((new Finder)->files()->in($directory)->name('*.blade.php')->sortByName(), false);

            foreach ($files as $file) {
                hash_update($context, $file->getRelativePathname()."\0".$file->getContents()."\0");
            }
        }

        $vendorFile = self::vendorFile($tool);

        if ($vendorFile !== null) {
            hash_update($context, (string) file_get_contents($vendorFile));
        }

        return substr(hash_final($context), 0, 12);
    }

    private static function vendorFile(ClusterTool $tool): ?string
    {
        try {
            $file = (new ReflectionClass($tool->vendor()))->getFileName();
        } catch (Throwable) {
            return null;
        }

        return is_string($file) && is_file($file) ? $file : null;
    }
}
