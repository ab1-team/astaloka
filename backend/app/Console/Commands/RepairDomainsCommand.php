<?php

namespace App\Console\Commands;

use App\Models\Central\Tenant;
use App\Services\DomainNormalizer;
use App\Services\LegacyDomainSync;
use Illuminate\Console\Command;

/**
 * Perbaiki daftar domain lokasi yang kehilangan baris di `tenant_domains`.
 *
 * KENAPA PERINTAH INI ADA
 * ========================
 *
 * `LocationController::syncLegacyDomain()` -- versi lama -- melepas baris
 * "lama" sebuah slot dengan cara menghapus SELURUH baris domain persis milik
 * lokasi itu, untuk slot cadangan. Urutan slotnya `domain` dulu, lalu
 * `domain_alternatif`, jadi baris utama yang barusan dibuat ikut terhapus
 * oleh langkah slot cadangan.
 *
 * Akibatnya sebuah lokasi yang mengisi kedua field form ("Domain Utama" dan
 * "Domain Alternatif") berakhir dengan SATU baris di `tenant_domains` --
 * dan baris itu pun bukan `is_primary`. Lokasi seperti itu tetap bisa
 * diakses lewat kolom legacy-nya, jadi tidak ada yang rusak secara
 * fungsional: panel pusat hanya menampilkan satu domain, padahal form-nya
 * berisi dua, dan tidak ada error yang menyinggung penyebabnya.
 *
 * `backfillLegacyDomains()` SENGAJA tidak memperbaiki kondisi ini: ia
 * melewati lokasi yang sudah punya baris apa pun di `tenant_domains`, supaya
 * tidak menimpa pilihan admin yang lebih baru. Lokasi yang rusak ini justru
 * punya baris -- cuma kurang satu -- jadi ia tidak pernah tersentuh.
 *
 * PERINTAH INI TIDAK MENGHAPUS APA PUN
 * ====================================
 *
 * Perbaikan hanya MENAMBAH baris yang hilang, dan hanya kalau host-nya belum
 * dimiliki lokasi lain. Baris yang sudah ada -- termasuk domain tambahan
 * yang ditambahkan lewat halaman Domain -- dibiarkan apa adanya.
 *
 * Aman dijalankan berulang: baris yang sudah ada dilewati.
 *
 * Contoh pemakaian:
 *
 *     php artisan tenancy:repair-domains
 *     php artisan tenancy:repair-domains --dry-run
 *     php artisan tenancy:repair-domains tirto-mulo
 */
class RepairDomainsCommand extends Command
{
    protected $signature = 'tenancy:repair-domains
        {tenant? : Slug lokasi. Kosongkan untuk semua lokasi terdaftar.}
        {--dry-run : Tampilkan apa yang akan diperbaiki tanpa menulis apa pun}';

    protected $description = 'Perbaiki lokasi yang domainnya hilang dari tenant_domains (slot utama/alternatif)';

    public function handle(LegacyDomainSync $sync): int
    {
        $slug = $this->argument('tenant');

        $tenants = $slug
            ? Tenant::where('slug', $slug)->get()
            : Tenant::orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->error($slug ? "Lokasi `{$slug}` tidak terdaftar." : 'Tidak ada lokasi terdaftar.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line('<fg=yellow>DRY-RUN</>: tidak ada yang ditulis.');
            $this->newLine();
        }

        $totalDisimpan = 0;
        $totalDilewati = 0;
        $perbaiki = 0;

        foreach ($tenants as $tenant) {
            $slot = $this->slotYangHilang($tenant);

            if ($slot === null) {
                $this->line("<fg=green>—</> {$tenant->slug}: lengkap.");

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("<fg=yellow>—</> {$tenant->slug}: `{$slot}` belum ada di tenant_domains.");
                $perbaiki++;

                continue;
            }

            $hasil = $sync->repair($tenant);

            $totalDisimpan += $hasil['disimpan'];
            $totalDilewati += $hasil['dilewati'];
            $perbaiki++;

            $this->line("<fg=green>✓</> {$tenant->slug}: {$hasil['disimpan']} baris ditambahkan, {$hasil['dilewati']} dilewati.");
        }

        $this->newLine();

        if ($this->option('dry-run')) {
            $this->info("{$perbaiki} lokasi perlu diperbaiki. Jalankan tanpa `--dry-run` untuk menulis.");

            return self::SUCCESS;
        }

        $this->info("{$perbaiki} lokasi diperbaiki: {$totalDisimpan} baris ditambahkan, {$totalDilewati} dilewati.");

        return self::SUCCESS;
    }

    /**
     * Host slot mana yang belum punya baris di `tenant_domains`.
     *
     * Dicek per slot, bukan per lokasi: lokasi yang punya satu dari dua slot
     * tetap perlu diperbaiki, dan laporan per-lokasi harus menyebut slot mana
     * yang bermasalah -- bukan cuma "ada yang kurang".
     *
     * Host-nya dinormalkan DULU, dengan aturan yang sama persis seperti
     * `LegacyDomainSync::repair()`. Kalau tidak, laporan dan perbaikan akan
     * melihat dua bentuk berbeda dari host yang sama: kolom legacy bisa
     * berisi `https://demo.test/`, dan `repair()` akan menormalkannya menjadi
     * `demo.test` -- yang ternyata sudah punya baris. Laporan akan bilang
     * "perlu diperbaiki" padahal tidak ada yang berubah, dan admin mulai
     * meragukan laporannya.
     */
    private function slotYangHilang(Tenant $tenant): ?string
    {
        foreach (['domain', 'domain_alternatif'] as $kolom) {
            $host = DomainNormalizer::normalizeLegacy($tenant->{$kolom});

            if ($host === null) {
                continue;
            }

            $ada = $tenant->domains()
                ->where('host', $host)
                ->where('is_wildcard', false)
                ->exists();

            if (! $ada) {
                return $host;
            }
        }

        return null;
    }
}
