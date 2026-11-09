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
 * Dua kolom SOP, dua arti:
 *
 *   - `toleransi_tunggakan` = TANGGAL jatuh tempo / akhir bayar (1-31);
 *   - `batas_tagihan`       = JUMLAH BULAN tunggakan sebelum suspend.
 */
class BillingToleranceTest extends TestCase
{
    use RefreshDatabase;

    private ?int $customerId = null;

    private function setSOP(int $toleransi, int $batasBulan = 0): void
    {
        Setting::updateOrCreate(
            ['key' => 'sop'],
            [
                'toleransi_tunggakan' => $toleransi,
                'batas_tagihan' => $batasBulan,
            ]
        );
    }

    #[Test]
    public function aturan_tanggal_membaca_toleransi_tunggakan(): void
    {
        $this->setSOP(6, 3);

        $t = app(BillingTolerance::class);

        $this->assertSame(6, $t->tanggal());
        $this->assertSame(6, $t->generateDay());
        $this->assertSame(6, $t->advanceDayOfMonth());
    }

    #[Test]
    public function batas_tunggakan_dibaca_dari_batas_tagihan(): void
    {
        $this->setSOP(20, 3);

        $t = app(BillingTolerance::class);

        $this->assertSame(3, $t->unpaidBillLimit());
        $this->assertSame(20, $t->generateDay());
    }

    #[Test]
    public function batas_tunggakan_nol_berarti_tidak_pernah_memutus(): void
    {
        $this->setSOP(6, 0);

        $t = app(BillingTolerance::class);

        $this->assertSame(0, $t->unpaidBillLimit());
        $this->assertFalse($t->shouldSuspend(999));
    }

    #[Test]
    public function tanpa_setting_batas_tunggakan_berarti_nol(): void
    {
        $t = app(BillingTolerance::class);

        $this->assertSame(0, $t->tanggal());
        $this->assertSame(0, $t->generateDay());
        $this->assertSame(0, $t->unpaidBillLimit());
        $this->assertFalse($t->shouldSuspend(1));
    }

    #[Test]
    public function batas_tunggakan_memakai_operator_ketidak_samaan(): void
    {
        $this->setSOP(6, 3);

        $t = app(BillingTolerance::class);

        $this->assertFalse($t->shouldSuspend(3), 'Tepat sama dengan batas: belum diputus.');
        $this->assertTrue($t->shouldSuspend(4), 'Melebihi batas: diputus.');
    }

    #[Test]
    public function tanggal_di_luar_rentang_tidak_dijadwalkan(): void
    {
        $this->setSOP(0);
        $this->assertSame(0, (new BillingTolerance)->generateDay());

        $this->setSOP(32);
        $this->assertSame(0, (new BillingTolerance)->generateDay());
    }

    #[Test]
    public function tanggal_29_sampai_31_diperpanjang_ke_hari_terakhir_bulan(): void
    {
        $this->setSOP(29);
        $t = new BillingTolerance;
        $this->assertSame(29, $t->generateDay());
        $this->assertSame(29, $t->effectiveGenerateDay(Carbon::parse('2026-10-29')));
        $this->assertSame(28, $t->effectiveGenerateDay(Carbon::parse('2026-02-15')));

        $this->setSOP(31);
        $t = new BillingTolerance;
        $this->assertSame(31, $t->generateDay());
        $this->assertSame(30, $t->effectiveGenerateDay(Carbon::parse('2026-11-15')));
        $this->assertSame(28, $t->effectiveGenerateDay(Carbon::parse('2026-02-15')));
    }

    #[Test]
    public function tagihan_menunggak_menghormati_batas_suspend(): void
    {
        $this->setSOP(6, 3);
        $this->assertSame(3, app(BillingTolerance::class)->unpaidBillLimit());

        $unpaid = MonthlyBill::where('status', 'unpaid')->count();

        $this->assertFalse(app(BillingTolerance::class)->shouldSuspend($unpaid));
    }

    #[Test]
    public function jumlah_bulan_menunggak_dihitung_dari_tagihan_terlama(): void
    {
        $now = Carbon::parse('2026-10-15');

        $this->seedTagihan([2026, 6], '2026-06-26', 'unpaid');
        $this->seedTagihan([2026, 7], '2026-07-26', 'unpaid');
        $this->seedTagihan([2026, 8], '2026-08-26', 'unpaid');

        $customerId = MonthlyBill::orderBy('id')->first()->customer_id;

        $this->assertSame(
            4,
            app(UnpaidMonths::class)->forCustomer($customerId, $now),
            'Menunggak dihitung dari periode tagihan paling lama yang unpaid.'
        );
    }

    #[Test]
    public function tagihan_yang_belum_jatuh_tempo_tidak_dihitung_menunggak(): void
    {
        $now = Carbon::parse('2026-10-15');

        $this->seedTagihan([2026, 10], '2026-10-26', 'unpaid');

        $customerId = MonthlyBill::orderBy('id')->first()->customer_id;

        $this->assertSame(
            0,
            app(UnpaidMonths::class)->forCustomer($customerId, $now)
        );
    }

    #[Test]
    public function tagihan_yang_sudah_dibayar_tidak_dihitung_menunggak(): void
    {
        $now = Carbon::parse('2026-10-15');

        $this->seedTagihan([2026, 6], '2026-06-26', 'paid');
        $this->seedTagihan([2026, 8], '2026-08-26', 'unpaid');

        $customerId = MonthlyBill::orderBy('id')->first()->customer_id;

        $this->assertSame(
            2,
            app(UnpaidMonths::class)->forCustomer($customerId, $now)
        );
    }

    #[Test]
    public function api_sop_menyimpan_dua_kolom(): void
    {
        $admin = $this->buatAdmin();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$admin->createToken('auth_token')->plainTextToken,
        ])->postJson('/api/settings/sop/sistem-tagihan', [
            'toleransiTunggakan' => 21,
            'batasTagihan' => 4,
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $setting = Setting::first();

        $this->assertSame(21, (int) $setting->toleransi_tunggakan);
        $this->assertSame(4, (int) $setting->batas_tagihan);
    }

    #[Test]
    public function api_sop_menerima_alias_lama_batas_bulan_tunggakan(): void
    {
        $admin = $this->buatAdmin();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$admin->createToken('auth_token')->plainTextToken,
        ])->postJson('/api/settings/sop/sistem-tagihan', [
            'toleransiTunggakan' => 21,
            'batasBulanTunggakan' => 4,
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $setting = Setting::first();

        $this->assertSame(21, (int) $setting->toleransi_tunggakan);
        $this->assertSame(4, (int) $setting->batas_tagihan);
    }

    #[Test]
    public function api_sop_mengirim_dua_field_yang_benar(): void
    {
        $admin = $this->buatAdmin();

        $this->setSOP(27, 3);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$admin->createToken('auth_token')->plainTextToken,
        ])->getJson('/api/settings/sop');

        $response->assertOk()
            ->assertJsonPath('data.sistemTagihan.toleransiTunggakan', 27)
            ->assertJsonPath('data.sistemTagihan.batasTagihan', 3);
    }

    private function buatAdmin(): User
    {
        return User::create([
            'name' => 'Admin SOP',
            'email' => 'admin-sop-'.substr(uniqid('', true), -6).'@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }

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

    private function buatPelanggan(): Customer
    {
        $package = InstallationPackage::create([
            'name' => 'Paket Test',
            'installation_fee' => 0,
            'monthly_abodemen' => 10_000,
            'late_penalty' => 0,
        ]);

        $admin = $this->buatAdmin();

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
            'customer_code' => 'PDAM-TOLERANSI-00001',
            'initial_meter_reading' => 0,
            'activated_at' => now(),
        ]);
    }
}
