<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallationPackage;
use App\Models\InstallationTicket;
use App\Models\MeterReading;
use App\Models\MonthlyBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kolom "Awal" pada Cetak Form Input harus berisi meter kumulatif terakhir.
 *
 * Konteks: halaman `/usages/cetak_form` dipakai teknisi untuk mencatat
 * meter AKHIR periode berjalan. Kolom "Awal" adalah titik mulanya, jadi
 * kalau kolom itu salah, seluruh pemakaian yang dihitung teknisi salah.
 *
 * Bug yang dikunci di sini: kolom "Awal" ikut fallback ke
 * `customers.initial_meter_reading`, yang nilainya 0 untuk seluruh data
 * legacy. Akibatnya form cetak Oktober (tagihannya belum digenerate)
 * menampilkan kolom Awal = 0 untuk semua pelanggan -- padahal meter akhir
 * September sudah tercatat.
 *
 * Perilaku yang dikunci:
 *
 *   1. Tagihan periode ini SUDAH ada  -> "Awal" = `meter_reading_start`
 *      tagihan itu (sudah final, dihitung BillingService dari reading N-1).
 *
 *   2. Tagihan periode ini BELUM ada -> "Awal" = meter kumulatif terakhir
 *      yang diketahui. Sumbernya: `monthly_bills.meter_reading_end` atau
 *      `meter_readings.meter_value`, mana pun periodenya paling baru.
 *
 *   3. Pelanggan yang belum punya meter sama sekali -> "Awal" =
 *      `initial_meter_reading` (fallback terakhir, boleh 0).
 *
 *   4. Meter 0 itu angka meter yang SAH (meter belum pernah dipakai), jadi
 *      pilihan sumber tidak boleh melompat ke sumber berikutnya hanya
 *      karena nilainya nol.
 *
 * CATATAN MULTI-TENANT
 * --------------------
 * Semua query lewat model Eloquent atau lewat koneksi default yang sudah
 * diarahkan ke database LOKASI. Tidak ada query ke database pusat.
 */
class CetakFormInputMeterAwalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Test',
            'email' => 'admin@cetak.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }

    private function makePaket(): InstallationPackage
    {
        return InstallationPackage::create([
            'name' => 'Paket Cetak Test',
            'installation_fee' => 1_500_000,
            'monthly_abodemen' => 15_000,
            'late_penalty' => 10_000,
        ]);
    }

    private function makeCustomer(InstallationPackage $package, int $meterAwal = 0): Customer
    {
        $user = User::create([
            'name' => 'Pelanggan '.uniqid(),
            'email' => uniqid().'@cetak.test',
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
            'created_by' => $this->admin->id,
        ]);

        return Customer::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'customer_code' => 'CETAK-'.uniqid(),
            'initial_meter_reading' => $meterAwal,
            'meter_photo_url' => '/storage/test.jpg',
            'activated_at' => now()->subMonths(3),
        ]);
    }

    private function catatMeter(Customer $customer, int $tahun, int $bulan, int $nilai): MeterReading
    {
        return MeterReading::create([
            'customer_id' => $customer->id,
            'meter_value' => $nilai,
            'reading_month' => $bulan,
            'reading_year' => $tahun,
            'recorded_at' => now(),
            'recorded_by' => $this->admin->id,
        ]);
    }

    private function buatTagihan(Customer $customer, int $tahun, int $bulan, float $awal, float $akhir): MonthlyBill
    {
        return MonthlyBill::create([
            'customer_id' => $customer->id,
            'billing_period_year' => $tahun,
            'billing_period_month' => $bulan,
            'meter_reading_start' => $awal,
            'meter_reading_end' => $akhir,
            'usage_m3' => max(0, $akhir - $awal),
            'usage_charge' => 0,
            'abodemen' => 15_000,
            'penalty_amount' => 0,
            'total_amount' => 15_000,
            'status' => 'unpaid',
            'due_date' => now()->addMonth()->toDateString(),
        ]);
    }

    private function ambil(string $tahun, int $bulan): array
    {
        Sanctum::actingAs($this->admin);

        $res = $this->getJson('/api/monthly-bills/usage?month='.$bulan.'&year='.$tahun);

        $res->assertOk();

        $data = collect($res->json('data'))->keyBy('id');

        return $data->all();
    }

    #[Test]
    public function tagihan_periode_ini_sudah_ada_meter_awal_dari_tagihan(): void
    {
        $paket = $this->makePaket();
        $pelanggan = $this->makeCustomer($paket);

        // Tagihan Oktober sudah ada -> "Awal" final dari tagihan.
        $this->buatTagihan($pelanggan, 2026, 10, 120, 135);

        $data = $this->ambil('2026', 10);

        $this->assertSame(120, (int) $data[$pelanggan->id]['meter_awal']);
    }

    #[Test]
    public function tagihan_belum_ada_meter_awal_dari_tagihan_sebelumnya(): void
    {
        $paket = $this->makePaket();
        $pelanggan = $this->makeCustomer($paket);

        // September sudah ada tagihannya, Oktober belum (belum digenerate).
        $this->buatTagihan($pelanggan, 2026, 9, 100, 120);

        $data = $this->ambil('2026', 10);

        $this->assertSame(
            120,
            (int) $data[$pelanggan->id]['meter_awal'],
            'Kolom Awal harus meter akhir September, bukan 0.'
        );
    }

    #[Test]
    public function tagihan_belum_ada_meter_awal_dari_pembacaan_sebelumnya(): void
    {
        $paket = $this->makePaket();
        $pelanggan = $this->makeCustomer($paket);

        // Tidak ada tagihan sama sekali, tapi meter September tercatat.
        $this->catatMeter($pelanggan, 2026, 9, 120);

        $data = $this->ambil('2026', 10);

        $this->assertSame(
            120,
            (int) $data[$pelanggan->id]['meter_awal'],
            'Kolom Awal harus meter tercatat terakhir, bukan 0.'
        );
    }

    #[Test]
    public function sumber_dengan_periode_terbaru_yang_dipakai(): void
    {
        $paket = $this->makePaket();
        $pelanggan = $this->makeCustomer($paket);

        // Tagihan Agustus (meter akhir 110) dan pembacaan September (125).
        // September lebih baru, jadi THAT yang dipakai.
        $this->buatTagihan($pelanggan, 2026, 8, 100, 110);
        $this->catatMeter($pelanggan, 2026, 9, 125);

        $data = $this->ambil('2026', 10);

        $this->assertSame(
            125,
            (int) $data[$pelanggan->id]['meter_awal'],
            'Periode terbaru harus menang, bukan yang paling lama.'
        );
    }

    #[Test]
    public function periode_lebih_lama_dari_periode_berjalan_tidak_dipakai(): void
    {
        $paket = $this->makePaket();
        $pelanggan = $this->makeCustomer($paket);

        // Oktober sudah ada pembacaannya (150), November yang diminta.
        // Meter Oktober adalah "Awal" November.
        $this->catatMeter($pelanggan, 2026, 10, 150);

        $data = $this->ambil('2026', 11);

        $this->assertSame(
            150,
            (int) $data[$pelanggan->id]['meter_awal'],
            'Pembacaan pada periode yang sedang berjalan tidak boleh jadi "Awal" periode itu.'
        );
    }

    #[Test]
    public function pelanggan_tanpa_meter_apapun_pakai_initial_meter_reading(): void
    {
        $paket = $this->makePaket();
        $pelanggan = $this->makeCustomer($paket, 42);

        $data = $this->ambil('2026', 10);

        $this->assertSame(
            42,
            (int) $data[$pelanggan->id]['meter_awal'],
            'Tanpa data meter, initial_meter_reading adalah fallback terakhir.'
        );
    }

    #[Test]
    public function meter_nol_bukan_berarti_tidak_ada_data(): void
    {
        $paket = $this->makePaket();
        $pelanggan = $this->makeCustomer($paket, 999);

        // Meter 0 itu sah: meter belum pernah dipakai.
        $this->buatTagihan($pelanggan, 2026, 9, 0, 0);

        $data = $this->ambil('2026', 10);

        $this->assertSame(
            0,
            (int) $data[$pelanggan->id]['meter_awal'],
            'Nilai 0 harus dihormati, bukan melompat ke sumber berikutnya.'
        );
    }

    #[Test]
    public function tagihan_yang_terhapus_tidak_dipakai_sebagai_sumber(): void
    {
        $paket = $this->makePaket();
        $pelanggan = $this->makeCustomer($paket);

        // Tagihan September dihapus (soft delete) -> tidak boleh jadi sumber.
        $tagihan = $this->buatTagihan($pelanggan, 2026, 9, 100, 120);
        $tagihan->delete();

        // Pembacaan Agustus masih ada dan itu yang dipakai.
        $this->catatMeter($pelanggan, 2026, 8, 90);

        $data = $this->ambil('2026', 10);

        $this->assertSame(
            90,
            (int) $data[$pelanggan->id]['meter_awal'],
            'Tagihan yang dihapus tidak boleh jadi sumber meter awal.'
        );
    }

    #[Test]
    public function periode_tagihan_ikut_dikirim(): void
    {
        $paket = $this->makePaket();
        $pelanggan = $this->makeCustomer($paket);

        $this->buatTagihan($pelanggan, 2026, 10, 120, 135);

        $data = $this->ambil('2026', 10);

        $this->assertSame(10, (int) $data[$pelanggan->id]['billing_period_month']);
        $this->assertSame(2026, (int) $data[$pelanggan->id]['billing_period_year']);
    }
}
