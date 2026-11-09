<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Satu tempat untuk aturan tagihan yang memakai SOP.
 *
 * DUA KOLOM, DUA SATUAN
 * ---------------------
 *
 *   - `settings.toleransi_tunggakan` = TANGGAL (1-31) akhir bayar /
 *     jatuh tempo. Jatuh tempo dihitung pada BULAN PENCATATAN meter:
 *     pencatatan sampai tanggal ini masih jatuh tempo di bulan yang sama;
 *     lewat tanggal ini bergeser ke bulan berikutnya.
 *   - `settings.batas_tagihan` = JUMLAH BULAN tunggakan yang masih
 *     ditoleransi sebelum sambungan pelanggan diputus. 0 berarti tidak
 *     pernah diputus otomatis.
 *
 * Tidak ada kolom ketiga. Nama `billing_unpaid_limit` sudah dihapus.
 *
 * PENGAKUAN PIUTANG
 * -----------------
 *
 * Sebuah tagihan menjadi piutang pada saat TANGGAL JATUH TEMPO-nya sudah
 * tercapai. Tidak ada lagi "ambang mundur N hari" -- satuan tanggal
 * (1-31) tidak boleh dibaca sebagai jumlah hari.
 */
class BillingTolerance
{
    /**
     * TANGGAL akhir bayar / jatuh tempo dari `settings.toleransi_tunggakan`.
     *
     * Nilai 1-31. Nilai 0 atau di luar rentang berarti SOP belum diisi;
     * pemanggil harus menganggap generate dan jatuh tempo tidak
     * dijadwalkan, bukan memakai angka bawaan yang tidak terlihat admin.
     *
     * Jatuh tempo dihitung pada bulan pencatatan meter, bukan bulan
     * pemakaian. Rumusnya ada di `BillingCycleSettings::dueDateFor()`.
     */
    public function tanggal(): int
    {
        return BillingCycleSettings::dueDay();
    }

    /**
     * (1) Tanggal generate piutang dalam bulan, rentang 1-31.
     *
     * Nilai di luar rentang berarti SOP belum diisi, dan pemanggil wajib
     * menganggap generate tidak dijadwalkan sama sekali -- bukan diam-diam
     * memakai angka yang tidak masuk akal seperti 40 atau 0.
     *
     * Kenapa 1-31, bukan 1-28
     * ----------------------
     *
     * Dulu batasnya 28. Alasannya benar secara teori: tanggal 29, 30, dan
     * 31 tidak ada di SETIAP bulan, jadi tanggal generate yang bergantung
     * pada bulan akan hilang di bulan yang lebih pendek.
     *
     * Tapi batas itu menjadikan hari 29-31 tidak bisa dipakai sama sekali.
     * Admin yang menyetel tanggal 29 lalu menyimpannya akan mendapat
     * `generateDay()` = 0, yang dibaca sebagai "SOP belum diisi" -- jadi
     * generate TIDAK PERNAH jalan. Tidak ada error, tidak ada popup, tidak
     * ada log. Persis gejala "sudah saya set tanggalnya tapi kok generate
     * tidak jalan?".
     *
     * Angka 31 juga tidak bisa dipakai mentah-mentah: Februari hanya punya
     * 28 hari, jadi tanggal 31 di sana tidak pernah terjadi.
     *
     * Solusinya: 1-31 boleh diset, dan tanggal 29/30/31 dipanjangkan ke
     * HARI TERAKHIR bulan -- 29 di Oktober, 30 di November, 28 di Februari.
     * Itu yang dilakukan `effectiveGenerateDay()`.
     */
    public function generateDay(): int
    {
        $day = $this->tanggal();

        return ($day >= 1 && $day <= 31) ? $day : 0;
    }

    /**
     * Tanggal generate yang BENAR-BENAR ada di bulan ini.
     *
     * `generateDay()` mengembalikan angka yang diset admin (1-31). Angka itu
     * belum tentu ada di bulan berjalan: tanggal 31 tidak ada di Februari,
     * tanggal 30 tidak ada di Februari, dan tanggal 29 tidak ada di
     * Februari tahun biasa.
     *
     * `effectiveGenerateDay()` menjadikannya konkret: kalau angka yang diset
     * melewati hari terakhir bulan ini, hasilnya DITARIK ke hari terakhir
     * bulan. Jadi:
     *
     *   - diset 28 -> 28, di bulan apa pun.
     *   - diset 29 -> 29 di Oktober, 28 di Februari.
     *   - diset 31 -> 31 di Oktober, 28/29 di Februari.
     *
     * Konsekuensinya disengaja dan harus diketahui: di bulan yang lebih
     * pendek, generate berjalan pada hari terakhir bulan -- BUKAN tanggal
     * yang diklik admin. Ini satu-satunya cara supaya tanggal 29-31 tetap
     * berguna tanpa membuat generate hilang di Februari.
     *
     * Nilai 0 (SOP belum diisi) dikembalikan apa adanya.
     */
    public function effectiveGenerateDay(?Carbon $now = null): int
    {
        $now ??= now();
        $day = $this->generateDay();

        if ($day === 0) {
            return 0;
        }

        return min($day, $now->daysInMonth);
    }

    /**
     * (2) Tanggal terakhir pengakuan piutang: sebuah tagihan dianggap sudah
     *     jatuh tempo -- dan boleh dibukukan jadi piutang -- paling lambat
     *     pada tanggal ini.
     *
     * ATURANNYA
     * ---------
     *
     * Sebuah tagihan menjadi piutang begitu TANGGAL JATUH TEMPO-nya sudah
     * tercapai. Jadi ambangnya adalah TANGGAL GENERATE itu sendiri -- bukan
     * tanggal generate dikurangi sejumlah hari.
     *
     * Kenapa bukan "generate hari ini dikurangi N hari"?
     *
     * Karena generate hanya jalan SATU HARI dalam bulan, dan tanggal
     * jalannya diambil dari angka yang SAMA dengan tanggal jatuh tempo
     * tagihan (`toleransi_tunggakan`). Kalau ambangnya digeser mundur N hari,
     * ambangnya selalu jatuh di AKHIR BULAN SEBELUMNYA -- sehingga
     * tagihan periode M (jatuh tempo di bulan M+1) baru dijurnal pada
     * generate bulan M+2: satu bulan lebih lambat dari aturan bisnis
     * ("tagihan periode Mei menjadi piutang pada generate bulan Juni")
     * dan satu bulan lebih lambat dari aplikasi lama.
     *
     * Ini juga alasan kenapa memakai accessor ini lebih baik daripada
     * menulis `due_date <= now()` langsung di command: aturan pengakuan
     * piutang punya SATU sumber, dan sumber itu bisa ditanya kalau
     * angkanya pernah diperdebatkan.
     *
     * Parameter `$tanggalGenerate` dipakai supaya pemanggil yang
     * menjalankan command dengan `--tanggal` (mis. generate ulang untuk
     * tanggal lampau) memakai acuan yang sama dengan tagihan yang
     * dijurnalkan.
     */
    public function piutangBerlakuPada(?Carbon $tanggalGenerate = null): string
    {
        $tanggalGenerate ??= now();

        return $tanggalGenerate->toDateString();
    }

    /**
     * (3) Tanggal toleransi untuk deteksi advance payment.
     *
     * Pembayaran dianggap advance kalau tanggal bayar lebih awal dari
     * `monthly_bills.due_date`. `due_date` sudah dihitung dari tanggal
     * pencatatan meter dan SOP, jadi accessor ini hanya dipertahankan
     * untuk pemanggil lama yang masih membutuhkan angka tanggalnya.
     */
    public function advanceDayOfMonth(): int
    {
        return $this->tanggal();
    }

    /**
     * (4) Batas tagihan menunggak sebelum sambungan diputus.
     *
     * Satuannya JUMLAH BULAN -- bukan tanggal dalam bulan, dan bukan jumlah
     * hari. Contoh: nilai 3 berarti sambungan boleh tetap aktif selama
     * pelanggan menunggak 3 bulan; begitu menunggak melewati 3 bulan,
     * tiket instalasinya diubah menjadi `suspended`.
     *
     * Sumbernya `settings.batas_tagihan`. Tidak ada kolom lain dan tidak
     * ada angka bawaan di kode: kalau SOP belum diisi, nilainya 0 yang
     * berarti "jangan putus otomatis". Admin bisa mengubahnya dari halaman
     * SOP.
     */
    public function unpaidBillLimit(): int
    {
        return BillingCycleSettings::arrearsLimit();
    }

    /**
     * Apakah sambungan pelanggan diputus setelah mencatat meter.
     *
     * Dipakai `MeterReadingController::store`. Defaultnya sama persis dengan
     * versi lama; yang berubah hanya sumber nilainya.
     *
     * $unpaidCount di sini adalah JUMLAH BULAN menunggak (lihat
     * `unpaidMonths()`), bukan jumlah baris tagihan.
     */
    public function shouldSuspend(int $unpaidCount, ?int $limit = null): bool
    {
        $limit ??= $this->unpaidBillLimit();

        return $limit > 0 && $unpaidCount > $limit;
    }

    /**
     * (5) Masa tenggang tagihan langganan ASTALOKA.
     *
     * Bedanya keempat: satuan BULAN, bukan hari, dan berlaku untuk tagihan
     * dari pusat ke lokasi -- bukan tunggakan air pelanggan.
     *
     * Nilai ini TIDAK boleh dibaca dari `toleransi_tunggakan`.
     * `TenantInvoice::deadline()` memakai `config('tenancy.invoice_grace_months')`.
     * Yang ada di sini hanya pengingat eksplisit supaya keduanya tidak
     * disamakan di kemudian hari.
     */
    public function invoiceGraceMonths(): int
    {
        return (int) config('tenancy.invoice_grace_months', 1);
    }
}
