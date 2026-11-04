<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallationPackage;
use App\Models\InstallationTicket;
use App\Models\MeterReading;
use App\Models\MonthlyBill;
use App\Models\User;
use App\Models\WaterTariffBlock;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Least-privilege daftar & detail tagihan.
 *
 * Halaman "Daftar Tagihan Tertunggak" milik teknisi memakai server-side
 * pagination: frontend memanggil `GET /api/monthly-bills` dan
 * `GET /api/monthly-bills/{id}`. Kalau backend tidak memfilter tagihan
 * milik teknisi, teknisi melihat (dan bisa membuka) tagihan pelanggan
 * teknisi lain -- datanya bocor meski tampilannya terlihat benar.
 *
 * Filter dipasang di level QUERY (bukan di PHP setelah fetch) supaya
 * `meta.total` dan paginasi ikut benar. Kalau tidak, teknisi melihat
 * "254 tagihan" tapi halamannya berisi baris milik teknisi lain.
 *
 * CATATAN MULTI-TENANT
 * --------------------
 * Semua query lewat model Eloquent, jadi otomatis memakai koneksi tenant
 * yang sedang aktif (database LOKASI).
 */
class LeastPrivilegeTagihanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisiA;

    private User $teknisiB;

    private BillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Test',
            'email' => 'admin@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $this->teknisiA = User::create([
            'name' => 'Teknisi A',
            'email' => 'teknisi-a@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'teknisi',
        ]);

        $this->teknisiB = User::create([
            'name' => 'Teknisi B',
            'email' => 'teknisi-b@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'teknisi',
        ]);

        $this->billing = new BillingService;
    }

    private function makePaket(): InstallationPackage
    {
        $package = InstallationPackage::create([
            'name' => 'Paket Test',
            'installation_fee' => 1_500_000,
            'monthly_abodemen' => 15_000,
            'late_penalty' => 10_000,
        ]);

        WaterTariffBlock::create([
            'package_id' => $package->id,
            'usage_min_m3' => 0,
            'usage_max_m3' => 20,
            'price_per_m3' => 2_000,
        ]);

        WaterTariffBlock::create([
            'package_id' => $package->id,
            'usage_min_m3' => 20,
            'usage_max_m3' => null,
            'price_per_m3' => 3_000,
        ]);

        return $package;
    }

    private function makeCustomer(InstallationPackage $package, ?User $oleh): Customer
    {
        $user = User::create([
            'name' => 'Pelanggan '.uniqid(),
            'email' => uniqid().'@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'pelanggan',
        ]);

        $ticket = InstallationTicket::create([
            'package_id' => $package->id,
            'applicant_name' => $user->name,
            'nik' => '330000000000'.random_int(1000, 9999),
            'address' => 'Jl. Test No. 1',
            'lat' => -7.797068,
            'lng' => 110.370529,
            'status' => 'completed',
            // `user_id` = teknisi yang menangani instalasi pelanggan ini.
            // Inilah sumber kebenaran untuk filter least-privilege (lihat
            // `AuthorizesMeterReadings::isWithinTechnicianArea`).
            'user_id' => $oleh?->id ?? $this->admin->id,
            'created_by' => $oleh?->id ?? $this->admin->id,
        ]);

        return Customer::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'customer_code' => 'PDAM-'.uniqid(),
            'initial_meter_reading' => 119,
            'meter_photo_url' => '/storage/test.jpg',
            'activated_at' => now()->subMonth(),
        ]);
    }

    private function buatTagihan(Customer $customer): MonthlyBill
    {
        MeterReading::create([
            'customer_id' => $customer->id,
            'meter_value' => 129,
            'reading_month' => now()->month,
            'reading_year' => now()->year,
            'recorded_at' => now(),
            'recorded_by' => $this->admin->id,
        ]);

        return $this->billing->generateForCustomer($customer, now()->year, now()->month);
    }

    /**
     * Daftar tagihan milik teknisi hanya berisi pelanggan yang ia tangani.
     */
    #[Test]
    public function daftar_tagihan_teknisi_hanya_berisi_pelanggan_miliknya(): void
    {
        $package = $this->makePaket();

        $milikA = $this->makeCustomer($package, $this->teknisiA);
        $milikB = $this->makeCustomer($package, $this->teknisiB);

        $billA = $this->buatTagihan($milikA);
        $this->buatTagihan($milikB);

        Sanctum::actingAs($this->teknisiA, ['*'], 'sanctum');

        $res = $this->getJson('/api/monthly-bills?status=unpaid')->assertOk();

        $ids = collect($res->json('data.bills'))->pluck('id')->map(fn ($v) => (int) $v)->all();

        $this->assertContains($billA->id, $ids, 'Tagihan milik teknisi sendiri harus muncul.');
        $this->assertCount(1, $ids, 'Hanya tagihan milik teknisi sendiri yang boleh muncul.');
    }

    /**
     * `meta.total` juga harus terfilter.
     *
     * Kalau hanya barisnya yang difilter tapi `total` dihitung dari semua
     * tagihan, paginasi jadi bohong: teknisi melihat "3 halaman" tapi
     * halaman 2 dan 3 kosong.
     */
    #[Test]
    public function meta_total_daftar_tagihan_teknisi_terfilter(): void
    {
        $package = $this->makePaket();

        $this->buatTagihan($this->makeCustomer($package, $this->teknisiA));

        // Tiga pelanggan milik teknisi lain -- tidak boleh ikut terhitung.
        for ($i = 0; $i < 3; $i++) {
            $this->buatTagihan($this->makeCustomer($package, $this->teknisiB));
        }

        Sanctum::actingAs($this->teknisiA, ['*'], 'sanctum');

        $res = $this->getJson('/api/monthly-bills?status=unpaid')->assertOk();

        $this->assertSame(1, (int) $res->json('meta.total'), 'Total harus hanya menghitung tagihan milik teknisi.');
        $this->assertSame(1, (int) $res->json('meta.last_page'), 'Jumlah halaman harus mengikuti total yang terfilter.');
    }

    /**
     * Admin tetap melihat SEMUA tagihan.
     */
    #[Test]
    public function admin_tetap_melihat_semua_tagihan(): void
    {
        $package = $this->makePaket();

        $this->buatTagihan($this->makeCustomer($package, $this->teknisiA));
        $this->buatTagihan($this->makeCustomer($package, $this->teknisiB));

        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $res = $this->getJson('/api/monthly-bills?status=unpaid')->assertOk();

        $this->assertSame(2, (int) $res->json('meta.total'));
        $this->assertCount(2, $res->json('data.bills'));
    }

    /**
     * Teknisi boleh membuka rincian tagihan miliknya sendiri.
     */
    #[Test]
    public function teknisi_bisa_membuka_detail_tagihan_miliknya(): void
    {
        $package = $this->makePaket();
        $customer = $this->makeCustomer($package, $this->teknisiA);
        $bill = $this->buatTagihan($customer);

        Sanctum::actingAs($this->teknisiA, ['*'], 'sanctum');

        $this->getJson('/api/monthly-bills/'.$bill->id)
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    /**
     * Teknisi TIDAK boleh membuka rincian tagihan milik teknisi lain,
     * walaupun dia tahu ID-nya (mis. dari URL lama atau request manual).
     */
    #[Test]
    public function teknisi_ditolak_membuka_detail_tagihan_teknisi_lain(): void
    {
        $package = $this->makePaket();
        $customer = $this->makeCustomer($package, $this->teknisiB);
        $bill = $this->buatTagihan($customer);

        Sanctum::actingAs($this->teknisiA, ['*'], 'sanctum');

        $this->getJson('/api/monthly-bills/'.$bill->id)
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    /**
     * Admin bebas membuka detail tagihan siapa saja.
     */
    #[Test]
    public function admin_bisa_membuka_detail_tagihan_siapa_saja(): void
    {
        $package = $this->makePaket();
        $customer = $this->makeCustomer($package, $this->teknisiB);
        $bill = $this->buatTagihan($customer);

        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $this->getJson('/api/monthly-bills/'.$bill->id)
            ->assertOk()
            ->assertJson(['success' => true]);
    }
}
