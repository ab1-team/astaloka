<?php

namespace App\Services;

use App\Models\MonthlyBill;
use Carbon\Carbon;

/**
 * Menghitung berapa BULAN pelanggan menunggak.
 *
 * KENAPA ADA SERVICE INI
 * ---------------------
 *
 * Batas tunggakan di SOP ("Batas Tagihan Bulanan") bersatuan BULAN, bukan
 * jumlah tagihan dan bukan jumlah hari. Sebelumnya angka itu dibaca dari
 * kolom yang sama dengan tanggal generate piutang, sehingga satuan "tanggal
 * dalam bulan" dan "jumlah bulan" bercampur dalam satu kolom.
 *
 * Contoh yang nyata: admin menyetel batas 3. Kalau batas itu dibaca sebagai
 * JUMLAH TAGIHAN, pelanggan yang menunggak 4 tagihan langsung diputus --
 * padahal 4 tagihan itu bisa saja berjarak 4 bulan, atau bisa saja 4 tagihan
 * dalam 2 bulan kalau ada tagihan susulan. Yang ditanyakan admin sebenarnya
 * adalah "berapa bulan pelanggan belum bayar".
 *
 * ATURAN PERHITUNGANNYA
 * --------------------
 *
 * Tagihan dianggap menunggak kalau:
 *
 *   1. status-nya `unpaid` (belum dibayar), DAN
 *   2. sudah lewat jatuh tempo (`due_date` < hari ini).
 *
 * Poin (2) penting: tagihan bulan ini yang jatuh temponya belum sampai
 * TIDAK dihitung sebagai tunggakan. Kalau ikut dihitung, setiap pelanggan
 * dianggap menunggak begitu tagihan bulan berikutnya dibuat -- persis
 * sebelum tagihan itu harus dibayar.
 *
 * Jumlah bulan dihitung dari tagihan unpaid yang SUDAH LEPAS TEMPO. Yang
 * dipakai adalah tagihan dengan periode paling LAMA (paling awal), karena
 * "menunggak 3 bulan" berarti tagihan 3 bulan lalu belum dibayar.
 *
 * CARA MENGHITUNG SELISIH BULAN
 * ----------------------------
 *
 * Selisih dihitung dari PERIODE tagihan (bulan pemakaian), bukan dari
 * `due_date`. Alasannya: yang ditanyakan admin adalah "berapa bulan
 * pemakaian yang belum dibayar", dan periode tagihan persis menyatakan
 * itu. `due_date` bisa bergeser (jatuh tempo di bulan tagihan), jadi
 * memakainya untuk menghitung selisih bulan bisa menghasilkan angka yang
 * meleset satu bulan.
 *
 * Rumus:
 *
 *     selisih = (tahun_skarang - tahun_tagihan) * 12
 *             + (bulan_skarang - bulan_tagihan)
 *
 * Contoh: sekarang Oktober 2026, tagihan tertua periode Juni 2026 yang
 * unpaid -> selisih = 4. Pelanggan dianggap menunggak 4 bulan.
 */
class UnpaidMonths
{
    /**
     * Berapa bulan pelanggan menunggak.
     *
     * Dihitung dari tagihan unpaid yang sudah lewat jatuh tempo. Tagihan
     * yang jatuh temponya belum sampai tidak ikut dihitung.
     *
     * @param  int  $customerId  ID pelanggan
     * @param  Carbon|null  $now  acuan waktu; default sekarang
     * @return int jumlah bulan menunggak (0 = tidak menunggak)
     */
    public function forCustomer(int $customerId, ?Carbon $now = null): int
    {
        $now ??= now();
        $today = $now->copy()->startOfDay();

        /*
         * Ambil periode tagihan unpaid yang sudah lewat jatuh tempo.
         *
         * Diurutkan dari periode paling awal, karena tagihan tertua yang
         * menentukan berapa lama pelanggan menunggak.
         */
        $bill = MonthlyBill::where('customer_id', $customerId)
            ->where('status', 'unpaid')
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today->toDateString())
            ->orderBy('billing_period_year')
            ->orderBy('billing_period_month')
            ->first();

        if (! $bill) {
            return 0;
        }

        $year = (int) $bill->billing_period_year;
        $month = (int) $bill->billing_period_month;

        // Periode tidak sah -- jangan ditafsirkan sebagai menunggak.
        if ($year < 1 || $month < 1 || $month > 12) {
            return 0;
        }

        $selisih = (($now->year - $year) * 12) + ($now->month - $month);

        // Tagihan bulan ini yang sudah lewat jatuh tempo (mis. jatuh tempo
        // tanggal 1, sekarang tanggal 15) belum berarti "menunggak 1
        // bulan". Bulannya sendiri belum selesai -- jadi dihitung 0.
        return max(0, $selisih);
    }
}
