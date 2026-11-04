<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;

/**
 * Satu tempat untuk semua aturan yang dulu berbagi kolom `toleransi_tunggakan`.
 *
 * MASALAH
 * -------
 *
 * Satu kolom itu dibaca sebagai empat hal berbeda:
 *
 *   1. TANGGAL dalam bulan, 1-31 -> `DashboardController` menentukan hari
 *      generate piutang.
 *   2. JUMLAH HARI mundur dari hari run -> `GenerateOverdueTransactions`
 *      menghitung `thresholdDate = today - N days`.
 *   3. TANGGAL dalam bulan pemakaian tagihan -> `MonthlyBillController::pay`
 *      menentukan ambang deteksi advance payment.
 *   4. JUMLAH tagihan unpaid sebelum suspend -> `MeterReadingController::store`
 *      memutus sambungan dengan `$unpaidCount > $toleransi`.
 *
 * Makna (2) SUDAH DIHAPUS. Alasannya ada di bagian "PENGAKUAN PIUTANG"
 * di bawah -- singkatnya: angka "hari ke berapa dalam bulan" tidak pernah
 * bisa dipakai sebagai JUMLAH HARI, dan selama dipakai begitu, ambang
 * pengakuan piutang selalu jatuh di akhir bulan sebelumnya -- sehingga
 * tagihan baru dijurnal satu bulan lebih lambat dari seharusnya.
 *
 * Kelima: `TenantManager:511` mengisi kolom ini dari `invoice_grace_months`
 * yang satuan-nya BULAN, untuk tagihan langganan ke lokasi.
 *
 * Dengan nilai 6, semua aktif bersamaan:
 *   - generate hanya jalan tanggal 6
 *   - ambang piutang = hari ini - 6 hari
 *   - bayar sebelum tanggal 6 bulan tagihan = advance
 *   - suspend setelah 7 tagihan unpaid
 *   - tagihan langganan tenggang 6 BULAN
 *
 * KENAPA SATU ANGKA TIDAK CUKUP
 * -----------------------------
 *
 * Tiga makna pertama tidak bisa bergerak naik-turun bersama dalam satu
 * rentang. Ambang piutang butuh nilai kecil, tanggal generate butuh nilai
 * besar, batas suspend butuh angka puluhan. Admin yang ingin menunda
 * generate sampai tanggal 20 tidak akan ikutan ingin ambang piutang 20 hari.
 *
 * Makna (2) sudah dihapus sepenuhnya -- lihat "PENGAKUAN PIUTANG" di bawah.
 * Sekarang tidak ada lagi aturan yang memakai satuan JUMLAH HARI, jadi
 * satu angka tanggal ini benar-benar bisa berlaku untuk semua aturan
 * berbasis tanggal.
 *
 * PENGAKUAN PIUTANG
 * -----------------
 *
 * ATURAN SEKARANG: sebuah tagihan menjadi piutang pada saat TANGGAL
 * JATUH TEMPO-nya sudah tercapai. Tidak ada lagi "ambang mundur N hari".
 *
 * Kenapa begini?
 *
 *   1. Satuan "hari ke berapa dalam bulan" (1-31) TIDAK BISA dipakai
 *      sebagai JUMLAH HARI. Angka 27 berarti tanggal 27, bukan 27 hari.
 *      Membacanya sebagai jumlah hari membuat ambang pengakuan piutang
 *      jatuh di tanggal 27, yang -- karena tanggal generate juga diambil
 *      dari angka yang sama -- selalu berakhir di AKHIR BULAN SEBELUMNYA.
 *
 *   2. Akibatnya, tagihan periode M (jatuh tempo di bulan M+1) baru
 *      dijurnal pada generate bulan M+2 -- satu bulan lebih lambat dari
 *      aturan bisnis ("tagihan periode Mei menjadi piutang pada generate
 *      bulan Juni") dan satu bulan lebih lambat dari aplikasi lama
 *      (`$usage->tgl_akhir <= $date`).
 *
 *   3. Menambah "toleransi" agar piutang diakui lebih cepat tidak
 *      menolong: generate hanya jalan SATU HARI dalam bulan, dan tanggal
 *      jalannya sama dengan tanggal jatuh tempo tagihan. Jadi agar
 *      tagihan periode M tercatat pada generate bulan M+1 -- generate itu
 *      harus jalan tepat pada tanggal jatuh temponya. Toleransi berapa
 *      pun yang lebih besar dari nol justru mendorongnya ke bulan M+2.
 *
 *   4. Aturan "jatuh tempo sudah tercapai" juga persis sama dengan
 *      aplikasi lama: sana pengakuan piutang terjadi saat
 *      `$usage->tgl_akhir <= $date`. Perbedaannya cuma pada TANGGAL
 *      jurnalnya: aplikasi lama memakai tanggal jatuh tempo, ASTALOKA
 *      memakai tanggal generate (tanggal jalannya generate == tanggal
 *      jatuh tempo tagihan pada aturan normal, jadi angkanya sama).
 *
 * Ini juga menjawab pertanyaan "kenapa generate di bulan ini tidak
 * mencatat apa-apa?": jawaban yang benar adalah "karena tagihan itu
 * BELUM jatuh tempo" -- bukan "karena ambangnya sudah lewat bulan lalu".
 *
 * Yang TIDAK berubah: definisi "menunggak" untuk keperluan suspend
 * (`UnpaidMonths`) dan daftar Arsip Piutang tetap memakai
 * `due_date < hari ini` -- tagihan yang jatuh tempo hari ini belum
 * dianggap menunggak sampai besok.
 *
 * YANG DILAKUKAN
 * -------------
 *
 * Dua kolom yang tadinya sama-sama "tanggal" digabung jadi SATU angka:
 *
 *   - `batas_tagihan`         : TANGGAL jatuh tempo tagihan (1-31).
 *   - `toleransi_tunggakan`   : TANGGAL generate piutang (1-31).
 *
 * Keduanya punya satuan yang sama: "hari ke berapa dalam bulan".
 * Menampilkan dua input untuk satu satuan yang sama bikin admin mengira
 * ada dua aturan berbeda, padahal tujuannya satu: "hari ke berapa dalam
 * bulan sistem jalan".
 *
 * Jadi sekarang:
 *
 *   - `settings.batas_tagihan` = sumber angka TANGGAL yang dipakai SEMUA
 *     aturan berbasis tanggal: jatuh tempo tagihan, generate piutang,
 *     dan deteksi advance payment.
 *   - `settings.toleransi_tunggakan` = SALINAN dari angka yang sama.
 *     Kolomnya tidak dihapus (dipakai import legacy, dan dilacak lewat
 *     banyak query lama), tapi isinya selalu sama dengan `batas_tagihan`.
 *
 * Kenapa kolomnya tidak dihapus?
 * ------------------------------
 *
 * Karena `batas_tagihan` dibaca DI LANGSUNG oleh belasan tempat
 * (`BillingService`, `MonthlyBillService`, `BillRecalculationService`,
 * `MeterReadingController`, `BillingController`, `SeedTenantPelangganCommand`,
 * `ImportLegacyMonthlyBillsCommand`, `BillingDueDate`), sedangkan
 * `toleransi_tunggakan` hanya dibaca lewat service ini.
 *
 * Kalau angka tunggalnya ditaruh di `toleransi_tunggakan`, semua pembaca
 * langsung `batas_tagihan` harus ikut berubah -- padahal `due_date` yang
 * sudah tersimpan di `monthly_bills` memakai angka `batas_tagihan` itu.
 * Kalau tanggalnya bergeser, tagihan lama dan tagihan baru punya jatuh
 * tempo yang berbeda dalam bulan yang sama.
 *
 * Dengan `batas_tagihan` jadi sumbernya, tagihan yang SUDAH ada tidak
 * bergeser sama sekali: `due_date`-nya sudah di-materialisasi di database.
 *
 * Pemisahan makna (4) ke kolom sendiri
 * ------------------------------------
 *
 * Percobaan pertama memisahkan makna (4) ke kolom `billing_unpaid_limit`
 * sekaligus menambah input sendiri di halaman SOP. Input itu justru
 * membuat masalah: dua angka dengan satuan berbeda (tanggal vs jumlah
 * tagihan) berdampingan di satu kartu dan terlihat saling menimpa.
 *
 * SEKARANG pemisahan itu DIPULIHKAN, tapi dengan pembagian yang benar:
 *
 *   - `batas_tagihan` = TANGGAL (1-31). Satu angka untuk jatuh tempo,
 *     generate piutang, dan advance payment.
 *   - `billing_unpaid_limit` = JUMLAH BULAN. Batas tagihan menunggak yang
 *     boleh ditoleransi sebelum sambungan pelanggan diputus.
 *
 * Dipisah karena satuan keduanya memang berbeda: tanggal dalam bulan vs
 * jumlah bulan. Admin yang ingin menunda generate ke tanggal 20 tidak akan
 * ikutan mengubah batas tagihan yang boleh menunggak.
 *
 * Kolom `billing_unpaid_limit` sudah ada di database sejak
 * `2026_10_20_000001_add_billing_unpaid_limit_to_settings_table.php`
 * (nullable, tanpa default), jadi menghidupkannya kembali tidak perlu
 * migration baru.
 *
 * KESETARAAN DENGAN APLIKASI LAMA
 * -------------------------------
 *
 * Aplikasi lama (`siap_mapan`) punya dua angka yang persis seperti ini:
 *
 * ```php
 * // resources/views/sop/partials/sistem_instal.blade.php
 * "Batas Tagihan Bulanan"  -> batas_tagihan   -> satuan BULAN
 * "Batas Tagihan Bulanan"  -> tanggal_toleransi -> satuan TANGGAL
 * ```
 *
 * Di sana `batas_tagihan` = BULAN (toleransi menunggak, -> status BLOKIR)
 * dan `tanggal_toleransi` = TANGGAL (-> `tgl_akhir`, jatuh tempo tagihan).
 *
 * Di ASTALOKA pembagiannya dibalik supaya tidak membingungkan: kolom
 * `batas_tagihan` sudah lama berisi TANGGAL jatuh tempo dan dipakai di
 * belasan tempat, jadi memindahkannya berarti mengubah seluruh logika
 * jatuh tempo. Jumlah bulan-nya dipisah ke kolom terpisah yang memang
 * sudah tersedia.
 *
 * INVARIANT
 * ---------
 *
 * `tanggal()` mengembalikan SATU angka yang dipakai semua consumer
 * berbasis tanggal. Setiap perbaikan PERILAKU dilakukan lewat override,
 * sehingga selama override kosong hasilnya identik dengan versi lama.
 *
 * PENGECUALIAN: makna "ambang piutang" yang dulu memakai satuan JUMLAH
 * HARI sudah DIHAPUS (lihat "PENGAKUAN PIUTANG"). Tidak ada accessor
 * yang lagi membaca angka ini sebagai jumlah hari.
 */
class BillingTolerance
{
    /**
     * Bawaan batas tagihan menunggak (satuan BULAN).
     *
     * Dipakai kalau kolom `billing_unpaid_limit` masih kosong -- mis. pada
     * lokasi yang belum pernah menyimpan SOP lewat halaman ini. Angka 3
     * diambil dari `SettingsSeeder` dan sama dengan application's bawaan
     * di aplikasi lama (`siap_mapan`: `batas_tagihan` = 3 bulan).
     */
    public const DEFAULT_UNPAID_MONTH_LIMIT = 3;

    private ?Setting $settings = null;

    /**
     * Ambil `Setting` satu kali per instance.
     *
     * Satu request bisa memanggil beberapa accessor di sini (login memanggil
     * `generateDay()`, `piutangBerlakuPada()`, dan `unpaidBillLimit()`), dan
     * tiap pemanggilan tanpa cache adalah satu query.
     */
    private function settings(): Setting
    {
        return $this->settings ??= Setting::first() ?? new Setting;
    }

    /**
     * TANGGAL dalam bulan yang dipakai semua aturan berbasis tanggal.
     *
     * Ini angka TUNGGAL hasil penggabungan dua kolom yang tadinya
     * terpisah: `batas_tagihan` (jatuh tempo tagihan) dan
     * `toleransi_tunggakan` (generate piutang). Keduanya satuan dan
     * maknanya sama -- "hari ke berapa dalam bulan" -- jadi halaman SOP
     * sekarang hanya punya SATU input untuk keduanya.
     *
     * SUMBERNYA `settings.batas_tagihan`, bukan `toleransi_tunggakan`.
     *
     * Alasan pemilihan kolom ini penting, karena ada tagihan yang SUDAH
     * tersimpan di `monthly_bills` dengan `due_date` hasil hitungan
     * `batas_tagihan`. Kalau sumbernya `toleransi_tunggakan`, angka itu
     * (= 30 di lokasi demo) akan menggeser jatuh tempo tagihan baru ke
     * tanggal 30 sementara tagihan lama tetap tanggal 27 -- satu bulan
     * tagihan yang sama punya dua jatuh tempo berbeda. Dengan
     * `batas_tagihan` (= 27) sebagai sumber, tagihan lama dan baru sama
     * saja.
     *
     * `toleransi_tunggakan` tetap ada di database dan tetap diisi dengan
     * angka yang sama -- lihat migration
     * `2026_10_31_000002_sync_toleransi_tunggakan_with_batas_tagihan.php`
     * dan writer di `SopController::updateSistemTagihan()`. Jadi satu
     * angka ini berlaku untuk semua aturan, dan kedua kolomnya tidak
     * mungkin berbeda setelah disimpan lewat SOP.
     *
     * FALLBACK KE `toleransi_tunggakan`
     * --------------------------------
     *
     * Kalau `batas_tagihan` tidak berisi tanggal yang sah (0, atau di
     * luar 1-31), `toleransi_tunggakan` dipakai sebagai cadangan --
     * asalkan itu juga tanggal yang sah.
     *
     * Alasannya: baris dengan `batas_tagihan` = 0 adalah baris lama yang
     * belum pernah mengisi SOP. Dulu baris seperti itu tetap punya
     * generate piutang yang jalan pada tanggalnya (`toleransi_tunggakan`),
     * jadi membaca `toleransi_tunggakan` sebagai cadangan mempertahankan
     * perilaku lama persis. Tanpa fallback, baris itu tiba-tiba berhenti
     * menjadwalkan generate -- perubahan yang tidak diminta siapa pun.
     *
     * Kalau keduanya tidak valid, hasilnya 0: "SOP belum diisi", dan
     * generate TIDAK berjalan sama sekali.
     */
    public function tanggal(): int
    {
        $batas = (int) ($this->settings()->batas_tagihan ?? 0);

        if ($batas >= 1 && $batas <= 31) {
            return $batas;
        }

        $toleransi = (int) ($this->settings()->toleransi_tunggakan ?? 0);

        return ($toleransi >= 1 && $toleransi <= 31) ? $toleransi : 0;
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
     * tagihan (`batas_tagihan`). Kalau ambangnya digeser mundur N hari,
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
     * (3) Ambang deteksi advance payment: TANGGAL dalam bulan pemakaian tagihan.
     *
     * Logika yang SANGAT sensitif, tidak boleh diubah tanpa persetujuan
     * bisnis. Perhitungannya:
     *
     *     threshold = Carbon::create(year, month, min(day, daysInMonth))
     *     isAdvance = paidAtDate->lt(threshold)
     *
     * Frontend `BillingForm.vue` menghitung ulang hal yang sama untuk
     * menampilkan pratinjau, jadi keduanya harus memakai angka yang sama.
     * Kalau tidak, pratinjau dan hasil sebenarnya berbeda dan user melihat
     * status advance yang salah.
     *
     * CATATAN PENTING: angka ini dulu dibaca dari `toleransi_tunggakan`,
     * sementara `due_date` tagihan dihitung dari `batas_tagihan`. Kalau
     * keduanya berbeda -- yang terjadi di lokasi demo: 30 vs 27 -- maka
     * pembayaran pada tanggal 28, 29, dan 30 dianggap ADVANCE padahal
     * tagihannya belum jatuh tempo. Sekarang keduanya satu angka, jadi
     * bug itu ikut hilang.
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
     * Sumbernya kolom `billing_unpaid_limit`, yang terpisah dari
     * `batas_tagihan` karena satuannya memang berbeda.
     *
     * NILAI KOSONG = 3 (bawaan)
     * -------------------------
     *
     * Kolomnya nullable tanpa default, jadi lokasi yang belum pernah
     * mengisinya akan punya `null` di database. `null` dibaca sebagai
     * 3 -- angka yang sama dengan bawaan `SettingsSeeder` -- supaya
     * semua lokasi punya perilaku yang seragam sejak awal, dan supaya
     * angka yang tampil di halaman SOP bukan kolom kosong.
     *
     * Kenapa bukan 0 (yang berarti "tidak pernah memutus")? Karena 0
     * berarti fitur auto-suspend mati total tanpa disadari admin, dan
     * angka 3 sudah jadi bawaan aplikasi lama.
     */
    public function unpaidBillLimit(): int
    {
        $limit = $this->settings()->billing_unpaid_limit;

        if ($limit === null) {
            return self::DEFAULT_UNPAID_MONTH_LIMIT;
        }

        return max(0, (int) $limit);
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
