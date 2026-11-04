<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is utilized if another isn't explicitly
    | specified when running a cache operation inside the application.
    |
    */

    'default' => env('CACHE_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "array", "database", "file", "memcached",
    |                    "redis", "dynamodb", "octane",
    |                    "failover", "null"
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        /*
         * `DB_CACHE_CONNECTION` TIDAK dibiarkan kosong.
         *
         * Kosong berarti `DatabaseStore` dibangun tanpa nama koneksi, jadi
         * ia memakai koneksi DEFAULT saat store itu pertama kali di-resolve.
         *
         * Di aplikasi satu-lokasi itu tidak berbahaya. Di ASTALOKA tidak:
         * `TenantManager::activate()` hanya memanggil `DB::purge('tenant')` --
         * store cache tidak pernah dibangun ulang. Begitu request pertama yang
         * menyentuh cache selesai, store itu terkunci ke database lokasi
         * pertama di proses itu, dan SETIAP lokasi berikutnya diam-diam
         * menulis serta membaca cache di database lokasi pertama.
         *
         * Akibatnya pada `CACHE_STORE=database`:
         *   - satu proses FPM yang melayani lokasi A lalu lokasi B memakai
         *     satu tabel `cache` milik A saja;
         *   - lokasi B kehilangan cache-nya, sehingga statistik dihitung
         *     ulang terus-menerus, atau -- lebih buruk -- membaca flag A.

         * Karena itu store ini dipaksa memakai koneksi pusat. Tabel `cache`
         * di database pusat itu yang jadi keyspace bersama, dan pemisahan
         * antar lokasi sekarang jadi TANGGUNG JAWAB `TenantCache` lewat
         * awalan kunci `t{tenantId}:v{n}:` -- bukan fallout urutan request.

         * Nilai tetap bisa ditimpa lewat `DB_CACHE_CONNECTION` untuk
         * kebutuhan khusus, tapi default-nya tidak lagi `null`.
         */
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION', env('TENANCY_CENTRAL_CONNECTION', 'central')),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION', env('TENANCY_CENTRAL_CONNECTION', 'central')),
            'lock_table' => env('DB_CACHE_LOCK_TABLE', 'cache_locks'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

        'failover' => [
            'driver' => 'failover',
            'stores' => [
                'database',
                'array',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Store untuk Mutex Scheduler
    |--------------------------------------------------------------------------
    |
    | Store yang dipakai Laravel untuk mengunci job scheduler -- mutex
    | `withoutOverlapping()`, flag pause, dan flag interrupt.
    |
    | Kenapa TIDAK ikut `database` (default `CACHE_STORE`)
    | ---------------------------------------------------
    |
    | `schedule:run` dipanggil setiap menit oleh Task Scheduler. Sebelum
    | menjalankan apa pun, Laravel membaca flag pause dari cache:
    |
    |     $this->cache->get('illuminate:schedule:paused', false)
    |
    | Kalau cache itu di database, satu query ke database terjadi DI SETIAP
    | menit -- dan kalau MySQL sedang mati atau belum selesai start, query
    | itu melempar `SQLSTATE[HY000] [2002]`. Akibatnya:
    |
    |   - `schedule:run` CRASH sebelum job apa pun sempat dicek, jadi job
    |     yang due TIDAK jalan -- termasuk generate piutang pada tanggalnya;
    |   - satu baris ERROR ditulis ke `storage/logs/laravel.log` tiap menit,
    |     jadi log cepat membanjir dan error yang SEBENARNYA jadi sulit
    |     ditemukan di antara ribuan error koneksi.
    |
    | Mutex scheduler bukan data yang perlu bertahan lama atau perlu dibagi
    | antar lokasi -- kuncinya cuma "apakah job ini sedang jalan". Dan
    | "sedang jalan" justru hal yang paling perlu benar saat database TIDAK
    | bisa dijangkau: kalau MySQL mati, yang kita mau adalah scheduler
    | melewati job yang butuh database dengan tenang, bukan crash.
    |
    | Store `file` tidak butuh database sama sekali, jadi:
    |
    |   - scheduler tidak pernah crash karena MySQL mati;
    |   - mutex `withoutOverlapping()` tetap bekerja -- justru lebih penting
    |     di situation itu, supaya dua proses tidak menjalankan job yang
    |     sama bersamaan;
    |   - `schedule:pause` / `schedule:resume` tetap berfungsi, karena flag
    |     pause juga dibaca dari store ini.
    |
    | File-nya ada di `storage/framework/cache/data`. Itu folder lokal
    | server, jadi di multi-server mutex ini tidak berlaku lintas server --
    | dan itu memang tidak relevan di sini: ASTALOKA dijalankan di satu
    | server, dan `onOneServer()` tidak dipakai di sini.
    |
    | Nilai tetap bisa ditimpa lewat `SCHEDULE_CACHE_STORE` untuk kebutuhan
    | khusus (mis. Redis di instalasi yang butuh mutex lintas server).
    */
    'schedule_store' => env('SCHEDULE_CACHE_STORE', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),

    /*
    |--------------------------------------------------------------------------
    | Serializable Classes
    |--------------------------------------------------------------------------
    |
    | This value determines the classes that can be unserialized from cache
    | storage. By default, no PHP classes will be unserialized from your
    | cache to prevent gadget chain attacks if your APP_KEY is leaked.
    |
    */

    'serializable_classes' => false,

];
