<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Card Replication Configuration
    |--------------------------------------------------------------------------
    |
    | Konfigurasi untuk PostgreSQL Logical Replication
    | Master: Admin Dashboard (PC) - tempat cards diedit
    | Replica: Server - read-only untuk gate access & citaion
    |
    */

    'enabled' => env('REPLICATION_ENABLED', false),

    'master' => [
        'host' => env('REPLICATION_MASTER_HOST', '192.168.214.163'),
        'port' => env('REPLICATION_MASTER_PORT', 5432),
        'database' => env('REPLICATION_MASTER_DATABASE', 'dashboard'),
        'username' => env('REPLICATION_MASTER_USERNAME', 'postgres'),
        'password' => env('REPLICATION_MASTER_PASSWORD', ''),
    ],

    'subscription' => [
        'name' => env('REPLICATION_SUBSCRIPTION_NAME', 'cards_kartus_sub'),
        'publication' => env('REPLICATION_PUBLICATION_NAME', 'cards_kartus_pub'),
    ],

    // Publication name di master/publisher (PC Admin)
    'publication' => [
        'name' => env('REPLICATION_PUBLICATION_NAME', 'cards_kartus_pub'),
    ],

    'audit' => [
        'enabled' => env('CARD_AUDIT_LOG_ENABLED', true),
        'table' => 'card_sync_audit_logs',
    ],

    'webhook' => [
        'url' => env('CARD_CHANGE_WEBHOOK_URL', null),
        'secret' => env('CARD_CHANGE_WEBHOOK_SECRET', null),
    ],

    'tables' => [
        'cards' => 'cards',
        'kartus' => 'kartus',
    ],
];
