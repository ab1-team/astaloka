<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apakah kamera meteran boleh langsung terbuka saat popup input pemakaian dibuka.
 *
 * Latar belakang
 * --------------
 *
 * Popup "Input Pemakaian" (`inputMeterModal.vue`) dulu selalu membuka kamera
 * scan begitu popup-nya muncul. padahal yang WAJIB diisi cuma angka meter
 * akhir -- foto meteran dan hasil scan OCR sama-sama opsional.
 *
 * Akibatnya di lapangan tiap pencatatan meter harus:
 *
 *   1. menunggu popup selesai memuat kamera,
 *   2. membuang waktu mengarahkan kamera ke meteran, dan
 *   3. menutup kamera yang tidak pernah dipakai.
 *
 * Untuk pelanggan yang meter-nya sudah dibaca manual, seluruh ritual itu
 * murni menambah waktu tanpa menambah data.
 *
 * Yang dilakukan
 * --------------
 *
 * Kolom baru boolean, TANPA default. Nilai kosong (NULL) berarti "kamera TIDAK
 * dibuka otomatis" -- jadi lokasi yang sudah ada tidak ikut berubah saat
 * migrasi ini jalan. Perilaku mereka (kamera terbuka otomatis) baru berhenti
 * setelah admin consciously mengaktifkan setting ini lewat halaman SOP.
 *
 * Kenapa `nullable`, bukan `default(false)`
 * -----------------------------------------
 *
 * Kolom boolean dengan default `false` yang di-cast ke `boolean` akan mengubah
 * `NULL` jadi `false` begitu barisnya dibaca -- jadi `NULL` (belum pernah diisi)
 * dan `false` (sengaja dimatikan) jadi tidak bisa dibedakan. Yang dibutuhkan
 * justru keduanya berbeda: kosong berarti "pakai perilaku bawaan", dan
 * `false` berarti "admin sengaja mematinekan".
 *
 * Yang TIDAK diubah
 * -----------------
 *
 * Foto meteran tetap opsional di backend (`MeterReadingController::store`),
 * dan tombol "Scan Meteran Sekarang" tetap bisa dipakai kapan saja. Setting
 * ini cuma mengatur KAPAN kamera dibuka otomatis -- bukan apakah fitur scan
 * boleh dipakai.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'auto_open_meter_camera')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('auto_open_meter_camera')
                ->nullable()
                ->after('billing_unpaid_limit')
                ->comment('Kamera meteran langsung terbuka saat popup input pemakaian? NULL = tidak (bawaan).');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('settings', 'auto_open_meter_camera')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('auto_open_meter_camera');
        });
    }
};
