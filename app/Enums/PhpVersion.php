<?php

namespace App\Enums;

use App\Contracts\HasCommandOptions;
use App\Contracts\HasHiddenComponents;
use App\Contracts\HasSelectOptions;
use App\Data\ConfigData;
use App\Traits\ProvidesCommandOptions;
use App\Traits\ProvidesSelectOptions;

enum PhpVersion: string implements HasCommandOptions, HasHiddenComponents, HasSelectOptions
{
    use ProvidesCommandOptions, ProvidesSelectOptions;

    public function isHidden(?ConfigData $config = null): bool
    {
        $v = (float) $this->value;

        // If we are creating a NEW project (Laravel 13), it only supports 8.3+
        if ($config?->isScaffolding() && $v < 8.3) {
            return true;
        }

        // FrankenPHP only supports 8.3+
        if ($config?->getServerVariation() === ServerVariation::FRANKENPHP && $v < 8.3) {
            return true;
        }

        return false;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::PHP_8_6 => 'PHP 8.6 (Newest)',
            self::PHP_8_5 => 'PHP 8.5 (Recommended)',
            self::PHP_8_4 => 'PHP 8.4',
            self::PHP_8_3 => 'PHP 8.3',
            self::PHP_8_2 => 'PHP 8.2',
            self::PHP_8_1 => 'PHP 8.1',
            self::PHP_8_0 => 'PHP 8.0',
            self::PHP_7_4 => 'PHP 7.4',
        };
    }

    case PHP_8_6 = '8.6';
    case PHP_8_5 = '8.5';
    case PHP_8_4 = '8.4';
    case PHP_8_3 = '8.3';
    case PHP_8_2 = '8.2';
    case PHP_8_1 = '8.1';
    case PHP_8_0 = '8.0';
    case PHP_7_4 = '7.4';
}
