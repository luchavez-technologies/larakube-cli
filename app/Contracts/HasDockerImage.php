<?php

namespace App\Contracts;

use App\Data\ConfigData;

interface HasDockerImage
{
    public function getDockerImage(?ConfigData $config = null): string;

    public function getDownloadSize(?ConfigData $config = null): ?int;

    public function getOnDiskSize(?ConfigData $config = null): ?int;

    public function getAllocatedStorage(?ConfigData $config = null): ?string;
}
