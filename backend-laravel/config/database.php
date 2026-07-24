<?php

use Illuminate\Support\Str;

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DATABASE_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // Koneksi default untuk semua tabel kecuali kartus & cards.
        // Single host ke PC Admin (.161) — read & write ke satu tempat.
        // Tabel yang TIDAK direplikasi harus menggunakan koneksi ini.
        'pgsql' => [
            'driver'        => 'pgsql',
            'url'           => env('DATABASE_URL'),
            'host'          => env('DB_HOST', '127.0.0.1'),
            'port'          => env('DB_PORT', '5432'),
            'database'      => env('DB_DATABASE', 'forge'),
            'username'      => env('DB_USERNAME', 'forge'),
            'password'      => env('DB_PASSWORD', ''),
            'charset'       => 'utf8',
            'prefix'        => '',
            'prefix_indexes'=> true,
            'schema'        => 'public',
            'sslmode'       => 'prefer',
            'timezone'      => 'Asia/Jakarta',
        ],

        // Koneksi khusus untuk tabel kartus & cards dengan read/write split.
        // READ  → 192.168.214.161 (PC Admin – replica, SELECT cepat)
        // WRITE → 192.168.214.163 (Virtual IP – master, INSERT/UPDATE/DELETE)
        // Logical replication otomatis sync .163 → .161 untuk kedua tabel ini.
        'pgsql_cards' => [
            'driver' => 'pgsql',
            'url'    => env('DATABASE_URL'),
            'read'   => [
                'host' => [env('DB_HOST', '127.0.0.1')],
            ],
            'write'  => [
                'host' => [env('DB_WRITE_HOST', env('DB_HOST', '127.0.0.1'))],
            ],
            'sticky'        => env('DB_STICKY', true),
            'port'          => env('DB_PORT', '5432'),
            'database'      => env('DB_DATABASE', 'forge'),
            'username'      => env('DB_USERNAME', 'forge'),
            'password'      => env('DB_PASSWORD', ''),
            'charset'       => 'utf8',
            'prefix'        => '',
            'prefix_indexes'=> true,
            'schema'        => 'public',
            'sslmode'       => 'prefer',
            'timezone'      => 'Asia/Jakarta',
        ],

        // Koneksi langsung ke Server/master (192.168.214.163)
        // Digunakan untuk monitoring & testing replication dari sisi publisher
        'pgsql_replica' => [
            'driver' => 'pgsql',
            'host' => env('DB_REPLICA_HOST', '192.168.214.163'),
            'port' => env('DB_REPLICA_PORT', '5432'),
            'database' => env('DB_REPLICA_DATABASE', env('DB_DATABASE', 'garden_house')),
            'username' => env('DB_REPLICA_USERNAME', env('DB_USERNAME', 'postgres')),
            'password' => env('DB_REPLICA_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'schema' => 'public',
            'sslmode' => 'prefer',
            'timezone' => 'Asia/Jakarta',
        ],

    ],

    'migrations' => 'migrations',

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
