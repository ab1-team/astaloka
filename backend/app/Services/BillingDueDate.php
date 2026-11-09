<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Kompatibilitas lama untuk perhitungan jatuh tempo.
 *
 * Aturan yang berlaku sekarang ada di `BillingCycleSettings`:
 *   - tanggal dari `settings.toleransi_tunggakan`;
 *   - patokan bulan PENCATATAN meter;
 *   - `settings.batas_tagihan` hanya untuk batas bulan suspend.
 *
 * Method `compute()`, `inBillingMonth()`, dan `inUsageMonth()` di bawah
 * dipertahankan hanya untuk migration lama dan test lama. Kode produksi
 * baru tidak boleh memakainya.
 *
 * @deprecated Gunakan `BillingCycleSettings`.
 */
class BillingDueDate
{
    public static function dueDay(): int
    {
        return BillingCycleSettings::dueDay();
    }

    public static function dueDate($recordedAt = null): ?string
    {
        return BillingCycleSettings::dueDate($recordedAt);
    }

    /**
     * @deprecated Gunakan `BillingCycleSettings::dueDate()`.
     */
    public static function compute(int $year, int $month, int $day): string
    {
        return self::inBillingMonth($year, $month, $day)->toDateString();
    }

    /**
     * @deprecated Gunakan `BillingCycleSettings::dueDate()`.
     */
    public static function inBillingMonth(int $year, int $month, int $day): Carbon
    {
        $billing = Carbon::create($year, $month, 1)->addMonthNoOverflow();
        $effectiveDay = min(max($day, 1), $billing->daysInMonth);

        return $billing->setDay($effectiveDay)->startOfDay();
    }

    /**
     * @deprecated Tidak dipakai lagi; `recorded_at` adalah waktu input.
     */
    public static function inUsageMonth(int $year, int $month, int $day): Carbon
    {
        $usage = Carbon::create($year, $month, 1);
        $effectiveDay = min(max($day, 1), $usage->daysInMonth);

        return $usage->setDay($effectiveDay)->startOfDay();
    }
}
