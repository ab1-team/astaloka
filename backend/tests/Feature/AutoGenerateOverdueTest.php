<?php

namespace Tests\Feature;

use App\Models\MonthlyBill;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutoGenerateOverdueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Kunci waktu ke tanggal yang PASTI di dalam rentang generate 1-28.
     *
     * Kenapa test di kelas ini perlu dikunci waktunya
     * -----------------------------------------------
     *
     * Gate generate membandingkan "hari ini" dengan `toleransi_tunggakan`.
     * Kalau test memakai `now()` sungguhan, hasilnya berubah setiap hari,
     * dan sebagian test HANYA benar di hari-hari tertentu.
     *
     * Contoh paling buruknya `auto_generate_overdue_tidak_jalan_jika_tanggal_tidak_cocok`:
     * test itu memakai rumus `(now()->format('d') % 28) + 1` untuk memilih
     * tanggal yang "tidak cocok" dengan hari ini. Rumus itu ditulis saat
     * gate-nya masih `===` -- waktu "tidak cocok" berarti "beda dengan hari
     * ini". Gate sekarang `>=`, jadi yang relevan adalah "tanggal generate
     * BELUM tercapai": contoh hari 28, tanggal generate 29.
     *
     * Dengan rumus lama, di tanggal 28 hasilnya `28 % 28 + 1 = 1`, dan
     * `28 >= 1` -- gate-nya LOLOS. Test mengharapkan `ran=false`, jadi
     * test gagal. Tanggal 29, 30, 31 punya masalah yang sama.
     *
     * Menulis tanggal secara eksplisit membuat test ini hasilnya sama
     * terlepas dari kapan pun ia dijalankan -- persis seperti yang sudah
     * dilakukan di `LoginGeneratePiutangTest`.
     */
    private function freezeDate(): void
    {
        // 15 Oktober 2026 -- comfortably di tengah bulan, jadi tanggal ini
        // ada di dalam rentang generate 1-28 dan tidak pernah melewati
        // batas akhir bulan.
        $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    }

    private function createUser(string $role = 'admin'): User
    {
        // Pakai suffix random kecil supaya email selalu unik walau
        // dipanggil beberapa kali dalam satu test class.
        $uniq = uniqid('', true);

        return User::create([
            'name' => 'Test User',
            'email' => 'test-'.$role.'-'.substr($uniq, -6).'@pdam.test',
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    private function authHeaders(User $user): array
    {
        $token = $user->createToken('auth_token')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    #[Test]
    public function auto_generate_overdue_tanpa_auth_ditolak(): void
    {
        $response = $this->postJson('/api/dashboard/auto-generate-overdue');
        $response->assertStatus(401);
    }

    #[Test]
    public function auto_generate_overdue_oleh_pelanggan_ditolak(): void
    {
        $user = $this->createUser('pelanggan');

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/dashboard/auto-generate-overdue');

        $response->assertStatus(403);
    }

    #[Test]
    public function auto_generate_overdue_tidak_jalan_jika_toleransi_nol(): void
    {
        /*
         * Kedua kolom diset 0.
         *
         * Setelah penggabungan dua tanggal jadi satu, "tanggal 0" berarti
         * "SOP belum diisi" untuk SEMUA aturan berbasis tanggal -- jadi
         * `batas_tagihan` juga harus 0. Kalau hanya `toleransi_tunggakan`
         * yang 0, `BillingTolerance::tanggal()` akan jatuh ke fallback
         * kolom itu dan hasilnya tetap 0, tapi test ini jadi menguji
         * fallback, bukan "SOP belum diisi".
         */
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 0,
            'toleransi_tunggakan' => 0,
        ]);

        $user = $this->createUser('admin');

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/dashboard/auto-generate-overdue');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'ran' => false,
            ]);
    }

    #[Test]
    public function auto_generate_overdue_tidak_jalan_jika_tanggal_tidak_cocok(): void
    {
        $this->freezeDate();

        Setting::create([
            'key' => 'sop',
            // Tanggal generate = 20, sedangkan "hari ini" = 15.
            //
            // Gate-nya `===`: generate HANYA pada tanggal yang di-set.
            // Angka 20 dipilih eksplisit supaya test ini hasilnya sama
            // terlepas dari kapan pun ia dijalankan.
            //
            // Kedua kolom diset 20 karena keduanya sekarang satu angka.
            'batas_tagihan' => 20,
            'toleransi_tunggakan' => 20,
        ]);

        $user = $this->createUser('admin');

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/dashboard/auto-generate-overdue');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'ran' => false,
            ])
            ->assertJsonStructure(['reason']);
    }

    /**
     * Generate boleh dijalankan BERULANG.
     *
     * Dulu ada flag `auto_gen_overdue_{bulan}_{user}` (TTL 35 hari) yang
     * membuat panggilan kedua pada bulan yang sama dilewati. Flag itu
     * dihapus karena menghambat kasus nyata: ada pelanggan yang lupa
     * somehow, ada tagihan yang baru masuk belakangan, atau ada tagihan
     * yang tadinya salah lalu diperbaiki admin. Semuanya butuh generate
     * ULANG.
     *
     * Yang mencegah jurnal dobel bukan flag itu, melainkan command yang
     * sudah idempotent (pasangan `reverence_id|account_kredit`).
     */
    #[Test]
    public function auto_generate_overdue_boleh_dijalankan_berulang_dan_tidak_membuat_jurnal_ganda(): void
    {
        $this->freezeDate();

        Setting::create([
            'key' => 'sop',
            // Satu angka untuk dua kolom: tanggal generate = hari ini (15),
            // jadi command benar-benar jalan.
            'batas_tagihan' => (int) now()->format('d'),
            'toleransi_tunggakan' => (int) now()->format('d'),
        ]);

        $user = $this->createUser('admin');
        $headers = $this->authHeaders($user);

        // Panggilan ke-1 harus eksekusi (ran=true).
        $first = $this->withHeaders($headers)
            ->postJson('/api/dashboard/auto-generate-overdue');
        $first->assertOk()
            ->assertJsonPath('ran', true);

        $jurnalSetelahPertama = Transaction::where('reverence_type', 'overdue_bill')->count();

        // Panggilan ke-2 juga harus EKSEKUSI (ran=true) — inilah yang
        // diminta: generate boleh diulang, termasuk di hari yang sama.
        $second = $this->withHeaders($headers)
            ->postJson('/api/dashboard/auto-generate-overdue');
        $second->assertOk()
            ->assertJsonPath('ran', true);

        // Tapi TIDAK boleh menambah jurnal: command-nya idempotent.
        $this->assertSame(
            $jurnalSetelahPertama,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Generate ulang tidak boleh membuat jurnal piutang ganda.'
        );
    }

    /**
     * Generate TIDAK jalan setelah tanggal generate terlewat.
     *
     * Gate `===`: `toleransi_tunggakan` = 15 berarti generate HANYA pada
     * tanggal 15. "Hari ini" = 20, jadi generate tidak boleh jalan.
     *
     * Ini yang diminta: "generate hanya 1 hari di tgl yang di-set, bukan
     * hari ini -- tgl itu, tapi hanya tgl itu".
     */
    #[Test]
    public function auto_generate_overdue_tidak_jalan_setelah_tanggal_generate_terlewat(): void
    {
        // Tanggal generate = 15, sedangkan "hari ini" = 20 -- tanggal sudah
        // lewat. Gate `===` menahannya.
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));

        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 15,
            'toleransi_tunggakan' => 15,
        ]);

        $user = $this->createUser('admin');

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/dashboard/auto-generate-overdue');

        $response->assertOk()
            ->assertJsonPath('ran', false);
    }

    #[Test]
    public function check_endpoint_melaporkan_will_run_tanpa_tergantung_sudah_jalan(): void
    {
        $this->freezeDate();

        // `will_run` tidak boleh bergantung pada cache "sudah pernah jalan",
        // karena frontend memakainya untuk membuka popup di setiap login.
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => (int) now()->format('d'),
            'toleransi_tunggakan' => (int) now()->format('d'),
        ]);

        $admin = $this->createUser('admin');
        $headers = $this->authHeaders($admin);

        $this->withHeaders($headers)
            ->postJson('/api/dashboard/auto-generate-overdue')
            ->assertOk();

        // Setelah generate pertama, `will_run` tetap true.
        $this->withHeaders($headers)
            ->getJson('/api/dashboard/auto-generate-overdue/check')
            ->assertOk()
            ->assertJsonPath('will_run', true)
            ->assertJsonPath('already_ran', false);
    }

    #[Test]
    public function auto_generate_overdue_tanpa_tagihan_overdue_masih_ran_true_namun_summary_nol(): void
    {
        $this->freezeDate();

        // Set toleransi = hari ini, TAPI tidak ada tagihan overdue.
        // Endpoint harus tetap return success dengan summary=0.
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => (int) now()->format('d'),
            'toleransi_tunggakan' => (int) now()->format('d'),
        ]);

        $user = $this->createUser('admin');

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/dashboard/auto-generate-overdue');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'ran' => true,
            ])
            ->assertJsonStructure([
                'summary' => [
                    'tagihan_dengan_abodemen_tungakan',
                    'tagihan_dengan_pemakaian_tungakan',
                    'total_unpaid',
                    'total_overdue',
                ],
            ]);

        // Tanpa tagihan overdue -> semua summary harus 0.
        $response->assertJsonPath('summary.tagihan_dengan_abodemen_tungakan', 0)
            ->assertJsonPath('summary.tagihan_dengan_pemakaian_tungakan', 0)
            ->assertJsonPath('summary.total_unpaid', 0)
            ->assertJsonPath('summary.total_overdue', 0);

        // Tidak ada jurnal baru.
        $this->assertSame(0, Transaction::where('reverence_type', 'overdue_bill')->count());
    }

    #[Test]
    public function response_selalu_memiliki_field_wajib(): void
    {
        // Apapun kondisi SOP, response harus menyertakan field
        // wajib agar frontend bisa menampilkan pop up dengan benar.
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 0,
            'toleransi_tunggakan' => 0, // non-trigger
        ]);

        $user = $this->createUser('admin');

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/dashboard/auto-generate-overdue');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'ran',
                'scheduled_day',
                'today_day',
                'date',
            ]);
    }

    #[Test]
    public function command_menghasilkan_format_keterangan_piutang_abodemen_dan_denda(): void
    {
        $this->freezeDate();

        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => (int) now()->format('d'),
            'toleransi_tunggakan' => (int) now()->format('d'),
        ]);

        // Buat admin dulu karena ticket.created_by mereferensinya.
        $adminUser = $this->createUser('admin');

        // Setup chain: package → ticket → customer → monthly bill overdue
        $packageId = \DB::table('installation_packages')->insertGetId([
            'name' => 'Paket Test',
            'installation_fee' => 0,
            'monthly_abodemen' => 10000,
            'late_penalty' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = \DB::table('installation_tickets')->insertGetId([
            'package_id' => $packageId,
            'applicant_name' => 'Fuji Riyanta',
            'nik' => '3321010101900001',
            'order_date' => now()->toDateString(),
            'address' => 'Alamat Test',
            'lat' => -7.1234567,
            'lng' => 110.1234567,
            'status' => 'completed',
            'created_by' => $adminUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // User dengan nama "Fuji Riyanta" (simulasi akun pelanggan).
        $customerUser = User::create([
            'name' => 'Fuji Riyanta',
            'email' => 'fuji.riyanta-'.uniqid().'@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'pelanggan',
        ]);

        $customerId = \DB::table('customers')->insertGetId([
            'ticket_id' => $ticketId,
            'user_id' => $customerUser->id,
            'customer_code' => '7349',
            'initial_meter_reading' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $periodMonth = max(1, (int) now()->format('m') - 1);
        MonthlyBill::create([
            'customer_id' => $customerId,
            'billing_period_year' => now()->year,
            'billing_period_month' => $periodMonth,
            'meter_reading_start' => 0,
            'meter_reading_end' => 10,
            'usage_m3' => 10,
            'usage_charge' => 50000,
            'abodemen' => 10000,
            'penalty_amount' => 0,
            'total_amount' => 60000,
            'status' => 'unpaid',
            // `due_date` harus PAST melewati ambang `today - toleransi_tunggakan`.
            // Test ini menyetel toleransi = tanggal hari ini, jadi ambangnya
            // bergeser mengikuti tanggal eksekusi. `subDays(5)` dulu dipakai
            // sebagai tebakan tetap dan langsung gagal pada tanggal 1-4:
            // ambang jatuh di bulan sebelumnya sehingga `subDays(5)` justru
            // LEBIH baru dari ambang, sehingga tagihan tidak terdeteksi
            // menunggak dan jurnal tidak pernah terbentuk.
            // `subMonths(1)->day(1)` menaruh due_date di awal bulan lalu,
            // yang pasti lebih kecil dari ambang berapa pun tanggal test jalan.
            'due_date' => now()->subMonths(1)->day(1)->toDateString(),
        ]);

        $response = $this->withHeaders($this->authHeaders($adminUser))
            ->postJson('/api/dashboard/auto-generate-overdue');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'ran' => true,
            ]);

        // Keterangan harus mengandung "Piutang Abodemen bulan <long month> <year>
        // an. Fuji Riyanta (7349)" untuk jurnal 4.1.01.02.
        $monthsLong = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
        $expectedPeriod = $monthsLong[$periodMonth].' '.now()->year;
        $expectedSuffix = 'bulan '.$expectedPeriod.' an. Fuji Riyanta (7349)';

        $abodemenJurnal = Transaction::where('reverence_type', 'overdue_bill')
            ->where('account_kredit', '4.1.01.02')
            ->first();
        $this->assertNotNull($abodemenJurnal, 'Harus ada jurnal piutang Abodemen.');
        $this->assertSame(
            'Piutang Abodemen '.$expectedSuffix,
            $abodemenJurnal->keterangan_transaksi,
            'Format keterangan Abodemen harus "Piutang Abodemen <suffix>".'
        );

        // Jurnal untuk 4.1.01.03 ("Tagihan Pemakaian") harus BERITAH
        // pemakaian, bukan denda.
        //
        // Versi lama test ini mengunci perilaku yang salah: akun `4.1.01.03`
        // adalah "Tagihan Pemakaian" (`AccountsTableSeeder.php:94`), tetapi
        // keterangannya ditulis "Piutang Denda". Akibatnya baris buku besar
        // menampilkan pendapatan denda pada akun pemakaian, dan akun Denda
        // (`4.1.01.04`) tidak pernah dipakai di jalur accrue sama sekali.
        $pemakaianJurnal = Transaction::where('reverence_type', 'overdue_bill')
            ->where('account_kredit', '4.1.01.03')
            ->first();
        $this->assertNotNull($pemakaianJurnal, 'Harus ada jurnal piutang Pemakaian.');
        $this->assertSame(
            'Piutang Pemakaian '.$expectedSuffix,
            $pemakaianJurnal->keterangan_transaksi,
            'Format keterangan Pemakaian harus "Piutang Pemakaian <suffix>".'
        );

        // Relasi harus berisi customer_code.
        $this->assertSame('7349', $abodemenJurnal->relasi);
        $this->assertSame('7349', $pemakaianJurnal->relasi);
    }

    #[Test]
    public function command_menggunakan_nama_dari_customer_user_jika_tersedia(): void
    {
        $this->freezeDate();

        // Bila customer->user->name ada, gunakan itu.
        // (lihat Customer->user relasi).
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => (int) now()->format('d'),
            'toleransi_tunggakan' => (int) now()->format('d'),
        ]);

        $adminUser = $this->createUser('admin');

        $packageId = \DB::table('installation_packages')->insertGetId([
            'name' => 'Paket Test',
            'installation_fee' => 0,
            'monthly_abodemen' => 10000,
            'late_penalty' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = \DB::table('installation_tickets')->insertGetId([
            'package_id' => $packageId,
            'applicant_name' => 'Nama di Tiket', // berbeda
            'nik' => '3321010101900002',
            'order_date' => now()->toDateString(),
            'address' => 'Alamat Test',
            'lat' => -7.1234567,
            'lng' => 110.1234567,
            'status' => 'completed',
            'created_by' => $adminUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerUser = User::create([
            'name' => 'Nama di User', // sumber utama
            'email' => 'nama.user-'.uniqid().'@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'pelanggan',
        ]);

        $customerId = \DB::table('customers')->insertGetId([
            'ticket_id' => $ticketId,
            'user_id' => $customerUser->id,
            'customer_code' => 'CUST-X',
            'initial_meter_reading' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        MonthlyBill::create([
            'customer_id' => $customerId,
            'billing_period_year' => now()->year,
            'billing_period_month' => max(1, (int) now()->format('m') - 1),
            'meter_reading_start' => 0,
            'meter_reading_end' => 10,
            'usage_m3' => 10,
            'usage_charge' => 50000,
            'abodemen' => 10000,
            'penalty_amount' => 0,
            'total_amount' => 60000,
            'status' => 'unpaid',
            // `due_date` harus PAST melewati ambang `today - toleransi_tunggakan`.
            // Test ini menyetel toleransi = tanggal hari ini, jadi ambangnya
            // bergeser mengikuti tanggal eksekusi. `subDays(5)` dulu dipakai
            // sebagai tebakan tetap dan langsung gagal pada tanggal 1-4:
            // ambang jatuh di bulan sebelumnya sehingga `subDays(5)` justru
            // LEBIH baru dari ambang, sehingga tagihan tidak terdeteksi
            // menunggak dan jurnal tidak pernah terbentuk.
            // `subMonths(1)->day(1)` menaruh due_date di awal bulan lalu,
            // yang pasti lebih kecil dari ambang berapa pun tanggal test jalan.
            'due_date' => now()->subMonths(1)->day(1)->toDateString(),
        ]);

        $this->withHeaders($this->authHeaders($adminUser))
            ->postJson('/api/dashboard/auto-generate-overdue')
            ->assertOk();

        $jurnal = Transaction::where('reverence_type', 'overdue_bill')
            ->where('account_kredit', '4.1.01.02')
            ->first();
        $this->assertNotNull($jurnal);
        $this->assertStringContainsString('Nama di User', $jurnal->keterangan_transaksi);
        $this->assertStringNotContainsString('Nama di Tiket', $jurnal->keterangan_transaksi);
    }
}
