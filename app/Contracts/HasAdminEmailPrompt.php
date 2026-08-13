<?php

namespace App\Contracts;

interface HasAdminEmailPrompt
{
    /** The human-readable label shown when prompting for the admin email (e.g. 'Reactive Resume', 'Forgejo'). */
    public function adminEmailLabel(): string;
}
