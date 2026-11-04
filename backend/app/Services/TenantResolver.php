<?php

namespace App\Services;

use App\Models\Central\Tenant;
use App\Models\Central\TenantDomain;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

/**
 * Menentukan lokasi mana yang melayani request.
 *
 * Dua sumber identitas, berurutan dari yang paling kuat:
 *
 *   1. HOST   -- `tirto-mulo.astaloka.co.id`. Ini cara produksi, karena
 *                user tidak pernah memilih lokasi secara manual; browser
 *                yang membawa identitasnya lewat subdomain.
 *
 *   2. SLUG   -- header `X-Tenant-Slug` atau body/param. Ini jalur untuk
 *                development lokal, di mana semua lokasi diakses lewat
 *                `localhost` sehingga host tidak bisa membedakan.
 *
 * Header slug sengaja hanya dipercaya di luar produksi. Kalau tidak, siapa
 * saja bisa menambahkan `X-Tenant-Slug` di request dan membaca data lokasi
 * lain hanya dengan menebak slug-nya -- tanpa perlu punya akun di lokasi itu.
 */
class TenantResolver
{
    public function __construct(private TenantManager $manager) {}

    /**
     * Lokasi untuk request ini, atau null kalau tidak ada.
     *
     * Null BUKAN berarti error: request di domain pusat (landing page,
     * login superadmin, API admin) memang tidak punya lokasi.
     */
    public function resolve(Request $request): ?Tenant
    {
        $tenant = $this->fromHost($request);

        if ($tenant) {
            return $tenant;
        }

        return $this->fromSlug($request);
    }

    /**
     * Cari lokasi dari host request.
     *
     * Host dicocokkan ke tiga sumber, berurutan dari yang paling umum:
     *
     *   1. `tenant_domains` -- domain custom, termasuk wildcard `*.x.test`
     *   2. kolom `tenants.domain`
     *   3. kolom `tenants.domain_alternatif`
     *
     * Ketiganya dicocokkan sebagai HOST LITERAL, bukan dengan memotong
     * subdomain. Menebak subdomain dari host lebih sederhana, tapi salah
     * begitu cepat: dengan domain `astaloka.co.id`, host `www.astaloka.co.id`
     * akan menghasilkan slug `www` yang tidak ada, sedangkan
     * `tirto-mulo.astaloka.co.id` menghasilkan `tirto-mulo` -- padahal nama
     * lokasi belum tentu sama dengan slug subdomain-nya.
     *
     * Host SELALU diperiksa, di mode `host` maupun mode `slug`. Pencocokan
     * host hanya memperketat identitas: kalau tidak cocok, request tetap
     * boleh lanjut ke jalur slug. Yang bersifat opsional adalah sebaliknya --
     * di produksi slug tidak boleh dipercaya -- dan itu sudah ditangani
     * slugAllowed() di bawah.
     *
     * Tanpa pengecekan host tanpa syarat, `TENANCY_LOOKUP=slug` untuk
     * development membuat subdomain lokasi ikut dilayani halaman depan.
     * Petugas yang membuka `tirto-mulo.astaloka.test` akan melihat halaman
     * marketing di mana dia justru ingin langsung masuk login.
     */
    private function fromHost(Request $request): ?Tenant
    {
        try {
            $host = strtolower(trim($request->getHost()));
        } catch (SuspiciousOperationException) {
            /*
             * Host yang tidak valid DITOLAK, bukan diteruskan ke query.
             *
             * `Request::getHost()` melempar `SuspiciousOperationException` untuk
             * host berisi karakter yang tidak boleh ada di header -- `%`, `_`,
             * newline. Menangkapnya di sini mengubah dua hal sekaligus:
             *
             *   1. Request tetap mendapat jawaban normal (tenant tidak
             *      ditemukan -> halaman depan), bukan 500 dari dalam
             *      middleware. Penolakan host memang kondisi yang wajar --
             *      scanner internet mengirim header aneh secara rutin.
             *   2. Query wildcard `LIKE` tidak pernah menerima `%` atau `_`
             *      dari user. Tanpa capture ini, `escapeLike()` di bawah
             *      adalah satu-satunya pertahanan, dananguard seperti itu
             *      mudah terlewat kalau ada jalur lain yang menambah klausa
             *      LIKE.
             *
             * `escapeLike()` tetap dipakai. Dua lapis, bukan satu: capture ini
             * menegakkan aturan "host tidak valid tidak dilayani", sedangkan
             * escape menegakkan aturan "wildcard di LIKE adalah literal".
             */
            return null;
        }

        if ($host === '') {
            return null;
        }

        // (1) Domain persis. Satu indexed query, bukan load-all-then-loop:
        // daftar lokasi tumbuh seiring pelanggan, dan tiap request tidak boleh
        // ikut melambat.
        //
        // `is_active` ada di KEDUA tabel yang terlibat -- `tenant_domains` dan
        // `tenants` -- jadi harus ditulis lengkap dengan nama tabelnya.
        // MySQL menolak dengan "Column 'is_active' in where clause is
        // ambiguous" kalau dibiarkan polos.
        //
        // `whereHas` dipakai, bukan `join` + `select('tenants.*')`: join
        // mengembalikan instance `TenantDomain` yang kolomnya berisi baris
        // `tenants`, sehingga `$tenant->id` malah berisi id domain dan
        // `TenantManager::activate()` akan membuka database yang salah --
        // tanpa error apa pun, hanya data lokasi yang tidak sesuai.
        $exact = TenantDomain::query()
            ->where('host', $host)
            ->where('tenant_domains.is_active', true)
            ->whereHas('tenant', fn ($q) => $q->where('is_active', true))
            ->first();

        if ($exact) {
            return $exact->tenant;
        }

        // (2) Wildcard `*.astaloka.test`. Host `demo.astaloka.test` tidak ada
        // di kolom `host`, jadi query (1) tidak akan menemukannya.
        //
        // Kandidat diambil lewat `LIKE '%' . suffix`, yang memakai index
        // `tenant_domains_host_unique` untuk mempersempit ke domain yang
        // berakhiran sama. Setelah itu aturan "hanya menjulurkan satu label"
        // ditegakkan di PHP oleh `TenantDomain::matches()`, karena `*.a.b.test`
        // tidak boleh melayani `x.y.a.b.test` -- aturan itu mustahil
        // diekspresikan di SQL tanpa mengorbankan index.
        //
        // Suffix = host tanpa label pertamanya, didahului titik:
        // `demo.astaloka.test` -> `.astaloka.test`.
        $suffix = '.'.$this->wildcardSuffix($host);

        if ($suffix !== '.') {
            $candidates = TenantDomain::query()
                ->where('is_wildcard', true)
                ->where('tenant_domains.is_active', true)
                ->where('host', 'like', '%'.$this->escapeLike($suffix))
                ->whereHas('tenant', fn ($q) => $q->where('is_active', true))
                ->get();

            foreach ($candidates as $candidate) {
                if ($candidate->matches($host)) {
                    return $candidate->tenant;
                }
            }
        }

        // (3) Kolom legacy. Tetap dibaca sebagai cadangan supaya lokasi yang
        // ditambahkan lewat `php artisan tenancy:make` -- yang tidak melewati
        // controller dan tidak selalu punya baris di `tenant_domains` -- tetap
        // bisa diakses lewat domainnya.
        //
        // TAPI host yang barisnya sudah ada dan DINONAKTIFKAN tidak boleh
        // dilayani lagi lewat jalur ini. Tanpa penjagaan di bawah, mematikan
        // satu-satunya domain sebuah lokasi tidak mengubah apa pun: exact
        // match-nya hilang dari `tenant_domains`, lalu fallback ini menemukan
        // host yang sama di `tenants.domain` dan tetap mengarahkan request ke
        // database lokasi itu.
        return Tenant::query()
            ->where('is_active', true)
            ->where(function ($q) use ($host) {
                $q->where('domain', $host)
                    ->orWhere('domain_alternatif', $host);
            })
            ->whereNotExists(function ($q) use ($host) {
                $q->selectRaw('1')
                    ->from('tenant_domains')
                    ->where('tenant_domains.host', $host)
                    ->where('tenant_domains.is_active', false);
            })
            ->first();
    }

    /**
     * Buang label pertama dari host, sisakan sisanya untuk pencarian wildcard.
     *
     * `demo.astaloka.test` -> `astaloka.test`
     * `astaloka.test`      -> `` (tidak punya subdomain sama sekali)
     */
    private function wildcardSuffix(string $host): string
    {
        $dot = strpos($host, '.');

        return $dot === false ? '' : substr($host, $dot + 1);
    }

    /**
     * Escape wildcard SQL di LIKE.
     *
     * Host dari request bisa memuat `%` atau `_` -- keduanya wildcard di LIKE.
     * Tanpa escape, permintaan ke `foo%bar.test` cocok dengan host apa pun yang
     * berakhiran `bar.test`, termasuk milik lokasi lain. Pengguna normal tidak
     * punya akses ke sini, tapi input ini tetap datang dari luar.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** Cari lokasi dari slug yang dikirim frontend. */
    private function fromSlug(Request $request): ?Tenant
    {
        $header = (string) config('tenancy.slug_header', 'X-Tenant-Slug');

        // Produksi hanya percaya host. Lokasi di sini hanya untuk frontend
        // dev, di mana `localhost` tidak bisa membedakan lokasi.
        if (! $this->slugAllowed()) {
            return null;
        }

        $slug = $request->header($header);

        if (! $slug) {
            // Body hanya untuk endpoint login, yang memang dijalankan
            // sebelum ada lokasi yang aktif.
            $slug = $request->input('tenant');
        }

        if (! $slug || ! is_string($slug)) {
            return null;
        }

        $slug = strtolower(trim($slug));

        // Slug bukan input bebas -- dia masuk ke query. Batasi pola supaya
        // tidak bisa dipakai untuk menggali atau menebak nama lain.
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug)) {
            return null;
        }

        return Tenant::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();
    }

    /** Apakah slug boleh dipercaya pada environment ini. */
    private function slugAllowed(): bool
    {
        if (config('tenancy.lookup') === 'slug') {
            return true;
        }

        return ! app()->environment('production');
    }
}
