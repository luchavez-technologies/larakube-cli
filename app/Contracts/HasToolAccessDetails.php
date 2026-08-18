<?php

namespace App\Contracts;

interface HasToolAccessDetails
{
    /**
     * Additional table rows to display in {tool}:show (e.g. admin credentials, SSH host, sub-component URLs).
     *
     * @return list<array{0: string, 1: string}>
     */
    public function toolAccessRows(?string $host, string $env, string $kubectl, string $instance = ''): array;
}
