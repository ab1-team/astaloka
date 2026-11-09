<?php

use App\Services\BillingCycleSettings;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Samakan arti dua kolom SOP dengan aturan yang dipakai aplikasi:
 *
 *   - `settings.toleransi_tunggakan` = TANGGAL akhir bayar (1-31);
 *   - `settings.batas_tagihan`       = JUMLAH BULAN tunggakan.
 *
 * Sebelumnya `batas_tagihan` dipakai sebagai tanggal dan
 * `billing_unpaid_limit` sebagai jumlah bulan. Migration ini memindahkan
 * nilai lama ke kolom yang benar, memperbaiki `recorded_at` yang dulu
 * diisi tanggal akhir periode (bukan waktu input), lalu menghitung ulang
 * `due_date` dari tanggal pencatatan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $hasLimit = Schema::hasColumn('settings', 'billing_unpaid_limit');

        $columns = ['id', 'batas_tagihan', 'toleransi_tunggakan'];

        if ($hasLimit) {
            $columns[] = 'billing_unpaid_limit';
        }

        foreach (DB::table('settings')->get($columns) as $row) {
            $toleransi = (int) ($row->toleransi_tunggakan ?? 0);
            $oldBatas = (int) ($row->batas_tagihan ?? 0);
            $limit = $hasLimit ? $row->billing_unpaid_limit : null;

            $dueDay = ($toleransi >= 1 && $toleransi <= 31)
                ? $toleransi
                : (($oldBatas >= 1 && $oldBatas <= 31) ? $oldBatas : 0);

            $months = $limit !== null
                ? max(0, (int) $limit)
                : (($oldBatas >= 0 && $oldBatas <= 24) ? $oldBatas : 0);

            DB::table('settings')->where('id', $row->id)->update([
                'toleransi_tunggakan' => $dueDay,
                'batas_tagihan' => $months,
                'updated_at' => now(),
            ]);
        }

        /*
         * `recorded_at` lama = hari `batas_tagihan` di bulan pemakaian,
         * dengan jam yang disalin dari `created_at`. Jadi kalau tanggalnya
         * berbeda tetapi jamnya sama, itu tanda data lama dan sumber yang
         * benar adalah `created_at`.
         */
        if (Schema::hasTable('meter_readings')) {
            DB::table('meter_readings')
                ->whereNotNull('recorded_at')
                ->whereNotNull('created_at')
                ->chunkById(500, function ($readings) {
                    foreach ($readings as $reading) {
                        $recorded = Carbon::parse($reading->recorded_at);
                        $created = Carbon::parse($reading->created_at);

                        if ($recorded->format('H:i:s') !== $created->format('H:i:s')) {
                            continue;
                        }

                        if ($recorded->toDateString() === $created->toDateString()) {
                            continue;
                        }

                        DB::table('meter_readings')
                            ->where('id', $reading->id)
                            ->update([
                                'recorded_at' => $created,
                                'updated_at' => now(),
                            ]);
                    }
                });
        }

        /*
         * Hitung ulang jatuh tempo dari tanggal pencatatan yang sudah
         * dibersihkan. Tagihan yang sudah dibayar tidak diubah supaya
         * riwayat pembayaran tetap konsisten.
         */
        if (Schema::hasTable('monthly_bills') && Schema::hasTable('meter_readings')) {
            $dueDay = (int) (DB::table('settings')
                ->orderBy('id')
                ->value('toleransi_tunggakan') ?? 0);

            if ($dueDay >= 1) {
                DB::table('monthly_bills')
                    ->whereNull('deleted_at')
                    ->where('status', '!=', 'paid')
                    ->chunkById(500, function ($bills) use ($dueDay) {
                        foreach ($bills as $bill) {
                            $reading = DB::table('meter_readings')
                                ->where('customer_id', $bill->customer_id)
                                ->where('reading_year', $bill->billing_period_year)
                                ->where('reading_month', $bill->billing_period_month)
                                ->orderByDesc('id')
                                ->first();

                            if (! $reading || ! $reading->recorded_at) {
                                continue;
                            }

                            $newDue = BillingCycleSettings::dueDateFor(
                                $reading->recorded_at,
                                $dueDay
                            );

                            if ($newDue === null || (string) $bill->due_date === $newDue) {
                                continue;
                            }

                            DB::table('monthly_bills')
                                ->where('id', $bill->id)
                                ->update([
                                    'due_date' => $newDue,
                                    'updated_at' => now(),
                                ]);
                        }
                    });
            }
        }

        if ($hasLimit && Schema::hasColumn('settings', 'billing_unpaid_limit')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('billing_unpaid_limit');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        if (! Schema::hasColumn('settings', 'billing_unpaid_limit')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('billing_unpaid_limit')
                    ->nullable()
                    ->after('toleransi_tunggakan');
            });

            // Nilai terbaik yang bisa dikembalikan: batas bulan saat ini.
            DB::table('settings')->update([
                'billing_unpaid_limit' => DB::raw('batas_tagihan'),
            ]);
        }
    }
};
