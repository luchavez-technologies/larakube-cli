<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo view('k8s.traefik-managed', ['email' => 'james@luchtech.dev', 'loadBalancerName' => 'larakube-do-sgp1-luchtech-managed'])->render();
