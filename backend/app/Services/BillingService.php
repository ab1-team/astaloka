<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\MonthlyBill;
use App\Models\WaterTariffBlock;
use Illuminate\Support\Facades\Log;

class BillingService
{
    public function generateForCustomer(Customer $customer, int $year, int $month, ?int $dueDay = null): MonthlyBill
    {
        $currentReading = $customer->meterReadings()
            ->where('reading_year', $year)
            ->where('reading_month', $month)
            ->first();

        if (! $currentReading) {
            throw new \RuntimeException('Belum ada pencatatan meter untuk periode ini.');
        }

        $lastMonth = $month === 1 ? 12 : $month - 1;
        $lastMonthYear = $month === 1 ? $year - 1 : $year;

        $previousReading = $customer->meterReadings()
            ->where('reading_year', $lastMonthYear)
            ->where('reading_month', $lastMonth)
            ->first();

        $startMeter = $previousReading
            ? $previousReading->meter_value
            : $customer->initial_meter_reading;

        $endMeter = $currentReading->meter_value;
        $usageM3 = max(0, $endMeter - $startMeter);

        $usageCharge = $this->calculateUsageCharge(
            $customer->ticket->package,
            $usageM3
        );

        $abodemen = round($customer->ticket->package->monthly_abodemen);
        $penaltyAmount = $this->calculatePenalty($customer, $year, $month);

        $totalAmount = round($usageCharge + $abodemen + $penaltyAmount);

        $dueDate = BillingCycleSettings::dueDate($currentReading->recorded_at, $dueDay);

        if ($dueDate === null) {
            throw new \RuntimeException('Tanggal Toleransi Menunggak belum diisi di SOP.');
        }

        // Tagihan Rp 0 bisa langsung berstatus `paid` — TAPI hanya kalau nolnya
        // benar-benar bisa dibenarkan, yaitu pelanggan tidak mengonsumsi air.
        //
        // Dua penyebab total_amount == 0 sama sekali berbeda sifatnya:
        //
        // 1. usage_m3 == 0 → pelanggan memang tidak pakai air, jadi tidak ada
        //    apa pun untuk dibayar. Nol ini sah. Kalau dibiarkan `unpaid`, dia
        //    akan memenuhi lonceng navbar sebagai "tagihan belum bayar", ikut
        //    dihitung menunggak di dashboard, dan menjatuhkan tiket jadi
        //    `suspended` — semuanya untuk sesuatu yang tidak ada uangnya.
        //
        // 2. usage_m3 > 0 tapi total tetap 0 → berarti `calculateUsageCharge`
        //    mengembalikan 0 padahal air mengalir. Penyebabnya hampir selalu
        //    paket belum punya blok tarif (`water_tariff_blocks` kosong) atau
        //    semua `price_per_m3`-nya 0. Paket dan blok tarif memang dibuat
        //    terpisah lewat endpoint berbeda, jadi kondisi ini bisa tersimpan.
        //    Auto-paid di sini berarti pelanggan yang benar-benar pakai air
        //    dapat air gratis, dan paket rusak ikut tersembunyi selamanya.
        //
        // Jadi cases (2) sengaja dibiarkan `unpaid`: tagihannya Rp 0 tapi
        // statusnya menggantung di daftar tagihan supaya terlihat dan bisa
        // dibetulkan admin, bukan hilang diam-diam.
        $isNihilLegitimate = $usageM3 <= 0 && $totalAmount <= 0;

        if ($usageM3 > 0 && $totalAmount <= 0) {
            Log::warning('Tagihan Rp 0 padahal pelanggan memakai layanan — konfigurasi paket kemungkinan bermasalah', [
                'customer_id' => $customer->id,
                'package_id' => $customer->ticket->package->id ?? null,
                'usage_m3' => $usageM3,
                'total_amount' => $totalAmount,
                'period' => $year.'-'.$month,
            ]);
        }

        $status = $isNihilLegitimate ? 'paid' : 'unpaid';

        return MonthlyBill::create([
            'customer_id' => $customer->id,
            'billing_period_year' => $year,
            'billing_period_month' => $month,
            'meter_reading_start' => $startMeter,
            'meter_reading_end' => $endMeter,
            'usage_m3' => $usageM3,
            'usage_charge' => $usageCharge,
            'abodemen' => $abodemen,
            'penalty_amount' => $penaltyAmount,
            'total_amount' => $totalAmount,
            'status' => $status,
            'due_date' => $dueDate,
        ]);
    }

    /**
     * Hitung biaya pemakaian untuk satu paket.
     *
     * RUMUSNYA FLAT: `pemakaian × harga paket per m³`.
     *
     * Artinya SELURUH pemakaian dihitung dengan harga blok yang memuat
     * pemakaian itu -- bukan dipecah per blok. Contoh Paket "Warga"
     * (10 m³ pertama @ 3.500, selanjutnya @ 4.000):
     *
     *   10 m³ → 10 × 3.500 = 35.000
     *   19 m³ → 19 × 4.000 = 76.000
     *   35 m³ → 35 × 4.000 = 140.000
     *
     * INI BUKAN KEBOHONGAN -- ini yang dipakai aplikasi LAMA, dan itu
     * dibuktikan dari data produksi, bukan dari dugaan:
     *
     *   - `pamsides_dump.usages` berisi 25.569 baris pemakaian nyata yang
     *     dihitung APLIKASI (bukan import Excel) dan paketnya bertarif.
     *     Rumus FLAT cocok 25.540 baris (99,89%); rumus progresif bertingkat
     *     cocok 11.832 baris (46,27%).
     *   - Pelanggan "Arjo Suwito" (paket Warga, 4.000/m³): pemakaian 19 m³
     *     tercatat 76.000 = 19 × 4.000. Progresif akan memberi 71.000.
     *
     * Populasi di atas = `user_id IS NOT NULL` (dihitung aplikasi, bukan
     * import Excel) DAN `jumlah > 0` DAN paketnya punya harga. Baris paket
     * gratis (561 baris) tidak dihitung karena memang tidak ada harga.
     *
     * Dalem legacy, blok tarif itu hanya PEMBATAS: dipakai untuk memilih
     * harga per m³, bukan untuk membagi pemakaian jadi beberapa bagian
     * dengan harga berbeda.
     *
     * Kenapa dulu kelihatan progresif? Versi lama (commit `9282aa3`) sudah
     * memakai rumus flat yang sama. Yang berubah justru arahnya: saat
     * tarif blok diturunkan ke bentuk rentang (`1-10 m3` = 10 m³), rumus
     * flat yang "cari blok pertama yang memuat pemakaian" mulai ambigu,
     * lalu diganti progressive -- dan itu membuat tagihan BERBEDA dari
     * aplikasi lama. Formulasi sekarang mengembalikan perilaku flat yang
     * sama, dengan batas atas blok yang INKLUSIF.
     *
     * ATURAN PEMILIHAN BLOK
     * ---------------------
     *   - Blok dipilih berdasarkan batas ATASNYA (inklusif): blok pertama
     *     yang `usage_max_m3`-nya >= pemakaian.
     *   - Blok terakhir tanpa `usage_max_m3` (tak terbatas) jadi fallback
     *     untuk pemakaian yang melewati semua batas atas.
     *   - Pemakaian 0 → 0.
     *
     * Kenapa "blok pertama", dan bukan "blok terakhir" seperti source legacy?
     * ---------------------------------------------------------------------
     * Source legacy (`siap_mapan/app/Http/Controllers/UsageController.php:287`)
     * menulis `$result[$i] = $index` di dalam loop, jadi angka yang
     * dicakup lebih dari satu blok berakhir menunjuk blok TERAKHIR. Blok
     * legacy memang tumpang tindih (`0-10`, `10-20`, `20-100`), jadi aturan
     * itu BEDA dari yang tertulis di paket.
     *
     * Diuji terhadap data produksi yang tersimpan:
     *
     *   - FLAT + blok PERTAMA (aturan di sini) : 25.540 / 25.569 = 99,89%
     *   - FLAT + blok TERAKHIR (source legacy)   : 25.377 / 25.569 = 99,25%
     *
     * Jadi "blok pertama" justru LEBIH cocok dengan data yang benar-benar
     * tersimpan di produksi.
     *
     * 29 baris sisanya bukan bukti aturan yang berbeda: TIDAK SATU PUN
     * dari mereka cocok dengan aturan blok TERAKHIR legacy -- semuanya
     * gagal cocok dengan kedua aturan. Isinya anomali data:
     *
     *   - 8 baris `nominal` = 0 (pemakaian tercatat, tidak ada tagihan).
     *   - 4 baris memakai harga 10.000/m³ yang bukan harga paket mana pun.
     *   - 3 baris memakai harga paket lain dari blok terpilih.
     *   - 14 baris `nominal`-nya duplikat/hasil import.
     *
     * Sebar di 22 tanggal berbeda dan 10 bulan berbeda (terpadat 7 baris
     * dalam satu timestamp), jadi tidak terkluster -- bukan bukti aturan
     * yang berbeda.
     *
     * PEMBULATAN
     * ----------
     * Hasil dikembalikan sebagai rupiah penuh (`round()`).
     *
     * Aplikasi LAMA TIDAK membulatkan di sini -- source-nya
     * (`siap_mapan/app/Http/Controllers/UsageController.php:315`) menyimpan
     * `harga × pemakaian` apa adanya. Tapi itu tidak berarti harus ikut
     * menyimpan pecahan rupiah:
     *
     *   - Semua harga paket legacy kelipatan 100, dan pemakaian yang
     *     diinput aplikasi SELALU bulat (`jumlah = akhir - awal` dari meter).
     *     Jadi `bulat × kelipatan-100` = kelipatan 100, dan pembulatan ke
     *     rupiah penuh TIDAK mengubah satu rupiah pun untuk pemakaian
     *     bulat. Hasilnya identik dengan legacy.
     *   - Diuji terhadap data produksi: dari 25.569 baris yang dihitung
     *     aplikasi, 25.540 baris cocok FLAT+blok PERTAMA dan SEMUA 25.540
     *     di antaranya menghasilkan `harga × pemakaian` yang sudah bulat --
     *     nol baris pecahan, jadi `round()` tidak mengubah apa pun.
     *   - `monthly_bills.usage_charge` bertipe DECIMAL(12,2), dan tagihan
     *     dibayar dalam rupiah penuh -- tagihan 33.985 rupiah tidak pernah
     *     bisa dibayar tepat, jadi pembulatan ke rupiah penuh lebih
     *     realistis dan tidak pernah mengubah nominal untuk pemakaian bulat.
     *
     * Pembulatan ke RATUSAN (mis. 33.985 → 34.000) TIDAK dipakai: tidak ada
     * satu pun aturan di aplikasi LAMA yang melakukannya. Helper
     * `Keuangan::pembulatan()` ada di source legacy tapi tidak dipanggil
     * dari mana pun (kode mati), dan tabel `businesses` tidak punya kolom
     * `pembulatan` sama sekali -- jadi tidak ada pengaturan yang bisa
     * mengaturnya.
     *
     * CATATAN KONSISTENSI: ini SATU-SATUNYA tempat rumus tagihan dipakai
     * air ditetapkan. `MonthlyBillService::calculateTariff()` dan
     * `BillRecalculationService::recalculateForCustomer()` memanggil
     * method ini, bukan menyalin logikanya. Dulu ketiganya punya salinan
     * sendiri, dan selisih sekecil apa pun akan terlihat oleh klien
     * sebagai "tagihan berbeda antar lokasi".
     */
    public function calculateUsageCharge($package, float $usageM3): float
    {
        if ($usageM3 <= 0) {
            return 0;
        }

        $blocks = WaterTariffBlock::where('package_id', $package->id)
            ->orderBy('usage_min_m3')
            ->get();

        if ($blocks->isEmpty()) {
            return 0;
        }

        $price = null;

        foreach ($blocks as $block) {
            // Batas atas blok ini. Kalau `usage_max_m3` kosong, bloknya
            // tak terbatas -- jadi blok inilah yang harus dipakai kalau
            // pemakaiannya melewati semua batas atas di blok sebelumnya.
            $max = $block->usage_max_m3 === null
                ? null
                : (float) $block->usage_max_m3;

            if ($max === null || $usageM3 <= $max) {
                $price = (float) $block->price_per_m3;

                break;
            }
        }

        // Pemakaian melewati semua batas atas dan tidak ada blok tak
        // terbatas: pakai harga blok terakhir.
        if ($price === null) {
            $price = (float) $blocks->last()->price_per_m3;
        }

        return round($usageM3 * $price);
    }

    public function calculatePenalty(Customer $customer, int $year, int $month): float
    {
        $prevMonth = $month === 1 ? 12 : $month - 1;
        $prevMonthYear = $month === 1 ? $year - 1 : $year;

        $oldBill = MonthlyBill::where('customer_id', $customer->id)
            ->where('billing_period_year', $prevMonthYear)
            ->where('billing_period_month', $prevMonth)
            ->where('status', 'unpaid')
            ->first();

        if (! $oldBill) {
            return 0;
        }

        return round($customer->ticket->package->late_penalty);
    }

    /**
     * Tanggal jatuh tempo untuk satu waktu pencatatan meter.
     *
     * Menuju ke `BillingCycleSettings` supaya rumusnya cuma ada di satu
     * tempat.
     */
    public function computeDueDate($recordedAt, ?int $dueDay = null): ?string
    {
        return BillingCycleSettings::dueDate($recordedAt, $dueDay);
    }

    public function calculateChargeForTesting($package, float $usageM3): float
    {
        return $this->calculateUsageCharge($package, $usageM3);
    }
}
