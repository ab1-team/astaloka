<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Satu-satunya tempat "jatuh tempo" tagihan bulanan dihitung.
 *
 * ATURAN BISNIS
 * -------------
 *
 * Pemakaian dibayar pada bulan BERIKUTNYA. Tagihan periode Juni 2026
 * jatuh tempo pada tanggal `batas_tagihan` di bulan Juli 2026.
 *
 * Ini yang dipakai aplikasi lama (`siap_mapan`) sejak dulu:
 *
 * ```php
 * // UsageController.php:303
 * $tglAkhir = date('Y-m', strtotime('+1 month', $tglPakai))
 *            . '-' . str_pad($data['toleransi'], 2, '0', STR_PAD_LEFT);
 * ```
 *
 * Versi ASTALOKA sebelumnya menghitung jatuh tempo di bulan yang SAMA
 * dengan bulan pemakaian:
 *
 * ```php
 * // BillingService::computeDueDate() (lama)
 * return Carbon::create($year, $month, 1)->setDay($day)->toDateString();
 * ```
 *
 * Yang kedua tidak sesuai aturan bisnis: air dipakai bulan ini,
 * tagihannya keluar di bulan depan -- persis seperti listrik dan internet.
 * Pelanggan yang memakai air bulan Juni baru ditagih pada Juli.
 *
 * KENAPA DI PUSATKAN DI SINI
 * --------------------------
 *
 * Rumus ini dipakai di belasan tempat: generator tagihan massal, generator
 * per pelanggan, hitung-ulang tagihan, import legacy, dan pencatatan meter.
 * Sebelumnya tiap tempat punya salinan sendiri, dan salinan itu sudah
 * pernah berbeda satu sama lain -- `BillingService` dan `MonthlyBillService`
 * punya method `computeDueDate()` yang identik tapi terpisah, jadi mudah
 * kelewat kalau hanya salah satu yang diperbaiki.
 *
 * Dengan satu service, semua pemanggil dijamin memakai rumus yang sama.
 *
 * CATATAN SOAL KOLOM SETTINGS
 * ---------------------------
 *
 * `settings.batas_tagihan` = tanggal jatuh tempo (1-31) yang diset admin
 * lewat SOP. Satuannya HARI, bukan tanggal lengkap -- jadi "hari 26" hanya
 * bermakna kalau bulan TAGIHAN-nya sudah diketahui.
 *
 * Di sinilah letak perubahannya: sebelum aturan ini, "hari 26" berarti
 * 26 Juni untuk tagihan pemakaian Juni. Sesudah aturan ini, "hari 26"
 * berarti 26 JULI untuk tagihan pemakaian Juni -- tanggalnya dihitung di
 * bulan tagihan, bukan di bulan pemakaian.
 */
class BillingDueDate
{
    /**
     * Nilai default `batas_tagihan` kalau SOP belum diisi.
     *
     * Sama dengan fallback yang dipakai service lain (27), supaya lokasi
     * yang belum pernah menyimpan SOP tidak melihat perubahan perilaku
     * yang tidak diminta.
     */
    public const DEFAULT_BATAS_TAGIHAN = 27;

    /**
     * Tanggal jatuh tempo untuk satu periode pemakaian.
     *
     * @param  int  $year  tahun pemakaian, mis. 2026
     * @param  int  $month  bulan pemakaian, 1-12 (mis. 6 = Juni)
     * @param  int  $day  tanggal jatuh tempo (1-31) dari SOP
     */
    public static function compute(int $year, int $month, int $day = self::DEFAULT_BATAS_TAGIHAN): string
    {
        return self::inBillingMonth($year, $month, $day)->toDateString();
    }

    /**
     * Tanggal acuan di bulan TAGIHAN, untuk satu periode pemakaian.
     *
     * Dipakai oleh dua hal yang butuh tanggal, bukan string:
     *   - `compute()` di atas, untuk mengisi kolom `monthly_bills.due_date`;
     *   - ambang deteksi ADVANCE payment, yang comparing tanggal bayar
     *     dengan tanggal acuan ini.
     *
     * @param  int  $year  tahun pemakaian
     * @param  int  $month  bulan pemakaian, 1-12
     * @param  int  $day  tanggal dalam bulan (1-31)
     */
    public static function inBillingMonth(int $year, int $month, int $day = self::DEFAULT_BATAS_TAGIHAN): Carbon
    {
        // Bulan TAGIHAN = bulan pemakaian + 1. Ini inti aturan bisnisnya.
        $billing = Carbon::create($year, $month, 1)->addMonthNoOverflow();

        // Tanggal yang diset dijepit ke hari terakhir bulan tagihan.
        // Jadi batas 31 -> 31 di Oktober, 30 di November, 28/29 di Februari.
        $effectiveDay = min(max($day, 1), $billing->daysInMonth);

        return $billing->setDay($effectiveDay)->startOfDay();
    }
}
