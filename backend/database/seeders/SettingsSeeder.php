<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('settings')->updateOrInsert(
            ['id' => 1],
            [
                'key' => 'sop',
                'value' => '',
                'nama' => 'ASTALOKA Tirto Mulo',
                'alamat' => 'Desa Sonosari, Kecamatan Karangmalang, Kabupaten Sragen',
                'email' => 'admin@tirtomulo.desa.id',
                'telepon' => '08123456789',
                'domain' => 'https://tirtomulo.desa.id',
                'status_pembayaran' => true,
                /*
                 * DUA kolom, DUA fungsi:
                 *
                 * `toleransi_tunggakan` = TANGGAL akhir bayar / jatuh tempo.
                 * `batas_tagihan` = JUMLAH BULAN tunggakan sebelum
                 * sambungan pelanggan diputus.
                 *
                 * Nilainya hanya titik awal lokasi baru. Setelah itu admin
                 * mengubahnya lewat halaman SOP, dan seluruh sistem membaca
                 * dari kolom yang sama.
                 */
                'toleransi_tunggakan' => 10,
                'batas_tagihan' => 3,
                'logo' => null,
                'pesan_tagihan' => 'Yth. {customer}, tagihan layanan bulan ini sebesar {jumlah_tagihan}. Mohon segera melakukan pembayaran sebelum jatuh tempo. Terima kasih.',
                'pesan_pembayaran' => 'Yth. {customer}, pembayaran tagihan sebesar {tagihan} telah kami terima. Terima kasih.',
                'updated_at' => now(),
            ]
        );

        // Hapus baris duplikat dari seeder lama (key-value lama)
        DB::table('settings')->where('id', '!=', 1)->delete();
    }
}
