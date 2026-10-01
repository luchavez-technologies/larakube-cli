<?php

namespace App\Enums;

/**
 * Functional category descriptors for Cluster Tools.
 *
 * Tools may belong to multiple categories (e.g. PocketBase is both a
 * Database, a Backend, and provides Auth). In LaraKube, categories serve as
 * informational descriptors, UI facets, and filter tags rather than execution
 * verbs or namespace boundaries.
 */
enum ToolCategory: string
{
    public function label(): string
    {
        return match ($this) {
            self::DATABASE => 'Database',
            self::BACKEND => 'Backend & CMS',
            self::AUTH => 'Auth & Identity',
            self::SECURITY => 'Security & Secrets',
            self::COMMUNICATION => 'Communication',
            self::OBSERVABILITY => 'Observability & Metrics',
            self::PRODUCTIVITY => 'Productivity & Office',
            self::DEVOPS => 'DevOps & CI/CD',
            self::ANALYTICS => 'Analytics & BI',
            self::STORAGE => 'Storage & S3',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DATABASE => '🗄️',
            self::BACKEND => '⚡',
            self::AUTH => '🪪',
            self::SECURITY => '🔒',
            self::COMMUNICATION => '💬',
            self::OBSERVABILITY => '📡',
            self::PRODUCTIVITY => '📋',
            self::DEVOPS => '🛠️',
            self::ANALYTICS => '📊',
            self::STORAGE => '☁️',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DATABASE => 'emerald',
            self::BACKEND => 'sky',
            self::AUTH => 'violet',
            self::SECURITY => 'rose',
            self::COMMUNICATION => 'indigo',
            self::OBSERVABILITY => 'amber',
            self::PRODUCTIVITY => 'blue',
            self::DEVOPS => 'orange',
            self::ANALYTICS => 'cyan',
            self::STORAGE => 'teal',
        };
    }
    case DATABASE = 'database';
    case BACKEND = 'backend';
    case AUTH = 'auth';
    case SECURITY = 'security';
    case COMMUNICATION = 'communication';
    case OBSERVABILITY = 'observability';
    case PRODUCTIVITY = 'productivity';
    case DEVOPS = 'devops';
    case ANALYTICS = 'analytics';
    case STORAGE = 'storage';
}
