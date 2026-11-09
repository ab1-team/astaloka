<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;

/**
 * Satu-satunya sumber aturan siklus tagihan.
 *
 * DUA kolom SOP, DUA fungsi yang berbeda:
 *
 *   - `settings.toleransi_tunggakan` = TANGGAL (1-31). Ini tanggal akhir
 *     bayar / jatuh tempo tagihan.
 *   - `settings.batas_tagihan` = JUMLAH BULAN tunggakan yang boleh
 *     menumpuk sebelum sambungan pelanggan di-suspend.
 *
 * Nilai-nilai itu TIDAK boleh ditanam di kode. Semua jalur (generate
 * tagihan, pencatatan meter, hitung ulang, dan suspend) membaca dari
 * sini supaya angka yang tampil di SOP selalu sama dengan yang dipakai
 * sistem.
 *
 * ATURAN JATUH TEMPO
 * ------------------
 * Tanggal toleransi dihitung pada BULAN PENCATATAN meter:
 *
 *   - pencatatan tanggal <= toleransi -> jatuh tempo bulan itu;
 *   - pencatatan tanggal >  toleransi -> jatuh tempo bulan berikutnya.
 *
 * Contoh (toleransi 26):
 *   2 November 2026  -> 26 November 2026
 *   29 Januari 2026  -> 26 Februari 2026
 */
class BillingCycleSettings
{
    /**
     * Tanggal akhir bayar dari SOP. 0 = belum diisi.
     */
    public static function dueDay(): int
    {
        $settings = Setting::first();
        $day = (int) ($settings?->toleransi_tunggakan ?? 0);

        return ($day >= 1 && $day <= 31) ? $day : 0;
    }

    /**
     * Jatuh tempo untuk satu waktu pencatatan meter.
     *
     * @param  string|\DateTimeInterface|null  $recordedAt
     * @return string|null null kalau tanggal toleransi belum diisi
     */
    public static function dueDate($recordedAt = null, ?int $dueDay = null): ?string
    {
        $day = $dueDay ?? self::dueDay();

        if ($day < 1) {
            return null;
        }

        return self::dueDateFor($recordedAt, $day);
    }

    /**
     * Varian `dueDate()` dengan tanggal toleransi eksplisit.
     *
     * Dipakai test dan pemanggil yang sudah membaca SOP sekali, supaya
     * tidak query `settings` berulang kali di tengah loop.
     */
    public static function dueDateFor($recordedAt, int $dueDay): ?string
    {
        if ($dueDay < 1) {
            return null;
        }

        $recorded = self::recordedDate($recordedAt);
        $month = $recorded->copy()->startOfMonth();

        if ($recorded->day > $dueDay) {
            $month = $month->addMonthNoOverflow()->startOfMonth();
        }

        return $month
            ->setDay(min($dueDay, $month->daysInMonth))
            ->toDateString();
    }

    /**
     * Ubah waktu pencatatan menjadi tanggal kalender lokal.
     *
     * `null` berarti "sekarang", dipakai saat input meter baru.
     */
    public static function recordedDate($recordedAt = null): Carbon
    {
        $timezone = self::timezone();

        if ($recordedAt instanceof \DateTimeInterface) {
            return Carbon::instance($recordedAt)
                ->setTimezone($timezone)
                ->startOfDay();
        }

        if (is_string($recordedAt) && trim($recordedAt) !== '') {
            return Carbon::parse($recordedAt, $timezone)->startOfDay();
        }

        return Carbon::now($timezone)->startOfDay();
    }

    public static function timezone(): string
    {
        return (string) config('app.timezone', 'Asia/Jakarta');
    }

    /**
     * Batas tunggakan dalam BULAN. 0 = auto-suspend dimatikan.
     */
    public static function arrearsLimit(): int
    {
        $settings = Setting::first();

        return max(0, (int) ($settings?->batas_tagihan ?? 0));
    }
}
