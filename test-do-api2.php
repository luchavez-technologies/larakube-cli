<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Http;

$clusterId = '67ca580c-319c-4965-a64d-e084c832621a';
$config = json_decode(file_get_contents(getenv('HOME').'/.larakube/config.json'), true);
$token = $config['doToken'] ?? null;
$response = Http::withToken($token)->get("https://api.digitalocean.com/v2/kubernetes/clusters/{$clusterId}/kubeconfig");
echo $response->body()."\n";
