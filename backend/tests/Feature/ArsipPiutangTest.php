<?php

namespace Tests\Feature;

use App\Models\MonthlyBill;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Arsip Piutang: apa yang boleh masuk daftarnya, dan apa yang tidak.
 *
 * Endpoint ini menentukan ANGKA PIUTANG yang dilihat admin -- jadi
 * definisi "piutang" yang dipakai di sini adalah hal yang paling
 * susceptible untuk salah.
 *
 * SUMBER KEBENARAN: JURNAL, BUKAN `penalty_amount > 0`
 * ------------------------------------------------------
 *
 * Proxy lama (`penalty_amount > 0`) salah dua arah:
 *
 *   1. Tagihan menunggak yang dendanya 0 disembunyikan, padahal piutang
 *     -nya sudah membebani akun 1.1.03.01.
 *   2. Tagihan yang dendanya > 0 tapi belum pernah dijurnal ikut muncul,
 *      padahal belum membebani piutang sama sekali.
 *
 * Test di sini mengunci kedua arah itu -- bukan hanya "ada isinya", tapi
 * juga "yang salah tidak ikut".
 *
 * CATATAN MULTI-TENANT
 * --------------------
 * Semua query lewat model Eloquent, jadi otomatis memakai koneksi tenant
 * yang sedang aktif (database LOKASI).
 */
class ArsipPiutangTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Kunci waktu ke tanggal yang PASTI di dalam rentang generate 1-31.
     *
     * Gate generate-nya `===`: tanggal generate harus PERSIS sama dengan
     * hari ini. Kalau waktu tidak dikunci, test ini hanya benar kalau
     * dijalankan tepat pada tanggal generate -- dan diam-diam gagal pada
     * tanggal lain, dengan pesan yang tidak menyebut tanggal sama sekali.
     *
     * 15 Oktober 2026 dipakai karena comfortably di tengah bulan: ada di
     * dalam rentang 1-31 dan tidak pernah melewati batas akhir bulan.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Arsip',
            'email' => 'arsip-'.substr(uniqid('', true), -6).'@pdam.test',
            'password' => Hash::make('rahasia123'),
            'role' => 'admin',
        ]);
    }

    /**
     * SOP dengan tanggal generate = HARI INI.
     *
     * `BillingTolerance::generateDay()` menerima 1-31, jadi memakai
     * "hari ini" membuat hasilnya sama terlepas dari kapan pun test ini
     * dijalankan.
     */
    private function setSOP(): void
    {
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => max(1, min(31, (int) now()->format('d'))),
            'toleransi_tunggakan' => max(1, min(31, (int) now()->format('d'))),
        ]);
    }

    /**
     * Satu pelanggan + satu tagihan menunggak.
     *
     * `$tagihan` dikembalikan supaya test bisa menulis jurnal piutang
     * untuk tagihan itu sendiri.
     */
    private function seedTagihan(float $denda = 0, string $dueDate = '2026-09-01'): MonthlyBill
    {
        $admin = $this->admin();

        $packageId = DB::table('installation_packages')->insertGetId([
            'name' => 'Paket Arsip',
            'installation_fee' => 0,
            'monthly_abodemen' => 10_000,
            'late_penalty' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = DB::table('installation_tickets')->insertGetId([
            'package_id' => $packageId,
            'applicant_name' => 'Budi Santoso',
            'nik' => '3321010101900002',
            'order_date' => now()->toDateString(),
            'address' => 'Alamat Arsip',
            'lat' => -7.1234567,
            'lng' => 110.1234567,
            'status' => 'completed',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pelanggan = User::create([
            'name' => 'Budi Santoso',
            'email' => 'pelanggan-'.substr(uniqid('', true), -6).'@pdam.test',
            'password' => Hash::make('rahasia123'),
            'role' => 'pelanggan',
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'ticket_id' => $ticketId,
            'user_id' => $pelanggan->id,
            'customer_code' => '7350',
            'initial_meter_reading' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return MonthlyBill::create([
            'customer_id' => $customerId,
            'billing_period_year' => 2026,
            'billing_period_month' => 9,
            'meter_reading_start' => 0,
            'meter_reading_end' => 10,
            'usage_m3' => 10,
            'usage_charge' => 50_000,
            'abodemen' => 10_000,
            'penalty_amount' => $denda,
            'total_amount' => 60_000 + $denda,
            'status' => 'unpaid',
            'due_date' => $dueDate,
        ]);
    }

    /**
     * Jurnal piutang untuk satu tagihan -- bentuknya sama dengan yang
     * ditulis `GenerateOverdueTransactions`.
     *
     * DiSOFT-DELETE kalau `$softDelete` true: jurnal yang sudah di-soft-delete
     * tidak lagi menyumbang saldo ke akun piutang, jadi tidak boleh
     * dihitung sebagai piutang tercatat.
     */
    private function seedJurnalPiutang(
        MonthlyBill $bill,
        string $tanggal = '2026-10-01',
        float $saldo = 60_000,
        bool $softDelete = false
    ): void {
        $id = ((int) DB::table('transactions')->max('id')) + 1;

        DB::table('transactions')->insert([
            'id' => $id,
            'tgl_transaksi' => $tanggal,
            'account_debet' => '1.1.03.01',
            'account_kredit' => '4.1.01.02',
            'reverence_type' => 'overdue_bill',
            'reverence_id' => $bill->id,
            'keterangan_transaksi' => 'Piutang Abodemen bulan September 2026',
            'relasi' => '7350',
            'saldo' => $saldo,
            'id_user' => $this->admin()->id,
            'urutan' => $id,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $softDelete ? now() : null,
        ]);
    }

    /**
     * Panggil endpoint arsip sebagai admin.
     */
    private function arsip(array $query = []): TestResponse
    {
        Sanctum::actingAs($this->admin());

        return $this->getJson('/api/piutang/arsip'.($query ? '?'.http_build_query($query) : ''));
    }

    /**
     * Tagihan menunggak yang SUDAH dibukukan jadi piutang harus muncul,
     * dengan nominal piutangnya sesuai jurnal -- bukan sesuai
     * `penalty_amount`.
     */
    #[Test]
    public function tagihan_yang_sudah_dibukukan_muncul_dengan_piutang_tercatat(): void
    {
        $this->setSOP();

        $bill = $this->seedTagihan();
        $this->seedJurnalPiutang($bill, '2026-10-01', 60_000);

        $res = $this->arsip();

        $res->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, $res->json('meta.total'), 'Tagihan berpiutang harus masuk arsip.');

        $baris = $res->json('data.0');

        $this->assertSame('Tercatat', $baris['status'], 'Status harus "Tercatat".');
        $this->assertEquals(60_000, $baris['piutangTercatat']);
        $this->assertSame('2026-10-01', $baris['tanggalPencatatan']);
        $this->assertSame('September 2026', $baris['periodeLabel']);
    }

    /**
     * TAGIHAN MENUNGGAK YANG DENDANYA 0 TETAP HARUS MUNCUL.
     *
     * Ini persis kasus yang hilang oleh proxy lama `penalty_amount > 0`:
     * tagihannya menunggak, piutangnya sudah membebani akun 1.1.03.01, tapi
     * dendanya nol -- jadi proxy lama tidak pernah menunjukinya.
     */
    #[Test]
    public function tagihan_menunggak_dengan_denda_nol_tetap_muncul(): void
    {
        $this->setSOP();

        $bill = $this->seedTagihan(denda: 0);
        $this->seedJurnalPiutang($bill, '2026-10-01', 60_000);

        $res = $this->arsip();

        $res->assertOk();

        $this->assertSame(1, $res->json('meta.total'), 'Denda nol tidak boleh menyembunyikan piutang.');
        $this->assertEquals(60_000, $res->json('data.0.piutangTercatat'));
    }

    /**
     * Tagihan yang BELUM PERNAH dijurnal tidak boleh dihitung sebagai
     * piutang tercatat -- tapi tetap ditampilkan, dengan status "Menunggu
     * Generate".
     *
     * Disembunyikannya akan membuat orang mengira tidak ada tunggakan,
     * padahal tagihannya memang menunggak.
     */
    #[Test]
    public function tagihan_belum_dijurnal_tetap_ditampilkan_sebagai_menunggu_generate(): void
    {
        $this->setSOP();

        $this->seedTagihan(denda: 5_000);

        $res = $this->arsip();

        $res->assertOk();

        $this->assertSame(1, $res->json('meta.total'));

        $baris = $res->json('data.0');

        $this->assertSame('Menunggu Generate', $baris['status']);
        $this->assertEquals(0, $baris['piutangTercatat'], 'Belum ada jurnal, jadi piutang tercatat nol.');
        $this->assertNull($baris['tanggalPencatatan']);
        $this->assertSame(1, $res->json('meta.periode.jml_menunggu'));
        $this->assertSame(0, $res->json('meta.periode.jml_tercatat'));
    }

    /**
     * Jurnal yang sudah di-SOFT-DELETE tidak lagi menyumbang saldo ke
     * akun piutang, jadi tidak boleh dihitung.
     *
     * Menghitungnya akan menampilkan nominal piutang yang tidak ada di
     * pembukuan -- angka yang lebih besar dari kenyataannya.
     */
    #[Test]
    public function jurnal_piutang_yang_soft_deleted_tidak_dihitung(): void
    {
        $this->setSOP();

        $bill = $this->seedTagihan();
        $this->seedJurnalPiutang($bill, '2026-10-01', 60_000, softDelete: true);

        $res = $this->arsip();

        $res->assertOk();

        $this->assertEquals(0, $res->json('data.0.piutangTercatat'));
        $this->assertSame('Menunggu Generate', $res->json('data.0.status'));
    }

    /**
     * Filter bulan memakai bulan PENCATATAN piutang (tanggal jurnal),
     * bukan bulan periode tagihan.
     *
     * Ini yang membuat filter bisa dipakai: tagihan periode September yang
     * baru jadi piutang saat generate Oktober harus muncul saat filter
     * "Oktober" dipilih, bukan saat "September" dipilih.
     */
    #[Test]
    public function filter_bulan_mengikuti_bulan_pencatatan_piutang(): void
    {
        $this->setSOP();

        $bill = $this->seedTagihan();
        $this->seedJurnalPiutang($bill, '2026-10-01', 60_000);

        // Oktober = bulan pencatatan -> harus ada.
        $this->arsip(['bulan' => 10, 'tahun' => 2026])
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // September = bulan PERIODE tagihan -> tidak boleh ada.
        $this->arsip(['bulan' => 9, 'tahun' => 2026])
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    /**
     * Parameter `month`/`year` (bahasa API) dan `bulan`/`tahun` (bahasa
     * form Indonesia) harus menghasilkan hasil yang sama.
     */
    #[Test]
    public function alias_bulan_dan_tahun_menghasilkan_hasil_yang_sama(): void
    {
        $this->setSOP();

        $bill = $this->seedTagihan();
        $this->seedJurnalPiutang($bill, '2026-10-01', 60_000);

        $this->arsip(['month' => 10, 'year' => 2026])
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    /**
     * Pencarian MENGANDUNG kata kunci, bukan hanya diawali.
     *
     * `like 'keyword%'` tidak menemukan "arif" di dalam "muhammad arif" --
     * padahal itulah yang dicari orang.
     */
    #[Test]
    public function pencarian_mencari_kata_kunci_yang_terdapat_di_tengah(): void
    {
        $this->setSOP();

        $bill = $this->seedTagihan();
        $this->seedJurnalPiutang($bill, '2026-10-01', 60_000);

        $this->arsip(['search' => 'Santoso'])
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->arsip(['search' => 'Budi'])
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->arsip(['search' => 'TidakAda'])
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    /**
     * Paginasi harus konsisten: `meta.total` menghitung SELURUH baris yang
     * cocok, sementara `data` hanya berisi baris halaman itu.
     */
    #[Test]
    public function paginasi_melaporkan_total_yang_benar(): void
    {
        $this->setSOP();

        $bill = $this->seedTagihan();
        $this->seedJurnalPiutang($bill, '2026-10-01', 60_000);

        $res = $this->arsip(['per_page' => 1]);

        $res->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.last_page', 1);

        $this->assertCount(1, $res->json('data'));
    }

    /**
     * "Telat N hari" harus POSITIF untuk tanggal jatuh tempo yang sudah
     * lewat.
     *
     * Carbon 3 membuat `diffInDays` BERSIGN, jadi arah pemanggilannya
     * menentukan tanda. Kalau terbalik, `max(0, ...)` mengubah semua
     * nilai menjadi nol dan kolom "Telat N hari" selalu kosong.
     */
    #[Test]
    public function hari_menunggak_positif_untuk_jatuh_tempo_yang_sudah_lewat(): void
    {
        $this->setSOP();

        $bill = $this->seedTagihan(dueDate: '2026-10-01');
        $this->seedJurnalPiutang($bill, '2026-10-01', 60_000);

        $res = $this->arsip();

        $res->assertOk();

        // 2026-10-01 -> 2026-10-15 = 14 hari.
        $this->assertSame(14, $res->json('data.0.hariMenunggak'));
    }

    /**
     * Ringkasan "keseluruhan" harus tetap memberitahu total piutang yang
     * sedang berjalan, walau sedang difilter satu bulan.
     *
     * Kalau tidak, admin yang sedang melihat satu bulan tidak pernah tahu
     * berapa piutang total yang harus ditagih.
     */
    #[Test]
    public function ringkasan_keseluruhan_tetap_mencerminkan_seluruh_piutang(): void
    {
        $this->setSOP();

        $bill = $this->seedTagihan();
        $this->seedJurnalPiutang($bill, '2026-10-01', 60_000);

        // Filter bulan yang TIDAK berisi piutang ini.
        $res = $this->arsip(['bulan' => 1, 'tahun' => 2026]);

        $res->assertOk();

        $this->assertSame(0, $res->json('meta.periode.jml_tercatat'), 'Periode terpilih kosong.');
        $this->assertSame(1, $res->json('meta.keseluruhan.jml_tercatat'), 'Keseluruhan tetap ada.');
        $this->assertEquals(60_000, $res->json('meta.keseluruhan.total_piutang'));
    }
}
