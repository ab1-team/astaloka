<?php

namespace App\Http\Middleware;

use App\Models\Central\Tenant;
use App\Services\TenantManager;
use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Arahkan koneksi database ke lokasi yang melayani request.
 *
 * URUTAN PENTING. Middleware ini wajib jalan SEBELUM `auth:sanctum`, karena
 * Sanctum mencari token lewat `$model::findToken()`, dan model
 * `PersonalAccessToken` memakai `database.default`. Kalau auth jalan lebih
 * dulu, token akan dibaca dari database yang salah.
 *
 * DAUA DUNIA TOKEN
 *
 * Token hidup di dua database berbeda dan keduanya memakai tabel
 * `personal_access_tokens` dengan kolom `id` yang sama:
 *
 *   - token lokasi  -> database lokasi
 *   - token pusat   -> database pusat (superadmin ASTA BRATA)
 *
 * Jadi middleware ini tidak hanya mengaktifkan lokasi -- ia juga HARUS
 * memilih dengan benar mana yang jadi `database.default`. Tanpa itu:
 *   - token pusat tidak ditemukan karena dibaca di database lokasi (401)
 *   - atau sebaliknya, token lokasi tidak ditemukan
 *
 * Karena `id` kedua tabel sama-sama autoincrement dari 1, token `1|abc` di
 * satu database bisa collide dengan token `1|xyz` di database lain. Angka
 * `id` pada prefix token memang tidak unik secara global, tapi Sanctum
 * memverifikasi hash `token` setelah menemukan baris, sehingga tabrakan id
 * hanya menambah satu query sia-sia -- bukan celah keamanan. Yang tetap
 * penting: database yang dipilih harus benar sejak awal.
 */
class ResolveTenant
{
    /**
     * Prefix path milik panel pusat. Request ke sini membaca database pusat.
     *
     * Dicek per-path, bukan per-role, karena role baru diketahui SETELAH
     * token ditemukan -- padahal keputusan database harus diambil sebelum
     * itu.
     */
    private const CENTRAL_PREFIX = 'api/central';

    /**
     * Prefix path yang SELALU dilayani database pusat, bukan database lokasi.
     *
     * SENGAJA KOSONG.
     *
     * Dulu `api/login-user` ada di sini, dengan alasan halaman pintasan
     * menampilkan user dari SELURUH lokasi sehingga harusnya membaca registry
     * pusat. Alasannya sudah tidak berlaku: `ShortcutLoginController` sekarang
     * hanya membaca lokasi yang sedang melayani request
     * (`TenantManager::current()`), jadi jawabannya justru data milik lokasi
     * itu sendiri -- bukan data lokasi lain.
     *
     * Kalau path ini tetap dipaksa ke database pusat, daftar user yang tampil
     * akan dibaca dari database yang salah, dan endpoint itu kembali berbohong
     * soal lokasi mana yang sedang dilayani.
     *
     * Daftar ini dibiarkan ada (meski kosong) supaya penambahan path pusat
     * berikutnya tetap punya tempat, dan supaya `isCentralPath()` tidak
     * harus ditulis ulang.
     */
    private const CENTRAL_PATHS = [
    ];

    /**
     * Endpoint tagihan milik USER LOKASI, bukan pusat.
     *
     * Harus dikecualikan dari CENTRAL_PREFIX. Tanpa ini, user lokasi yang
     * membuka halaman "tagihan saya" akan dicari di database pusat dan
     * mendapat 401 tepat saat dia paling butuh melihat tagihannya.
     */
    private const LOCATION_PATHS = [
        'api/tagihan-saya',
    ];

    public function __construct(
        private TenantResolver $resolver,
        private TenantManager $manager,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->path();

        $defaultSebelum = (string) Config::get('database.default');

        try {
            if ($this->isCentralPath($path)) {
                Config::set('database.default', (string) config('tenancy.central_connection', 'central'));

                return $next($request);
            }

            $tenant = $this->resolver->resolve($request);

            if ($tenant instanceof Tenant) {
                $this->manager->activate($tenant);

                // Dibagikan ke view/controller supaya UI bisa menampilkan nama
                // lokasi tanpa query ulang ke database pusat.
                $request->attributes->set('tenant', $tenant);

                return $next($request);
            }

            /*
             * Tidak ada lokasi untuk request ini.
             *
             * `database.default` dibiarkan apa adanya -- yaitu nilai `.env`.
             * Ini disengaja, bukan kelalaian.
             *
             * Alternatifnya adalah mengarahkan ke database pusat ketika
             * lokasi tidak ditemukan. Itu terlihat lebih aman, tapi
             *ritepatkan install mode SATU lokasi: di sana `DB_DATABASE`
             * menunjuk database lokasi yang benar, dan itulah yang
             * membuat login, dashboard, dan seluruh aplikasi bekerja tanpa
             * subdomain sama sekali. Mengarahkan ke pusat membuat
             * `User::where('email', ...)` mencari user di database yang
             * salah, dan setiap login gagal dengan "Email tidak terdaftar
             * di lokasi ini".
             *
             * Yang berbahaya bukan fallback-nya, tapi fallback yang tidak
             * terlihat. Lokasi yang salah hanya terjadi bila domain tidak
             * terdaftar, dan gejalanya jelas: user tidak ditemukan,
             * branding tidak cocok.
             *
             * Pengaman yang dipakai sebagai gantinya: `api/branding`
             * menjawab dari `TenantManager::current()`, yang null saat tidak
             * ada lokasi, jadi tidak ada data lokasi lain yang ikut terbaca.
             */
            $request->attributes->set('tenant', null);

            return $next($request);
        } finally {
            /*
             * Kembalikan `database.default` ke nilai sebelum request.
             *
             * Tanpa baris ini, request ke `api/central/*` meninggalkan
             * `database.default = central` untuk sisa proses. Pada server
             * HTTP (PHP-FPM) itu tidak terlihat karena tiap request punya
             * proses sendiri -- tapi pada `php artisan test` semua test
             * berbagi satu proses, dan test berikutnya yang memakai
             * `RefreshDatabase` akan membungkus transaksinya di koneksi
             * pusat sementara migrasi men-drop tabel koneksi lokasi.
             * Keduanya saling tunggu lock, dan suite menggantung tanpa batas
             * tanpa satu pun pesan error.
             *
             * `deactivate()` juga mengosongkan `TenantManager::$current`.
             * Tanpa itu, pada proses panjang (test, queue worker) request
             * berikutnya yang tidak punya lokasi masih membaca
             * `current()` milik lokasi sebelumnya -- mis. `api/branding`
             * menjawab branding lokasi lama padahal host-nya tidak dikenal.
             *
             * `finally`, bukan sesudah `$next` sukses: kalau controller melempar
             * exception, default tetap harus kembali atau render halaman error
             * berikutnya membaca database yang salah.
             */
            $this->manager->deactivate();

            Config::set('database.default', $defaultSebelum);
        }
    }

    private function isCentralPath(string $path): bool
    {
        foreach (self::LOCATION_PATHS as $loc) {
            if ($path === $loc || str_starts_with($path, $loc.'/')) {
                return false;
            }
        }

        foreach (self::CENTRAL_PATHS as $central) {
            if ($path === $central || str_starts_with($path, $central.'/')) {
                return true;
            }
        }

        return $path === self::CENTRAL_PREFIX || str_starts_with($path, self::CENTRAL_PREFIX.'/');
    }
}
