<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\InstallationPackage;
use App\Models\MeterReading;
use App\Models\MonthlyBill;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MonthlyBillService
{
    public function generate()
    {
        $now = now();
        $bulan = $now->month;
        $tahun = $now->year;

        // CEGAH GENERATE DOBEL
        $exists = MonthlyBill::where('billing_period_month', $bulan)
            ->where('billing_period_year', $tahun)
            ->exists();

        if ($exists) {
            return [
                'status' => false,
                'message' => 'Tagihan bulan ini sudah pernah digenerate',
            ];
        }

        // VALIDASI METER
        $totalCustomer = Customer::count();

        $totalReading = MeterReading::where('reading_month', $bulan)
            ->where('reading_year', $tahun)
            ->count();

        if ($totalReading < $totalCustomer) {
            return [
                'status' => false,
                'message' => 'Masih ada pelanggan yang belum dicatat meternya',
            ];
        }

        $readings = MeterReading::where('reading_month', $bulan)
            ->where('reading_year', $tahun)
            ->get();

        $count = 0;

        foreach ($readings as $reading) {

            $last = MeterReading::where('customer_id', $reading->customer_id)
                ->where(function ($q) use ($bulan, $tahun) {
                    $q->where('reading_year', '<', $tahun)
                        ->orWhere(function ($q2) use ($bulan, $tahun) {
                            $q2->where('reading_year', $tahun)
                                ->where('reading_month', '<', $bulan);
                        });
                })
                ->orderByDesc('reading_year')
                ->orderByDesc('reading_month')
                ->first();

            if (! $last) {
                continue;
            }

            $usage = $reading->meter_value - $last->meter_value;

            if ($usage < 0) {
                continue;
            }

            $customer = Customer::with('ticket.package')->find($reading->customer_id);

            if (! $customer || ! $customer->ticket || ! $customer->ticket->package) {
                continue;
            }

            $package = $customer->ticket->package;

            $usage_charge = $this->calculateTariff($usage, $package);

            $abodemen = $package->monthly_abodemen;

            $penalty = $this->calculatePenalty(
                $reading->customer_id,
                $bulan,
                $tahun,
                $package
            );

            $total = $usage_charge + $abodemen + $penalty;

            // Tagihan Rp 0 bisa langsung berstatus `paid` — tapi hanya kalau nolnya
            // benar-benar bisa dibenarkan, yaitu pelanggan tidak mengonsumsi air.
            //
            // Kalau `$usage > 0` tapi total tetap 0, itu berarti paketnya belum
            // punya blok tarif (atau semua price_per_m3-nya 0). Membiarkan tagihan
            // seperti itu jadi `paid` berarti pelanggan yang benar-benar pakai
            // air tidak ditagih sama sekali dan paket yang rusak ikut tersembunyi.
            // Jadi kasus itu sengaja dibiarkan `unpaid` supaya kelihatan di
            // daftar tagihan dan bisa dibetulkan admin.
            //
            // Sama seperti `BillingService::generateForCustomer` yang dipakai saat
            // input pemakaian satu per satu.
            $isNihilLegitimate = $usage <= 0 && $total <= 0;

            if ($usage > 0 && $total <= 0) {
                Log::warning('Tagihan Rp 0 padahal pelanggan memakai layanan — konfigurasi paket kemungkinan bermasalah', [
                    'customer_id' => $reading->customer_id,
                    'package_id' => $package->id,
                    'usage_m3' => $usage,
                    'total_amount' => $total,
                    'period' => $tahun.'-'.$bulan,
                ]);
            }

            $status = $isNihilLegitimate ? 'paid' : 'unpaid';

            $dueDate = BillingCycleSettings::dueDate($reading->recorded_at);

            if ($dueDate === null) {
                throw new \RuntimeException('Tanggal Toleransi Menunggak belum diisi di SOP.');
            }

            MonthlyBill::create([
                'customer_id' => $reading->customer_id,
                'billing_period_month' => $bulan,
                'billing_period_year' => $tahun,
                'meter_reading_start' => $last->meter_value,
                'meter_reading_end' => $reading->meter_value,
                'usage_m3' => $usage,
                'usage_charge' => $usage_charge,
                'abodemen' => $abodemen,
                'penalty_amount' => $penalty,
                'total_amount' => $total,
                'status' => $status,
                'due_date' => $dueDate,
            ]);

            $count++;
        }

        return [
            'status' => true,
            'message' => 'Tagihan berhasil digenerate',
            'total_generated' => $count,
        ];
    }

    /**
     * Hitung biaya pemakaian.
     *
     * RUMUSNYA FLAT: `pemakaian × harga paket per m³` -- sama dengan
     * aplikasi LAMA (dibuktikan dari 25.569 baris pemakaian yang dihitung
     * aplikasi di `pamsides_dump.usages`, di mana rumus flat cocok 25.540
     * baris (99,89%) sedangkan rumus progresif hanya 11.832 baris (46,27%)).
     *
     * Blok tarif dipakai untuk MEMILIH harga per m³, bukan untuk membagi
     * pemakaian jadi beberapa bagian dengan harga berbeda. Blok pertama
     * yang batas atasnya (inklusif) sudah >= pemakaian yang dipakai.
     *
     * CATATAN KONSISTENSI: rumus ini memanggil
     * `BillingService::calculateUsageCharge()` -- bukan menyalin logikanya.
     * Dulu logika ini ditulis ulang (disalin) di sini, dan dua salinan itu
     * bisa saja berbeda satu rupiah tanpa ada yang sadar. Sekarang ada
     * SATU tempat yang menentukan rumusnya, jadi "tagihan berbeda antar
     * lokasi" tidak mungkin terjadi karena salinan yang tidak sinkron.
     */
    private function calculateTariff($usage, InstallationPackage $package): float
    {
        return app(BillingService::class)->calculateUsageCharge($package, (float) $usage);
    }

    private function calculatePenalty($customer_id, $bulan, $tahun, $package)
    {
        $date = Carbon::create($tahun, $bulan, 1)->subMonths(1);

        $bill = MonthlyBill::where('customer_id', $customer_id)
            ->where('billing_period_month', $date->month)
            ->where('billing_period_year', $date->year)
            ->first();

        if ($bill && $bill->status == 'unpaid') {
            return $package->late_penalty;
        }

        return 0;
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
}
