<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallationPackage;
use App\Models\InstallationTicket;
use App\Models\MonthlyBill;
use App\Models\Setting;
use App\Models\User;
use App\Services\BillingTolerance;
use App\Services\UnpaidMonths;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pemisahan arti `toleransi_tunggakan` dari aturan yang benar-benar berbeda.
 *
 * Latar belakang
 * ---------------
 *
 * Satu kolom `settings.toleransi_tunggakan` dibaca sebagai empat hal yang
 * tidak bisa bergerak naik-turun bersama dalam satu rentang angka:
 *
 *   1. Tanggal generate piutang dalam bulan (1-31).
 *   2. Jumlah hari mundur dari hari run untuk ambang piutang.
 *   3. Tanggal dalam bulan pemakaian tagihan untuk deteksi advance payment.
 *   4. Batas tunggakan sebelum sambungan pelanggan diputus.
 *
 * Dengan nilai 6, tiga yang pertama aktif bersamaan: generate hanya jalan
 * tanggal 6, ambang piutang = hari ini - 6 hari, bayar sebelum tanggal 6
 * dianggap advance.
 *
 * Makna (4) sekarang DIPISAH ke kolom `settings.billing_unpaid_limit`,
 * dengan satuan JUMLAH BULAN. Alasannya satuan itu memang berbeda: yang
 * lain TANGGAL dalam bulan, yang ini jumlah bulan.
 *
 * Test di sini mengunci pemisahan itu -- dan mengunci bahwa
 * `toleransi_tunggakan` TIDAK lagi ikut governs batas tunggakan, sehingga
 * admin yang menggeser tanggal generate ke tanggal 20 tidak lagi diam-diam
 * mengubah batas tagihan yang boleh menunggak.
 *
 * PENGGABUNGAN DUA TANGGAL
 * ------------------------
 *
 * Makna (1) dan (3) sebenarnya SALAH SATU angka: "hari ke berapa dalam
 * bulan". Tapi implementasinya pernah baca dari dua kolom berbeda --
 * `batas_tagihan` (jatuh tempo) dan `toleransi_tunggakan` (generate) --
 * sehingga di database keduanya bisa berbeda: 27 vs 30 di lokasi demo.
 *
 * Akibatnya ada bug nyata: `advanceDayOfMonth()` memakai 30 sementara
 * `due_date` dihitung dari 27, jadi pembayaran tanggal 28-30 dianggap
 * ADVANCE padahal tagihannya belum jatuh tempo.
 *
 * Sekarang keduanya satu angka: `settings.batas_tagihan` jadi sumbernya,
 * `settings.toleransi_tunggakan` salinannya. Arahnya penting --
 * `batas_tagihan` adalah angka yang sudah TERMATERIALISASI di
 * `monthly_bills.due_date`, jadi tagihan yang sudah ada tidak bergeser.
 *
 * Test di bagian akhir class ini mengunci penggabungan itu: satu angka
 * untuk semua aturan, migration yang menyamakan kedua kolom, dan API SOP
 * yang menolak dua tanggal yang saling bertentangan.
 */
class BillingToleranceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ID pelanggan hasil `seedTagihan()`.
     *
     * Disimpan sebagai properti, bukan `static`, supaya tidak bocor antar
     * test: `RefreshDatabase` menghapus isi tabel tiap test, jadi ID dari
     * test sebelumnya tidak boleh dipakai lagi.
     */
    private ?int $customerId = null;

    /**
     * Tulis (atau timpa) SOP lokasi ini.
     *
     * Dipakai `updateOrCreate`, bukan `create`, karena beberapa test memanggilnya
     * lebih dari sekali untuk diperiksa dua nilai berbeda dalam satu test --
     * dan `settings.key` itu unik, jadi `create` kedua akan meledak.
     */
    private function setSOP(int $toleransi, ?int $batasBulan = null): void
    {
        Setting::updateOrCreate(
            ['key' => 'sop'],
            [
                'batas_tagihan' => $toleransi,
                'toleransi_tunggakan' => $toleransi,
                'billing_unpaid_limit' => $batasBulan,
            ]
        );
    }

    #[Test]
    public function tiga_arti_tanggal_membaca_satu_sumber_angka(): void
    {
        $this->setSOP(6);

        $t = app(BillingTolerance::class);

        $this->assertSame(6, $t->tanggal(), 'Nilai mentah harus sama.');
        $this->assertSame(6, $t->generateDay(), 'Tanggal generate harus sama.');
        $this->assertSame(6, $t->graceDays(), 'Ambang piutang harus sama.');
        $this->assertSame(6, $t->advanceDayOfMonth(), 'Ambang advance harus sama.');
    }

    #[Test]
    public function batas_tunggakan_dibaca_dari_kolom_bulannya_sendiri(): void
    {
        /*
         * Batas tunggakan bersatuan BULAN, jadi harus punya kolom sendiri.
         *
         * Kalau batas ini masih dibaca dari `toleransi_tunggakan`, admin
         * yang menyetel tanggal generate ke tanggal 20 ikut mengubah batas
         * tunggakan menjadi 20 -- dan yang dihitung adalah "20 bulan
         * menunggak", bukan "20 tanggal".
         */
        $this->setSOP(20, batasBulan: 3);

        $t = app(BillingTolerance::class);

        $this->assertSame(
            3,
            $t->unpaidBillLimit(),
            'Batas tunggakan harus dari billing_unpaid_limit, bukan toleransi_tunggakan.'
        );
        $this->assertSame(
            20,
            $t->generateDay(),
            'Tanggal generate tidak boleh ikut berubah oleh batas tunggakan.'
        );
    }

    #[Test]
    public function batas_tunggakan_kosong_berarti_bawaan_tiga_bulan(): void
    {
        // Kolomnya nullable tanpa default, jadi lokasi yang belum pernah
        // menyimpan SOP punya NULL di sana.
        $this->setSOP(6);

        $t = app(BillingTolerance::class);

        $this->assertSame(
            BillingTolerance::DEFAULT_UNPAID_MONTH_LIMIT,
            $t->unpaidBillLimit(),
            'Kolom kosong harus dibaca sebagai bawaan 3 bulan.'
        );
    }

    #[Test]
    public function batas_tunggakan_nol_berarti_tidak_pernah_memutus(): void
    {
        $this->setSOP(6, batasBulan: 0);

        $t = app(BillingTolerance::class);

        $this->assertSame(0, $t->unpaidBillLimit());
        $this->assertFalse(
            $t->shouldSuspend(999),
            'Nilai 0 berarti tidak pernah memutus sambungan otomatis.'
        );
    }

    #[Test]
    public function batas_tunggakan_memakai_operator_ketidak_sama(): void
    {
        $this->setSOP(6, batasBulan: 3);

        $t = app(BillingTolerance::class);

        // Perilaku lama `MeterReadingController`: `$unpaidMonths > $toleransi`.
        // Harus dipertahankan persis -- yang berubah hanya sumber angkanya
        // dan satuannya (BULAN, bukan jumlah tagihan).
        $this->assertFalse($t->shouldSuspend(3), 'Tepat sama dengan batas: belum diputus.');
        $this->assertTrue($t->shouldSuspend(4), 'Melebihi batas: diputus.');
    }

    #[Test]
    public function batas_tunggakan_tidak_ada_setting_pakai_bawaan_tiga_bulan(): void
    {
        // Tidak ada baris Setting sama sekali.
        $t = app(BillingTolerance::class);

        $this->assertSame(0, $t->tanggal());
        $this->assertSame(0, $t->generateDay());

        // Batas tunggakan punya bawaan sendiri: 3 BULAN. Jadi lokasi yang
        // belum pernah menyimpan SOP tetap punya perilaku auto-suspend
        // yang sama dengan lokasi lain -- bukan diam-diam kehilangan
        // pemutusan otomatis.
        $this->assertSame(
            BillingTolerance::DEFAULT_UNPAID_MONTH_LIMIT,
            $t->unpaidBillLimit(),
            'Tanpa SOP, batas tunggakan tetap pakai bawaan 3 bulan.'
        );

        $this->assertFalse(
            $t->shouldSuspend(3),
            'Tepat sama dengan batas bawaan: belum diputus.'
        );
        $this->assertTrue(
            $t->shouldSuspend(4),
            'Melebihi batas bawaan: diputus.'
        );
    }

    #[Test]
    public function tanggal_generate_di_luar_rentang_berarti_tidak_dijadwalkan(): void
    {
        // Nilai 0 berarti "SOP belum diisi", bukan "generate tanggal 0".
        $this->setSOP(0);
        $this->assertSame(0, app(BillingTolerance::class)->generateDay());

        // Nilai 32 tidak bisa jadi tanggal generate -- bulan tidak punya
        // tanggal 32.
        $this->setSOP(32);
        $this->assertSame(0, app(BillingTolerance::class)->generateDay());
    }

    /**
     * Tanggal 29, 30, dan 31 HARUS bisa dipakai.
     *
     * Dulu rentangnya 1-28, jadi admin yang menyetel tanggal 29 mendapat
     * `generateDay()` = 0 -- yang dibaca sebagai "SOP belum diisi" -- dan
     * generate TIDAK PERNAH jalan: tanpa error, tanpa popup, tanpa log.
     *
     * Karena 29/30/31 tidak ada di SETIAP bulan, nilainya dipanjangkan ke
     * HARI TERAKHIR bulan: 29 di Oktober, 28 di Februari.
     */
    #[Test]
    public function tanggal_29_sampai_31_bisa_dijadwalkan_dan_diperpanjang_ke_hari_terakhir_bulan(): void
    {
        // PENTING: `BillingTolerance` meng-cache `Setting` per instance, dan
        // instance di container juga bertahan selama satu test. Jadi setiap
        // nilai diuji lewat instance BARU -- kalau tidak, test diam-diam
        // memeriksa nilai yang di-set sebelumnya dan terlihat berhasil
        // padahal tidak ada yang diuji.
        $this->setSOP(29);
        $t = new BillingTolerance;

        $this->assertSame(29, $t->generateDay(), 'Tanggal 29 harus bisa dijadwalkan.');
        $this->assertSame(
            29,
            $t->effectiveGenerateDay(Carbon::parse('2026-10-29')),
            'Di Oktober, tanggal 29 berarti tanggal 29.'
        );
        $this->assertSame(
            28,
            $t->effectiveGenerateDay(Carbon::parse('2026-02-15')),
            'Di Februari, tanggal 29 berarti hari terakhir bulan (28).'
        );

        $this->setSOP(31);
        $t = new BillingTolerance;

        $this->assertSame(31, $t->generateDay(), 'Tanggal 31 harus bisa dijadwalkan.');
        $this->assertSame(
            30,
            $t->effectiveGenerateDay(Carbon::parse('2026-11-15')),
            'Di November, tanggal 31 berarti hari terakhir bulan (30).'
        );
        $this->assertSame(
            28,
            $t->effectiveGenerateDay(Carbon::parse('2026-02-15')),
            'Di Februari, tanggal 31 berarti hari terakhir bulan (28).'
        );

        // Tanggal yang ada di bulan manapun tidak bergerak.
        $this->setSOP(28);
        $t = new BillingTolerance;

        $this->assertSame(
            28,
            $t->effectiveGenerateDay(Carbon::parse('2026-02-15')),
            'Tanggal 28 tetap 28 di bulan mana pun.'
        );
    }

    #[Test]
    public function tenggang_tagihan_langganan_terpisah_dan_satuan_bulan(): void
    {
        $this->setSOP(6);

        $bulan = app(BillingTolerance::class)->invoiceGraceMonths();

        $this->assertNotSame(
            app(BillingTolerance::class)->tanggal(),
            $bulan,
            'Tenggangan langganan (BULAN) tidak boleh disamakan dengan toleransi (HARI/TANGGAL).'
        );
    }

    #[Test]
    public function tagihan_menunggak_menghormati_batas_suspend(): void
    {
        // Regresi langsung untuk `MeterReadingController::store`, yang
        // memakai `BillingTolerance::unpaidBillLimit()` dan
        // `UnpaidMonths::forCustomer()`.
        //
        // Yang diperiksa di sini adalah sumber angkanya dan hitungan
        // bulannya, bukan seluruh alur meter reading: seluruh logika
        // suspend di controller sudah ada dan tidak berubah -- hanya nilai
        // yang dibacanya.
        $this->setSOP(6, batasBulan: 3);
        $this->assertSame(3, app(BillingTolerance::class)->unpaidBillLimit());

        // Count unpaid seperti yang dibaca controller.
        $unpaid = MonthlyBill::where('status', 'unpaid')->count();

        $this->assertFalse(
            app(BillingTolerance::class)->shouldSuspend($unpaid),
            'Tidak ada tagihan unpaid: tidak ada yang diputus.'
        );
    }

    #[Test]
    public function jumlah_bulan_menunggak_dihitung_dari_tagihan_terlama(): void
    {
        // Sekarang = Oktober 2026. Tagihan tertua periode Juni 2026 yang
        // unpaid dan sudah lewat jatuh tempo -> 4 bulan menunggak.
        $now = Carbon::parse('2026-10-15');

        $this->seedTagihan(periodo: [2026, 6], due: '2026-07-26', status: 'unpaid');
        $this->seedTagihan(periodo: [2026, 7], due: '2026-08-26', status: 'unpaid');
        $this->seedTagihan(periodo: [2026, 8], due: '2026-09-26', status: 'unpaid');

        $customerId = MonthlyBill::orderBy('id')->first()->customer_id;

        $this->assertSame(
            4,
            app(UnpaidMonths::class)->forCustomer($customerId, $now),
            'Menunggak dihitung dari periode tagihan paling lama yang unpaid.'
        );
    }

    #[Test]
    public function tagihan_yang_jatuh_temponya_belum_sampai_tidak_dithitung_menunggak(): void
    {
        // Tagihan periode Oktober 2026 yang jatuh tempo 26 Oktober -- saat
        // ini tanggal 15 Oktober, jadi jatuh temponya BELUM lewat.
        //
        // Kalau ikut dihitung, setiap pelanggan dianggap menunggak begitu
        // tagihan bulan berikutnya dibuat -- persis sebelum tagihan itu
        // harus dibayar.
        $now = Carbon::parse('2026-10-15');

        $this->seedTagihan(periodo: [2026, 10], due: '2026-10-26', status: 'unpaid');

        $customerId = MonthlyBill::orderBy('id')->first()->customer_id;

        $this->assertSame(
            0,
            app(UnpaidMonths::class)->forCustomer($customerId, $now),
            'Tagihan yang belum jatuh tempo bukan tunggakan.'
        );
    }

    #[Test]
    public function tagihan_yang_sudah_dibayar_tidak_dithitung_menunggak(): void
    {
        $now = Carbon::parse('2026-10-15');

        $this->seedTagihan(periodo: [2026, 6], due: '2026-07-26', status: 'paid');
        $this->seedTagihan(periodo: [2026, 8], due: '2026-09-26', status: 'unpaid');

        $customerId = MonthlyBill::orderBy('id')->first()->customer_id;

        $this->assertSame(
            2,
            app(UnpaidMonths::class)->forCustomer($customerId, $now),
            'Tagihan lunas tidak boleh dihitung sebagai tunggakan.'
        );
    }

    /**
     * Migration tenant mengisi batas tunggakan yang kosong.
     *
     *migration-nya idempotent: baris yang sudah punya angka (mis. diisi
     * admin lewat halaman SOP) tidak boleh tertimpa.
     */
    #[Test]
    public function migration_mengisi_batas_kosong_tanpa_menimpa_yang_sudah_ada(): void
    {
        Setting::updateOrCreate(
            ['key' => 'sop'],
            [
                'batas_tagihan' => 6,
                'toleransi_tunggakan' => 6,
                'billing_unpaid_limit' => null,
            ]
        );

        Setting::updateOrCreate(
            ['key' => 'sop-kosong'],
            [
                'batas_tagihan' => 6,
                'toleransi_tunggakan' => 6,
                'billing_unpaid_limit' => null,
            ]
        );

        $migration = require base_path(
            'database/migrations_tenant/2026_10_31_000001_fill_billing_unpaid_limit_default.php'
        );

        $migration->up();

        $this->assertSame(
            3,
            Setting::where('key', 'sop')->first()->billing_unpaid_limit,
            'Batas kosong harus diisi 3 (bawaan).'
        );

        // Idempotent: jalan lagi tidak mengubah apa pun.
        $migration->up();

        $this->assertSame(
            3,
            Setting::where('key', 'sop')->first()->billing_unpaid_limit,
            'Migration harus idempotent.'
        );

        // Nilai yang sudah diset admin tidak boleh ditimpa.
        Setting::updateOrCreate(
            ['key' => 'sop'],
            ['billing_unpaid_limit' => 7]
        );

        $migration->up();

        $this->assertSame(
            7,
            Setting::where('key', 'sop')->first()->billing_unpaid_limit,
            'Nilai yang sudah diisi admin tidak boleh ditimpa migration.'
        );
    }

    // ================================================================
    // Penggabungan dua tanggal jadi satu
    // ================================================================

    /**
     * Satu angka untuk semua aturan berbasis tanggal.
     *
     * Dulu ada dua kolom terpisah: `batas_tagihan` (jatuh tempo tagihan)
     * dan `toleransi_tunggakan` (generate piutang). Keduanya TANGGAL dalam
     * bulan, jadi page SOP menampilkan dua input untuk satu satuan yang
     * sama -- dan di database keduanya bisa berisi angka BERBEDA.
     *
     * Yang diuji di sini: angka yang dipakai semua aturan (tanggal
     * generate, ambang piutang, ambang advance) adalah `batas_tagihan`,
     * dan `toleransi_tunggakan` hanyalah salinannya.
     */
    #[Test]
    public function satu_angka_tanggal_mengganti_dua_kolom(): void
    {
        Setting::updateOrCreate(
            ['key' => 'sop'],
            [
                'batas_tagihan' => 27,
                'toleransi_tunggakan' => 27,
                'billing_unpaid_limit' => 3,
            ]
        );

        $t = app(BillingTolerance::class);

        $this->assertSame(27, $t->tanggal());
        $this->assertSame(27, $t->generateDay());
        $this->assertSame(27, $t->graceDays());
        $this->assertSame(27, $t->advanceDayOfMonth());
    }

    /**
     * Bug advance payment harus hilang setelah penggabungan.
     *
     * SEBELUM penggabungan: `advanceDayOfMonth()` membaca
     * `toleransi_tunggakan` (30) sementara `due_date` dihitung dari
     * `batas_tagihan` (27). Jadi pembayaran pada tanggal 28, 29, atau 30
     * dianggap ADVANCE -- padahal tagihannya belum jatuh tempo.
     *
     * SESUDAH penggabungan: keduanya satu angka, jadi ambang advance
     * selalu sama dengan tanggal jatuh tempo.
     */
    #[Test]
    public function ambang_advance_sama_dengan_tanggal_jatuh_tempo(): void
    {
        $this->setSOP(27);

        $t = app(BillingTolerance::class);

        $this->assertSame(
            27,
            $t->advanceDayOfMonth(),
            'Ambang advance harus sama dengan tanggal jatuh tempo tagihan.'
        );

        $this->assertSame(
            app(BillingTolerance::class)->tanggal(),
            $t->advanceDayOfMonth(),
            'Ambang advance harus membaca angka yang sama dengan semua aturan lain.'
        );
    }

    /**
     * Migration menyamakan `toleransi_tunggakan` dengan `batas_tagihan`.
     *
     * Arahnya penting: yang mengikuti `batas_tagihan`, bukan sebaliknya.
     * `batas_tagihan` adalah angka yang sudah TERMATERIALISASI di
     * `monthly_bills.due_date`, jadi kalau arahnya dibalik, tagihan lama
     * (tanggal 27) dan tagihan baru (tanggal 30) akan punya jatuh tempo
     * berbeda di bulan yang sama.
     */
    #[Test]
    public function migration_menyamakan_kedua_kolom_tanggal(): void
    {
        Setting::updateOrCreate(
            ['key' => 'sop'],
            [
                'batas_tagihan' => 27,
                'toleransi_tunggakan' => 30,
                'billing_unpaid_limit' => 3,
            ]
        );

        $migration = require base_path(
            'database/migrations_tenant/2026_10_31_000002_sync_toleransi_tunggakan_with_batas_tagihan.php'
        );

        $migration->up();

        $this->assertSame(
            27,
            (int) Setting::where('key', 'sop')->first()->toleransi_tunggakan,
            'Kolom salinan harus mengikuti tanggal jatuh tempo.'
        );

        $this->assertSame(
            27,
            (int) Setting::where('key', 'sop')->first()->batas_tagihan,
            'Tanggal jatuh tempo tidak boleh berubah.'
        );

        // Idempotent: jalan lagi tidak mengubah apa pun.
        $migration->up();

        $this->assertSame(
            27,
            (int) Setting::where('key', 'sop')->first()->toleransi_tunggakan,
            'Migration harus idempotent.'
        );
    }

    /**
     * Baris yang SOP-nya belum diisi tidak boleh ikut berubah.
     *
     * `batas_tagihan` = 0 berarti "belum dijadwalkan". Migration tidak
     * boleh mengubah kolom salinan baris seperti itu -- kalau iya, nilai
     * yang tidak pernah dipilih siapa pun ikut jadi tanggal generate.
     *
     * Perilaku generate baris ini TIDAK berubah: `BillingTolerance::tanggal()`
     * memakai `toleransi_tunggakan` sebagai cadangan kalau `batas_tagihan`
     * tidak berisi tanggal yang sah. Jadi baris yang generate-nya sudah
     * jalan tetap jalan.
     */
    #[Test]
    public function migration_tidak_mengubah_baris_yang_belum_diisi(): void
    {
        Setting::updateOrCreate(
            ['key' => 'sop'],
            [
                'batas_tagihan' => 0,
                'toleransi_tunggakan' => 30,
                'billing_unpaid_limit' => 3,
            ]
        );

        $migration = require base_path(
            'database/migrations_tenant/2026_10_31_000002_sync_toleransi_tunggakan_with_batas_tagihan.php'
        );

        $migration->up();

        $this->assertSame(
            30,
            (int) Setting::where('key', 'sop')->first()->toleransi_tunggakan,
            'Baris yang belum diisi SOP tidak boleh ikut berubah.'
        );

        $this->assertSame(
            0,
            (int) Setting::where('key', 'sop')->first()->batas_tagihan,
            'Tanggal jatuh tempo yang belum diisi tidak boleh diisi diam-diam.'
        );

        // Perilaku generate tidak berubah: baris ini tetap memakai kolom
        // salinan sebagai cadangan.
        $this->assertSame(
            30,
            app(BillingTolerance::class)->generateDay(),
            'Baris yang belum diisi SOP tetap punya jadwal generate yang tidak berubah.'
        );
    }

    /**
     * API SOP menyimpan SATU angka ke DUA kolom.
     *
     * Kalau hanya satu kolom yang ditulis, dua kolom itu bisa berbeda
     * lagi -- dan itu persis masalah yang penggabungan ini diselesaikan.
     */
    #[Test]
    public function api_sop_menyimpan_satu_angka_ke_dua_kolom(): void
    {
        $admin = User::create([
            'name' => 'Admin SOP',
            'email' => 'admin-sop-'.substr(uniqid('', true), -6).'@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$admin->createToken('auth_token')->plainTextToken,
        ])->postJson('/api/settings/sop/sistem-tagihan', [
            'toleransiTunggakan' => 21,
            'batasBulanTunggakan' => 4,
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $setting = Setting::first();

        $this->assertSame(21, (int) $setting->batas_tagihan);
        $this->assertSame(21, (int) $setting->toleransi_tunggakan);
        $this->assertSame(4, (int) $setting->billing_unpaid_limit);
    }

    /**
     * Alias lama `batasTagihan` harus diterima kalau nilainya sama.
     */
    #[Test]
    public function api_sop_menerima_alias_batas_tagihan_jika_sama(): void
    {
        $admin = User::create([
            'name' => 'Admin SOP',
            'email' => 'admin-sop-'.substr(uniqid('', true), -6).'@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$admin->createToken('auth_token')->plainTextToken,
        ])->postJson('/api/settings/sop/sistem-tagihan', [
            'toleransiTunggakan' => 21,
            'batasTagihan' => 21,
            'batasBulanTunggakan' => 4,
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $setting = Setting::first();

        $this->assertSame(21, (int) $setting->batas_tagihan);
        $this->assertSame(21, (int) $setting->toleransi_tunggakan);
    }

    /**
     * Alias lama yang BERBEDA harus ditolak.
     *
     * Kalau diterima, sistem akan diam-diam memilih salah satu angka --
     * dan hasilnya lagi dua tanggal yang saling bertentangan.
     */
    #[Test]
    public function api_sop_menolak_alias_batas_tagihan_yang_berbeda(): void
    {
        $admin = User::create([
            'name' => 'Admin SOP',
            'email' => 'admin-sop-'.substr(uniqid('', true), -6).'@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$admin->createToken('auth_token')->plainTextToken,
        ])->postJson('/api/settings/sop/sistem-tagihan', [
            'toleransiTunggakan' => 21,
            'batasTagihan' => 27,
            'batasBulanTunggakan' => 4,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('batasTagihan');

        // Tidak boleh ada settings yang tersimpan sama sekali.
        $this->assertSame(
            0,
            Setting::count(),
            'Request yang ditolak tidak boleh menyimpan apa pun.'
        );
    }

    /**
     * GET /settings/sop mengirim angka yang sama di kedua field.
     *
     * Dua konsumen frontend masih membaca `batasTagihan`
     * (`ReportCetakDaftarTagihan.vue` dan `BillingForm.vue`), jadi keduanya
     * harus melihat angka yang sama dengan yang dipakai backend.
     */
    #[Test]
    public function api_sop_mengirim_angka_yang_sama_di_kedua_field(): void
    {
        $admin = User::create([
            'name' => 'Admin SOP',
            'email' => 'admin-sop-'.substr(uniqid('', true), -6).'@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        Setting::updateOrCreate(
            ['key' => 'sop'],
            [
                'batas_tagihan' => 27,
                'toleransi_tunggakan' => 27,
                'billing_unpaid_limit' => 3,
            ]
        );

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$admin->createToken('auth_token')->plainTextToken,
        ])->getJson('/api/settings/sop');

        $response->assertOk()
            ->assertJsonPath('data.sistemTagihan.batasTagihan', 27)
            ->assertJsonPath('data.sistemTagihan.toleransiTunggakan', 27)
            ->assertJsonPath('data.sistemTagihan.batasBulanTunggakan', 3);
    }

    /**
     * Satu pelanggan + satu tagihan.
     *
     * Pelanggan harus punya `ticket_id` dan `user_id` yang valid: kedua
     * kolom itu `NOT NULL` di database, jadi insert langsung ke tabel
     * `customers` akan ditolak.
     */
    private function seedTagihan(array $periodo, string $due, string $status): MonthlyBill
    {
        if ($this->customerId === null) {
            $this->customerId = $this->buatPelanggan()->id;
        }

        return MonthlyBill::create([
            'customer_id' => $this->customerId,
            'billing_period_year' => $periodo[0],
            'billing_period_month' => $periodo[1],
            'meter_reading_start' => 0,
            'meter_reading_end' => 10,
            'usage_m3' => 10,
            'usage_charge' => 10000,
            'abodemen' => 5000,
            'penalty_amount' => 0,
            'total_amount' => 15000,
            'status' => $status,
            'due_date' => $due,
        ]);
    }

    /**
     * Pelanggan lengkap: user -> tiket instalasi -> customer.
     */
    private function buatPelanggan(): Customer
    {
        $package = InstallationPackage::create([
            'name' => 'Paket Test',
            'installation_fee' => 0,
            'monthly_abodemen' => 10_000,
            'late_penalty' => 0,
        ]);

        $admin = User::create([
            'name' => 'Admin Test',
            'email' => 'admin-'.substr(uniqid('', true), -6).'@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $user = User::create([
            'name' => 'Pelanggan Test',
            'email' => 'pelanggan-'.substr(uniqid('', true), -6).'@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'pelanggan',
        ]);

        $ticket = InstallationTicket::create([
            'package_id' => $package->id,
            'applicant_name' => 'Pelanggan Test',
            'nik' => '3300000000000001',
            'address' => 'Jl. Test No. 1',
            'lat' => -7.797068,
            'lng' => 110.370529,
            'status' => 'completed',
            'created_by' => $admin->id,
        ]);

        return Customer::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'customer_code' => 'PDAM-UNPAID-00001',
            'initial_meter_reading' => 0,
            'activated_at' => now(),
        ]);
    }
}
