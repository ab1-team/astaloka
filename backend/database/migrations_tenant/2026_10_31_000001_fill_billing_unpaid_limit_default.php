<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Isi batas tunggakan (BULAN) untuk lokasi yang belum punya nilainya.
 *
 * LATAR BELAKANG
 * --------------
 *
 * Kolom `settings.billing_unpaid_limit` sudah ada sejak
 * `2026_10_20_000001_add_billing_unpaid_limit_to_settings_table.php` --
 * nullable, tanpa default -- tapi sempat DIBATALKAN: tidak ada kode yang
 * membacanya maupun menulisnya, dan tidak ada input di halaman SOP.
 *
 * Sekarang kolom itu dipakai kembali, dengan satuan yang JELAS: JUMLAH
 * BULAN tunggakan yang masih ditoleransi sebelum sambungan pelanggan
 * diputus (suspended). Dipisah dari `toleransi_tunggakan` karena satuan
 * keduanya berbeda: satu tanggal dalam bulan, satu jumlah bulan.
 *
 * YANG TIDAK BERUBAH
 * -----------------
 *
 *   - `batas_tagihan` -- TANGGAL jatuh tempo. Nilainya di database tetap
 *     apa adanya, jadi tagihan yang sudah ada TIDAK jatuh temponya
 *     berubah sama sekali.
 *   - `toleransi_tunggakan` -- TANGGAL generate piutang. Tetap apa adanya.
 *
 * Yang ditambahkan hanya nilai batas tunggakan, yang tadinya kosong.
 *
 * MENGAPA DIISI 3
 * ---------------
 *
 * Kolomnya nullable tanpa default, jadi lokasi yang belum pernah menyimpan
 * SOP punya `NULL` di sana. `NULL` dibaca sebagai 3 oleh
 * `BillingTolerance::unpaidBillLimit()` -- jadi behavior-nya sudah sama
 * sejak kode berubah, dan migration ini cuma membuat angka yang terlihat
 * di halaman SOP sama dengan angka yang dipakai sistem.
 *
 * Nilai 3 diambil dari `SettingsSeeder` dan sama dengan bawaan di
 * aplikasi lama (`siap_mapan`: `batas_tagihan` = 3 bulan).
 *
 * IDEMPOTENT
 * ----------
 *
 * Hanya baris yang nilainya `NULL` yang diisi. Baris yang sudah punya
 * angka -- mis. diisi admin lewat halaman SOP -- tidak disentuh, jadi
 * migration ini aman dijalankan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasColumn('settings', 'billing_unpaid_limit')) {
            return;
        }

        DB::table('settings')
            ->whereNull('billing_unpaid_limit')
            ->update([
                'billing_unpaid_limit' => 3,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasColumn('settings', 'billing_unpaid_limit')) {
            return;
        }

        /*
         * Dikembalikan ke NULL -- bukan ke angka lain.
         *
         * Kolomnya nullable sejak awal, jadi NULL adalah kondisi yang
         * valid dan dibaca sebagai bawaan (3). Mengembalikan ke angka
         * tertentu akan memaksa semua lokasi punya batas yang sama,
         * padahal nilai aslinya memang admin yang menentukan.
         */
        DB::table('settings')
            ->where('billing_unpaid_limit', 3)
            ->update([
                'billing_unpaid_limit' => null,
                'updated_at' => now(),
            ]);
    }
};
