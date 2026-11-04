<?php

namespace App\Services;

use App\Models\Central\Tenant;
use App\Models\Central\TenantDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Pembersihan & validasi input domain custom.
 *
 * Dipisah dari controller karena aturan ini dipakai di tiga tempat yang
 * tidak boleh berbeda sedikit pun: menambah domain, mengedit lokasi (yang
 * juga menyentuh kolom legacy), dan_places yang memanggilnya dari form
 * request. Kalau tiap tempat punya versinya sendiri, cepat atau lambat
 * ketiganya berbeda, dan yang kalah biasanya yang terakhir ditulis -- yaitu
 * yang dipakai user.
 */
final class DomainNormalizer
{
    /**
     * Host yang tidak boleh didaftarkan sebagai domain lokasi.
     *
     * Semuanya menunjuk ke mesin atau loopback. Domain seperti `localhost` yang
     * berhasil didaftarkan akan membuat request di host itu dialihkan ke
     * database lokasi -- sesuatu yang secara teknis "bekerja", tapi karena
     * `localhost` dipakai juga oleh Vite dev server dan oleh panel pusat
     * lokal, hasilnya adalah login yang mendadak mengarah ke data lokasi
     * yang salah.
     */
    private const RESERVED_HOSTS = [
        'localhost',
        '127.0.0.1',
        '::1',
        '0.0.0.0',
        'host.docker.internal',
    ];

    /**
     * Bersihkan input mentah dari form menjadi host yang siap disimpan.
     *
     * Yang dibuang, berurutan: spasi di tepi, skema (`https://`), path
     * (`/login`), query, port, titik di ujung, dan huruf besar.
     *
     * Jadi `https://Demo.Astaloka.test/login` -> `demo.astaloka.test`. Yang
     * diketik user adalah kesalahan yang sangat umum, dan juga yang paling
     * merusak kalau tidak dibuang: domain yang tersimpan apa adanya TIDAK AKAN
     * PERNAH ditemukan resolver, karena request selalu datang sebagai host
     * polos. Gejalanya: domain terlihat tersimpan di panel, tapi alamatnya
     * masih mendarat di lokasi lain atau di halaman marketing.
     *
     * @return array{0:string|null,1:string|null} [host|null, pesan error|null]
     */
    public static function clean(?string $raw): array
    {
        if (! is_string($raw)) {
            return [null, 'Domain tidak boleh kosong.'];
        }

        $host = strtolower(trim($raw));

        if ($host === '') {
            return [null, 'Domain tidak boleh kosong.'];
        }

        // Skema dibuang lebih dulu, sebelum explode('/') -- kalau tidak,
        // `https://` ikut terpotong dan tersisa `https:` yang tidak bisa
        // dipakai.
        $host = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $host);

        // Path, query, fragment. `demo.test/login` -> `demo.test`.
        $host = explode('/', $host)[0];
        $host = explode('?', $host)[0];

        // Port. `demo.test:8080` -> `demo.test`.
        $host = explode(':', $host)[0];

        // Titik di ujung Fully-Qualified Domain: `demo.test.` -> `demo.test`.
        $host = rtrim($host, '.');

        if ($host === '') {
            return [null, 'Domain tidak boleh kosong.'];
        }

        if (in_array($host, self::RESERVED_HOSTS, true)) {
            return [null, "Domain `{$host}` tidak boleh dipakai -- itu alamat lokal, bukan domain publik."];
        }

        // Host harus punya minimal satu titik. Tanpa titik, varian seperti
        // `srv01` akan lolos validasi sintaks tapi tidak akan pernah terjangkau
        // lewat DNS.
        if (! str_contains($host, '.')) {
            return [null, "Domain `{$host}` tidak lengkap. Domain harus punya minimal satu titik, misalnya `demo.astaloka.test`."];
        }

        // Panjang label dan panjang total. Batas 63 karakter per label dan
        // 253 karakter total adalah aturan DNS, bukan pilihan kita.
        foreach (explode('.', $host) as $label) {
            if ($label === '') {
                return [null, 'Domain tidak valid: ada label yang kosong.'];
            }

            if (strlen($label) > 63) {
                return [null, "Domain tidak valid: label `{$label}` melebihi 63 karakter."];
            }

            if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                return [null, "Domain tidak valid. Label `{$label}` hanya boleh berisi huruf, angka, dan tanda hubung (tidak boleh diawali/diakhiri hubung)."];
            }
        }

        if (strlen($host) > 253) {
            return [null, 'Domain tidak valid: panjang total melebihi 253 karakter.'];
        }

        // Label terakhir harus berupa huruf (TLD), bukan angka. Tanpa ini,
        // `192.168.1.10` lolos sebagai "domain" padahal itu alamat IP, dan
        // tidak punya DNS sama sekali.
        $tld = substr($host, strrpos($host, '.') + 1);

        if (! preg_match('/^[a-z]{2,}$/', $tld)) {
            return [null, "Domain tidak valid: bagian terakhir `{$tld}` bukan domain. Gunakan nama domain, bukan alamat IP."];
        }

        return [$host, null];
    }

    /**
     * Bersihkan input yang boleh berupa wildcard `*.x.test`.
     *
     * Wildcard hanya boleh di awal, dan hanya satu label. `*.x.y.test` tetap
     * diterima di sini -- batasiannya (hanya boleh menjulurkan satu label)
     * ditegakkan saat pencocokan, di `TenantDomain::matches()`.
     *
     * @return array{0:string|null,1:bool,2:string|null} [host|null, wildcard, pesan error|null]
     */
    public static function cleanWildcard(?string $raw): array
    {
        if (! is_string($raw)) {
            return [null, false, 'Domain tidak boleh kosong.'];
        }

        $raw = strtolower(trim($raw));

        if (str_starts_with($raw, '*.')) {
            $suffix = self::clean(substr($raw, 2));

            if ($suffix[1] !== null) {
                return [null, false, $suffix[1]];
            }

            // Prefiks `*.` dikembalikan lagi. Disimpan penuh di kolom `host` supaya apa
            // yang diketik admin terbaca apa adanya di panel dan di DNS -- dan
            // supaya `ensureAvailable()` bisa membandingkan satu string utuh,
            // bukan hasil-hasil yang harus dirakit ulang di setiap pemanggil.
            return ['*.'.$suffix[0], true, null];
        }

        $host = self::clean($raw);

        return [$host[0], false, $host[1]];
    }

    /**
     * Host yang sedang dipakai lokasi lain di sistem.
     *
     * Validasi keunikan di sini, BUKAN hanya index unik di database.
     *
     * Alasannya: index unik memang membuat tabrakan gagal, tapi pesannya comes
     * dari driver MySQL dalam bahasa Inggris dan tidak menyebut lokasi mana
     * yang sudah memakai host tersebut. Untuk admin yang sedang menambah
     * domain ke lokasi kedua, "Duplicate entry" tidak menjawab pertanyaan
     * yangdia punya -- yaitu "domain ini sudah dipakai siapa?". Pertanyaan itu
     * dijawab sebelum query INSERT, dan index unik tetap ada sebagai jaring
     * pengaman terakhir untuk dua admin yang menekan tombol bersamaan.
     *
     * @param  int  $tenantId  Lokasi yang sedang diedit; domain miliknya sendiri tidak dianggap bentrok
     * @return array{0:bool,1:string|null} [boleh, pesan|null]
     */
    public static function ensureAvailable(string $host, bool $isWildcard, int $tenantId): array
    {
        // Wildcard hanya bertabrakan dengan wildcard: `*.astaloka.test` dan
        // `demo.astaloka.test` boleh hidup berdampingan -- justru itu yang
        // membuat domain persis berguna di atas wildcard. Exact match hanya
        // bertabrakan dengan exact match yang sama persis.
        $bentrok = TenantDomain::query()
            ->where('host', $host)
            ->where('is_wildcard', $isWildcard)
            ->where('tenant_id', '!=', $tenantId)
            ->with('tenant')
            ->first();

        if ($bentrok) {
            $pemilik = $bentrok->tenant?->label() ?? "lokasi #{$bentrok->tenant_id}";

            return [false, "Domain `{$host}` sudah dipakai oleh {$pemilik}. Satu domain hanya boleh dimiliki satu lokasi."];
        }

        /*
         * Kolom legacy ikut diperiksa.
         *
         * Lokasi yang dibuat lewat `tenancy:make` versi lama hanya punya
         * `tenants.domain` dan tidak punya baris di `tenant_domains`. Kalau
         * pemeriksaan ini hanya melihat tabel baru, host itu bisa didaftarkan
         * ke lokasi lain lewat halaman Domain -- dan resolver, yang mencari
         * `tenant_domains` lebih dulu, langsung memindahkan seluruh request
         * ke lokasi kedua.
         *
         * `$isWildcard` tidak mengubah pemeriksaan legacy: kolom lama tidak
         * pernah menyimpan wildcard yang benar-benar berfungsi (resolver
         * mencocokkannya persis), jadi hanya tabrakan persis yang relevan.
         */
        $legacy = Tenant::query()
            ->where('id', '!=', $tenantId)
            ->where(function ($q) use ($host) {
                $q->where('domain', $host)->orWhere('domain_alternatif', $host);
            })
            ->first();

        if ($legacy) {
            return [false, "Domain `{$host}` masih tercatat sebagai domain lama {$legacy->label()}. Lepaskan dulu dari lokasi itu sebelum dipakai di sini."];
        }

        /*
         * Tabrakan dengan domain PUSAT.
         *
         * Yang dicek: host pusat itu sendiri, dan host pusat sebagai subdomain
         * dari domain wildcard yang baru. `*.astaloka.test` akan capturar
         * `panel.astaloka.test` -- jadi daftar host yang wajib dikecualikan
         * tidak bisa hanya diisi `astaloka.test`.
         */
        $centralHosts = self::centralHosts();

        if (in_array($host, $centralHosts, true)) {
            return [false, "`{$host}` adalah domain panel pusat. Domain ini tidak bisa dipakai lokasi."];
        }

        if ($isWildcard) {
            foreach ($centralHosts as $central) {
                if (self::wildcardWouldCapture($host, $central)) {
                    return [false, "Wildcard `{$host}` akan ikut menangkap `{$central}`, yang adalah domain panel pusat. Domain ini tidak bisa dipakai lokasi."];
                }
            }
        }

        return [true, null];
    }

    /**
     * Apakah wildcard ini akan menangkap host tersebut.
     *
     * Memakai `TenantDomain::matches()` -- aturan yang sama dengan yang dipakai
     * resolver saat request datang -- bukan `str_ends_with()` sendiri. Dua
     * tempat yang "hampir sama" adalah cara paling umum validasi lolos: yang
     * lolos di sini tapi tidak cocok saat request datang membuat domain yang
     * terlihat aktif dan sebenarnya mati. Yang lebih berbahaya adalah
     * sebaliknya, yang lolos keduanya dan diam-diam mengambil alih domain
     * pusat.
     */
    private static function wildcardWouldCapture(string $wildcardHost, string $host): bool
    {
        return (new TenantDomain([
            'host' => $wildcardHost,
            'is_wildcard' => true,
        ]))->matches($host);
    }

    /**
     * Bersihkan host dari data lama, tanpa menolak apa pun.
     *
     * Berbeda dengan `clean()`, yang ini TIDAK memvalidasi -- ia hanya
     * membersihkan. Dipakai saat menyalin kolom `domain` /
     * `domain_alternatif` ke `tenant_domains`.
     *
     * Alasan tidak memakai `clean()` di sana: kolom lama diisi dari form yang
     * tidak pernah memvalidasi format sama sekali, jadi isinya bisa saja
     * `https://Tirto-Mulo.Astaloka.test/login` atau `localhost:5173`. Menolak
     * baris seperti itu berarti lokasi kehilangan domain lamanya di daftar
     * baru -- dan satu baris tidak layak tidak boleh membatalkan pemindahan
     * seluruh lokasi lain.
     *
     * Satu-satunya yang dibuang di sini adalah bentuk yang jelas tidak bisa jadi
     * host: kosong, skema, path, query, dan port. Hasil akhir yang tidak punya
     * titik tetap diteruskan -- `TenantResolver` tidak akan pernah
     * menemukannya, tapi membiarkan datanya utuh lebih baik daripada
     * menghapusnya.
     */
    public static function normalizeLegacy(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $host = strtolower(trim($raw));

        if ($host === '') {
            return null;
        }

        // Skema dicocokkan lebih dulu lewat regex supaya `http://` tidak ikut
        // terpotong oleh explode('/') di bawah.
        $host = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $host);

        // Path, query, dan fragment: `demo.test/login` -> `demo.test`.
        $host = explode('/', $host)[0];
        $host = explode('?', $host)[0];

        // Port. Penting untuk development: `localhost:5173` adalah host Vite
        // yang tidak boleh ikut terimpan sebagai domain lokasi.
        $host = explode(':', $host)[0];

        // Titik di ujung FQDN: `demo.test.` -> `demo.test`.
        $host = rtrim($host, '.');

        return $host === '' ? null : $host;
    }

    /**
     * Salin kolom legacy `domain` / `domain_alternatif` ke `tenant_domains`.
     *
     * Idempoten dan aman dipanggil berulang: baris yang sudah ada dilewati
     * lewat `insertOrIgnore`, dan index unik `tenant_domains_host_unique`
     * menjadi jaring pengaman terakhir untuk dua proses yang menjalankan
     * bersamaan.
     *
     * Host yang sudah dimiliki lokasi lain DILEWATI, bukan membuat pemindahan
     * gagal. Data lama memang bisa saja duplikat -- tidak ada constraint yang
     * mencegahnya. Melewatkan satu baris berarti lokasi itu kehilangan domain
     * lamanya di daftar baru, yang masih bisa dibaca lewat kolom lamanya.
     * Membatalkan seluruh operasi karena satu baris bermasalah berarti TIDAK
     * ADA lokasi yang punya domain baru sama sekali.
     *
     * @return array{disimpan:int, dilewati:int} Statistik, untuk pesan log.
     */
    public static function backfillLegacyDomains(): array
    {
        $central = (string) config('tenancy.central_connection', 'central');

        if (! Schema::connection($central)->hasTable('tenant_domains')) {
            return ['disimpan' => 0, 'dilewati' => 0];
        }

        $disimpan = 0;
        $dilewati = 0;
        $now = now();

        foreach (DB::connection($central)->table('tenants')->get() as $tenant) {
            /*
             * Kalau lokasi ini sudah punya domain di daftar baru, SALINAN LAMA
             * TIDAK boleh menimpanya.
             *
             * Kolom `tenants.domain` bisa tertinggal: admin sudah mengganti
             * domain utama lewat panel, sementara kolom lamanya masih menunjuk
             * domain sebelumnya. Pemulihan yang menimpa akan membalikkan
             * perubahan admin itu -- dan salah tanpanya, karena panel menampilkan
             * hasil yang kelihatan benar sementara lokasi kembali ke domain lama.
             *
             * Yang disalin hanya lokasi yang daftar barunya benar-benar kosong,
             * yaitu kasus yang justru rusak: tabelnya ada tapi isinya hilang.
             */
            $sudahPunya = DB::connection($central)
                ->table('tenant_domains')
                ->where('tenant_id', $tenant->id)
                ->exists();

            if ($sudahPunya) {
                $dilewati += 2;

                continue;
            }

            $sumber = [
                ['host' => $tenant->domain, 'primary' => true],
                ['host' => $tenant->domain_alternatif, 'primary' => false],
            ];

            foreach ($sumber as $slot) {
                $host = self::normalizeLegacy($slot['host']);

                if ($host === null) {
                    continue;
                }

                $inserted = DB::connection($central)->table('tenant_domains')->insertOrIgnore([
                    'tenant_id' => $tenant->id,
                    'host' => $host,
                    'is_wildcard' => false,
                    'is_primary' => $slot['primary'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $disimpan += $inserted;
                $dilewati += $inserted ? 0 : 1;
            }
        }

        return ['disimpan' => $disimpan, 'dilewati' => $dilewati];
    }

    /**
     * Host milik panel pusat yang tidak boleh dipakai lokasi.
     *
     * Sumbernya tiga, dari yang paling tegas ke yang paling lemah:
     *   1. `CENTRAL_HOSTS` di config -- satu daftar yang bisa diisi ops,
     *      ditulis sekali dan dibaca di mana saja;
     *   2. `APP_URL` -- host yang dipakai Laravel sendiri;
     *   3. host sentuhan lokal (`localhost`, `127.0.0.1`) yang selalu
     *      diabaikan karena tidak bisa di-DNS.
     *
     * @return list<string>
     */
    public static function centralHosts(): array
    {
        $hosts = (array) config('tenancy.central_hosts', []);

        $appUrl = (string) config('app.url');
        if ($appUrl !== '') {
            $parsed = parse_url($appUrl);
            if (! empty($parsed['host'])) {
                $hosts[] = strtolower($parsed['host']);
            }
        }

        $hosts = array_map(
            fn ($h) => strtolower(trim((string) $h)),
            $hosts
        );

        return array_values(array_unique(array_filter($hosts, fn ($h) => $h !== '')));
    }

    /**
     * Pastikan tepat satu domain utama untuk sebuah lokasi.
     *
     * Dipanggil setiap kali domain dibuat, dimatikan, dijadikan utama, atau
     * dihapus. Tanpa ini, `settings.domain` di setiap database lokasi bisa
     * tertunjuk ke domain yang sudah tidak aktif -- dan laporan yang
     * mencantumkan domain perusahaan menghasilkan alamat yang tidak bisa
     * dibuka.
     *
     * Domain utama diambil dari: domain yang baru ditandai utama, atau
     * fallback ke domain aktif pertama. Kalau tidak ada domain aktif sama
     * sekali, kolom legacy `tenants.domain` yang dipakai -- supaya lokasi
     * yang belum pernah menyentuh domain baru tetap punya nilai.
     */
    public static function syncPrimary(Tenant $tenant): void
    {
        /*
         * Hanya domain persis. Wildcard `*.x.test` tidak punya host yang bisa
         * dibuka atau dicetak di kop laporan -- menuliskannya ke kolom legacy
         * membuat `settings.domain` berisi string yang tidak pernah bisa
         * dipakai, dan `punyaDomain()` menganggap lokasi itu punya alamat.
         */
        $utama = $tenant->activeDomains()
            ->where('is_wildcard', false)
            ->where('is_primary', true)
            ->orderBy('id')
            ->first();

        if (! $utama) {
            $utama = $tenant->activeDomains()
                ->where('is_wildcard', false)
                ->orderBy('id')
                ->first();
        }

        $host = $utama?->host;

        if ($host === null) {
            /*
             * Tidak ada domain persis yang aktif. Kolom legacy hanya boleh
             * dipertahankan kalau host itu memang belum pernah dikelola di
             * `tenant_domains` -- kalau barisnya ada tapi nonaktif, host itu
             * sudah "dimatikan" dan tidak boleh hidup lagi lewat fallback
             * resolver.
             *
             * Wildcard sisa versi lama juga dilepas di sini: nilai seperti
             * `*.x.test` tidak pernah bisa dipakai sebagai host persis.
             */
            $legacy = $tenant->domain;

            if (is_string($legacy)) {
                if (str_starts_with($legacy, '*.') || $legacy === '') {
                    $legacy = null;
                } elseif ($tenant->domains()->where('host', $legacy)->exists()) {
                    $legacy = null;
                }
            }

            $host = $legacy;
        }

        // Tidak ada yang berubah -> berhenti sebelum menyentuh database lokasi.
        //
        // Ini bukan hanya penghematan. `runFor()` di bawah membuka koneksi
        // MySQL sungguhan, dan lokasi yang database-nya hilang atau belum
        // provisioned akan membuat setiap panggilan ke sini menunggu koneksi
        // yang memang tidak akan pernah berhasil -- satu tempat yang bisa
        // menggantungkan seluruh halaman detail lokasi, bukan hanya satu
        // domainnya.
        if ($host === $tenant->domain) {
            return;
        }

        $tenant->update(['domain' => $host]);

        /*
         * Ditulis ke database lokasi juga, karena `applyTenantIdentity()`
         * mengisi `settings.domain` dan laporan membacanya dari sana. Satu
         * database per lokasi, jadi satu update per domain utama yang berubah.
         *
         * Kegagalan TIDAK menggagalkan penyimpanan domain: domain sudah
         * benar di registry pusat, dan `tenancy:reseed-master <slug> --identity` bisa
         * menjalankan ulang langkah ini.
         */
        if (! $tenant->databaseExists()) {
            Log::warning('Domain utama diperbarui, tetapi database lokasi tidak ada.', [
                'tenant_id' => $tenant->id,
                'slug' => $tenant->slug,
                'database' => $tenant->database,
                'host' => $host,
            ]);

            return;
        }

        try {
            app(TenantManager::class)->runFor($tenant, function () use ($host) {
                DB::connection(TenantManager::CONNECTION)
                    ->table('settings')
                    ->where('id', 1)
                    ->update([
                        'domain' => $host,
                        'updated_at' => now(),
                    ]);
            });
        } catch (Throwable $e) {
            Log::warning('Gagal menyinkronkan domain utama ke database lokasi.', [
                'tenant_id' => $tenant->id,
                'slug' => $tenant->slug,
                'database' => $tenant->database,
                'host' => $host,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
