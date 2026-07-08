<?php

namespace App\Traits;

use App\Contracts\HasDockerImage;
use App\Contracts\HasHiddenComponents;
use App\Data\ConfigData;

trait ProvidesSelectOptions
{
    public static function getSelectOptions(?ConfigData $config = null): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }

            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }

    public static function getSelectOptionsWithSizes(?ConfigData $config = null): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case instanceof HasHiddenComponents && $case->isHidden($config)) {
                continue;
            }

            $label = $case->getLabel();

            if ($case instanceof HasDockerImage) {
                $parts = [];

                if ($download = $case->getDownloadSize($config)) {
                    $parts[] = "dl: {$download} MB";
                }

                if ($storage = $case->getAllocatedStorage($config)) {
                    $parts[] = "vol: {$storage}";
                }

                if (! empty($parts)) {
                    $label .= ' ('.implode(', ', $parts).')';
                }
            }

            $options[$case->value] = $label;
        }

        return $options;
    }
}
