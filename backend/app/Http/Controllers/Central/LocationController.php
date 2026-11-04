<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use App\Models\Central\TenantDomain;
use App\Models\Central\TenantInvoice;
use App\Services\AuditLogger;
use App\Services\DomainNormalizer;
use App\Services\TenantManager;
use App\Support\Branding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Panel pusat ASTA BRATA: daftar lokasi, pendaftaran lokasi baru, dan tagihan
 * langganan.
 *
 * SEMUA akses ke sini dijaga middleware `superadmin`, yang juga mengembalikan
 * koneksi default ke database pusat. Jadi kelas ini boleh memakai
 * `Tenant::query()` dan `DB::table()` tanpaFear terhadap tenant lain.
 */
class LocationController extends Controller
{
    public function __construct(
        private TenantManager $manager,
        private AuditLogger $audit,
    ) {}

    /** Daftar semua lokasi + ringkasan tagihannya. */
    public function index(Request $request): JsonResponse
    {
        $lokasi = Tenant::query()
            // Jumlah invoice yang belum lunas per lokasi. Dipakai untuk badge
            // "menunggak" di daftar, jadi hanya yang UNPAID yang dihitung.
            ->withCount(['unpaidInvoices as tagihan_belum_lunas'])
            ->withSum(
                ['invoices as total_tagihan' => fn ($q) => $q->where('status', 'UNPAID')],
                'jumlah'
            )
            ->orderBy('id')
            ->get()
            ->map(fn (Tenant $t) => $this->present($t));

        return response()->json([
            'success' => true,
            'data' => $lokasi,
        ]);
    }

    /**
     * Daftar sektor yang boleh dipilih untuk sebuah tenant.
     *
     * Endpoint terpisah, bukan menyisipkan daftar ke respons mana pun. Daftar
     * ini milik FORM, dan form adalah tempat yang memutuskan mana yang
     * worth-call di jaringan -- dashboard tidak pernah butuh kategori utuh,
     * hanya warna dan label kategori yang sudah muncul di responsnya.
     *
     * Dipisah juga karena konstanta `Tenant::SEKTOR` harus tetap bisa berubah
     * tanpa menyentuh kode dashboard: kalau daftarnya ikut di setiap respons,
     * satu perubahan daftar jadi perubahan bentuk JSON di beberapa tempat.
     */
    public function sektor(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Tenant::SEKTOR,
        ]);
    }

    /**
     * Warna branding yang boleh dipilih untuk sebuah lokasi.
     *
     * Alasan endpoint ini ada, terpisah dari `sektor()`, sudah sama:
     * daftarnya milik FORM, dan form harus menawarkan persis apa yang
     * backend terima -- kalau frontend punya daftarnya sendiri, warna yang
     * sengaja tidak ada di daftar ini akan lolos sampai backend dan ditolak
     * dengan 422 yang tidak menyebut warna mana yang salah.
     *
     * Bentuk respons: daftar objek `{ hex, label }`, bukan map
     * `kunci => [hex, label]`. Kunci pada konstanta PHP ada untuk dibaca
     * manusia di source code; yang dikirim ke form adalah pasangan
     * warna + nama yang ditampilkan, dan urutan yang tampil mengikuti urutan
     * deklarasi (biru muda dulu, hijau, seterusnya).
     */
    public function warnaBranding(): JsonResponse
    {
        $daftar = [];

        foreach (Branding::WARNA_TERSEDIA as [$hex, $label]) {
            $daftar[] = ['hex' => $hex, 'label' => $label];
        }

        return response()->json([
            'success' => true,
            'data' => $daftar,
        ]);
    }

    /** Detail satu lokasi. */
    public function show(Tenant $location): JsonResponse
    {
        $location->load([
            'invoices' => fn ($q) => $q->orderByDesc('tgl_invoice'),
            'domains' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('host'),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->present($location, detail: true),
        ]);
    }

    /**
     * Daftarkan lokasi baru.
     *
     * Provisioning database lokasi adalah WAJIB, bukan opsi.
     *
     * Setiap langkah -- CREATE DATABASE, 66 migration, seeder master,
     * identitas di tabel `settings`, akun admin -- terjadi dalam satu
     * request. Yang boleh dilewati hanya akun admin, dan hanya bila
     * email/password-nya memang tidak diisi: database tanpa admin masih
     * bisa dibenahi lewat `tenancy:make-user`, sedangkan database yang
     * tidak punya skema tidak bisa dipakai sama sekali.
     *
     * Dampak nyata dari membuat lokasi tanpa database: tenant terdaftar
     * dan terlihat aktif di panel, tapi setiap request ke lokasi itu gagal
     * dengan error koneksi. Superadmin baru sadar saat lokasi itu
     * dibuka, bukan saat didaftarkan.
     *
     * `provision` masih diterima di payload untuk kompatibilitas ke depan,
     * tapi nilainya DIABAIKAN kalau false -- permintaan kontradisikan,
     * dan diam-diam mengabaikannya lebih aman daripada membuat setengah
     * lokasi. Field itu akan dihapus setelah tidak ada klien yang
     * mengirimnya.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:tenants,slug'],
            'nama' => ['required', 'string', 'max:150'],
            'nama_lembaga' => ['nullable', 'string', 'max:191'],
            'sektor' => ['nullable', 'string', 'max:60', 'in:'.implode(',', Tenant::SEKTOR)],
            'bidang_usaha' => ['nullable', 'string', 'max:120'],
            'deskripsi_usaha' => ['nullable', 'string', 'max:500'],
            'judul_login' => ['nullable', 'string', 'max:80'],
            'sub_judul_login' => ['nullable', 'string', 'max:80'],
            'deskripsi_login' => ['nullable', 'string', 'max:255'],
            'warna_utama' => ['nullable', 'string', 'regex:'.Branding::POLA_WARNA],
            'domain' => ['nullable', 'string', 'max:191'],
            'domain_alternatif' => ['nullable', 'string', 'max:191'],
            'alamat' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:191'],
            'telepon' => ['nullable', 'string', 'max:30'],
            'tgl_berakhir' => ['nullable', 'date'],
            'admin_email' => ['nullable', 'email', 'max:191'],
            'admin_password' => ['nullable', 'string', 'min:8'],
            'admin_name' => ['nullable', 'string', 'max:150'],
        ]);

        if (in_array($data['slug'], (array) config('tenancy.reserved_slugs', []), true)) {
            return response()->json([
                'success' => false,
                'message' => "Slug `{$data['slug']}` dicadangkan untuk sistem.",
            ], 422);
        }

        $dbName = TenantManager::databaseNameFor($data['slug']);

        if (Tenant::where('database', $dbName)->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Database `{$dbName}` sudah terdaftar untuk lokasi lain.",
            ], 422);
        }

        /*
         * Registry BELUM cukup. Nama database yang sudah ada di MySQL juga
         * harus ditolak di sini.
         *
         * Kedua keadaan itu berbeda, dan hanya satu yang terlihat dari
         * `tenants`:
         *
         *   - Baris tenant masih ada, database ikut ada. Keadaan ini sudah
         *     ditangani pemeriksaan di atas.
         *   - Baris tenant DIHAPUS (manual, atau provisioning yang gagal
         *     separuh jalan), tapi database-nya dibiarkan begitu saja.
         *     `tenants` tidak punya jejaknya -- nama `astaloka_tirto_mulo`
         *     sama sekali tidak muncul di registry, padahal database-nya
         *     masih berdiri dan berisi data lokasi lama.
         *
         * Di keadaan kedua, permintaan ini lolos semua validasi, lalu
         * `createDatabaseNamed()` melempar `RuntimeException` karena nama
         * sudah dipakai. Operator melihat 500 dengan pesan dari MySQL
         * alih-alih 422 yang menjelaskan apa yang sebenarnya bentrok.
         *
         * Yang membuatnya mudah terjadi: `databaseNameFor()` sengaja
         * menganggap hyphen dan underscore sebagai slug yang SAMA -- hasil
         * dari normalisasi yang disengaja dan diuji di
         * `tests/Unit/DatabaseNameTest.php`. Jadi `tirto-mulo` dan
         * `tirto_mulo` menghasilkan nama yang benar-benar sama, dan keduanya
         * dianggap slug berbeda oleh unique index `tenants.slug`. Lokasi
         * pertama bisa dibuat lewat CLI, dihapus dari registry panel, lalu
         * lokasi kedua dibuat dengan ejaan lain -- dan bentroknya baru
         * ketahuan di `CREATE DATABASE`.
         *
         * `dropDatabase()` mengembalikan `true` kalau benar-benar dihapus
         * dan `false` kalau memang tidak pernah ada, jadi hasil di bawah
         * tetap jujur soal database yang benar-benar ada.
         */
        if ($this->manager->databaseExists($dbName)) {
            return response()->json([
                'success' => false,
                'message' => "Database `{$dbName}` sudah ada di server tapi tidak "
                    .'terdaftar untuk lokasi mana pun. Lokasi yang pernah memakai '
                    .'nama itu kemungkinan baru dihapus dari daftar, atau provisioning-nya '
                    .'gagal di tengah. Cek dulu isinya sebelum menghapusnya, lalu jalankan '
                    ."`php artisan tenancy:make {$data['slug']} --force` untuk memakainya "
                    .'kembali.',
            ], 422);
        }

        $tenant = null;

        // Permintaan lama masih mengirim `provision: false`. Lokasi tanpa
        // skema tidak pernah bisa dipakai, jadi bolak-balik sama saja --
        // tolak lebih awal, sebelum database dibuat dan dihapus lagi.
        if ($request->boolean('provision', true) === false) {
            return response()->json([
                'success' => false,
                'message' => 'Penyiapan database lokasi tidak bisa dimatikan. '
                    .'Lokasi tanpa skema tidak bisa dipakai. Buat lewat panel pusat, '
                    .'atau pakai `php artisan tenancy:make` untuk penyiapan manual.',
            ], 422);
        }

        try {
            $this->manager->createDatabase($data['slug']);

            $tenant = Tenant::create([
                'slug' => $data['slug'],
                'nama' => $data['nama'],
                'nama_lembaga' => $data['nama_lembaga'] ?? null,
                'sektor' => $data['sektor'] ?? null,
                'bidang_usaha' => $data['bidang_usaha'] ?? null,
                'deskripsi_usaha' => $data['deskripsi_usaha'] ?? null,
                'judul_login' => $data['judul_login'] ?? null,
                'sub_judul_login' => $data['sub_judul_login'] ?? null,
                'deskripsi_login' => $data['deskripsi_login'] ?? null,
                'warna_utama' => $this->normalisasiWarna($data['warna_utama'] ?? null),
                'database' => $dbName,

                /*
                 * Kolom legacy ditulis DALAM BENTUK BERSIH, bukan mentah
                 * seperti input form.
                 *
                 * `TenantResolver::fromHost()` membaca `tenants.domain`
                 * apa adanya -- tanpa normalisasi -- karena ia membandingkan
                 * host polos dari request. Input yang disimpan mentah tidak
                 * akan PERNAH cocok: `https://demo.astaloka.test` tersimpan
                 * lengkap dengan skemanya, dan tidak ada request yang
                 * punya host `https://demo.astaloka.test`.
                 *
                 * Gejalanya sangat menyesatkan karena tidak ada error sama
                 * sekali: domain terlihat tersimpan di panel, barisnya juga
                 * dibuat di `tenant_domains`, tapi request di domain itu
                 * tetap mendarat di halaman depan -- seolah domainnya tidak
                 * pernah terdaftar.
                 *
                 * Yang dipakai adalah `DomainNormalizer::normalizeLegacy()`,
                 * sama yang dipakai migrasi backfill, jadi kedua jalur
                 * menulis bentuk yang sama persis.
                 */
                'domain' => $this->bersihkanLegacyDomain($data['domain'] ?? null),
                'domain_alternatif' => $this->bersihkanLegacyDomain($data['domain_alternatif'] ?? null),

                'alamat' => $data['alamat'] ?? null,
                'email' => $data['email'] ?? null,
                'telepon' => $data['telepon'] ?? null,
                'tgl_registrasi' => now()->toDateString(),
                'tgl_pakai' => now()->toDateString(),
                'tgl_berakhir' => $data['tgl_berakhir'] ?? null,
                'is_active' => true,
            ]);

            $this->manager->migrateTenantDatabase($tenant);

            // Master data (chart of accounts, jenis transaksi, jabatan)
            // wajib ada sebelum lokasi dipakai: tanpa CoA, halaman jurnal
            // akan kosong dan user tidak bisa mulai mencatat.
            $this->manager->seedTenantDatabase($tenant);

            /*
             * Domain dari form pendaftaran ikut didaftarkan ke
             * `tenant_domains`, supaya lokasi baru langsung punya domain yang
             * bisa dipakai -- bukan harus menunggu superadmin membuka halaman
             * Domain dan mengetik ulang apa yang barusan diketik di sini.
             *
             * Kegagalan validasi domain TIDAK menggagalkan pendaftaran lokasi.
             * Database lokasi sudah dibuat dan dimigrasi saat titik ini, dan
             * membatalkan semuanya demi satu host yang berformat aneh akan
             * meninggalkan DELETE yang harus dibersihkan terpisah. Baris yang
             * ditolak tidak disimpan; kolom legacy tetap menyimpan input
             * mentahnya supaya tidak hilang dari catatan.
             */
            $this->syncLegacyDomain($tenant, [
                'domain' => $data['domain'] ?? null,
                'domain_alternatif' => $data['domain_alternatif'] ?? null,
            ]);

            if (! empty($data['admin_email']) && ! empty($data['admin_password'])) {
                $this->createLocationAdmin($tenant, $data);
            }

            /*
             * Empat akun demo, satu per role, dibuat untuk SETIAP lokasi baru.
             *
             * Sengaja TIDAK bergantung pada `admin_email`/`admin_password` di
             * atas. Keduanya menjawab pertanyaan yang berbeda:
             *
             *   - akun demo -> "user mana yang bisa langsung dicoba?"
             *   - admin    -> "akun siapa yang sebenarnya memegang lokasi ini?"
             *
             * Lokasi yang dibuat tanpa admin tetap harus bisa langsung dicoba.
             * Itu keadaan yang paling sering terjadi: superadmin ingin
             * menyiapkan database dulu, dan kredensial menyusul lewat
             * `tenancy:make-user`.
             *
             * Kegagalan di sini TIDAK boleh menggagalkan pendaftaran. Akun demo
             * bukan bagian dari lokasi yang bisa dipakai -- lokasi tetap utuh
             * tanpanya. Sebaliknya, membiarkan kegagalan ini mematikan
             * provisioning membuat satu akun contoh yang jadi alasan seluruh
             * database lokasi dibuang, dan gejalanya ("Lokasi gagal
             * disiapkan") tidak pernah menyinggung bahwa yang hilang cuma akun
             * contoh.
             */
            $akunDemo = 0;

            try {
                $akunDemo = $this->manager->seedDemoUsers($tenant);
            } catch (Throwable $e) {
                report($e);

                $akunDemo = 0;
            }

            /*
             * Identitas lokasi ditulis untuk SETIAP lokasi baru, bukan hanya
             * yang kebetulan mengirim kredensial admin.
             *
             * Ini sebelumnya menempel di dalam `createLocationAdmin()`, jadi
             * hanya jalan kalau `admin_email` DAN `admin_password` ikut dikirim.
             * Lokasi yang dibuat tanpa admin -- pola yang wajar saat superadmin
             * hanya ingin menyiapkan database lebih dulu -- tidak pernah punya
             * identitas sendiri, dan semua kop laporannya berbunyi nilai bawaan
             * `SettingsSeeder` ("ASTALOKA Tirto Mulo") sampai ada yang ingat
             * menggantinya lewat menu pengaturan.
             *
             * Pesan errornya pun menyesatkan: provisioning dianggap gagal
             * padahal yang sebenarnya gagal cuma kwarta yang tidak dikirim.
             */
            $this->manager->applyTenantIdentity($tenant);
        } catch (Throwable $e) {
            /*
             * Jangan tinggalkan tenant yang menunjuk database tidak ada, dan
             * jangan sisakan database setengah jadi. Keduanya lebih buruk
             * daripada lokasi yang gagal dibuat: tenant yang masih
             * terdaftar tapi database-nya hilang tidak terlihat di panel
             * sebagai masalah, hanya sebagai lokasi yang tidak bisa dibuka.
             *
             * `dropDatabase()` SENGAJA DI LUAR `if ($tenant)`.
             *
             * Versi sebelumnya membungkusnya di dalam, dengan asumsi
             * database tidak mungkin ada kalau tenant belum terbentuk. Asumsi
             * itu salah, dan urutannya membuatnya salah: `createDatabase()`
             * sudah selesai -- database benar-benar sudah berdiri -- baru
             * `Tenant::create()` yang bisa gagal. Koneksi ke database pusat
             * terputus, kolom yang ditambahkan migrasi belum ada, unique index
             * bentrok. Semuanya terjadi SESUDAH ada database, dan semuanya
             * meninggalkan `$tenant` bernilai `null`.
             *
             * Di keadaan itu blok lama melakukan tidak apa-apa: tidak ada
             * tenant untuk dihapus, jadi `dropDatabase()` tidak pernah
             * dipanggil, dan `astaloka_<slug>` tertinggal sebagai database
             * yatim -- tidak terlihat di panel pusat, tidak tercatat di
             * registry, dan tidak pernah dibersihkan tanpa intervention
             * manual. Setiap lokasi yang gagal di titik ini menambah 100MB+
             * yang tidak pernah terpakai.
             *
             * `$dbName` sudah dihitung SEBELUM `try`, jadi nilainya selalu
             * ada di sini. `dropDatabase()` aman dipanggil untuk database yang
             * belum pernah dibuat: ia mengembalikan `false` kalau memang tidak
             * ada, dan melempar `RuntimeException` kalau nama tidak valid atau
             * menunjuk database pusat -- dua kondisi yang di titik ini sudah
             * mustahil, dan lebih baik menggagalkan daripada diam-diam
             * melaporkan cleanup yang gagal.
             *
             * Diperbaiki dengan memindahkan `dropDatabase()` keluar dari
             * percabangan `$tenant`.
             */
            $dbDibuang = false;

            if ($tenant) {
                $tenant->delete();
            }

            // Hapus database TANPA PAUS, apa pun yang gagal sebelumnya.
            // Pengecekan ulang dipakai karena `createDatabase` bisa saja
            // gagal sebelum CREATE DATABASE sempat jalan.
            if ($this->manager->dropDatabase($dbName)) {
                $dbDibuang = true;
            }

            // Dicatat meski gagalnya provisioning. Ini justru momen paling
            // penting untuk diaudit: admin mencoba membuat lokasi, prosesnya
            // pecah di tengah, dan sekarang perlu tahu DB-nya sudah dibuang
            // supaya tidak ada setengah-jadi yang menggantung.
            //
            // `$tenant` sudah dihapus di blok di atas, tapi objeknya masih
            // ada di memori -- cukup untuk mengisi kolom audit tanpa
            // membacanya lagi dari database yang sudah tidak punya barisnya.
            $this->audit->record(
                'tenant.provision_failed',
                tenant: $tenant,
                subjectType: Tenant::class,
                subjectId: $tenant?->id,
                description: "Gagal menyiapkan lokasi `{$data['slug']}`: {$e->getMessage()}",
                properties: [
                    'slug' => $data['slug'],
                    'nama' => $data['nama'],
                    'database' => $dbName,
                    'error' => $e->getMessage(),
                    'database_dibuang' => $dbDibuang,
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyiapkan lokasi: '.$e->getMessage(),
            ], 500);
        }

        $this->audit->record(
            'tenant.create',
            tenant: $tenant,
            subjectType: Tenant::class,
            subjectId: $tenant->id,
            description: "Lokasi `{$tenant->slug}` ({$tenant->nama}) berhasil dibuat di database `{$dbName}`.",
            properties: [
                'slug' => $tenant->slug,
                'nama' => $tenant->nama,
                'nama_lembaga' => $tenant->nama_lembaga,
                'sektor' => $tenant->sektor,
                'bidang_usaha' => $tenant->bidang_usaha,
                'database' => $dbName,
                'domain' => $tenant->domain,
                'admin_email' => $data['admin_email'] ?? null,
                'akun_demo' => $akunDemo,
                'tgl_berakhir' => $tenant->tgl_berakhir?->toDateString(),
            ],
        );

        /*
         * Kredensial akun demo ikut dikembalikan, satu kali, di respons ini.
         *
         * Formulir ini menampilkan email dan password-nya supaya superadmin
         * bisa langsung masuk ke lokasi yang baru dibuat -- daripada membuka
         * daftar akun untuk mencari tahu email mana yang sebenarnya bisa
         * dipakai (`config('tenancy.demo_users')`).
         *
         * Yang membuatnya aman untuk ditampilkan adalah passwordnya sudah
         * tertulis di source code dan di README. Tidak ada rahasia yang
         * dibocorkan: kalau `TENANCY_DEMO_USERS=false`, `demo_users` bernilai
         * kosong dan tidak ada yang dikirim sama sekali.
         *
         * Tidak pernah dikirim dari `show()`/`index()` -- hanya dari `store()`,
         * jadi tidak ada halaman yang memuat kata sandi setiap kali dibuka.
         *
         * `akun_demo` ditambahkan ke respons yang sudah jadi, bukan lewat
         * `array_merge($this->present(...), [...])`. Hasilnya sama persis,
         * tapi bentuk ini tidak bergantung pada urutan evaluasi argumen
         * `array_merge`, jadi tidak perlu dipikirkan saat funcinya nanti
         * ditambah.
         *
         * Barisnya dibaca ULANG dari koneksi `central`, bukan lewat
         * `$tenant->fresh()`.
         *
         * Alasannya `applyTenantIdentity()` dan `seedDemoUsers()` di atas
         * memanggil `TenantManager::activate()`, yang menunjuk
         * `database.default` ke database LOKASI. `fresh()` memakai koneksi
         * default itu -- jadi ia mencari baris `tenants` di database lokasi,
         * tempat tabel itu tidak ada, dan keluar sebagai NULL. Gejalanya
         * `present(): Argument #1 must be of type Tenant, null given`, yang
         * sama sekali tidak menyuruhkaan penyebabnya.
         *
         * `Tenant` mengunci `$connection = 'central'`, jadi `find()` di sini
         * tetap ke database pusat meski default sudah berubah.
         */
        $tersimpan = Tenant::query()->find($tenant->id) ?? $tenant;

        $data = $this->present($tersimpan, detail: true);

        $data['akun_demo'] = $this->demoUserCredentials($akunDemo);

        return response()->json([
            'success' => true,
            'message' => 'Lokasi berhasil dibuat.',
            'data' => $data,
        ], 201);
    }

    /** Ubah data lokasi (tanpa menyentuh database-nya). */
    public function update(Request $request, Tenant $location): JsonResponse
    {
        $data = $request->validate([
            'nama' => ['sometimes', 'required', 'string', 'max:150'],
            'nama_lembaga' => ['nullable', 'string', 'max:191'],
            'sektor' => ['nullable', 'string', 'max:60', 'in:'.implode(',', Tenant::SEKTOR)],
            'bidang_usaha' => ['nullable', 'string', 'max:120'],
            'deskripsi_usaha' => ['nullable', 'string', 'max:500'],
            'judul_login' => ['nullable', 'string', 'max:80'],
            'sub_judul_login' => ['nullable', 'string', 'max:80'],
            'deskripsi_login' => ['nullable', 'string', 'max:255'],
            'warna_utama' => ['nullable', 'string', 'regex:'.Branding::POLA_WARNA],
            'domain' => ['nullable', 'string', 'max:191'],
            'domain_alternatif' => ['nullable', 'string', 'max:191'],
            'alamat' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:191'],
            'telepon' => ['nullable', 'string', 'max:30'],
            'tgl_berakhir' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        /*
         | Kolom domain / domain_alternatif di form lama dan tabel
         | tenant_domains menyimpan hal yang sama: host yang dimiliki lokasi ini.
         | Keduanya harus bergerak bersama, kalau tidak panel menampilkan dua
         | daftar yang saling bertentangan.
         |
         | Yang dilakukan di sini BUKAN mengganti daftar lama dengan yang baru,
         | tapi menambahkan atau memperbarui baris yang di-set sebagai domain persis.
         | Baris wildcard yang sudah ada tidak pernah tersentuh -- kalau ikut
         | ditulis dari sini, wildcard yang sengaja dibuat di halaman Domain
         | akan hilang.
         |
         | Normalisasi dan validasi memakai DomainNormalizer yang sama, supaya
         | aturannya identik dengan halaman Domain. Kalau tidak, field yang sama
         | bisa diterima di satu halaman dan ditolak di halaman lain -- dan yang
         | ditolak biasanya justru yang paling penting: niat admin.
         */
        $domainErrors = $this->syncLegacyDomain($location, $data);

        if ($domainErrors !== []) {
            return response()->json([
                'success' => false,
                'message' => reset($domainErrors),
                'errors' => $domainErrors,
            ], 422);
        }

        // Disalin sebelum update. Setelah `$location->update()`, atribut di
        // model sudah berisi nilai baru, jadi diff() di afterwards tidak
        // akan menemukan apa pun yang berubah.
        $sebelum = $location->only(array_keys($data));

        /*
         * Kolom legacy ditulis dalam bentuk bersih, bukan mentah seperti
         * yang dikirim form.
         *
         * `TenantResolver::fromHost()` membandingkan `tenants.domain` dengan
         * host polos dari request, tanpa normalisasi. Input yang disimpan
         * mentah tidak akan pernah cocok -- `https://demo.astaloka.test`
         * tersimpan lengkap dengan skemanya, dan tidak ada request yang punya
         * host `https://demo.astaloka.test`. Gejalanya bukan error, melainkan
         * domain terlihat tersimpan di panel tapi request-nya tetap mendarat
         * di halaman depan.
         *
         * Normalisasi hanya menyentuh key yang memang dikirim. `??`
         * tidak bisa dipakai di sini: `null` adalah nilai yang bermakna
         * pada form ini -- mengosongkan kolom harus melepas domain, bukan
         * berarti "tidak diubah" -- jadi operator itu akan salah membaca
         * permintaan untuk mengosongkan sebagai permintaan untuk tidak
         * melakukan apa pun.
         *
         * Normalisasi hanya menyentuh key yang memang dikirim, dan
         * dijalankan SETELAH `syncLegacyDomain()` tanpa menyentuhnya: baris
         * di `tenant_domains` sudah dibangun dari host bersih oleh fungsi
         * itu, jadi keduanya tidak mungkin berbeda.
         *
         * Diff tetap diambil dari `$data` yang sudah dinormalkan, supaya
         * yang tercatat di audit sama dengan yang benar-benar ditulis.
         */
        if (array_key_exists('domain', $data)) {
            $data['domain'] = $this->bersihkanLegacyDomain($data['domain']);
        }

        if (array_key_exists('domain_alternatif', $data)) {
            $data['domain_alternatif'] = $this->bersihkanLegacyDomain($data['domain_alternatif']);
        }

        /*
         | Warna ditulis lowercase.
         |
         | Rule `regex` sudah menolak bentuk yang salah, jadi yang tersisa
         | hanya bentuk yang sah: `#ABCDEF`. Huruf besar tidak-invalid, tapi
         | tetap dinormalkan supaya diff di audit tidak mencatat perubahan
         | pada saat admin hanya mengetik ulang warna yang sama dengan huruf
         | besar berbeda.
         *
         | Sengaja TIDAK ikut	dikosongkan di sini kalau tidak dikirim:
         | seperti `domain` di atas, key yang absen bukan permintaan untuk
         | mengosongkan. Endpoint yang hanya mengirim sebagian kolom tidak
         | boleh diam-diam menghapus branding yang sudah diatur.
         */
        if (array_key_exists('warna_utama', $data)) {
            $data['warna_utama'] = $this->normalisasiWarna($data['warna_utama']);
        }

        $location->update($data);

        $perubahan = AuditLogger::diff($sebelum, $location->only(array_keys($data)));

        // Hanya catat kalau ada yang benar-benar berubah. Submit form tanpa
        // mengubah apa pun tetap menghasilkan request 200, dan log seperti
        // itu hanya menambah noise saat dicari.
        if ($perubahan !== []) {
            $this->audit->record(
                'tenant.update',
                tenant: $location,
                subjectType: Tenant::class,
                subjectId: $location->id,
                description: "Data lokasi `{$location->slug}` diperbarui ("
                    .implode(', ', array_keys($perubahan)).').',
                properties: ['changes' => $perubahan],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Data lokasi diperbarui.',
            'data' => $this->present($location->fresh(), detail: true),
        ]);
    }

    /**
     * Nonaktifkan lokasi (soft). Data tidak dihapus.
     *
     * Menghapus `is_active` membuat lokasi berhenti di-resolve, tapi database
     * dan datanya tetap utuh -- saklar yang bisa dinyalakan lagi. Penghapusan
     * fisik harus lewat proses terpisah yang eksplisit, yaitu
     * {@see forceDestroy()}.
     *
     * INI BUKAN ENDPOINT YANG BISA MENGAKTIFKAN KEMBALI.
     *
     * `DELETE` secara semantik berarti "hapus", dan endpoint ini sudah
     * dilunakkan menjadi "matikan". Mengaktifkannya kembali lewat
     * endpoint yang sama akan berarti DELETE bisa mengembalikan lokasi --
     * bertentangan dengan apa yang dimaksudkan DELETE, dan berbahaya: satu
     * skrip yang salah bisa membangunkan lokasi yang sengaja dimatikan.
     *
     * Aktivasi karena itu punya endpoint-nya sendiri: `PATCH
     * /central/locations/{location}/activate`.
     */
    public function destroy(Tenant $location): JsonResponse
    {
        $sudahNonaktif = ! $location->is_active;

        $location->update(['is_active' => false]);

        // Idempoten secara sengaja: DELETE kedua kali pada lokasi yang
        // sudah nonaktif tidak menghasilkan log baru. Menonaktifkan
        // lokasi yang memang sudah mati bukan kejadian.
        if (! $sudahNonaktif) {
            $this->audit->record(
                'tenant.deactivate',
                tenant: $location,
                subjectType: Tenant::class,
                subjectId: $location->id,
                description: "Lokasi `{$location->slug}` dinonaktifkan. Data dan database tidak dihapus.",
                properties: [
                    'slug' => $location->slug,
                    'nama' => $location->nama,
                    'database' => $location->database,
                ],
            );
        }

        return response()->json([
            'success' => true,
            'message' => "Lokasi `{$location->slug}` dinonaktifkan. Datanya tidak dihapus.",
        ]);
    }

    /**
     * Aktifkan kembali lokasi yang sebelumnya dinonaktifkan.
     *
     * Endpoint terpisah dari `destroy()` (lihat catatan di sana): DELETE
     * berarti "matikan", dan menghidupkan kembali lokasi lewat DELETE yang
     * sama akan membuat satu request bisa berarti dua hal yang bertentangan.
     *
     * Yang diperiksa di sini bukan hanya kolom `is_active`, tapi KONDISI
     * sebenarnya dari lokasi -- karena "aktif" yang tidak bisa dilayani
     * lebih buruk dari "tidak aktif" yang jujur:
     *
     *   1. Database-nya harus ada. Lokasi tanpa database tidak bisa dilayani
     *      oleh `TenantManager`, dan mengaktifkannya hanya memindahkan
     *      kegagalan dari "tidak aktif" ke "aktif tapi error 500".
     *   2. Domainnya harus ada. Tanpa domain, tidak ada satu pun request
     *      yang bisa sampai ke lokasi ini -- jadi status "aktif" hanya akan
     *      tampil di panel tanpa efek apa pun di aplikasi.
     *
     * Keduanya ditolak dengan 422 dan pesan yang menyebut apa yang kurang,
     * supaya admin bisa memperbaikinya tanpa perlu menebak.
     */
    public function activate(Tenant $location): JsonResponse
    {
        // Idempoten: mengaktifkan lokasi yang sudah aktif bukan kejadian,
        // jadi tidak menghasilkan log baru -- sama seperti `destroy()`.
        if ($location->is_active) {
            return response()->json([
                'success' => true,
                'message' => "Lokasi `{$location->slug}` sudah aktif.",
                'data' => $this->present($location->fresh()),
            ]);
        }

        if (! $location->databaseExists()) {
            return response()->json([
                'success' => false,
                'message' => "Database `{$location->database}` untuk lokasi `{$location->slug}` tidak ditemukan, jadi lokasi tidak bisa diaktifkan. "
                    .'Buat ulang database-nya lewat `php artisan tenancy:make '.$location->slug.' --force`, lalu aktifkan lagi.',
            ], 422);
        }

        if (! $this->punyaDomain($location)) {
            return response()->json([
                'success' => false,
                'message' => "Lokasi `{$location->slug}` belum punya domain, jadi tidak ada yang bisa mengaksesnya. "
                    .'Isi domainnya dulu di halaman Ubah Tenant, lalu aktifkan lagi.',
            ], 422);
        }

        $location->update(['is_active' => true]);

        $this->audit->record(
            'tenant.activate',
            tenant: $location,
            subjectType: Tenant::class,
            subjectId: $location->id,
            description: "Lokasi `{$location->slug}` diaktifkan kembali.",
            properties: [
                'slug' => $location->slug,
                'nama' => $location->nama,
                'database' => $location->database,
            ],
        );

        return response()->json([
            'success' => true,
            'message' => "Lokasi `{$location->slug}` diaktifkan kembali.",
            'data' => $this->present($location->fresh()),
        ]);
    }

    /**
     * Hapus lokasi PERMANEN, beserta database dan seluruh datanya.
     *
     * Bedanya dengan `destroy()`: `destroy()` mematikan lokasi tanpa
     * menyentuh database -- saklar yang bisa dinyalakan lagi. Yang di sini
     * menghapus semuanya, dan TIDAK bisa dibatalkan.
     *
     * Karena itu ada dua lapis pengaman, keduanya wajib:
     *
     *   1. Wajib menyebut slug lokasi pada body request. Tiap admin punya
     *      session sendiri, jadi tidak ada "aksen tidak sengaja" -- yang
     *      dilindungi di sini adalah salah klik, dan mengetik slug membuat
     *      salah klik harus terasa dua kali.
     *   2. Endpoint ini membatalkan semuanya kalau nama databasenya tidak
     *      valid atau menunjuk database pusat. `dropDatabase()` melempar
     *      exception untuk kedua hal itu, jadi tidak ada jalan untuk
     *      menghapus database yang salah dari sini.
     *
     * Kalau database lokasinya memang sudah tidak ada (terhapus manual,
     * atau provisioning yang gagal di tengah), `dropDatabase()` mengembalikan
     * false dan baris registry-nya TETAP dihapus. Itu yang diinginkan:
     * baris yang menunjuk database yang sudah tidak ada adalah sampah, dan
     * membiarkannya hanya menambah lokasi mati yang tampil di panel.
     *
     * Urutan operasinya penting: database lokasi dihapus DULU, baru baris
     * registry-nya. Kalau urutannya dibalik dan prosesnya mati di tengah,
     * yang tertinggal adalah tenant tanpa database -- keadaan yang paling
     * sulit didiagnosis, karena barisnya masih ada di panel tapi tidak ada
     * satu pun request yang bisa dilayaninya.
     *
     * Foreign key di database pusat (`tenant_domains`, `tenant_invoices`)
     * memakai `ON DELETE CASCADE`, jadi baris-baris ikutannya ikut terhapus
     * tanpa perlu dihapus manual satu per satu.
     */
    public function forceDestroy(Request $request, Tenant $location): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string'],
        ]);

        /*
         * Konfirmasi eksplisit: slug yang diketik harus sama persis dengan
         * slug lokasi.
         *
         * `hash_equals` dipakai, bukan `===`, supaya waktu yang dibutuhkan
         * perbandingan tidak bergantung pada posisi karakter yang cocok.
         * Untuk slug yang bukan rahasia, bedanya praktis tidak terasa --
         * tapi konsistensinya dengan pola "bandingkan input user dengan nilai
         * tersimpan" di tempat lain lebih mudah diingat.
         */
        if (! hash_equals($location->slug, $data['slug'])) {
            return response()->json([
                'success' => false,
                'message' => "Slug yang diketik tidak cocok dengan lokasi ini. Lokasi ini ber-slug `{$location->slug}`.",
            ], 422);
        }

        $namaDb = $location->database;
        $slug = $location->slug;

        // Rekam dulu, baru hapus. `audit_logs` tidak punya foreign key ke
        // `tenants`, jadi barisnya tetap ada walau tenant-nya hilang -- dan
        // itulah gunanya: jejak penghapusan harus bertahan setelah objeknya
        // tidak ada lagi.
        $this->audit->record(
            'tenant.force_delete',
            tenant: $location,
            subjectType: Tenant::class,
            subjectId: $location->id,
            description: "Lokasi `{$slug}` dihapus permanen beserta database `{$namaDb}` dan seluruh datanya.",
            properties: [
                'slug' => $slug,
                'nama' => $location->nama,
                'database' => $namaDb,
                'domain' => $location->domain,
            ],
        );

        /*
         * Database lokasi dihapus lebih dulu.
         *
         * Sebaliknya dari yang biasa dilakukan pada DELETE biasa, dan itu
         * disengaja: kalau baris registry lebih dulu, lalu prosesnya mati
         * sebelum `DROP DATABASE` selesai, yang tertinggal adalah tenant
         * tanpa database -- barisnya masih tampil di panel pusat, tapi tidak
         * ada satu pun request yang bisa dilayaninya, dan tidak ada tombol
         * yang bisa membersihkannya karena registry-nya sudah hilang.
         *
         * `dropDatabase()` melempar exception kalau nama tidak valid atau
         * menunjuk database pusat, jadi tidak ada jalan untuk menghapus
         * database yang salah dari sini.
         */
        $databaseDibuang = false;

        try {
            $databaseDibuang = $this->manager->dropDatabase($namaDb);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => "Database `{$namaDb}` gagal dihapus: ".$e->getMessage()
                    .' Lokasi tidak dihapus dari daftar -- perbaiki dulu masalahnya, lalu coba lagi.',
            ], 500);
        }

        $location->delete();

        return response()->json([
            'success' => true,
            'message' => "Lokasi `{$slug}` dihapus permanen"
                .($databaseDibuang ? ' beserta database dan seluruh datanya.' : '.'),
            'data' => [
                'slug' => $slug,
                'database' => $namaDb,
                'database_dibuang' => $databaseDibuang,
            ],
        ]);
    }

    /**
     * Apakah lokasi ini punya domain yang bisa dilayani.
     *
     * Dua sumber, dan keduanya dibaca: baris di `tenant_domains` (daftar
     * domain custom) dan kolom legacy `tenants.domain` /
     * `tenants.domain_alternatif`.
     *
     * Yang penting: yang dihitung hanya domain yang AKTIF. Domain yang
     * sengaja dimatikan di halaman Domain tidak dihitung sebagai punya
     * domain -- kalau dihitung, lokasi bisa diaktifkan padahal tidak ada
     * satu pun host yang menunjuk ke situ.
     */
    private function punyaDomain(Tenant $location): bool
    {
        $adaDiDaftar = $location->domains()->where('is_active', true)->exists();

        if ($adaDiDaftar) {
            return true;
        }

        return (bool) ($location->domain ?: $location->domain_alternatif);
    }

    /**
     * Normalisasi input domain dari form sebelum disimpan ke kolom legacy.
     *
     * Tidak menolak apa pun -- hanya membuang skema, path, query, port,
     * huruf besar, dan titik di ujung. Disederhanakan oleh
     * `DomainNormalizer::normalizeLegacy()`, yang persis dipakai migrasi
     * backfill, jadi tiga jalur yang menulis kolom ini -- form pendaftaran,
     * form edit, dan backfill -- menghasilkan bentuk yang sama.
     *
     * Sengaja memakai `normalizeLegacy()` dan bukan `clean()`: kolom legacy
     * bisa berisi data lama yang formatnya sudah rusak, dan menolak
     * penyimpanan berarti admin kehilangan host yang sedang terpakai.
     * Yang tidak bisa jadi host (skema, path, port) tetap dibuang.
     */
    private function bersihkanLegacyDomain(mixed $raw): ?string
    {
        return DomainNormalizer::normalizeLegacy($raw);
    }

    /**
     * Bentuk final warna hex yang disimpan.
     *
     * String kosong dan spasi saja jadi `null`, bukan `''`: kolomnya nullable,
     * dan `null` inilah yang berarti "pakai warna bawaan". `''` akan lolos
     * validasi `nullable` (karena Laravel memperlakukannya sebagai kosong)
     * tapi menyimpan string kosong -- yang oleh `Branding::warna()` tetap
     * jatuh ke bawaan, jadi hasilnya sama; masalahnya ada di diff audit, di
     * mana `''` dan `null` tercatat sebagai dua nilai berbeda untuk hal
     * yang sama.
     *
     * Rule `regex` sudah dijalankan sebelum fungsi ini dipanggil, jadi nilai
     * yang tidak `null` di sini sudah pasti `#rrggbb`.
     */
    private function normalisasiWarna(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $hex = trim($raw);

        return $hex === '' ? null : strtolower($hex);
    }

    /**
     * Samakan kolom legacy dengan tabel `tenant_domains`.
     *
     * Dipanggil dari update() SEBELUM `$location->update()`, dan nilai
     * kembalinya diperiksa lebih dulu. Kalau baris domain sudah terlanjur ditulis
     * lalu ternyata inputnya tidak valid, lokasi sudah dalam keadaan setengah
     * jadi: domain baru ada di `tenant_domains`, kolom lamanya masih kosong,
     * dan tidak ada yang melapor.
     *
     * Field yang tidak dikirim TIDAK disentuh. Form lama mengirim keduanya
     * setiap kali, tapi endpoint lain mungkin hanya mengirim salah satu -- dan
     * field yang absen bukan permintaan untuk mengosongkan.
     *
     * @return array<string, string> [nama kolom => pesan error]
     */
    private function syncLegacyDomain(Tenant $location, array $data): array
    {
        $errors = [];

        // Kolom mana yang perannya domain utama. `domain` = utama,
        // `domain_alternatif` = cadangan.
        $slots = [
            'domain' => true,
            'domain_alternatif' => false,
        ];

        foreach ($slots as $kolom => $isPrimary) {
            if (! array_key_exists($kolom, $data)) {
                continue;
            }

            $raw = $data[$kolom];
            $raw = is_string($raw) ? trim($raw) : '';

            // Mengosongkan form berarti "pakai halaman Domain saja". Baris
            // domain persis yang lama dilepas supaya tidak hidup dua kali --
            // dan supaya tidak memblokir index unik ketika host diganti.
            if ($raw === '') {
                $this->detachLegacyDomain($location, $isPrimary);

                continue;
            }

            [$host, $problem] = DomainNormalizer::clean($raw);

            if ($problem !== null) {
                $errors[$kolom] = $problem;

                continue;
            }

            [$boleh, $tabrak] = DomainNormalizer::ensureAvailable($host, false, $location->id);

            if (! $boleh) {
                $errors[$kolom] = $tabrak;

                continue;
            }

            // Host sama persis dengan yang sudah dimiliki lokasi ini: ini
            // bukan domain baru, hanya perubahan peran -- naik atau turun jadi
            // utama atau cadangan.
            $sama = $location->domains()
                ->where('host', $host)
                ->where('is_wildcard', false)
                ->first();

            if ($sama) {
                $sama->update(['is_active' => true, 'is_primary' => $isPrimary]);

                if ($isPrimary) {
                    $this->clearPrimaryExcept($location->id, $sama->id);
                }

                continue;
            }

            /*
             * Host BERUBAH, jadi baris lama milik lokasi ini masih memegang
             * host yang sekarang. Baris itu harus dilepas dulu:
             * `tenant_domains_host_unique` menolak dua baris dengan host yang
             * sama, dan meskipun keduanya milik satu lokasi yang sama, MySQL
             * tetap memperlakukannya sebagai duplikat.
             */
            $this->detachLegacyDomain($location, $isPrimary);

            $baru = TenantDomain::create([
                'tenant_id' => $location->id,
                'host' => $host,
                'is_wildcard' => false,
                'is_primary' => $isPrimary,
                'is_active' => true,
            ]);

            /*
             * `clearPrimaryExcept()` tanpa `$keepId` akan ikut mematikan baris yang
             * baru saja dibuat -- karena saat dipanggil, baris itu sudah punya
             * `is_primary = true`. Gejalanya tidak kelihatan di daftar domain
             * (barisnya ada, hanya tidak ditandai utama), tapi `syncPrimary()`
             * lalu jatuh ke domain lain, dan `tenants.domain` menunjuk ke host
             * yang tidak lagi jadi milik lokasi ini.
             *
             * Baris yang baru dibuat harus jadi PENGECUALIAN, bukan ikut
             * dibersihkan.
             */
            if ($isPrimary) {
                $this->clearPrimaryExcept($location->id, $baru->id);
            }
        }

        return $errors;
    }

    /**
     * Lepaskan baris domain persis milik satu slot legacy.
     *
     * Baris wildcard TIDAK ikut tersentuh. Wildcard dibuat lewat halaman
     * Domain, dan form lama tidak punya field untuk itu -- jadi ikut ditulis
     * di sini akan membuat wildcard terhapus setiap kali admin menyimpan form.
     *
     * Untuk slot cadangan, `is_primary` dipakai hanya sebagai urutan pilihan:
     * mana yang dibuang lebih dulu kalau lokasi punya beberapa domain persis
     * tanpa domain utama.
     */
    private function detachLegacyDomain(Tenant $location, bool $isPrimary): void
    {
        $location->domains()
            ->where('is_wildcard', false)
            ->when(
                $isPrimary,
                fn ($q) => $q->where('is_primary', true),
                fn ($q) => $q->orderByDesc('is_primary'),
            )
            ->delete();
    }

    /**
     * Pastikan hanya satu domain utama per lokasi.
     *
     * Tanpa ini, dua admin yang menekan tombol hampir bersamaan bisa menyimpan
     * dua `is_primary = true`. Efeknya bukan sekadar baris ganda di daftar --
     * `settings.domain` di database lokasi jadi tidak pasti menunjuk ke mana,
     * sehingga kop laporan bisa mencantumkan domain yang tidak dipakai lagi.
     */
    private function clearPrimaryExcept(int $tenantId, ?int $keepId = null): void
    {
        TenantDomain::query()
            ->where('tenant_id', $tenantId)
            ->where('is_primary', true)
            ->when($keepId !== null, fn ($q) => $q->where('id', '!=', $keepId))
            ->update(['is_primary' => false]);
    }

    /**
     * Buat akun admin pertama di database lokasi.
     *
     * Memakai INSERT langsung, bukan `User::create`, karena tabel `users`
     * di lokasi punya kolom yang tidak ada di database pusat
     * (`jabatan_id`, dengan FK ke `jabatans`). Kolom itu sengaja
     * dibiarkan null: admin belum ditugaskan ke jabatan apa pun, dan
     * form user akan menagih kelengkapan itu nanti.
     *
     * Identitas lokasi (`settings.nama_lembaga` dan friends) TIDAK ditulis
     * di sini. Pemanggilnya yang menjalankannya lewat
     * `applyTenantIdentity()`, supaya setiap lokasi baru -- dengan atau
     * tanpa admin -- mendapat identitasnya. Lihat catatan di `store()`.
     */
    private function createLocationAdmin(Tenant $tenant, array $data): void
    {
        $this->manager->runFor($tenant, function () use ($data) {
            DB::connection(TenantManager::CONNECTION)->table('users')->insert([
                'name' => $data['admin_name'] ?? 'Admin Lokasi',
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'role' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Daftar akun demo yang dibuat untuk lokasi baru.
     *
     * Mengembalikan array KOSONG -- bukan daftar lengkap dengan `created: 0` --
     * kalau tidak ada yang dibuat. Dua keadaan itu dibedakan karena keduanya
     * berarti hal yang berbeda bagi superadmin:
     *
     *   - `[]`    -> sakelar `TENANCY_DEMO_USERS` mati, atau akunnya sudah
     *                ada dari provisioning sebelumnya. Tidak perlu ditampilkan.
     *   - isi     -> empat akun baru, dan ini yang perlu disalin ke WhatsApp
     *                pemilik lokasi.
     *
     * Password ikut dikirim karena nilainya sudah tertulis di source code.
     * Yang dikirim tetap BUKAN hash -- ini daftar yang dibaca dari config,
     * persis seperti yang tertulis di README.
     *
     * @return array<int, array{role: string, email: string, password: string}>
     */
    private function demoUserCredentials(int $dibuat): array
    {
        if ($dibuat <= 0 || ! config('tenancy.demo_users.enabled', true)) {
            return [];
        }

        $password = (string) config('tenancy.demo_users.password', 'password');

        $akun = [];

        foreach ((array) config('tenancy.demo_users.emails', []) as $role => $email) {
            $email = trim((string) $email);

            if ($email === '') {
                continue;
            }

            $akun[] = [
                'role' => (string) $role,
                'email' => $email,
                'password' => $password,
            ];
        }

        return $akun;
    }

    private function present(Tenant $t, bool $detail = false): array
    {
        $data = [
            'id' => $t->id,
            'slug' => $t->slug,
            'nama' => $t->nama,
            'label' => $t->label(),
            'nama_lembaga' => $t->nama_lembaga,
            'sektor' => $t->sektor,
            'bidang_usaha' => $t->bidang_usaha,
            'deskripsi_usaha' => $t->deskripsi_usaha,

            /*
             * Branding halaman login, apa adanya -- bukan yang sudah turun ke
             * bawaan.
             *
             * `Branding::raw()` sengaja dipakai, bukan `Branding::for()`:
             * panel pusat harus menampilkan keadaan sebenarnya. Kalau kolomnya
             * kosong dan panel menampilkan teks bawaan, orang akan mengira
             * isian itu sudah sengaja diisi dan tidak akan pernah menyentuhnya
             * -- padahal yang sebenarnya terjadi, halamannya memakai bawaan.
             *
             * Warna dikecualikan: `raw()` tetap menurunkan ke bawaan, karena
             * panel pusat memakai nilainya untuk menggambar contoh warna dan
             * contoh itu tidak bisa menggambar "tidak ada warna".
             */
            'branding' => array_merge(Branding::raw($t), [
                'default_judul' => Branding::defaultJudul(),
                'default_sub_judul' => Branding::defaultSubJudul(),
                'default_deskripsi' => Branding::defaultDeskripsi(),
                'default_warna' => Branding::defaultWarna(),
            ]),
            // Nama usaha yang paling spesifik. Dihitung di backend supaya semua
            // layar menampilkan nama yang sama untuk tenant yang sama -- kalau
            // kalau tiap layar punya aturan fallback sendiri, perubahan urutan
            // aturan di salah satunya akan terlihat sebagai perubahan data.
            'usaha_label' => $t->usahaLabel(),
            'database' => $t->database,
            'domain' => $t->domain,
            'domain_alternatif' => $t->domain_alternatif,
            'alamat' => $t->alamat,
            'email' => $t->email,
            'telepon' => $t->telepon,
            'is_active' => $t->is_active,
            'tgl_berakhir' => $t->tgl_berakhir?->toDateString(),
            'database_ada' => $t->databaseExists(),
        ];

        // `tagihan_belum_lunas` hanya ada kalau index() dipanggil (memakai
        // withCount). Di show() atribut ini tidak di-set, jadi dibiarkan null
        // daripada nilai 0 yang menyesatkan -- 0 berarti "tidak ada
        // tagihan", sedangkan null berarti "tidak dihitung".
        if (array_key_exists('tagihan_belum_lunas', $t->getAttributes())) {
            $data['tagihan_belum_lunas'] = (int) $t->getAttribute('tagihan_belum_lunas');
        }

        if (array_key_exists('total_tagihan', $t->getAttributes())) {
            $data['total_tagihan'] = (float) ($t->getAttribute('total_tagihan') ?? 0);
        }

        if ($detail && $t->relationLoaded('invoices')) {
            $data['invoices'] = $t->invoices->map(fn (TenantInvoice $i) => [
                'id' => $i->id,
                'nomor' => $i->nomor,
                'jumlah' => (float) $i->jumlah,
                'status' => $i->status,
                'tgl_invoice' => $i->tgl_invoice?->toDateString(),
                'tgl_jatuh_tempo' => $i->tgl_jatuh_tempo?->toDateString(),
                'deadline' => $i->deadline()->toDateString(),
                'terlambat' => $i->isOverdue(),
                'sisa_hari' => $i->daysRemaining(),
            ]);
        }

        /*
         * Daftar domain custom ikut dikirim di detail. Halaman ini tempat
         * admin akan jelas mencari "domain mana yang dipakai lokasi ini",
         * dan halaman Domain adalah tempat semuanya sekaligus.
         */
        if ($detail && $t->relationLoaded('domains')) {
            $data['domains'] = $t->domains->map(fn (TenantDomain $d) => [
                'id' => $d->id,
                'host' => $d->displayHost(),
                'is_wildcard' => $d->is_wildcard,
                'is_primary' => $d->is_primary,
                'is_active' => $d->is_active,
            ])->values();
        }

        return $data;
    }
}
