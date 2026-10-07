<?php

namespace App\Commands\Wordpress;

use App\Commands\Data\DataShowCommand;
use App\Enums\ClusterTool;

class WordpressShowCommand extends DataShowCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::WORDPRESS;
    }
}
