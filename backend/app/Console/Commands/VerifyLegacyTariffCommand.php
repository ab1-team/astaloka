<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verifikasi rumus tarif terhadap data produksi aplikasi LAMA.
 *
 * Angka-angka yang dikutip di `docs/roadmap.md` §8.4, di docblock
 * `BillingService::calculateUsageCharge()`, dan di
 * `tests/Unit/BillingServiceTest.php` berasal dari perintah ini. Kalau
 * perintah ini menghasilkan angka berbeda, dokumentasinya yang harus
 * diperbarui -- bukan sebaliknya.
 *
 * Populasi yang dipakai (cuma satu, supaya tidak ada angka yang saling
 * bertentangan):
 *
 *   - `usages.user_id IS NOT NULL` -> baris yang dihitung APLIKASI, bukan
 *     hasil import Excel.
 *   - `usages.jumlah > 0` -> pemakaian positif.
 *   - paketnya punya harga > 0 -> paket gratis (mis. Mushola) tidak punya
 *     harga untuk dihitung, jadi tidak bisa diadu.
 */
class VerifyLegacyTariffCommand extends Command
{
    protected $signature = 'legacy:verify-tariff
                            {--connection=legacy : Koneksi DB yang dipakai (default: legacy)}
                            {--database= : Override nama database (mis. pamsides_dump)}';

    protected $description = 'Bandingkan rumus FLAT (blok pertama vs blok terakhir) vs progresif terhadap data produksi aplikasi LAMA';

    public function handle(): int
    {
        $connection = (string) $this->option('connection');

        $config = config("database.connections.{$connection}");

        if (! $config) {
            $this->error("Koneksi DB '{$connection}' tidak ada di config/database.php.");

            return self::FAILURE;
        }

        $originalDatabase = $config['database'] ?? null;

        if ($database = $this->option('database')) {
            // Override nama database tanpa mengubah .env.
            config(["database.connections.{$connection}.database" => $database]);
            DB::purge($connection);
        }

        try {
            DB::connection($connection)->getPdo();
        } catch (\Throwable $e) {
            $this->error("Gagal konek ke koneksi DB '{$connection}': ".$e->getMessage());
            $this->warn('Isi LEGACY_DB_* di .env, lalu jalankan: php artisan config:clear');

            $this->restoreDatabase($connection, $originalDatabase);

            return self::FAILURE;
        }

        // Tabel legacy yang dipakai. Kalau salah satu tidak ada, database ini
        // bukan hasil import legacy -- dan melanjutkan diam-diam di sini akan
        // menampilkan angka yang menyesatkan, jadi ditolak dengan pesan jelas.
        $requiredTables = ['usages', 'settings', 'packages', 'installations'];

        foreach ($requiredTables as $table) {
            if (! $this->tableExists($connection, $table)) {
                $this->error("Tabel `{$table}` tidak ada di database '{$originalDatabase}'.");
                $this->warn('Database ini belum berisi data aplikasi LAMA.');
                $this->warn('Isi LEGACY_DB_DATABASE di .env dengan nama database yang benar, atau pakai --database.');

                $this->restoreDatabase($connection, $originalDatabase);

                return self::FAILURE;
            }
        }

        $db = DB::connection($connection);

        // --- Blok tarif per bisnis (format legacy: settings.block JSON) ---
        $blocksByBusiness = [];
        $blockMapByBusiness = [];

        foreach ($db->table('settings')->select('business_id', 'block')->get() as $setting) {
            $decoded = json_decode($setting->block, true);

            if (! is_array($decoded)) {
                continue;
            }

            $list = [];
            $map = [];

            foreach ($decoded as $index => $item) {
                preg_match_all('/\d+/', $item['jarak'] ?? '', $matches);
                $min = (int) ($matches[0][0] ?? 0);
                $max = isset($matches[0][1]) ? (int) $matches[0][1] : 200;

                $list[$index] = ['min' => $min, 'max' => $max];

                for ($i = $min; $i <= $max; $i++) {
                    $map[$i] = $index;
                }
            }

            $blocksByBusiness[(int) $setting->business_id] = $list;
            $blockMapByBusiness[(int) $setting->business_id] = $map;
        }

        // --- Harga paket ---
        $pricesByPackage = [];

        foreach ($db->table('packages')->select('id', 'harga')->get() as $package) {
            $pricesByPackage[(int) $package->id] = json_decode($package->harga, true) ?: [];
        }

        // --- Paket tiap instalasi ---
        $packageByInstallation = [];

        foreach ($db->table('installations')->select('id', 'package_id')->get() as $installation) {
            $packageByInstallation[(int) $installation->id] = $installation->package_id;
        }

        $rows = $db->table('usages')
            ->select('id', 'business_id', 'id_instalasi', 'jumlah', 'nominal', 'user_id')
            ->whereNotNull('user_id')
            ->get();

        $total = 0;
        $gratis = 0;
        $first = 0;
        $last = 0;
        $progressive = 0;
        $bulat = 0;
        $pecahan = 0;
        $residual = [];

        foreach ($rows as $row) {
            $usage = (float) $row->jumlah;

            if ($usage <= 0) {
                continue;
            }

            $businessId = (int) $row->business_id;
            $packageId = $packageByInstallation[(int) $row->id_instalasi] ?? null;
            $prices = $packageId !== null ? ($pricesByPackage[(int) $packageId] ?? null) : null;

            if (! $prices || ! isset($blocksByBusiness[$businessId])) {
                continue;
            }

            $blocks = $blocksByBusiness[$businessId];

            // Blok PERTAMA yang batas atasnya (inklusif) >= pemakaian.
            $firstIndex = null;

            foreach ($blocks as $index => $block) {
                if ($usage <= $block['max']) {
                    $firstIndex = $index;

                    break;
                }
            }

            if ($firstIndex === null) {
                $firstIndex = array_key_last($blocks);
            }

            // Blok TERAKHIR yang mencakup angka (semantik source legacy).
            $map = $blockMapByBusiness[$businessId];
            $lastIndex = isset($map[(int) $usage]) ? $map[(int) $usage] : array_key_last($blocks);

            $firstPrice = $prices[$firstIndex] ?? null;

            if ($firstPrice === null || (float) $firstPrice <= 0) {
                $gratis++;

                continue;
            }

            $total++;
            $nominal = (float) $row->nominal;
            $raw = (float) $firstPrice * $usage;

            $matchesFirst = abs($raw - $nominal) < 0.5;
            $lastPrice = $prices[$lastIndex] ?? null;
            $matchesLast = $lastPrice !== null && (float) $lastPrice > 0 && abs((float) $lastPrice * $usage - $nominal) < 0.5;

            if ($matchesFirst) {
                $first++;

                // Pembulatan hanya relevan untuk baris yang sudah cocok.
                if (abs($raw - round($raw)) > 0.000001) {
                    $pecahan++;
                } else {
                    $bulat++;
                }
            } else {
                $residual[] = [
                    'id' => (int) $row->id,
                    'business_id' => $businessId,
                    'usage' => $usage,
                    'nominal' => $nominal,
                    'matches_last' => $matchesLast,
                ];
            }

            if ($matchesLast) {
                $last++;
            }

            // Rumus PROGRESIF (pembagian pemakaian per blok).
            $progressiveTotal = 0.0;
            $sisa = $usage;

            foreach ($blocks as $index => $block) {
                if ($sisa <= 0) {
                    break;
                }

                $berikutnya = null;

                foreach ($blocks as $other) {
                    if ($other['min'] > $block['min']) {
                        $berikutnya = (float) $other['min'];

                        break;
                    }
                }

                $rentang = $berikutnya !== null && $berikutnya === (float) $block['max'] + 1
                    ? (float) $block['max'] - (float) $block['min'] + 1
                    : max(0, (float) $block['max'] - (float) $block['min']);

                $dipakai = min($sisa, $rentang);
                $progressiveTotal += round($dipakai * (float) ($prices[$index] ?? 0));
                $sisa -= $dipakai;
            }

            if (abs(round($progressiveTotal) - $nominal) < 0.5) {
                $progressive++;
            }
        }

        $percent = fn (int $value): string => number_format($value / max(1, $total) * 100, 2).'%';

        $this->info('Populasi: usages.user_id IS NOT NULL (dihitung APLIKASI)');
        $this->line('  baris dengan pemakaian > 0 & paket bertarif : '.$total);
        $this->line('  baris paket gratis (tidak dihitung)         : '.$gratis);
        $this->line('');

        $this->table(
            ['Rumus', 'Cocok', 'Total', 'Persentase'],
            [
                ['FLAT + blok PERTAMA (implementasi)', $first, $total, $percent($first)],
                ['FLAT + blok TERAKHIR (source legacy)', $last, $total, $percent($last)],
                ['PROGRESIF (rumus lama aplikasi ini)', $progressive, $total, $percent($progressive)],
            ]
        );

        $this->info('Pembulatan (dari baris yang cocok FLAT + blok PERTAMA)');
        $this->line('  harga x pemakaian sudah bulat : '.$bulat.' (round() tidak mengubah apa pun)');
        $this->line('  harga x pemakaian pecahan     : '.$pecahan.' (butuh round())');
        $this->line('');

        $residualMatchesLast = count(array_filter($residual, fn ($r) => $r['matches_last']));

        $this->info('Residual (tidak cocok FLAT + blok PERTAMA): '.count($residual).' baris');
        $this->line('  di antaranya yang cocok dengan blok TERAKHIR : '.$residualMatchesLast);
        $this->line('  → kalau 0, residualnya anomali data, bukan bukti aturan blok yang berbeda');
        $this->line('');

        if ($residual !== []) {
            $this->table(
                ['id', 'business_id', 'usage', 'nominal', 'cocok blok terakhir'],
                array_map(
                    fn ($r) => [$r['id'], $r['business_id'], $r['usage'], $r['nominal'], $r['matches_last'] ? 'ya' : 'tidak'],
                    array_slice($residual, 0, 40)
                )
            );

            if (count($residual) > 40) {
                $this->line('... dan '.(count($residual) - 40).' baris lagi.');
            }
        }

        $this->restoreDatabase($connection, $originalDatabase);

        return self::SUCCESS;
    }

    /**
     * Apakah tabelnya ada di database yang sedang dipakai.
     */
    private function tableExists(string $connection, string $table): bool
    {
        try {
            return DB::connection($connection)->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Kembalikan nama database koneksi seperti semula.
     *
     * Override `--database` cuma untuk durasi perintah ini; kalau tidak
     * dikembalikan, proses artisan yang sama (mis. dipanggil dari test atau
     * dari perintah lain) akan lanjut membaca database yang salah.
     */
    private function restoreDatabase(string $connection, ?string $originalDatabase): void
    {
        if ($originalDatabase === null) {
            return;
        }

        config(["database.connections.{$connection}.database" => $originalDatabase]);
        DB::purge($connection);
    }
}
