<?php

use Illuminate\Console\Scheduling\ScheduleFinishCommand;
use Illuminate\Console\Scheduling\ScheduleListCommand;
use Illuminate\Console\Scheduling\ScheduleRunCommand;
use Illuminate\Database\Console\Factories\FactoryMakeCommand;
use Illuminate\Foundation\Console\ComponentMakeCommand;
use Illuminate\Foundation\Console\ConsoleMakeCommand;
use Illuminate\Foundation\Console\ModelMakeCommand;
use Illuminate\Foundation\Console\TestMakeCommand;
use Illuminate\Foundation\Console\VendorPublishCommand;
use LaravelZero\Framework\Commands\BuildCommand;
use LaravelZero\Framework\Commands\InstallCommand;
use LaravelZero\Framework\Commands\RenameCommand;
use LaravelZero\Framework\Commands\StubPublishCommand;
use NunoMaduro\LaravelConsoleSummary\SummaryCommand;
use Symfony\Component\Console\Command\DumpCompletionCommand;
use Symfony\Component\Console\Command\HelpCommand;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Command
    |--------------------------------------------------------------------------
    */

    'default' => SummaryCommand::class,

    /*
    |--------------------------------------------------------------------------
    | Commands Paths
    |--------------------------------------------------------------------------
    */

    'paths' => [app_path('Commands')],

    /*
    |--------------------------------------------------------------------------
    | Added Commands
    |--------------------------------------------------------------------------
    */

    'add' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Hidden Commands
    |--------------------------------------------------------------------------
    */

    'hidden' => [
        SummaryCommand::class,
        DumpCompletionCommand::class,
        HelpCommand::class,
        ScheduleRunCommand::class,
        ScheduleListCommand::class,
        ScheduleFinishCommand::class,
        VendorPublishCommand::class,
        StubPublishCommand::class,

        // Hide standard Laravel/PHP extensions
        ComponentMakeCommand::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Removed Commands
    |--------------------------------------------------------------------------
    */

    'remove' => (Phar::running() !== '') ? [
        // Remove build and rename tools ONLY from the standalone binary
        BuildCommand::class,
        RenameCommand::class,
        InstallCommand::class,

        // Remove all make: commands
        ConsoleMakeCommand::class,
        TestMakeCommand::class,
        FactoryMakeCommand::class,
        ModelMakeCommand::class,
    ] : [],
];
