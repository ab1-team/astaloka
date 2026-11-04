<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel `sessions` dan `jobs` di database PUSAT.
 *
 * Kenapa perlu
 * ------------
 *
 * Dua hal di folder `migrations_central/` sebelumnya hilang: `sessions` dan
 * `jobs`. Keduanya hanya ada di `migrations_tenant/`, jadi hanya pernah ada
 * di database LOKASI.
 *
 * Itu benar selama database lokasi adalah satu-satunya database yang dibaca
 * lewat `database.default`. Tapi ada keadaan lain yang sah dan justru sedang
 * dipakai di development: install yang belum punya lokasi, sehingga satu-
 *-satunya database yang ada adalah database PUSAT. Di keadaan itu
 * `DB_DATABASE` menunjuk ke `astaloka` juga, dan `database.default` ikut
 * menunjuk ke sana.
 *
 * Akibatnya dua hal langsung rusak:
 *
 *   1. `SESSION_DRIVER=database` menulis ke `sessions` pada koneksi default.
 *      Tabelnya tidak ada di database pusat, jadi setiap request yang lewat
 *      middleware `StartSession` gagal dengan "Table 'astaloka.sessions'
 *      doesn't exist". Gejalanya muncul sebagai 500 pada landing page dan
 *      pada health check -- bukan sebagai error yang menyebut session.
 *   2. `QUEUE_CONNECTION=database` membaca tabel `jobs` dari koneksi yang
 *      sama. `composer dev` menjalankan `php artisan queue:listen`, dan
 *      perintah itu mem-poll tabel `jobs` terus-menerus: begitu tabelnya
 *      tidak ada, queue berhenti sebelum sempat memproses apa pun.
 *
 * Tabel `cache` dan `cache_locks` sudah pindah ke database pusat duluan
 * dengan alasan yang persis sama (lihat
 * `2026_10_20_000002_create_cache_tables_in_central.php`): database pusat
 * harus cukup mandiri untuk tabel infrastruktur yang bukan milik lokasi
 * mana pun.
 *
 * Kenapa TIDAK memindahkan `password_reset_tokens`
 * -----------------------------------------------
 *
 * Tabel `users` di database pusat memang berbeda dari tabel `users` di
 * database lokasi: isinya superadmin, bukan user lokasi. Aplikasi ini tidak
 * punya alur lupa-password sama sekali -- tidak ada route, tidak ada
 * `sendPasswordResetLink()` -- jadi tabel itu tidak pernah dibaca. Menambah
 * tabel yang tidak pernah dipakai hanya menambah permukaan yang harus
 * dijaga.
 *
 * Yang ditambahkan di sini hanya apa yang benar-benar dibutuhkan oleh
 * konfigurasi yang sedang berjalan: `SESSION_DRIVER` dan `QUEUE_CONNECTION`
 * sama-sama `database`.
 *
 * Dampak ke mode banyak lokasi
 * ----------------------------
 *
 * Tidak ada. Tabel `sessions` di database lokasi tetap dipakai seperti
 * sebelumnya: `TenantManager::activate()` menulis ulang `database.default`
 * ke koneksi lokasi, dan handle session dibangun ulang mengikuti koneksi itu.
 * Tabel di database pusat hanya kepakai kalau default-nya menunjuk ke pusat
 * -- yaitu keadaan install tanpa lokasi.
 *
 * Dijalankan dengan:
 *     php artisan tenancy:migrate-central
 */
return new class extends Migration
{
    public function up(): void
    {
        $central = (string) config('tenancy.central_connection', 'central');

        $schema = Schema::connection($central);

        /*
         * `hasTable` di depan `create` supaya migrasi ini idempotent,
         * sama seperti seluruh migrasi `central/` lainnya: tabelnya bisa
         * sudah ada dari instalasi yang membuatnya manual, dan `EnsuresCentralTables`
         * menjalankan ulang seluruh migrasi pusat di tiap test.
         */
        if (! $schema->hasTable('sessions')) {
            $schema->create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }

        if (! $schema->hasTable('jobs')) {
            $schema->create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! $schema->hasTable('job_batches')) {
            $schema->create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (! $schema->hasTable('failed_jobs')) {
            $schema->create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        $central = (string) config('tenancy.central_connection', 'central');

        $schema = Schema::connection($central);

        /*
         * `dropIfExists` supaya `down()` aman pada instalasi yang tabel
         *Central-nya belum pernah dibuat.
         */
        $schema->dropIfExists('failed_jobs');
        $schema->dropIfExists('job_batches');
        $schema->dropIfExists('jobs');
        $schema->dropIfExists('sessions');
    }
};
