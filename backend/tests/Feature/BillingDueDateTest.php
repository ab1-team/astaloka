<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallationPackage;
use App\Models\InstallationTicket;
use App\Models\MonthlyBill;
use App\Models\Setting;
use App\Models\User;
use App\Services\BillingDueDate;
use App\Services\BillingService;
use App\Services\MonthlyBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aturan jatuh tempo tagihan bulanan: dibayar pada bulan BERIKUTNYA.
 *
 * Latar belakang
 * ---------------
 *
 * Pelanggan memakai air di bulan berjalan, tapi tagihannya keluar di bulan
 * berikutnya -- persis seperti listrik dan internet. Jadi tagihan pemakaian
 * Juni 2026 dengan `batas_tagihan` = 26 jatuh tempo 26 Juli 2026.
 *
 * Sebelumnya jatuh tempo dihitung di bulan yang SAMA dengan bulan pemakaian
 * (tagihan Juni jatuh tempo 26 Juni). Itu membuat pelanggan ditagih untuk
 * air yang belum selesai dipakai, di bulan yang sama.
 *
 * Aplikasi lama (`siap_mapan`) sudah memakai aturan yang benar:
 *
 * ```php
 * // UsageController.php:303
 * $tglAkhir = date('Y-m', strtotime('+1 month', $tglPakai))
 *            . '-' . str_pad($data['toleransi'], 2, '0', STR_PAD_LEFT);
 * ```
 *
 * Test di sini mengunci rumusnya supaya tidak ada lagi salinan rumus yang
 * berbeda satu sama lain -- dan supaya behavior yang diminta tidak berubah
 * diam-diam.
 */
class BillingDueDateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Satu pelanggan + satu SOP dengan `batas_tagihan` = 26.
     *
     * `batas_tagihan` dipakai migration di bawah, jadi nilainya harus
     * diset eksplisit -- kalau tidak, migration memakai nilai bawaan
     * (27) dan assertion soal tanggal 26 tidak ada artinya.
     */
    private function seedPelangganDanSop(): Customer
    {
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 26,
        ]);

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
            'customer_code' => 'PDAM-DUEDATE-00001',
            'initial_meter_reading' => 0,
            'activated_at' => now(),
        ]);
    }

    #[Test]
    public function jatuh_tempo_berada_di_bulan_tagihan_bukan_bulan_pemakaian(): void
    {
        // Contoh utama: pakai dulu, bayar bulan depan.
        $this->assertSame(
            '2026-07-26',
            BillingDueDate::compute(2026, 6, 26),
            'Tagihan pemakaian Juni harus jatuh tempo di bulan Juli.'
        );

        $this->assertSame(
            '2026-08-26',
            BillingDueDate::compute(2026, 7, 26),
            'Tagihan pemakaian Juli harus jatuh tempo di bulan Agustus.'
        );
    }

    #[Test]
    public function bulan_tagihan_melewati_tahun_benar_benar(): void
    {
        $this->assertSame(
            '2027-01-26',
            BillingDueDate::compute(2026, 12, 26),
            'Pemakaian Desember harus jatuh tempo di Januari tahun depan.'
        );

        $this->assertSame(
            '2026-02-26',
            BillingDueDate::compute(2026, 1, 26),
            'Pemakaian Januari harus jatuh tempo di Februari.'
        );
    }

    #[Test]
    public function tanggal_dijepit_ke_hari_terakhir_bulan_tagihan(): void
    {
        // Tagihan pemakaian Januari jatuh tempo di Februari, dan Februari
        // tahun biasa hanya punya 28 hari -- jadi tanggal 31 dijepit ke 28.
        $this->assertSame(
            '2026-02-28',
            BillingDueDate::compute(2026, 1, 31),
            'Batas 31 di Februari harus dijepit ke 28.'
        );

        // Tahun kabisat: Februari punya 29 hari.
        $this->assertSame(
            '2028-02-29',
            BillingDueDate::compute(2028, 1, 31),
            'Batas 31 di Februari tahun kabisat harus dijepit ke 29.'
        );

        // November hanya punya 30 hari.
        $this->assertSame(
            '2026-11-30',
            BillingDueDate::compute(2026, 10, 31),
            'Batas 31 di November harus dijepit ke 30.'
        );
    }

    #[Test]
    public function tanggal_di_luar_rentang_dijepit_ke_awal_bulan(): void
    {
        // Angka 0 atau negatif tidak mungkin dipakai sebagai tanggal, jadi
        // dijepit ke tanggal 1 -- bukan menghasilkan tanggal kosong.
        $this->assertSame(
            '2026-07-01',
            BillingDueDate::compute(2026, 6, 0),
            'Tanggal 0 harus dijepit ke tanggal 1.'
        );

        $this->assertSame(
            '2026-07-01',
            BillingDueDate::compute(2026, 6, -5),
            'Tanggal negatif harus dijepit ke tanggal 1.'
        );
    }

    #[Test]
    public function tanggal_acuan_menyimpan_tahun_bulan_dan_jam_nol(): void
    {
        $threshold = BillingDueDate::inBillingMonth(2026, 6, 26);

        $this->assertSame('2026-07-26', $threshold->toDateString());
        $this->assertSame('00:00:00', $threshold->format('H:i:s'), 'Awal hari untuk perbandingan tanggal.');
    }

    #[Test]
    public function semua_pemakai_berisi_satu_rumus_yang_sama(): void
    {
        // Dua service yang berbeda (generator massal & per pelanggan) harus
        // menghasilkan tanggal yang sama. Dulu keduanya punya salinan
        // `computeDueDate()` sendiri, dan salinan itulah yang sempat
        // berbeda satu sama lain.
        $service = app(BillingService::class);
        $massal = app(MonthlyBillService::class);

        foreach ([[2026, 6, 26], [2026, 12, 31], [2026, 2, 28]] as [$year, $month, $day]) {
            $expected = BillingDueDate::compute($year, $month, $day);

            $this->assertSame(
                $expected,
                $service->computeDueDate($year, $month, $day),
                'BillingService harus sama dengan rumus tunggal.'
            );

            $this->assertSame(
                $expected,
                $massal->computeDueDate($year, $month, $day),
                'MonthlyBillService harus sama dengan rumus tunggal.'
            );
        }
    }

    #[Test]
    public function tagihan_yang_sudah_ada_jatuh_temponya_ikut_bergeser(): void
    {
        // Migration harus menjangkau tagihan yang SUDAH ada, termasuk yang
        // sudah lunas -- supaya satu halaman tidak menampilkan dua jenis
        // tanggal jatuh tempo untuk bulan yang sama.
        //
        // Baris dibuat dengan jatuh tempo LAMA (bulan pemakaian), seperti
        // yang ditulis sebelum aturan ini berlaku.
        $customer = $this->seedPelangganDanSop();

        $bill = MonthlyBill::create([
            'customer_id' => $customer->id,
            'billing_period_year' => 2026,
            'billing_period_month' => 6,
            'meter_reading_start' => 0,
            'meter_reading_end' => 10,
            'usage_m3' => 10,
            'usage_charge' => 10000,
            'abodemen' => 5000,
            'penalty_amount' => 0,
            'total_amount' => 15000,
            'status' => 'paid',
            'due_date' => '2026-06-26',
        ]);

        $this->assertSame(
            '2026-06-26',
            $bill->fresh()->due_date->toDateString(),
            'Sanity check: tagihan dibuat dengan tanggal jatuh tempo lama.'
        );

        // Jalankan migration geser jatuh tempo.
        $migration = require base_path(
            'database/migrations_tenant/2026_10_30_000001_shift_bill_due_date_to_next_month.php'
        );
        $migration->up();

        $this->assertSame(
            '2026-07-26',
            $bill->fresh()->due_date->toDateString(),
            'Jatuh tempo tagihan lama harus bergeser ke bulan tagihan.'
        );

        // Idempotent: jalan lagi tidak mengubah apa pun.
        $migration->up();

        $this->assertSame(
            '2026-07-26',
            $bill->fresh()->due_date->toDateString(),
            'Migration harus idempotent.'
        );
    }
}
