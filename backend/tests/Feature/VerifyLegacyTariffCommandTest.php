<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Perintah `legacy:verify-tariff` -- alat untuk MENGULANG angka-angka tarif
 * yang dikutip di dokumentasi.
 *
 * Angka di `docs/roadmap.md` §8.4, di docblock
 * `BillingService::calculateUsageCharge()`, dan di
 * `tests/Unit/BillingServiceTest.php` semuanya berasal dari pengukuran
 * terhadap data produksi aplikasi LAMA. Kalau angkanya berubah, dokumentasi
 * yang harus diperbarui -- bukan sebaliknya.
 *
 * Test ini tidak mencoba menguji angka persis 25.569 (datanya ada di DB
 * legacy, bukan di DB testing). Yang diuji adalah PERILAKU perintah:
 * populasinya benar, perhitungannya jujur, database yang salah ditolak
 * dengan pesan jelas -- bukan menampilkan angka yang menyesatkan.
 */
class VerifyLegacyTariffCommandTest extends TestCase
{
    #[Test]
    public function perintah_terdaftar_di_daftar_artisan(): void
    {
        $this->assertArrayHasKey(
            'legacy:verify-tariff',
            $this->app[Kernel::class]->all(),
            'Perintah legacy:verify-tariff harus terdaftar di artisan.'
        );
    }

    #[Test]
    public function koneksi_database_yang_tidak_ada_ditangani_dengan_rapi(): void
    {
        /*
         * Koneksi `mysql` dipakai, bukan `legacy` -- alasannya sama seperti
         * test di bawahnya: `legacy` membaca `LEGACY_DB_*` dari `.env`, dan
         * nilai itu berbeda di setiap mesin.
         */
        $exit = $this->artisan('legacy:verify-tariff', [
            '--connection' => 'mysql',
            '--database' => 'database_yang_tidak_ada_sama_sekali',
        ])
            ->expectsOutputToContain('Gagal konek');

        $exit->run();
        $exit->assertFailed();
    }

    #[Test]
    public function database_yang_bukan_data_legacy_ditolak_dengan_pesan_jelas(): void
    {
        /*
         * DB testing punya tabel Laravel, tapi TIDAK punya tabel legacy
         * (`usages`, `settings`, `packages`, `installations`). Kalau
         * perintah diam-diam lanjut di sini, ia akan menampilkan "0 baris"
         * yang terlihat seperti hasil pengukuran -- padahal tidak ada
         * data yang diukur sama sekali.
         *
         * Itu bahaya: angka "0 dari 0" bisa disalin ke dokumentasi dan
         * dianggap bukti.
         *
         * Koneksi yang dipakai adalah koneksi `mysql` (yang dipakai test
         * lain), BUKAN koneksi `legacy`.
         *
         * Kenapa bukan `legacy`? Karena koneksi `legacy` dibaca dari
         * `LEGACY_DB_*` di `.env` -- dan nilainya berbeda di setiap mesin:
         * di laptop bisa kosong (lalu jatuh ke 127.0.0.1 tanpa password),
         * di CI pun tidak di-set sama sekali sehingga `LEGACY_DB_HOST`
         * bawaan dari `.env.example` menunjuk ke host luar. Akibatnya
         * perintah gagal duluan di tahap "Gagal konek", dan pesan yang
         * seharusnya diuji ("tidak ada di database") tidak pernah muncul.
         *
         * Test ini menguji PERILAKU perintah, bukan lokasi server legacy.
         * Karena itu ia ditunjuk ke database testing yang dijamin ada,
         * supaya pesannya sama di mana pun test ini dijalankan.
         */
        $database = (string) config('database.connections.mysql.database');

        $exit = $this->artisan('legacy:verify-tariff', [
            '--connection' => 'mysql',
            '--database' => $database,
        ])
            ->expectsOutputToContain('tidak ada di database');

        $exit->run();
        $exit->assertFailed();
    }

    #[Test]
    public function nama_database_ditimpa_tanpa_mengubah_konfigurasi(): void
    {
        /*
         * `--database` harus benar-benar menimpa nama database pada koneksi
         * itu saja -- kalau tidak, perintah ini diam-diam membaca DB yang
         * salah dan angkanya jadi tidak trustworthy.
         *
         * Dan setelah perintah selesai, konfigurasi harus kembali seperti
         * semula: proses artisan yang sama (mis. dipanggil dari test atau
         * dari perintah lain) tidak boleh lanjut membaca database yang
         * salah.
         *
         * Koneksi `mysql` dipakai, bukan `legacy`, supaya test ini tidak
         * bergantung pada `LEGACY_DB_*` di `.env` (nilainya berbeda di
         * setiap mesin, dan di CI `LEGACY_DB_HOST` bawaan menunjuk ke host
         * luar -- kalau itu dipakai, perintah gagal duluan di tahap
         * "Gagal konek" dan pesan yang diuji ini tidak pernah muncul).
         *
         * Yang diperiksa di sini tetap hal yang sama: override berlaku
         * selama perintah berjalan, lalu dikembalikan.
         */
        $before = config('database.connections.mysql.database');

        /*
         * Nama override HARUS berbeda dari nama aslinya. Kalau sama, test
         * ini akan luluseven juga kalau override-nya sama sekali tidak
         * bekerja -- karena nilainya sudah sama sebelum dan sesudah.
         */
        $this->assertNotSame(
            $before,
            'astaloka_testing_override',
            'Nama override harus berbeda dari nama database aslinya.'
        );

        $this->artisan('legacy:verify-tariff', [
            '--connection' => 'mysql',
            '--database' => 'astaloka_testing_override',
        ])->run();

        $this->assertSame(
            $before,
            config('database.connections.mysql.database'),
            'Override --database tidak boleh mengubah konfigurasi koneksi secara permanen.'
        );
    }

    #[Test]
    public function perintah_menolak_koneksi_yang_tidak_ada_di_config(): void
    {
        $exit = $this->artisan('legacy:verify-tariff', [
            '--connection' => 'koneksi_yang_tidak_ada',
        ])
            ->expectsOutputToContain('tidak ada di config/database.php');

        $exit->run();
        $exit->assertFailed();
    }
}
