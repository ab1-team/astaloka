<?php

use App\Services\BillingDueDate;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Geser jatuh tempo tagihan yang sudah ada ke bulan TAGIHAN.
 *
 * LATAR BELAKANG
 * --------------
 *
 * Aturan bisnis: pemakaian dibayar pada bulan BERIKUTNYA. Tagihan
 * pemakaian Juni 2026 jatuh tempo 26 Juli 2026.
 *
 * Sebelumnya jatuh tempo dihitung di bulan yang SAMA dengan bulan
 * pemakaian -- tagihan pemakaian Juni jatuh tempo 26 Juni 2026. Itu
 * tidak sesuai aturan: pelanggan ditagih untuk air yang belum selesai
 * dipakai, di bulan yang sama.
 *
 * Aplikasi lama (`siap_mapan`) sudah memakai aturan yang benar sejak
 * dulu:
 *
 * ```php
 * // UsageController.php:303
 * $tglAkhir = date('Y-m', strtotime('+1 month', $tglPakai))
 *            . '-' . str_pad($data['toleransi'], 2, '0', STR_PAD_LEFT);
 * ```
 *
 * Yang TIDAK diubah
 * -----------------
 *
 *   - `billing_period_year` / `billing_period_month` -- periode tagihan
 *     tetap bulan PEMAKAIAN. Yang bergeser hanya tanggal jatuh temponya.
 *   - `status` pembayaran. Tagihan yang sudah lunas tetap lunas.
 *   - `penalty_amount` dan `total_amount`. Denda dihitung dari tagihan
 *     bulan sebelumnya yang belum lunas, bukan dari tanggal jatuh tempo,
 *     jadi tidak ikut bergeser.
 *
 * Kenapa tagihan yang sudah LUNAS ikut diperbarui
 * ----------------------------------------------
 *
 * Kolom `due_date` adalah bagian dari data tagihan yang ditampilkan ke
 * pelanggan (Daftar Tagihan, detail, cetak, portal pelanggan). Kalau
 * hanya tagihan yang belum lunas yang digeser, halaman yang sama akan
 * menampilkan dua jenis tanggal jatuh tempo untuk dua tagihan dari bulan
 * yang sama -- dan tidak ada yang bisa menjelaskan bedanya.
 *
 * Yang penting untuk tagihan lunas adalah kolom `paid_at` dan
 * `bill_payments`, keduanya tidak disentuh di sini: tanggal bayar yang
 * tercatat tetap tanggal uang benar-benar masuk.
 *
 * KEAMANAN
 * --------
 *
 *   - Migration ini idempotent: tanggal dihitung ulang dari periode,
 *     jadi menjalankannya berkali-kali menghasilkan nilai yang sama.
 *   - Baris dengan `deleted_at` terisi dilewati -- tagihan yang dihapus
 *     tidak boleh dihidupkan hanya karena kolom `due_date` disentuh.
 *   - Nilai `settings.batas_tagihan` yang dipakai adalah nilai SAAT
 *     migration jalan. Kalau admin mengganti tanggal jatuh tempo
 *     kemudian, tagihan yang sudah ada tidak ikut bergeser -- sama
 *     seperti aturan yang berlaku untuk semua perubahan SOP lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('monthly_bills') || ! Schema::hasTable('settings')) {
            return;
        }

        $batasTagihan = (int) (DB::table('settings')->value('batas_tagihan')
            ?: BillingDueDate::DEFAULT_BATAS_TAGIHAN);

        DB::table('monthly_bills')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(500, function ($bills) use ($batasTagihan) {
                $updates = [];

                foreach ($bills as $bill) {
                    $year = (int) $bill->billing_period_year;
                    $month = (int) $bill->billing_period_month;

                    if ($year < 1 || $month < 1 || $month > 12) {
                        continue;
                    }

                    $newDue = BillingDueDate::compute($year, $month, $batasTagihan);

                    // Lewati kalau tanggalnya sudah sama -- supaya baris
                    // yang tidak berubah tidak ikut disentuh (dan tidak
                    // memicu updated_at yang tidak perlu).
                    if ((string) $bill->due_date === $newDue) {
                        continue;
                    }

                    $updates[] = [
                        'id' => (int) $bill->id,
                        'due_date' => $newDue,
                    ];
                }

                foreach ($updates as $update) {
                    DB::table('monthly_bills')
                        ->where('id', $update['id'])
                        ->update([
                            'due_date' => $update['due_date'],
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    /**
     * Kembalikan jatuh tempo ke bulan pemakaian (perilaku lama).
     *
     * Dipakai kalau aturan ini dibatalkan. Tanggal dihitung ulang dengan
     * rumus lama -- yaitu `batas_tagihan` di dalam bulan pemakaian.
     */
    public function down(): void
    {
        if (! Schema::hasTable('monthly_bills') || ! Schema::hasTable('settings')) {
            return;
        }

        $batasTagihan = (int) (DB::table('settings')->value('batas_tagihan')
            ?: BillingDueDate::DEFAULT_BATAS_TAGIHAN);

        DB::table('monthly_bills')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(500, function ($bills) use ($batasTagihan) {
                foreach ($bills as $bill) {
                    $year = (int) $bill->billing_period_year;
                    $month = (int) $bill->billing_period_month;

                    if ($year < 1 || $month < 1 || $month > 12) {
                        continue;
                    }

                    $oldDue = Carbon::create($year, $month, 1)
                        ->setDay(min($batasTagihan, Carbon::create($year, $month, 1)->daysInMonth))
                        ->toDateString();

                    if ((string) $bill->due_date === $oldDue) {
                        continue;
                    }

                    DB::table('monthly_bills')
                        ->where('id', (int) $bill->id)
                        ->update([
                            'due_date' => $oldDue,
                            'updated_at' => now(),
                        ]);
                }
            });
    }
};
