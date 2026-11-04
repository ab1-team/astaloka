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
                 * SATU ANGKA TANGGAL untuk semua aturan berbasis tanggal.
                 *
                 * `batas_tagihan` = TANGGAL jatuh tempo (dan, setelah
                 * penggabungan dua kolom, juga tanggal generate piutang,
                 * ambang piutang, serta ambang advance payment).
                 *
                 * `toleransi_tunggakan` = SALINAN dari angka yang sama.
                 * Kolomnya tidak dihapus -- masih dilacak importer legacy
                 * dan dipakai `BillingTolerance` -- tapi isinya selalu
                 * sama dengan `batas_tagihan`, jadi tidak ada lagi dua
                 * tanggal yang bisa saling bertentangan.
                 *
                 * Angka 10 diambil dari `batas_tagihan` bawaan lama, jadi
                 * tagihan yang sudah ada tidak bergeser.
                 */
                'batas_tagihan' => 10,
                'toleransi_tunggakan' => 10,
                /*
                 * Batas tunggakan dalam BULAN sebelum sambungan diputus.
                 *
                 * Satuannya berbeda dari dua di atas: yang ini JUMLAH BULAN,
                 * sedangkan `batas_tagihan`/`toleransi_tunggakan` adalah
                 * TANGGAL dalam bulan.
                 *
                 * Diisi eksplisit (bukan dibiarkan NULL) supaya angka yang
                 * tampil di halaman SOP langsung terisi, dan supaya semua
                 * lokasi punya titik awal yang sama.
                 */
                'billing_unpaid_limit' => 3,
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
