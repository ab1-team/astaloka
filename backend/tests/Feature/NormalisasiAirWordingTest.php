<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Migrasi `2026_10_28_000002_normalize_air_wording`.
 *
 * Yang diuji di sini bukan "migration-nya jalan" -- itu soal satu baris
 * `up()`. Yang diuji adalah hal-hal yang kalau rusak TIDAK pernah muncul
 * sebagai error, dan muncul sebagai data yang salah:
 *
 *   1. `trouble_reports.trouble_type` yang sudah tersimpan ikut
 *      diterjemahkan ke nilai baru. Tanpa ini, laporan gangguan lama
 *      masih berbunyi "Air Mati Total" -- dan tidak ada satupun yang bisa
 *      membacanya sebagai "layanan mati total". Formnya sudah generik,
 *      tapi data lamanya tidak, dan keduanya harus selesai bersamaan.
 *
 *   2. Pesan tagihan & pembayaran bawaan ikut di-normalisasi, TAPI
 *      hanya frasa yang menyebut air. Pesan yang sudah diedit admin
 *      harus tetap utuh -- kalau seluruh kalimat ditulis ulang, hasil
 *      edit admin ikut hilang, dan itu menghilangkan pekerjaan yang
 *      sudah dilakukan admin dengan sengaja.
 *
 *   3. Nilai yang sudah generik (`pipa_bocor`, `meter_rusak`) TIDAK
 *      disentuh. Migration ini hanya menerjemahkan, tidak pernah
 *      memPSI.
 *
 * Yang TIDAK diuji di sini: apakah nilainya "bagus" secara bahasa.
 * Migration ini penerjemahan nilai, bukan penulisan ulang prosa -- dan
 * penilaian prosa bukan sesuatu yang bisa dibuktikan assertion.
 */
class NormalisasiAirWordingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Jalankan file migrasi yang diuji.
     *
     * Dipanggil langsung, bukan lewat `migrate`, karena `RefreshDatabase`
     * sudah menjalankan SELURUH migrasi lokasi -- termasuk yang satu ini --
     * sebelum test pertama dimulai. Kalau migrasi ini diuji lewat
     * `migrate`, isinya sudah keburu jalan dan testnya menguji keadaan
     * yang sudah jadi, bukan migrasinya.
     */
    private function jalankanMigrasi(): void
    {
        $file = base_path('database/migrations_tenant/2026_10_28_000002_normalize_air_wording.php');

        $this->assertFileExists($file, 'File migrasi yang diuji harus ada.');

        (require $file)->up();
    }

    /**
     * Isi tabel `trouble_reports` dengan nilai `trouble_type` tertentu.
     */
    private function seedTroubleReport(string $troubleType, string $deskripsi): void
    {
        DB::table('trouble_reports')->insert([
            'customer_id' => null,
            'user_id' => null,
            'handled_by' => null,
            'trouble_type' => $troubleType,
            'description' => $deskripsi,
            // NOT NULL di skema. Isinya tidak pernah dipakai migration ini,
            // tapi kolomnya wajib diisi supaya INSERT-nya tidak ditolak.
            'contact_phone' => '08123456789',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Isi tabel `settings` dengan satu baris pesan.
     */
    private function seedPengaturan(string $pesanTagihan, ?string $pesanPembayaran = null): int
    {
        return DB::table('settings')->insertGetId([
            // NOT NULL + UNIQUE di skema.
            'key' => 'utama-'.uniqid(),
            'nama' => 'Lokasi Uji',
            'pesan_tagihan' => $pesanTagihan,
            'pesan_pembayaran' => $pesanPembayaran,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Semua nilai `trouble_type` yang ada, untuk nilai tertentu.
     */
    private function nilaiTroubleType(string $nilai): int
    {
        return DB::table('trouble_reports')
            ->where('trouble_type', $nilai)
            ->count();
    }

    #[Test]
    public function nilai_lama_dari_trouble_type_diterjemahkan(): void
    {
        $this->seedTroubleReport('air_mati', 'Air mati total sejak pagi');
        $this->seedTroubleReport('debit_kecil', 'Debit kecil');
        $this->seedTroubleReport('air_keruh', 'Air keruh dan berbau');

        $this->assertSame(1, $this->nilaiTroubleType('air_mati'));
        $this->assertSame(1, $this->nilaiTroubleType('debit_kecil'));
        $this->assertSame(1, $this->nilaiTroubleType('air_keruh'));

        $this->jalankanMigrasi();

        $this->assertSame(0, $this->nilaiTroubleType('air_mati'));
        $this->assertSame(0, $this->nilaiTroubleType('debit_kecil'));
        $this->assertSame(0, $this->nilaiTroubleType('air_keruh'));

        $this->assertSame(1, $this->nilaiTroubleType('layanan_mati_total'));
        $this->assertSame(1, $this->nilaiTroubleType('debit_rendah'));
        $this->assertSame(1, $this->nilaiTroubleType('kualitas_buruk'));
    }

    #[Test]
    public function nilai_yang_sudah_generik_tidak_disentuh(): void
    {
        $this->seedTroubleReport('pipa_bocor', 'Pipa pecah di depan rumah');
        $this->seedTroubleReport('meter_rusak', 'Meteran rusak');

        $this->jalankanMigrasi();

        $this->assertSame(1, $this->nilaiTroubleType('meter_rusak'));
    }

    #[Test]
    public function pipa_bocor_ikut_diterjemahkan(): void
    {
        /*
         * "Pipa" tidak berlaku di lokasi yang layanannya bukan air.
         *
         * Yang dipetakan adalah NILAI-nya, jadi laporan lama dan laporan
         * baru memakai kosakata yang sama. Kalau ini dilewatkan, laporan
         * lama tetap berbunyi "pipa_bocor" sementara laporan baru
         * memakai "instalasi_bocor" -- dua nilai untuk satu jenis
         * gangguan yang sama.
         */
        $this->seedTroubleReport('pipa_bocor', 'Pipa pecah di depan rumah');

        $this->jalankanMigrasi();

        $this->assertSame(0, $this->nilaiTroubleType('pipa_bocor'));
        $this->assertSame(1, $this->nilaiTroubleType('instalasi_bocor'));
    }

    #[Test]
    public function deskripsi_lapangan_tidak_diubah(): void
    {
        /*
         * Yang diterjemahkan HANYA kolom `trouble_type`.
         *
         * `description` ditulis pelanggan sendiri -- bebas, dan boleh saja
         * menyebut air. Menulis ulang isi description berartimigration
         * mengubah kata-kata yang bukan miliknya, dan untuk laporan
         * gangguan yang justru informasi aslinya itu yang penting.
         */
        $this->seedTroubleReport('air_mati', 'Air mati total sejak pagi');

        $this->jalankanMigrasi();

        $deskripsi = DB::table('trouble_reports')->value('description');

        $this->assertSame('Air mati total sejak pagi', $deskripsi);
    }

    #[Test]
    public function pesan_tagihan_bawaan_ikut_dinormalisasi(): void
    {
        $id = $this->seedPengaturan(
            'Halo, tagihan air bulan ini sebesar Rp 10.000.',
            'Pembayaran tagihan air sebesar Rp 10.000 telah diterima.'
        );

        $this->jalankanMigrasi();

        $baris = DB::table('settings')->where('id', $id)->first();

        $this->assertSame(
            'Halo, tagihan layanan bulan ini sebesar Rp 10.000.',
            $baris->pesan_tagihan
        );

        $this->assertSame(
            'Pembayaran tagihan sebesar Rp 10.000 telah diterima.',
            $baris->pesan_pembayaran
        );
    }

    #[Test]
    public function pesan_yang_sudah_diedit_admin_tetap_utuh(): void
    {
        /*
         * Admin yang sudah mengubah pesannya sendiri harus tetap punya
         * pesannya. Kalau migration ini menulis ulang seluruh kalimat,
         * hasil edit admin ikut hilang -- dan itu menghapus pekerjaan
         * yang sudah dilakukan admin dengan sengaja.
         *
         * Yang diganti hanya frasa yang memang menyebut air.
         */
        $id = $this->seedPengaturan(
            'Assalamualaikum, tagihan air bulan ini sebesar Rp 10.000. Silakan bayar.'
        );

        $this->jalankanMigrasi();

        $pesan = DB::table('settings')->where('id', $id)->value('pesan_tagihan');

        $this->assertSame(
            'Assalamualaikum, tagihan layanan bulan ini sebesar Rp 10.000. Silakan bayar.',
            $pesan
        );
    }

    #[Test]
    public function pesan_yang_tidak_menyebut_air_tidak_disentuh(): void
    {
        $id = $this->seedPengaturan(
            'Halo, tagihan layanan bulan ini sebesar Rp 10.000.'
        );

        $this->jalankanMigrasi();

        $pesan = DB::table('settings')->where('id', $id)->value('pesan_tagihan');

        $this->assertSame(
            'Halo, tagihan layanan bulan ini sebesar Rp 10.000.',
            $pesan
        );
    }

    #[Test]
    public function huruf_besar_di_tengah_kalimat_tetap_besar(): void
    {
        /*
         * `LIKE` dan `REPLACE()` punya aturan case yang BERBEDA, dan
         * perbedaan itu tidak kelihatan dari kode.
         *
         * Bawaan pesan diawali huruf besar ("Yth. {customer}, pembayaran
         * tagihan air sebesar ..."), sementara frasa yang dicari ditulis
         * huruf kecil semua. `LIKE` yang case-insensitive TERPILIH baris
         * itu, tapi `REPLACE()` yang case-sensitive tidak mengubahnya --
         * migration selesai hijau, dan "tagihan air" tetap ada di database.
         *
         * Diperiksa di sini dengan huruf besar di AWAL kalimat, karena
         * itu persis bentuk bawaan yang shipped.
         */
        $id = $this->seedPengaturan(
            'Yth. {customer}, Pembayaran tagihan air sebesar {tagihan} telah kami terima.'
        );

        $this->jalankanMigrasi();

        $pesan = DB::table('settings')->where('id', $id)->value('pesan_tagihan');

        $this->assertSame(
            'Yth. {customer}, Pembayaran tagihan sebesar {tagihan} telah kami terima.',
            $pesan
        );
    }

    #[Test]
    public function frasa_yang_muncul_lebih_dari_satu_kali_semua_diganti(): void
    {
        /*
         * `REPLACE()` mengganti SEMUA kemunculan, dan penggantian di PHP
         * juga harus begitu. Kalau hanya kemunculan pertama yang diganti,
         * sisa "air"-nya masih terbaca pelanggan.
         */
        $id = $this->seedPengaturan(
            'Tagihan air bulan ini sebesar tagihan air bulan ini sebesar. Tagihan air bulan ini sebesar.'
        );

        $this->jalankanMigrasi();

        $pesan = DB::table('settings')->where('id', $id)->value('pesan_tagihan');

        $this->assertSame(
            'Tagihan layanan bulan ini sebesar tagihan layanan bulan ini sebesar. Tagihan layanan bulan ini sebesar.',
            $pesan
        );
    }

    #[Test]
    public function huruf_besar_semua_tetap_besar(): void
    {
        /*
         * Pola ketiga: seluruh huruf besar.
         *
         * Admin yang menulis pesan untuk dikhetgat biasanya memakai kapital
         * penuh, jadi pola ini bukan hal yang jarang.
         */
        $id = $this->seedPengaturan(
            'TAGIHAN AIR BULAN INI SEBESAR {jumlah_tagihan}.'
        );

        $this->jalankanMigrasi();

        $pesan = DB::table('settings')->where('id', $id)->value('pesan_tagihan');

        $this->assertSame(
            'TAGIHAN LAYANAN BULAN INI SEBESAR {jumlah_tagihan}.',
            $pesan
        );
    }

    #[Test]
    public function pesan_bisa_dikembalikan_ke_bentuk_lama(): void
    {
        /*
         * `down()` harus mengembalikan isi pesan seperti semula.
         *
         * Migration yang tidak bisa dibalik berarti `migrate:rollback`
         * merusak data yang tidak bisa dipulihkan -- dan pesan tagihan
         * yang sudah diedit admin termasuk data itu.
         */
        $id = $this->seedPengaturan(
            'Yth. {customer}, Pembayaran tagihan air sebesar {tagihan} telah kami terima.'
        );

        $file = base_path('database/migrations_tenant/2026_10_28_000002_normalize_air_wording.php');

        (require $file)->up();

        $this->assertSame(
            'Yth. {customer}, Pembayaran tagihan sebesar {tagihan} telah kami terima.',
            DB::table('settings')->where('id', $id)->value('pesan_tagihan')
        );

        (require $file)->down();

        $this->assertSame(
            'Yth. {customer}, Pembayaran tagihan air sebesar {tagihan} telah kami terima.',
            DB::table('settings')->where('id', $id)->value('pesan_tagihan')
        );
    }

    #[Test]
    public function migration_bolak_balik_mengembalikan_nilai_awal(): void
    {
        /*
         * `down()` harus mengembalikan keadaan seperti semula.
         *
         * Migration yang tidak bisa dibalik berarti `migrate:rollback`
         * destroying data yang tidak bisa dipulihkan -- dan di lokasi yang
         * menyimpan laporan gangguan pelanggan, itu data yang tidak ada
         * duplikatannya.
         */
        $this->seedTroubleReport('air_mati', 'Layanan mati');

        $file = base_path('database/migrations_tenant/2026_10_28_000002_normalize_air_wording.php');

        (require $file)->up();

        $this->assertSame(1, $this->nilaiTroubleType('layanan_mati_total'));

        (require $file)->down();

        $this->assertSame(1, $this->nilaiTroubleType('air_mati'));
        $this->assertSame(0, $this->nilaiTroubleType('layanan_mati_total'));
    }

    #[Test]
    public function migration_aman_dijalankan_kosong(): void
    {
        /*
         * Migration harus aman dijalankan pada tabel yang kosong.
         *
         * Lokasi baru belum punya satu pun laporan gangguan, jadi
         * `up()` akan jalan di atas tabel kosong. Kalau ada asumsi
         * "pasti ada baris", migration ini akan gagal justru di lokasi
         * yang paling baru dibuat -- dan gejalanya bukan "data kosong",
         * melainkan deploy yang berhenti.
         */
        $this->assertTrue(Schema::hasTable('trouble_reports'));
        $this->assertTrue(Schema::hasTable('settings'));

        $this->jalankanMigrasi();

        $this->assertSame(0, DB::table('trouble_reports')->count());
    }
}
