<?php

namespace App\Tools;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasImages;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\ClusterToolComponentRole;
use App\Tools\Concerns\PinsImages;

/**
 * Self-hosted Supabase stack (Studio, Envoy gateway, GoTrue Auth, PostgREST,
 * Postgres-Meta, Storage, plus optional Realtime, Edge Functions, Imgproxy,
 * Supavisor) backed by a dedicated per-instance `supabase/postgres`.
 */
final class Supabase implements ClusterToolVendor, HasCommonsBuckets, HasImages, HasSmtpWiring, HasVpnWiring, HasWorkloadComponents
{
    use PinsImages;

    public function getLabel(): string
    {
        return 'Supabase';
    }

    public function images(): array
    {
        return [
            'studio' => 'supabase/studio:2026.11.02-sha-a05e7ec',
            'gateway' => 'envoyproxy/envoy:v1.39.6',
            'auth' => 'supabase/gotrue:v2.197.0',
            'rest' => 'postgrest/postgrest:v14.18',
            'realtime' => 'supabase/realtime:v2.148.3',
            'storage' => 'supabase/storage-api:v1.89.12',
            'imgproxy' => 'darthsim/imgproxy:v4.0.21',
            'meta' => 'supabase/postgres-meta:v0.100.0',
            'functions' => 'supabase/edge-runtime:v1.77.9',
            'db' => 'supabase/postgres:17.6.1.136',
            'supavisor' => 'supabase/supavisor:2.9.13',
        ];
    }

    public function commonsBucketList(): array
    {
        return ['supabase'];
    }

    public function canonicalBucketList(): array
    {
        return ['supabase'];
    }

    public function components(?string $instance = null, ?string $engine = null): array
    {
        $slug = ($instance !== null && $instance !== '') ? $instance : 'supabase';

        return [
            new ClusterToolComponentData('gateway', ClusterToolComponentRole::PRIMARY, "supabase-{$slug}"),
            new ClusterToolComponentData('studio', ClusterToolComponentRole::INGRESS, "supabase-studio-{$slug}"),
            new ClusterToolComponentData('auth', ClusterToolComponentRole::WORKER, "supabase-auth-{$slug}"),
            new ClusterToolComponentData('rest', ClusterToolComponentRole::WORKER, "supabase-rest-{$slug}"),
            new ClusterToolComponentData('meta', ClusterToolComponentRole::WORKER, "supabase-meta-{$slug}"),
            new ClusterToolComponentData('storage', ClusterToolComponentRole::WORKER, "supabase-storage-{$slug}"),
            new ClusterToolComponentData('realtime', ClusterToolComponentRole::WORKER, "supabase-realtime-{$slug}"),
            new ClusterToolComponentData('functions', ClusterToolComponentRole::WORKER, "supabase-functions-{$slug}"),
            new ClusterToolComponentData('imgproxy', ClusterToolComponentRole::WORKER, "supabase-imgproxy-{$slug}"),
            new ClusterToolComponentData('supavisor', ClusterToolComponentRole::WORKER, "supabase-supavisor-{$slug}"),
            new ClusterToolComponentData('db', ClusterToolComponentRole::DATABASE, "supabase-db-{$slug}"),
        ];
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $slug = ($instance !== null && $instance !== '') ? $instance : 'supabase';

        return [
            'name' => ToolInstance::forInstance(ClusterTool::SUPABASE, $slug)->name('vpn-only'),
            'namespace' => ClusterTool::SUPABASE->namespace(),
        ];
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        $slug = ($instance !== null && $instance !== '') ? $instance : 'supabase';
        $names = ToolInstance::forInstance(ClusterTool::SUPABASE, $slug);

        return [
            'deployment' => $names->name('auth'),
            'namespace' => ClusterTool::SUPABASE->namespace(),
            'secret' => $names->secret(\App\Enums\SecretKind::SMTP),
            'vars' => [
                'host' => 'GOTRUE_SMTP_HOST',
                'port' => 'GOTRUE_SMTP_PORT',
                'user' => 'GOTRUE_SMTP_USER',
                'password' => 'GOTRUE_SMTP_PASS',
                'from' => 'GOTRUE_SMTP_ADMIN_EMAIL',
            ],
        ];
    }
}
