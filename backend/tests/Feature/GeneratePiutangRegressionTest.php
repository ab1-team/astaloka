<?php

namespace Tests\Feature;

use App\Models\MonthlyBill;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReceivableGenerator;
use App\Services\TenantCache;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regresi untuk beberapa bug generate piutang yang tidak pernah muncul
 * sebagai error di layar.
 *
 * Semua bug di sini bertipe sama: pekerjaan akuntansi TIDAK jalan, tanpa
 * pesan error, tanpa log yang mengarah ke penyebabnya. Tagihan tetap
 * `unpaid` di `monthly_bills`, tapi akun Piutang Usaha `1.1.03.01` tidak
 * pernah bertambah -- sehingga Neraca under-report sementara daftar tagihan
 * tidak, dan dua sumber kebenaran itu tidak sinkron.
 */
class GeneratePiutangRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Kunci waktu ke tanggal yang PASTI di dalam rentang generate 1-28.
     *
     * Gate-nya `===`: tanggal generate harus PERSIS sama dengan hari ini.
     * Kalau waktu tidak dikunci, test ini hanya benar kalau dijalankan
     * tepat pada tanggal generate -- dan diam-diam gagal pada tanggal
     * lain, dengan pesan yang tidak menyebut tanggal sama sekali
     * ("Belum tanggal generate piutang bulan ini").
     *
     * 15 Oktober 2026 dipakai karena comfortably di tengah bulan: ada di
     * dalam rentang 1-28 dan tidak pernah melewati batas akhir bulan.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    }

    private function createUser(string $role = 'admin'): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => 'regresi-'.$role.'-'.substr(uniqid('', true), -6).'@pdam.test',
            'password' => Hash::make('rahasia123'),
            'role' => $role,
        ]);
    }

    /**
     * Satu pelanggan dengan satu tagihan menunggak.
     */
    private function seedTagihanMenunggak(): MonthlyBill
    {
        $admin = $this->createUser('admin');

        $packageId = DB::table('installation_packages')->insertGetId([
            'name' => 'Paket Test',
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
            'address' => 'Alamat Test',
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
            'billing_period_year' => now()->year,
            'billing_period_month' => max(1, (int) now()->format('m') - 1),
            'meter_reading_start' => 0,
            'meter_reading_end' => 10,
            'usage_m3' => 10,
            'usage_charge' => 50_000,
            'abodemen' => 10_000,
            'penalty_amount' => 0,
            'total_amount' => 60_000,
            'status' => 'unpaid',
            'due_date' => now()->subMonths(1)->day(1)->toDateString(),
        ]);
    }

    /**
     * SOP dengan tanggal generate = HARI INI, supaya gate selalu lolos.
     *
     * Gate-nya `===`: tanggal generate harus PERSIS sama dengan hari ini.
     *
     * Nilai lama di sini adalah `1`, yang sekarang hanya cocok kalau test
     * dijalankan tepat pada tanggal 1 -- jadi hasil testnya bergantung
     * pada tanggal dijalankan, bukan pada apa pun yang diuji. Nilai
     * "hari ini" membuat hasilnya sama terlepas dari kapan pun test ini
     * dijalankan.
     *
     * `BillingTolerance::generateDay()` menerima 1-31, dan
     * `effectiveGenerateDay()` menarik angka yang melebihi hari terakhir
     * bulan ke hari terakhir bulan itu. Jadi kalau test ini dijalankan
     * tanggal 31 di bulan yang hanya punya 30 hari, nilainya tetap aman.
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
     * Id BARIS soft-deleted tetap memakai primary key.
     *
     * Command pernah menghitung `nextId` dari `Transaction::max('id')`.
     * Model `Transaction` memakai global scope `SoftDeletes`, jadi `max('id')`
     * TIDAK melihat baris yang sudah di-soft-delete -- padahal primary key
     * `id`-nya tetap dipakai baris itu. Soft delete tidak membebaskan id.
     *
     * Akibatnya `nextId` menunjuk id yang sudah dipakai -> INSERT gagal
     * dengan "Duplicate entry ... for key 'transactions.PRIMARY'" -> seluruh
     * generate piutang hari itu GAGAL tanpa menghasilkan satu baris pun.
     *
     * Kasus ini sangat mudah terjadi: rollback pembayaran me-soft-delete
     * jurnal `bill_payment`, dan baris soft-deleted itulah yang punya id
     * tertinggi.
     */
    #[Test]
    public function generate_tetap_jalan_walau_ada_baris_soft_deleted_dengan_id_tertinggi(): void
    {
        $this->setSOP();
        $bill = $this->seedTagihanMenunggak();

        // Jurnal "lama" yang di-soft-delete, dengan id TERTINGGI di tabel.
        //
        // Ditulis lewat query builder supaya `deleted_at`-nya terisi tanpa
        // memicu observer yang lain.
        $deletedId = ((int) DB::table('transactions')->max('id')) + 1;
        DB::table('transactions')->insert([
            'id' => $deletedId,
            'tgl_transaksi' => now()->subMonth()->toDateString(),
            'account_debet' => '1.1.03.01',
            'account_kredit' => '4.1.01.02',
            'reverence_type' => 'bill_payment',
            'reverence_id' => $bill->id,
            'keterangan_transaksi' => 'Jurnal lama yang di-soft-delete',
            'relasi' => '7350',
            'saldo' => 10_000,
            'id_user' => $this->createUser('admin')->id,
            'urutan' => $deletedId,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        // Id soft-deleted itu adalah yang tertinggi.
        $this->assertSame(
            $deletedId,
            (int) DB::table('transactions')->max('id'),
            'Baris soft-deleted harus tetap memakai id-nya.'
        );

        $result = app(ReceivableGenerator::class)->runIfDue();

        $this->assertTrue(
            $result['ran'],
            'Generate harus jalan: '.$result['reason']
        );

        $this->assertSame(
            2,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Harus ada jurnal piutang abodemen dan pemakaian.'
        );
    }

    /**
     * Id baru TIDAK boleh menabrak id yang sudah dipakai baris soft-deleted.
     */
    #[Test]
    public function id_jurnal_baru_melewati_id_yang_dipakai_baris_soft_deleted(): void
    {
        $this->setSOP();
        $bill = $this->seedTagihanMenunggak();

        $deletedId = ((int) DB::table('transactions')->max('id')) + 1;
        DB::table('transactions')->insert([
            'id' => $deletedId,
            'tgl_transaksi' => now()->subMonth()->toDateString(),
            'account_debet' => '1.1.03.01',
            'account_kredit' => '4.1.01.02',
            'reverence_type' => 'bill_payment',
            'reverence_id' => $bill->id,
            'keterangan_transaksi' => 'Jurnal lama yang di-soft-delete',
            'relasi' => '7350',
            'saldo' => 10_000,
            'id_user' => $this->createUser('admin')->id,
            'urutan' => $deletedId,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        app(ReceivableGenerator::class)->runIfDue();

        $ids = Transaction::where('reverence_type', 'overdue_bill')->pluck('id')->all();

        $this->assertNotContains(
            $deletedId,
            $ids,
            'Jurnal baru tidak boleh memakai id yang dipakai baris soft-deleted.'
        );
    }

    /**
     * Busy-guard WAJIB memakai kunci yang sama di semua jalur.
     *
     * Sebelumnya `ReceivableGenerator` memakai kunci berbasis TANGGAL
     * GENERATE, sedangkan `DashboardController` memakai kunci berbasis
     * TANGGAL HARI INI. Kalau tanggal generate berbeda dari hari ini --
     * yang pasti terjadi begitu tanggal generate terlewat -- kedua kunci
     * itu berbeda, sehingga dua proses bisa menjalankan agregasi `amount`
     * bersamaan persis hal yang busy-guard itu untuk mencegahnya.
     */
    #[Test]
    public function busy_guard_tidak_bergantung_pada_tanggal_generate(): void
    {
        $generator = app(ReceivableGenerator::class);

        $now = Carbon::parse('2026-10-28 10:00:00');

        $this->assertSame(
            $generator->lockKey($now),
            $generator->lockKey($now->copy()),
            'Kunci harus sama untuk waktu yang sama.'
        );

        // Kunci TIDAK boleh ikut berubah ketika tanggal generate di SOP
        // berubah, selama "waktu sekarang"-nya sama.
        //
        // Yang diuji: kunci ditentukan oleh TANGGAL HARI INI, bukan oleh
        // tanggal generate. Kalau ikut tanggal generate, begitu tanggal
        // generate terlewat (28 -> generate hari 5 November), kuncinya
        // berubah dan dua jalur tidak lagi saling menjaga.
        //
        // Kedua kolom diset sama karena sekarang cuma satu angka tanggal.
        Setting::updateOrCreate(
            ['key' => 'sop'],
            ['batas_tagihan' => 28, 'toleransi_tunggakan' => 28]
        );
        $kunciDenganTanggalGenerate = $generator->lockKey($now);

        Setting::updateOrCreate(
            ['key' => 'sop'],
            ['batas_tagihan' => 5, 'toleransi_tunggakan' => 5]
        );
        $kunciDenganTanggalGenerateLain = $generator->lockKey($now);

        $this->assertSame(
            $kunciDenganTanggalGenerate,
            $kunciDenganTanggalGenerateLain,
            'Kunci tidak boleh bergantung pada tanggal generate.'
        );

        // Dan kunci MEMANG berbeda untuk hari yang berbeda -- kalau tidak,
        // satu proses bisa saja menahan generate sepanjang hari.
        $this->assertNotSame(
            $generator->lockKey(Carbon::parse('2026-10-29 10:00:00')),
            $generator->lockKey(Carbon::parse('2026-10-28 10:00:00')),
            'Kunci harus berbeda untuk hari yang berbeda.'
        );
    }

    /**
     * Scheduler harus menghormati gate tanggal generate.
     *
     * Scheduler lama memanggil command secara langsung, jadi gate tanggal
     * generate TIDAK berlaku di jalur itu: piutang dijurnalkan di luar
     * jadwalnya.
     *
     * Yang diuji di sini adalah gate-nya sendiri -- satu-satunya tempat
     * yang menentukan KAPAN generate boleh jalan.
     */
    #[Test]
    public function scheduler_tidak_menjalankan_generate_sebelum_tanggal_generate(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 00:01:00'));

        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 20,
            'toleransi_tunggakan' => 20,
        ]);

        $this->seedTagihanMenunggak();

        $result = app(ReceivableGenerator::class)->runIfDue();

        $this->assertFalse($result['ran'], 'Gate harus menahan generate.');
        $this->assertSame(
            0,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Tidak boleh ada jurnal sebelum tanggal generate.'
        );
    }

    #[Test]
    public function scheduler_menjalankan_generate_setelah_tanggal_generate(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 00:01:00'));

        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 20,
            'toleransi_tunggakan' => 20,
        ]);

        $this->seedTagihanMenunggak();

        $result = app(ReceivableGenerator::class)->runIfDue();

        $this->assertTrue($result['ran'], 'Generate harus jalan: '.$result['reason']);
        $this->assertSame(
            2,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Harus ada jurnal piutang.'
        );
    }

    /**
     * Gate tanggal generate HANYA satu sumber: `BillingTolerance`.
     *
     * Nilai di luar rentang 1-31 berarti "SOP belum diisi", bukan
     * "generate tanggal 32".
     */
    #[Test]
    public function gate_menghormati_rentang_tanggal_generate_1_sampai_31(): void
    {
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 32,
            'toleransi_tunggakan' => 32,
        ]);

        $generator = app(ReceivableGenerator::class);

        $this->assertSame(0, $generator->scheduledDay(), 'Tanggal 32 bukan tanggal generate.');
        $this->assertFalse(
            $generator->shouldRun(Carbon::parse('2026-10-30 10:00:00')),
            'Generate tidak boleh jalan dengan SOP yang tidak valid.'
        );
    }

    /**
     * Tanggal 29/30/31 harus jalan pada hari terakhir bulan.
     *
     * Dulu rentangnya 1-28, jadi menyetel tanggal 29 membuat
     * `generateDay()` = 0 dan generate TIDAK PERNAH jalan -- symptom yang
     * sama seperti "SOP belum diisi", padahal SOP-nya sudah diisi.
     */
    #[Test]
    public function tanggal_29_jalan_pada_tanggal_29_dan_tidak_jalan_di_hari_lain(): void
    {
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 29,
            'toleransi_tunggakan' => 29,
        ]);

        $generator = app(ReceivableGenerator::class);

        $this->assertSame(29, $generator->scheduledDay());
        $this->assertTrue(
            $generator->shouldRun(Carbon::parse('2026-10-29 10:00:00')),
            'Tanggal 29 harus jalan di tanggal 29.'
        );
        $this->assertFalse(
            $generator->shouldRun(Carbon::parse('2026-10-28 10:00:00')),
            'Tanggal 28 bukan tanggal generate.'
        );
        $this->assertFalse(
            $generator->shouldRun(Carbon::parse('2026-10-30 10:00:00')),
            'Tanggal 30 bukan tanggal generate.'
        );
    }

    /**
     * Ringkasan yang basi tidak boleh sampai ke user.
     *
     * Ringkasan disimpan di cache dengan TTL 26 jam, jadi ringkasan dari
     * run KEMARIN masih hidup. Kalau yang ditampilkan adalah ringkasan itu,
     * user melihat angka yang sudah berumur sehari.
     */
    #[Test]
    public function ringkasan_basi_dari_hari_sebelumnya_tidak_dikembalikan(): void
    {
        $cache = app(TenantCache::class);

        $cache->flagPut('overdue_gen_run_summary', [
            'date' => '2026-10-27',
            'processed' => 1234,
            'jurnal_abodemen' => 1234,
            'jurnal_pemakaian' => 1234,
            'jurnal_denda' => 0,
            'total_unpaid' => 500,
            'total_overdue' => 300,
        ], now()->addHours(26));

        $summary = app(ReceivableGenerator::class)->runSummary(Carbon::parse('2026-10-28 10:00:00'));

        $this->assertNull(
            $summary,
            'Ringkasan dari hari sebelumnya tidak boleh dikembalikan sebagai ringkasan hari ini.'
        );
    }

    #[Test]
    public function ringkasan_hari_ini_dikembalikan(): void
    {
        $cache = app(TenantCache::class);

        $cache->flagPut('overdue_gen_run_summary', [
            'date' => '2026-10-28',
            'processed' => 7,
            'jurnal_abodemen' => 7,
            'jurnal_pemakaian' => 7,
            'jurnal_denda' => 0,
            'total_unpaid' => 500,
            'total_overdue' => 300,
        ], now()->addHours(26));

        $summary = app(ReceivableGenerator::class)->runSummary(Carbon::parse('2026-10-28 10:00:00'));

        $this->assertNotNull($summary, 'Ringkasan hari ini harus dikembalikan.');
        $this->assertSame(7, $summary['processed']);
    }

    /**
     * Ringkasan yang BENAR-BENAR ditulis command harus utuh.
     *
     * Ini mengunci bahwa `runSummary()` tidak memotong field yang dipakai
     * frontend.
     */
    #[Test]
    public function ringkasan_dari_command_mengandung_seluruh_field(): void
    {
        $this->setSOP();
        $bill = $this->seedTagihanMenunggak();

        $result = app(ReceivableGenerator::class)->runIfDue();

        $this->assertTrue($result['ran'], 'Generate harus jalan: '.$result['reason']);

        $summary = $result['summary'];

        $this->assertIsArray($summary, 'Ringkasan harus ada setelah generate.');
        $this->assertSame(1, $summary['jurnal_abodemen']);
        $this->assertSame(1, $summary['jurnal_pemakaian']);
        $this->assertSame(0, $summary['jurnal_denda']);
        $this->assertSame(1, $summary['processed']);
        $this->assertSame(0, $summary['skipped']);
        $this->assertSame(1, $summary['total_unpaid']);
        $this->assertSame(1, $summary['total_overdue']);
        $this->assertSame(
            now()->toDateString(),
            $summary['date'],
            'Ringkasan harus bertanggal hari ini.'
        );

        $this->assertSame(
            2,
            Transaction::where('reverence_type', 'overdue_bill')
                ->where('reverence_id', $bill->id)
                ->count(),
            'Jurnal harus relating ke tagihan yang diproses (abodemen + pemakaian).'
        );
    }
}
