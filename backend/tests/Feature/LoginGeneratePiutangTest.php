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
use Illuminate\Support\Facades\Hash;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Generate piutang tunggakan yang dipicu LOGIN.
 *
 * Latar belakang
 * ---------------
 *
 * Sebelum ada test ini, generate piutang hanya dipicu dari frontend
 * (`useOverdueGenNotification.js`) saat dashboard dibuka, dan hanya jalan bila
 * hari ini PERSIS sama dengan `toleransi_tunggakan`.
 *
 * Dua kegagalan yang bisa terjadi tanpa error, tanpa log, tanpa jejak:
 *
 *   1. Tidak ada staff yang membuka dashboard pada tanggal itu. Tagihan
 *      menunggak tidak pernah dijurnalkan. `monthly_bills` tetap `unpaid`,
 *      tapi akun Piutang Usaha `1.1.03.01` tidak pernah ditambah --
 *      Neraca under-report sementara daftar tagihan tidak.
 *
 *   2. Gate `===` membuat tanggal 29, 30, dan 31 tidak pernah bisa menjadi
 *      tanggal generate.
 *
 * Test di sini mengunci perilaku yang baru: login adalah pemicu generate.
 */
class LoginGeneratePiutangTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Kunci waktu ke tanggal yang PASTI ada di setiap bulan.
     *
     * Kenapa test di kelas ini perlu dikunci waktunya
     * -----------------------------------------------
     *
     * Gate generate membandingkan "hari ini" dengan `toleransi_tunggakan`.
     * Nilai di luar rentang 1-31 berarti "SOP belum diisi" -- dan generate
     * tidak pernah jalan.
     *
     * Test yang memakai `toleransi_tunggakan => now()->format('d')` punya
     * masalah yang sama persis. Di tanggal 29, 30, dan 31, `now()->format('d')`
     * menghasilkan angka yang TIDAK ada di setiap bulan -- jadi kalau bulan
     * itu hanya punya 28 hari, `effectiveGenerateDay()` menariknya ke hari
     * terakhir bulan dan generate tidak jalan -- padahal test mengharapkan
     * `ran=true`.
     *
     * Gejalanya muncul sebagai kegagalan yang sama sekali tidak
     * menyinggung tanggal: `assertJsonPath('data.piutang_tunggakan.ran', true)`
     * gagal dengan "Failed asserting that false is identical to true", dan
     * tidak ada satupun pesan yang menyebut tanggal. Test itu terlihat seperti
     * produknya rusak, padahal yang rusak cuma cara test itu memilih tanggal.
     *
     * Mengunci waktu ke tanggal yang ada di SETIAP bulan (15) membuat
     * hasilnya sama terlepas dari kapan pun test ini dijalankan.
     */
    private function freezeDate(): void
    {
        // 15 Oktober 2026 -- di tengah bulan, jadi tanggal ini ada di dalam
        // rentang generate 1-31 dan tidak pernah melewati batas akhir bulan.
        $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    }

    /**
     * Kunci waktu SEBELUM test jalan.
     *
     * Dipanggil di sini, bukan di dalam test satu per satu, karena hampir
     * semua test di kelas ini memakai `now()->format('d')` sebagai tanggal
     * generate -- dan nilai itu harus dihitung setelah waktu dikunci.
     *
     * Test yang memang butuh waktu lain memanggil `travelTo()` sendiri,
     * dan itu menimpa penguncian ini.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeDate();
    }

    /**
     * Satu lokasi dengan satu pelanggan dan satu tagihan menunggak.
     *
     * Mengembalikan `[$admin, $bill]`.
     *
     * @return array{0: User, 1: MonthlyBill}
     */
    private function seedLokasiDenganTagihanMenunggak(int $penalty = 0): array
    {
        $admin = $this->createUser('admin');

        $packageId = \DB::table('installation_packages')->insertGetId([
            'name' => 'Paket Test',
            'installation_fee' => 0,
            'monthly_abodemen' => 10_000,
            'late_penalty' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = \DB::table('installation_tickets')->insertGetId([
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
            'email' => 'budi-'.uniqid().'@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'pelanggan',
        ]);

        $customerId = \DB::table('customers')->insertGetId([
            'ticket_id' => $ticketId,
            'user_id' => $pelanggan->id,
            'customer_code' => '7350',
            'initial_meter_reading' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bill = MonthlyBill::create([
            'customer_id' => $customerId,
            'billing_period_year' => now()->year,
            'billing_period_month' => max(1, (int) now()->format('m') - 1),
            'meter_reading_start' => 0,
            'meter_reading_end' => 10,
            'usage_m3' => 10,
            'usage_charge' => 50_000,
            'abodemen' => 10_000,
            'penalty_amount' => $penalty,
            'total_amount' => 60_000 + $penalty,
            'status' => 'unpaid',
            // Awal bulan lalu: pasti lebih tua dari ambang berapa pun tanggal
            // test dijalankan.
            'due_date' => now()->subMonths(1)->day(1)->toDateString(),
        ]);

        return [$admin, $bill];
    }

    private function createUser(string $role = 'admin'): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => 'login-'.$role.'-'.substr(uniqid('', true), -6).'@pdam.test',
            'password' => Hash::make('rahasia123'),
            'role' => $role,
        ]);
    }

    /**
     * Login dan kembalikan respons JSON.
     */
    private function login(User $user)
    {
        return $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'rahasia123',
        ]);
    }

    #[Test]
    public function login_membuat_jurnal_piutang_tunggakan_tanpa_perlu_buka_dashboard(): void
    {
        // Tanggal generate = hari ini (15), supaya generate HARUS boleh jalan.
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => (int) now()->format('d'),
            'toleransi_tunggakan' => (int) now()->format('d'),
        ]);

        [$admin, $bill] = $this->seedLokasiDenganTagihanMenunggak();

        $this->assertSame(
            0,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Jurnal piutang harus belum ada sebelum login.'
        );

        $response = $this->login($admin);

        $response->assertOk()->assertJson(['success' => true]);

        // Inilah inti dari test ini: tidak ada panggilan ke dashboard, tidak
        // ada frontend yang menyentuh apa pun. Cukuplah login.
        $jurnal = Transaction::where('reverence_type', 'overdue_bill')
            ->where('reverence_id', $bill->id)
            ->get();

        $this->assertCount(2, $jurnal, 'Harus ada jurnal piutang abodemen dan pemakaian.');
        $this->assertSame(
            ['4.1.01.02', '4.1.01.03'],
            $jurnal->pluck('account_kredit')->sort()->values()->all(),
            'Akun kredit harus abodemen dan pemakaian.'
        );

        foreach ($jurnal as $trx) {
            $this->assertSame('1.1.03.01', $trx->account_debet, 'Piutang Usaha harus di-debet.');
        }
    }

    #[Test]
    public function login_melaporkan_ringkasan_generate_piutang(): void
    {
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => (int) now()->format('d'),
            'toleransi_tunggakan' => (int) now()->format('d'),
        ]);

        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        $response = $this->login($admin);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.piutang_tunggakan.ran', true)
            ->assertJsonPath('data.piutang_tunggakan.summary.jurnal_abodemen', 1)
            ->assertJsonPath('data.piutang_tunggakan.summary.jurnal_pemakaian', 1);
    }

    /**
     * Generate tidak jalan SEBELUM tanggal generate.
     *
     * Test ini dulu bergantung pada tanggal saat test dijalankan, sehingga
     * ia gagal pada tanggal 28 dan-setidaknyanya. Karena `now()->addDay()`
     * pada tanggal 28 menjadi 29, lalu dijepit ke 28 oleh rentang 1-28,
     * hasilnya SAMA dengan hari ini -- jadi gate `day >= scheduledDay`
     * lolos dan test gagal.
     *
     * Sekarang waktunya dikunci ke tanggal tetap, jadi hasilnya sama
     * terlepas dari kapan pun test ini dijalankan.
     */
    #[Test]
    public function login_tidak_menjalankan_generate_sebelum_tanggal_generate(): void
    {
        // 10 Oktober 2026, jadwal generate-nya 11 Oktober.
        $this->travelTo(Carbon::parse('2026-10-10 09:00:00'));

        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 11,
            'toleransi_tunggakan' => 11,
        ]);

        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        // Gate `day >= scheduledDay` HARUS menahannya. Kalau tidak, test ini
        // gagal dan berarti gate-nya salah.
        $this->login($admin)->assertOk()
            ->assertJsonPath('data.piutang_tunggakan.ran', false);

        $this->assertSame(
            0,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Tidak boleh ada jurnal sebelum tanggal generate.'
        );
    }

    /**
     * Gate berbasis tanggal lokal, bukan UTC.
     *
     * Aplikasi ini berjalan di WIB (UTC+7). Dulu `config/app.php` memakai
     * `UTC`, sehingga antara pukul 00:00-07:00 WIB `now()->day` menunjuk
     * ke HARI SEBELUMNYA -- dan gate tanggal generate meleset: tanggal 28
     * terlihat seperti tanggal 27, jadi generate tidak jalan sama sekali.
     *
     * Test ini mengunci itu: jam 00:30 waktu lokal tanggal 28 HARUS
     * dianggap tanggal 28.
     */
    #[Test]
    public function gate_menggunakan_tanggal_lokal_bukan_utc(): void
    {
        // 00:30 WIB tanggal 28 -- kalau app memakai UTC, ini masih tanggal 27.
        $this->travelTo(Carbon::parse('2026-10-28 00:30:00'));

        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 28,
            'toleransi_tunggakan' => 28,
        ]);

        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        $this->login($admin)->assertOk()
            ->assertJsonPath('data.piutang_tunggakan.ran', true);

        $this->assertSame(
            2,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Jam 00:30 tanggal 28 harus dianggap tanggal 28.'
        );
    }

    #[Test]
    public function login_tetap_berhasil_walaupun_generate_gagal(): void
    {
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => (int) now()->format('d'),
            'toleransi_tunggakan' => (int) now()->format('d'),
        ]);

        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        // Paksa `runIfDue()` melempar exception dari dalam dirinya, supaya
        // catch di `ReceivableGenerator` yang diuji -- bukan catch di
        // `AuthController`.
        //
        // Cara: injeksi dependency lewat container. Tabel TIDAK di-drop di
        // sini: `RefreshDatabase` membungkus tiap test dalam transaksi,
        // dan drop tabel di tengahnya membuat rollback framework-nya sendiri
        // gagal dengan "SAVEPOINT does not exist" -- itu menguji savepoint,
        // bukan ketahanan login.
        $generator = Mockery::mock(ReceivableGenerator::class);
        $generator->shouldReceive('runIfDue')
            ->andThrow(new \RuntimeException('Koneksi tagihan terputus.'));
        app()->instance(ReceivableGenerator::class, $generator);

        $response = $this->login($admin);

        // Password benar + akun aktif + tagihan langganan lunas.
        // Kegagalan pekerjaan akuntansi internal TIDAK BOLEH menjadi alasan
        // orang tidak bisa masuk.
        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.piutang_tunggakan', null)
            ->assertJsonPath('data.token', fn ($t) => is_string($t) && $t !== '');

        $this->assertSame(
            1,
            User::where('email', $admin->email)->count(),
            'User harus tetap ada setelah generate gagal.'
        );
    }

    #[Test]
    public function generate_gagal_dilaporkan_tanpa_menggagalkan_generate_berikutnya(): void
    {
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => (int) now()->format('d'),
            'toleransi_tunggakan' => (int) now()->format('d'),
        ]);

        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        // `ReceivableGenerator` yang benar, tapi generate-nya gagal sekali.
        $partial = new class(app(TenantCache::class)) extends ReceivableGenerator
        {
            public int $calls = 0;

            public function execute(?Carbon $now = null): ?array
            {
                $this->calls++;

                if ($this->calls === 1) {
                    throw new \RuntimeException('Deadlock saat agregasi amount.');
                }

                return parent::execute($now);
            }
        };
        app()->instance(ReceivableGenerator::class, $partial);

        // Percobaan pertama: gagal, tapi login tetap berhasil, dan mutex
        // HARUS dilepas supaya percobaan kedua di hari yang sama masih punya
        // kesempatan.
        $this->login($admin)->assertOk()
            ->assertJsonPath('data.piutang_tunggakan.ran', false);

        // Percobaan kedua: berhasil.
        $kedua = $this->login($admin);
        $kedua->assertOk()->assertJsonPath('data.piutang_tunggakan.ran', true);

        $this->assertSame(
            2,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Percobaan kedua harusnya membuat jurnal piutang.'
        );
    }

    /**
     * Generate boleh dijalankan ulang di hari yang sama.
     *
     * Dulu ada mutex "sekali per tanggal generate" yang membuat login kedua
     * pada tanggal yang sama dilewati. Mutex itu diturunkan menjadi
     * BUSY-GUARD saja (mencegah dua proses agregasi `amount` berjalan
     * bersamaan), bukan penghalang generate.
     *
     * Alasan operasionalnya: kadang ada pelanggan yang lupa digenerate, atau
     * tagihannya lewat dari tanggal generate. Generate ulang harus bisa
     * menutup kekurangan itu tanpa intervensi manual.
     *
     * Yang tetap dijaga: TIDAK boleh ada jurnal dobel. Command-nya sudah
     * idempotent lewat pasangan `reverence_id|account_kredit`.
     */
    #[Test]
    public function generate_boleh_dijalankan_ulang_di_hari_yang_sama_tanpa_jurnal_ganda(): void
    {
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => (int) now()->format('d'),
            'toleransi_tunggakan' => (int) now()->format('d'),
        ]);

        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        $this->login($admin)->assertOk()
            ->assertJsonPath('data.piutang_tunggakan.ran', true);

        $jurnalSetelahLoginPertama = Transaction::where('reverence_type', 'overdue_bill')->count();
        $this->assertSame(2, $jurnalSetelahLoginPertama);

        // Login kedua di hari yang sama: generate TETAP dijalankan.
        $kedua = $this->login($admin);
        $kedua->assertOk()
            ->assertJsonPath('data.piutang_tunggakan.ran', true);

        // Tapi tidak boleh menambah jurnal: command-nya idempotent.
        $this->assertSame(
            $jurnalSetelahLoginPertama,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Login kedua tidak boleh menambah jurnal piutang.'
        );
    }

    /**
     * Generate TIDAK jalan setelah tanggal generate terlewat.
     *
     * Gate `===`: `toleransi_tunggakan` = 15 berarti generate HANYA pada
     * tanggal 15. Hari 16 dan seterusnya TIDAK boleh memicu generate.
     *
     * Ini kebalikan dari perilaku lama (`>=`), dan itu memang yang diminta:
     * "generate hanya 1 hari di tgl yang di-set, bukan hari ini -- tgl itu,
     * tapi hanya tgl itu".
     *
     * Yang menutup celah "bulan tanpa staff yang login tepat pada tanggal
     * itu" adalah scheduler harian di `routes/console.php`.
     */
    #[Test]
    public function generate_tidak_jalan_setelah_tanggal_generate_terlewat(): void
    {
        // Tanggal generate = 15, "hari ini" = 20 -- tanggal sudah lewat.
        $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));

        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 15,
            'toleransi_tunggakan' => 15,
        ]);

        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        $this->login($admin)->assertOk()
            ->assertJsonPath('data.piutang_tunggakan.ran', false);

        $this->assertSame(
            0,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Tanggal generate yang sudah terlewat tidak boleh memicu generate.'
        );
    }

    /**
     * Generate jalan HANYA pada tanggal yang di-set -- dan boleh berulang
     * di tanggal itu sebanyak apa pun.
     *
     * Dua aturan yang keduanya diminta: tanggalnya PERSIS, tapi jumlahnya
     * tidak dibatasi.
     */
    #[Test]
    public function generate_hanya_jalan_pada_tanggal_yang_di_set(): void
    {
        // Tanggal generate = 15.
        $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));

        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 15,
            'toleransi_tunggakan' => 15,
        ]);

        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        // Login pada TANGGALNYA: generate jalan.
        $this->login($admin)->assertOk()
            ->assertJsonPath('data.piutang_tunggakan.ran', true);

        $this->assertSame(
            2,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Pada tanggal generate, jurnal harus terbentuk.'
        );

        // Login kedua di tanggal yang SAMA: generate TETAP jalan.
        $this->login($admin)->assertOk()
            ->assertJsonPath('data.piutang_tunggakan.ran', true);

        // Tapi jurnal tidak boleh dobel -- command-nya idempotent.
        $this->assertSame(
            2,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Login kedua di tanggal yang sama tidak boleh menambah jurnal.'
        );
    }

    #[Test]
    public function login_tidak_menjalankan_generate_kalendar_sistem_kosong(): void
    {
        // Tidak ada baris Setting sama sekali.
        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        $response = $this->login($admin);

        $response->assertOk()
            ->assertJsonPath('data.piutang_tunggakan.ran', false);

        $this->assertSame(
            0,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Tanpa tanggal generate di SOP, tidak ada yang boleh dijurnalkan.'
        );
    }
}
