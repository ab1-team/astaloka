<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\BillingCycleSettings;
use App\Services\BillingService;
use App\Services\MonthlyBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aturan jatuh tempo tagihan: dihitung pada BULAN PENCATATAN meter.
 *
 * Contoh (toleransi 26):
 *   2 November 2026  -> 26 November 2026
 *   29 Januari 2026  -> 26 Februari 2026
 *
 * `settings.toleransi_tunggakan` = TANGGAL.
 * `settings.batas_tagihan`       = JUMLAH BULAN sebelum suspend.
 */
class BillingDueDateTest extends TestCase
{
    use RefreshDatabase;

    private function setSop(int $toleransi, int $batasBulan = 3): void
    {
        Setting::create([
            'key' => 'sop',
            'toleransi_tunggakan' => $toleransi,
            'batas_tagihan' => $batasBulan,
        ]);
    }

    #[Test]
    public function jatuh_tempo_mengikuti_bulan_pencatatan(): void
    {
        $this->assertSame(
            '2026-11-26',
            BillingCycleSettings::dueDateFor('2026-11-02 08:00:00', 26),
            'Pencatatan 2 November harus jatuh tempo 26 November.'
        );

        $this->assertSame(
            '2026-02-26',
            BillingCycleSettings::dueDateFor('2026-01-29 08:00:00', 26),
            'Pencatatan 29 Januari harus jatuh tempo 26 Februari.'
        );
    }

    #[Test]
    public function pencatatan_pada_tanggal_toleransi_masih_bulan_yang_sama(): void
    {
        $this->assertSame(
            '2026-11-26',
            BillingCycleSettings::dueDateFor('2026-11-26 23:59:59', 26),
            'Pencatatan tepat pada tanggal toleransi belum lewat, jadi masih bulan yang sama.'
        );

        $this->assertSame(
            '2026-12-26',
            BillingCycleSettings::dueDateFor('2026-11-27 00:00:00', 26),
            'Pencatatan setelah tanggal toleransi bergeser ke bulan berikutnya.'
        );
    }

    #[Test]
    public function tanggal_dijepit_ke_hari_terakhir_bulan(): void
    {
        $this->assertSame(
            '2026-02-28',
            BillingCycleSettings::dueDateFor('2026-02-01', 31),
            'Batas 31 di Februari harus dijepit ke 28.'
        );

        $this->assertSame(
            '2028-02-29',
            BillingCycleSettings::dueDateFor('2028-02-01', 31),
            'Tahun kabisat: Februari punya 29 hari.'
        );

        $this->assertSame(
            '2026-11-30',
            BillingCycleSettings::dueDateFor('2026-11-01', 31),
            'November hanya punya 30 hari.'
        );
    }

    #[Test]
    public function toleransi_kosong_tidak_memakai_angka_bawaan(): void
    {
        $this->assertNull(BillingCycleSettings::dueDateFor('2026-11-02', 0));
    }

    #[Test]
    public function dua_kolom_sop_punya_arti_masing_masing(): void
    {
        $this->setSop(26, 3);

        $this->assertSame(26, BillingCycleSettings::dueDay());
        $this->assertSame(3, BillingCycleSettings::arrearsLimit());
    }

    #[Test]
    public function semua_service_memakai_rumus_yang_sama(): void
    {
        $service = app(BillingService::class);
        $massal = app(MonthlyBillService::class);

        foreach ([
            ['2026-11-02 08:00:00', 26],
            ['2026-01-29 08:00:00', 26],
            ['2026-12-01 08:00:00', 31],
        ] as [$recordedAt, $dueDay]) {
            $expected = BillingCycleSettings::dueDateFor($recordedAt, $dueDay);

            $this->assertSame(
                $expected,
                $service->computeDueDate($recordedAt, $dueDay),
                'BillingService harus memakai rumus yang sama.'
            );

            $this->assertSame(
                $expected,
                $massal->computeDueDate($recordedAt, $dueDay),
                'MonthlyBillService harus memakai rumus yang sama.'
            );
        }
    }
}
