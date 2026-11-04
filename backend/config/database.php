<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'connect_timeout' => (int) env('DB_TIMEOUT', 5),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,

            /*
             * Timezone SESI MySQL.
             *
             * MySQL menyimpan kolom `TIMESTAMP` dalam UTC dan mengubahnya ke
             * timezone SESI setiap kali dibaca. Laravel membaca `TIMESTAMP`
             * sebagai string polos tanpa konversi, jadi nilai yang ditulis
             * PHP harus sama dengan nilai yang dibaca MySQL -- keduanya harus
             * memakai zona waktu yang SAMA.
             *
             * Tanpa baris ini, session time_zone MySQL tetap `SYSTEM`, yaitu
             * timezone host. Di mesin ini host berjalan UTC, jadi jam WIB yang
             * ditulis Laravel (mis. `created_at` = 08:00) akan dibaca kembali
             * sebagai 08:00 UTC = 15:00 WIB -- mundur 7 jam. Tanggal
             * pembayaran dan laporan harian jadi tidak cocok dengan kenyataan.
             *
             * NILAINYA ADALAH OFFSET, BUKAN NAMA ZONA.
             *
             * `Asia/Jakarta` TIDAK bisa dipakai: MySQL hanya mengenali nama
             * zona kalau tabel timezone-nya sudah dimuat
             * (`mysql_tzinfo_to_sql`). Server yang belum memuat tabel itu
             * menolak dengan:
             *
             *     ERROR 1298 (HY000): Unknown or incorrect time zone: 'Asia/Jakarta'
             *
             * Offset `+07:00` tidak butuh tabel apa pun, jadi selalu bisa
             * dipakai. Indonesia tidak memakai daylight saving, jadi
             * offsetnya tetap sepanjang tahun.
             *
             * Kalau `APP_TIMEZONE` diubah ke zona lain, offset ini harus
             * diubah juga -- MySQL tidak bisa menebaknya dari nama zona.
             */
            'timezone' => env('DB_TIMEZONE', '+07:00'),

            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'connect_timeout' => (int) env('DB_TIMEOUT', 5),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'timezone' => env('DB_TIMEZONE', '+07:00'),
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
        |----------------------------------------------------------------------
        | Database PUSAT
        |----------------------------------------------------------------------
        |
        | Menyimpan data lintas lokasi: registry tenant (tabel `tenants`),
        | user superadmin, dan invoice langganan. Tidak memuat data operasional
        | maupun keuangan milik lokasi mana pun.
        |
        | Namanya `astaloka` -- sama dengan nama aplikasinya, tanpa
        | suffix. Database lokasi memakai pola `astaloka_<nama>`, jadi
        | `astaloka_lokasi`, `astaloka_pamsimas`, dst. Nama polos `astaloka`
        | dipakai pusat supaya tidak pernah tertukar dengan salah satu
        | database lokasi.
        |
        | Credensial memakai DB_* yang sama dengan tenant -- satu server MySQL,
        | hanya nama database-nya yang berbeda.
        |
        */
        'central' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('TENANCY_CENTRAL_DATABASE', 'astaloka'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'connect_timeout' => (int) env('DB_TIMEOUT', 5),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,

            // Sama dengan koneksi `mysql` di atas -- lihat catatan panjang
            // di sana soal kenapa offset, bukan nama zona.
            'timezone' => env('DB_TIMEZONE', '+07:00'),

            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
        |----------------------------------------------------------------------
        | Database TENANT (LOKASI) AKTIF
        |----------------------------------------------------------------------
        |
        | Nama database-nya TIDAK dikunci di sini. Diisi saat runtime oleh
        | `TenantManager::activate()`, yang membaca baris registry dari
        | database pusat lalu menimpa `database` koneksi ini sesuai lokasi
        | yang sedang dilayani.
        |
        | Nilai `database` di bawah hanya fallback untuk konteks yang tidak
        | pernah melewati middleware tenancy -- misalnya `artisan` yang
        | dijalankan tanpa `--tenant`, atau seeder. Karena itu nilainya
        | sengaja menunjuk DB_DATABASE (dari .env) supaya perintah
        | pemeliharaan tetap bisa menyentuh satu lokasi yang nyata, dan
        | `TenantManager` tetap menimpanya begitu tenant ter-resolve.
        |
        */
        'tenant' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'connect_timeout' => (int) env('DB_TIMEOUT', 5),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,

            // Sama dengan koneksi `mysql` di atas -- lihat catatan panjang
            // di sana soal kenapa offset, bukan nama zona.
            //
            // Penting untuk koneksi ini: `TenantManager::activate()` mengganti
            // `database` saja, jadi `timezone` yang diwarisi dari config
            // awal tetap ikut terpakai.
            'timezone' => env('DB_TIMEZONE', '+07:00'),

            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // Koneksi ke database LAMA (server produksi yg sudah online)
        // Dipakai khusus untuk import data, JANGAN dipakai di runtime aplikasi
        //
        // Default `astaloka_lama` BUKAN `astaloka` dengan sengaja. Nama polos
        // `astaloka` sekarang milik database pusat, jadi default lama
        // `astaloka` membuat koneksi ini diam-diam menunjuk ke pusat --
        // `getPdo()` tetap sukses, lalu import membaca tabel yang tidak ada
        // di sana. Kalau server legacy Anda memang memakai nama lain, isi
        // LEGACY_DB_DATABASE di .env.
        'legacy' => [
            'driver' => 'mysql',
            'url' => env('DATABASE_URL'),
            'host' => env('LEGACY_DB_HOST', '127.0.0.1'),
            'port' => env('LEGACY_DB_PORT', '3306'),
            'database' => env('LEGACY_DB_DATABASE', 'astaloka_lama'),
            'username' => env('LEGACY_DB_USERNAME', 'root'),
            'password' => env('LEGACY_DB_PASSWORD', ''),
            'unix_socket' => env('LEGACY_DB_SOCKET', ''),
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

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
