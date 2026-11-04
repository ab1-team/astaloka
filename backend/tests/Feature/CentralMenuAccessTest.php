<?php

namespace Tests\Feature;

use App\Http\Controllers\MenuAccessController;
use App\Models\Central\Tenant;
use App\Models\Jabatan;
use App\Models\User;
use App\Services\MenuAccessService;
use App\Services\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\EnsuresCentralTables;
use Tests\TestCase;
use Throwable;

/**
 * Hak akses menu per jabatan, ditulis dari PANEL PUSAT untuk semua lokasi.
 *
 * Test ini adalah tempat semua aturan PENULISAN yang pindah dari
 * `MenuAccessTest.php`. Aturannya sendiri masih hidup semua -- hanya endpoint-nya
 * yang pindah tempat -- jadi menyalinnya mentah tetap perlu, ditambah beberapa
 * masalah yang sama sekali tidak ada di aplikasi lokasi: menulis dari panel
 * pusat berarti MENYEBERANG ke database yang benar.
 *
 * Yang di sini, dan yang tidak bisa lolos kalau hanya salah satu diperiksa:
 *
 *  1. Tulisannya benar-benar mendarat di database LOKASI itu. `Jabatan` dan
 *     `JabatanMenuAccess` tidak mengunci `$connection`, jadi tanpa
 *     `TenantManager::runFor()` keduanya membaca tabel yang tidak punya
 *     keduanya -- dan gejalanya `table not found`, yang tidak menunjuk ke
 *     koneksi yang salah.
 *  2. `database.default` kembali seperti semula. Kalau tidak, request BERIKUTNYA
 *     di proses yang sama -- termasuk render halaman error -- diam-diam membaca
 *     database lokasi.
 *  3. Satu lokasi tidak boleh menulis jabatan milik lokasi lain. Nomor jabatan
 *     berimpit antar lokasi, jadi `id` saja tidak pernah cukup untuk menentukan
 *     jabatan siapa yang sedang diubah.
 *  4. Hanya superadmin. Endpoint di aplikasi lokasi sudah hilang, jadi kalau
 *     endpoint ini bocor berarti tabel yang sama bisa ditulis tanpa pernah
 *     melewati sesi panel pusat.
 *
 * KENAPA `RefreshDatabase` SENGAJA TIDAK DIPAKAI
 * ============================================
 *
 * `RefreshDatabase` membungkus test dalam transaksi pada koneksi default, dan
 * itu justru tidak bisa dipakai di sini. `TenantManager::runFor()` memanggil
 * `DB::purge()` lalu membuka koneksi BARU -- koneksi PDO yang berbeda, meski
 * menunjuk database yang sama. Koneksi baru itu tidak punya transaksi, jadi
 * tidak melihat satu baris pun yang belum ter-commit.
 *
 * Gejalanya sangat menyesatkan karena bukan error yang menunjuk ke sini: baris
 * jabatan yang baru dibuat test dianggap tidak ada, jadi controller membalas
 * 404 "Jabatan tidak ditemukan di lokasi ini" -- padahal jobatannya memang ada,
 * dan tabelnya pun ada. Test lain yang melakukan hal serupa (`ShortcutLoginTest`)
 * sudah punya catatan yang sama, dengan alasan yang persis sama.
 *
 * Jadi data di sini benar-benar di-commit dan dibersihkan manual di
 * `tearDown()`, dengan pola `prefix` unik per test supaya sisa run yang gagal
 * tidak pernah bentrok dengan run berikutnya.
 */
class CentralMenuAccessTest extends TestCase
{
    use EnsuresCentralTables;

    private const CENTRAL = 'central';

    /** Sufiks unik per test, untuk baris yang tidak bisa di-rollback. */
    private string $prefix;

    private Tenant $tenant;

    private User $superadmin;

    private string $email;

    /** Database yang dibuat test ini, untuk dibuang di `tearDown()`. */
    private array $databaseDibuat = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->runCentralMigrations();

        $this->prefix = 'hakakses-'.uniqid().'-';
        $this->email = $this->prefix.'admin@test.local';

        $this->pastikanSkemaLokasi();

        $central = DB::connection(self::CENTRAL);

        $this->bersihkan();

        /*
         * `database` menunjuk database UJI yang sama dengan koneksi default.
         *
         * Itu disengaja, bukan penyederhanaan. Yang perlu diuji controller ini
         * adalah menulis ke database yang benar untuk `tenant` yang diberikan,
         * dan memakai database uji yang sama membuat hasilnya bisa dibandingkan
         * langsung dengan `MenuAccessTest`. Database terpisah juga berarti
         * tabelnya harus dibuat manual di sini -- dan `jabatan_menu_access` yang
         * full-schema-nya tidak pernah diuji di jalur itu bisa jadi salah.
         */
        $this->tenant = Tenant::create([
            'slug' => $this->prefix.'pdam',
            'nama' => 'PDAM Uji Hak Akses',
            'nama_lembaga' => 'PDAM Uji Hak Akses Bersama',
            'database' => (string) config('database.connections.mysql.database'),
            'is_active' => true,
        ]);

        /*
         * Superadmin harus hidup di database PUSAT, bukan di database lokasi.
         *
         * Enum `users.role` di database lokasi hanya berisi
         * admin/surveyor/teknisi/pelanggan -- `superadmin` sengaja tidak ada di
         * sana, karena user pusat tidak pernah hidup di database lokasi.
         * Menulisnya ke koneksi default justru menghapus pemisahan dua dunia
         * itu.
         *
         * `Sanctum::actingAs()` tidak menyentuh database: ia meniru user
         * langsung di memori. Modelnya karena itu cukup `forceFill`, tapi baris
         * nyatanya tetap dibuat karena `EnsureSuperadmin` membaca `is_active`
         * lewat koneksi `central`.
         */
        $central->table('users')->insert([
            'name' => 'Admin Pusat',
            'email' => $this->email,
            'password' => Hash::make('password'),
            'role' => 'superadmin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->superadmin = (new User)->forceFill([
            'name' => 'Admin Pusat',
            'email' => $this->email,
            'password' => Hash::make('password'),
            'role' => 'superadmin',
        ]);

        $this->superadmin->id = $central->table('users')
            ->where('email', $this->email)
            ->value('id');
    }

    protected function tearDown(): void
    {
        /*
         * Tidak ada rollback di sini, jadi ini satu-satunya tempat yang
         * JAMIN memb worldview data uji -- dan `tearDown()` tetap jalan meski
         * test gagal di tengah. Baris yang dibiarkan hanya akan menambah
         * kebisingan: `ShortcutLoginTest` membaca SELURUH `tenants`, jadi satu
         * lokasi sisa akan ikut dengan `users` kosong dan merusak test lain.
         *
         * PENTING: `delete()` per-polanya, BUKAN `truncate()`. Kalau
         * `TENANCY_CENTRAL_DATABASE` pernah menunjuk database pusat yang
         * sebenarnya, satu `php artisan test` akan menghapus seluruh isi
         * registry tanpa forewarning dan tanpa bisa dikembalikan. Test yang
         * menghapus data bukan miliknya adalah bom waktu, bukan cleanup.
         */
        try {
            $this->bersihkan();
        } catch (Throwable $e) {
            // Jangan sampai kegagalan membersihkan menutupi kegagalan test
            // yang sebenarnya sedang diselidiki.
            report($e);
        }

        /*
         * Database uji buangan DIBUANG setelah `bersihkan()`.
         *
         * Urutannya penting: `bersihkan()` membaca database lokasi utama, dan
         * ia tidak boleh gagal hanya karena salah satu `DROP DATABASE` di
         * bawah menabrak koneksi yang masih terbuka. `TenantManager::runFor()`
         * sudah memurge koneksi `tenant` di blok `finally`-nya, tapi
         * `deactivate()` tidak dipanggil di sini, jadi koneksi ke database
         * uji bisa masih dipegang kalau test terakhir memakainya.
         */
        try {
            $this->buangDatabaseUji();
        } catch (Throwable $e) {
            report($e);
        }

        parent::tearDown();
    }

    /**
     * Buang seluruh database uji yang dibuat file ini.
     *
     * Tanpa ini, setiap `php artisan test` meninggalkan satu database utuh di
     * MySQL, dan setelah beberapa puluh run `SHOW DATABASES` jadi penuh nama
     * uji yang tidak pernah dijelaskan.
     *
     * Hapus per-nama, bukan `information_schema`-wide: nama database yang
     * dibuat di sini selalu berawalan prefix test ini, jadi tidak ada yang
     * bisa ikut terhapus tanpa sengaja.
     */
    private function buangDatabaseUji(): void
    {
        $central = DB::connection(self::CENTRAL);

        foreach ($this->databaseDibuat as $nama) {
            $central->statement("DROP DATABASE IF EXISTS `{$nama}`");
        }

        $this->databaseDibuat = [];
    }

    /**
     * Buat satu tenant dengan databaseNYA SENDIRI, plus tabel yang dibutuhkan.
     *
     * Dipakai untuk menguji isolasi antar lokasi. Dua tenant yang menunjuk
     * database yang sama TIDAK menguji apa pun: `jabatan_menu_access` yang
     * sama akan dipakai keduanya, jadi setiap percobaan "perubahan bocor ke
     * lokasi lain" akan selalu berhasil -- persis kebalikan dari yang ingin
     * dibuktikan.
     *
     * Namanya dibangun dari prefix test dan hanya berisi huruf, angka,
     * underscore, dan dash, jadi aman disisipkan apa adanya ke
     * `CREATE DATABASE` (MySQL tidak menerima placeholder di sana).
     *
     * Tabelnya dibuat manual, bukan lewat `artisan migrate`: `migrate` menulis
     * catatan ke tabel `migrations` pada koneksi yang SEDANG AKTIF, jadi
     * menjalankannya di sini akan menandai seluruh migrasi lokasi sebagai
     * sudah jalan di database utama -- dan test berikutnya yang bergantung
     * pada itu akan salah membaca skemanya.
     */
    private function buatLokasiTerpisah(): Tenant
    {
        $nama = str_replace('-', '_', $this->prefix.'terpisah');

        $central = DB::connection(self::CENTRAL);

        $central->statement("DROP DATABASE IF EXISTS `{$nama}`");
        $central->statement("CREATE DATABASE `{$nama}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->databaseDibuat[] = $nama;

        $central->statement(
            "CREATE TABLE `{$nama}`.`users` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `email` VARCHAR(255) NOT NULL,
                `jabatan_id` BIGINT UNSIGNED NULL,
                `role` VARCHAR(50) NOT NULL DEFAULT 'pelanggan',
                `password` VARCHAR(255) NOT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL
            )"
        );

        $central->statement(
            "CREATE TABLE `{$nama}`.`jabatans` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `nama_jabatan` VARCHAR(100) NOT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL
            )"
        );

        $central->statement(
            "CREATE TABLE `{$nama}`.`jabatan_menu_access` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `jabatan_id` BIGINT UNSIGNED NOT NULL,
                `menu_key` VARCHAR(100) NOT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                UNIQUE KEY `jabatan_menu_access_unique` (`jabatan_id`, `menu_key`)
            )"
        );

        return Tenant::create([
            'slug' => $this->prefix.'terpisah',
            'nama' => 'PDAM Uji Terpisah',
            'nama_lembaga' => 'PDAM Uji Terpisah Bersama',
            'database' => $nama,
            'is_active' => true,
        ]);
    }

    /**
     * Jabatan di database lokasi milik sebuah tenant, dengan `id` yang bisa
     * dipatok.
     *
     * `$id` sengaja ada. `jabatan_menu_access.jabatan_id` hanya berarti
     * "nomor jabatan DI LOKASI INI", jadi dua lokasi dalam satu server MySQL
     * bisa saja punya jabatan dengan nomor yang sama. Nomor yang sama justru
     * kondisi yang paling menantang untuk diuji: kalau panel pusat salah menulis
     * ke database lain, pemeriksaan "apakah barisnya berubah" tetap benar-benar
     * diuji, sementara identitas barisnya sendiri tidak bisa membedakan dua
     * lokasi.
     *
     * Nomor dari `AUTO_INCREMENT` tidak bisa dikendalikan, jadi id-nya ditulis
     * langsung ke instance lalu di-`save()`.
     *
     * Penulisan atribut seperti ini tidak melewati `$fillable` -- itulah yang
     * dibutuhkan, karena `id` memang tidak ada di sana. `forceCreate()` TIDAK
     * bisa dipakai di sini: dipanggil pada instance, ia diteruskan ke builder
     * yang mewajibkan argumen, dan hasilnya `ArgumentCountError` yang sama
     * sekali tidak menyinggung baris yang jadi penyebabnya.
     */
    private function jabatanIdDi(Tenant $tenant, string $nama, ?int $id = null): int
    {
        return (int) app(TenantManager::class)->runFor($tenant, function () use ($nama, $id) {
            $jabatan = new Jabatan(['nama_jabatan' => $nama]);

            if ($id !== null) {
                $jabatan->id = $id;
            }

            $jabatan->save();

            return (int) $jabatan->id;
        });
    }

    /**
     * Pastikan tabel database LOKASI ada, tanpa menyentuh isinya.
     *
     * Test ini tidak memakai `RefreshDatabase` (alasannya ada di docblock
     * class), jadi tidak ada yang membangun skema untuknya. Test lain tanpa
     * `RefreshDatabase` -- `ShortcutLoginTest` -- bisa lolos begitu saja karena
     * kebetulan: `users`-nya sudah ada dari run sebelumnya, atau dari test lain
     * yang me-refresh duluan di proses yang sama. File ini sendiri tidak punya
     * kebetulan itu -- kalau suite dijalankan dengan filter, tabel `jabatans`
     * belum tentu pernah dibuat.
     *
     * Yang dipanggil di sini hanya `migrate`, BUKAN `migrate:fresh`.
     * `migrate:fresh` menghapus seluruh isi database lokasi, termasuk baris
     * milik test lain yang belum sempat membersihkannya -- dan test yang
     * menghapus data bukan miliknya adalah bom waktu, bukan setup.
     *
     * Idempoten: `migrate` melewati yang sudah jalan, jadi pemanggilan di
     * setiap test tidak mengubah apa pun.
     */
    private function pastikanSkemaLokasi(): void
    {
        $schema = DB::connection('mysql')->getSchemaBuilder();

        if ($schema->hasTable('jabatans') && $schema->hasTable('jabatan_menu_access')) {
            return;
        }

        $this->artisan('migrate', [
            '--database' => 'mysql',
            '--force' => true,
        ])->assertExitCode(0);
    }

    /**
     * Hapus semua jejak test ini dari database pusat dan database lokasi.
     *
     * Dipakai dua kali: di `setUp()` untuk membersihkan sisa run sebelumnya
     * yang gagal sebelum `tearDown()`-nya sempat jalan, dan di `tearDown()`
     * untuk meninggalkan database dalam keadaan semula.
     */
    private function bersihkan(): void
    {
        $central = DB::connection(self::CENTRAL);

        $ids = $central->table('tenants')
            ->where('slug', 'like', 'hakakses-%')
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            $central->table('audit_logs')->whereIn('tenant_id', $ids)->delete();
        }

        $central->table('users')->where('email', 'like', 'hakakses-%@test.local')->delete();
        $central->table('tenants')->where('slug', 'like', 'hakakses-%')->delete();

        $lokasi = DB::connection('mysql');

        /*
         * User LOKASI yang dibuat test ini juga harus dibersihkan.
         *
         * Sebelumnya hanya user PUSAT di atas yang dihapus, sementara
         * `adminLokasi()` menulis ke database lokasi. Akibatnya user
         * `hakakses-*` menumpuk di database uji yang sama dan terbaca oleh
         * test lain -- `ShortcutLoginTest` yang menunjuk database itu akan
         * melihat user asing di daftar lokasinya.
         *
         * Tiket instalasi sisa run sebelumnya bisa menunjuk id user ini lewat
         * `created_by` (NOT NULL) atau `user_id`; FK menolak DELETE users
         * selama baris itu masih ada, jadi barisnya dibuang lebih dulu.
         */
        $idLokasi = $lokasi->table('users')
            ->where('email', 'like', 'hakakses-%@test.local')
            ->pluck('id');

        if ($idLokasi->isNotEmpty()) {
            $lokasi->table('installation_tickets')
                ->whereIn('created_by', $idLokasi)
                ->orWhereIn('user_id', $idLokasi)
                ->delete();

            $lokasi->table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $idLokasi)
                ->delete();

            $lokasi->table('users')->whereIn('id', $idLokasi)->delete();
        }

        $jabatanIds = $lokasi->table('jabatans')
            ->where('nama_jabatan', 'like', 'Uji-%')
            ->pluck('id');

        if ($jabatanIds->isNotEmpty()) {
            $lokasi->table('jabatan_menu_access')->whereIn('jabatan_id', $jabatanIds)->delete();
        }

        $lokasi->table('jabatans')->where('nama_jabatan', 'like', 'Uji-%')->delete();
    }

    // ================================================================
    // Helper
    // ================================================================

    private function actingAsSuperadmin(): void
    {
        Sanctum::actingAs($this->superadmin, ['*'], 'sanctum');
    }

    /** Admin LOKASI, untuk menguji batas panel pusat. */
    private function adminLokasi(string $email): User
    {
        return User::create([
            'name' => 'Admin Lokasi',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }

    /**
     * Jabatan di database LOKASI.
     *
     * Namanya berawalan `Uji-` supaya `bersihkan()` bisa mengenali dan
     * menghapusnya. Pola nama, bukan pola id: id-nya_auto-increment dan tidak
     * bisa ditebak, jadi membersihkannya butuh daftar id yang harus disimpan
     * di instance -- dan instance itu tidak ada di `setUp()` yang dipanggil
     * SEBELUM test apa pun berjalan.
     */
    private function jabatanId(string $nama): int
    {
        return (int) Jabatan::create(['nama_jabatan' => 'Uji-'.$nama])->id;
    }

    /**
     * Daftar menu yang tersimpan, dibaca di dalam `runFor()`.
     *
     * WAJIB di dalam `runFor()`: `MenuAccessService` memakai model yang tidak
     * mengunci `$connection`. Membaca langsung di sini bisa lulus karena
     * kebetulan -- di dalam proses test, `database.default` memang menunjuk
     * database lokasi -- dan gagal begitu `runFor()` selesai. Membaca
     * lewat jalur yang sama dengan yang dipakai controller adalah satu-satunya
     * cara supaya test ini menguji hal yang benar.
     */
    private function tersimpan(Tenant $tenant, int $jabatanId): ?array
    {
        return app(TenantManager::class)->runFor(
            $tenant,
            fn () => app(MenuAccessService::class)->storedKeysForJabatan($jabatanId)
        );
    }

    private function url(string $suffix = ''): string
    {
        return "/api/central/locations/{$this->tenant->slug}/menu-access{$suffix}";
    }

    /**
     * URL untuk tenant yang BUKAN tenant bawaan test ini.
     *
     * Dipisah dari `url()` supaya test isolasi tidak pernah bisa lolos karena
     * menulis ke tenant yang sama: kalau `url()` dipakai untuk "lokasi B",
     * test-nya akan memeriksa dua kali hal yang sama dan selalu hijau.
     */
    private function urlLokasi(Tenant $tenant, string $suffix = ''): string
    {
        return "/api/central/locations/{$tenant->slug}/menu-access{$suffix}";
    }

    // ================================================================
    // Otorisasi
    // ================================================================

    #[Test]
    public function admin_lokasi_tidak_bisa_membaca_hak_akses_lokasi_lain(): void
    {
        Sanctum::actingAs($this->adminLokasi($this->prefix.'lokasi@test.local'), ['*'], 'sanctum');

        $this->getJson($this->url())->assertForbidden();
    }

    /**
     * Admin lokasi tidak boleh MENULIS juga, dan ini yang paling penting dari
     * dua test otorisasi.
     *
     * Membaca yang bocor merusak kerahasiaan. Menulis yang bocor berarti satu
     * akun admin di satu lokasi bisa mengunci seluruh jabatan di lokasi yang
     * tidak menjadi miliknya -- dan perubahan itu langsung berlaku, karena
     * `EnforceMenuAccess` membaca tabel yang sama di aplikasi lokasi.
     */
    #[Test]
    public function admin_lokasi_tidak_bisa_menulis_hak_akses_lokasi_lain(): void
    {
        $jabatanId = $this->jabatanId('Bendahara');

        Sanctum::actingAs($this->adminLokasi($this->prefix.'lokasi2@test.local'), ['*'], 'sanctum');

        $this->putJson($this->url("/{$jabatanId}"), ['menu_keys' => ['master.pelanggan']])
            ->assertForbidden();

        // Penolakan harus benar-benar menolak: tabel tidak boleh tersentuh.
        $this->assertNull($this->tersimpan($this->tenant, $jabatanId));
    }

    // ================================================================
    // Membaca
    // ================================================================

    #[Test]
    public function daftar_jabatan_lokasi_tampil_lengkap(): void
    {
        $this->jabatanId('Bendahara');
        $this->jabatanId('Direktur');

        $this->actingAsSuperadmin();

        $res = $this->getJson($this->url())->assertOk();

        $nama = collect($res->json('data'))->pluck('nama');

        $this->assertTrue($nama->contains('Uji-Bendahara'));
        $this->assertTrue($nama->contains('Uji-Direktur'));
    }

    /**
     * Bentuk respons pusat harus PERSIS sama dengan yang dibaca aplikasi
     * lokasi, karena keduanya memakai komponen checkbox yang sama.
     *
     * Kalau bentuknya berbeda, frontend harus punya dua komponen yang harus
     * dijaga sinkron tanpa ada yang menguji sinkronitasnya -- dan begitu
     * katalog berubah, satu halaman diam-diam menampilkan centang untuk menu
     * yang sudah tidak berlaku.
     *
     * Membandingkan `array_keys` dan bukan isi penuh: isinya memang berbeda
     * karena jabatan yang berbeda dipilih, tapi bentuknya tidak boleh.
     */
    #[Test]
    public function bentuk_respons_sama_dengan_aplikasi_lokasi(): void
    {
        $jabatanId = $this->jabatanId('Bendahara');

        // Disimpan dari panel pusat dulu, supaya tidak ada jabatan yang belum
        // dikonfigurasi pun bisa dipakai sebagai pembanding.
        $this->actingAsSuperadmin();
        $this->putJson($this->url("/{$jabatanId}"), ['menu_keys' => ['master.pelanggan']])
            ->assertOk();

        $dariPusat = $this->getJson($this->url())->assertOk()->json('data');
        $dariPusat = collect($dariPusat)->firstWhere('id', $jabatanId);

        /*
         * Dibaca lewat HTTP di aplikasi lokasi, di dalam `runFor()`.
         *
         * `GET /api/menu-access` melewati `ResolveTenant`, yang membaca tenant
         * dari host -- dan di `php artisan test` tidak ada host lokasi. Memanggil
         * controller-nya langsung lewat `runFor()` adalah cara paling jujur
         * untuk membandingkan bentuk yang DIHASILKAN dua sisi tanpa middleware
         * ikut menghalang.
         */
        $dariLokasi = app(TenantManager::class)->runFor($this->tenant, function () {
            return app(MenuAccessController::class)->index();
        });

        $lokasi = collect(json_decode($dariLokasi->getContent(), true)['data'])
            ->firstWhere('id', $jabatanId);

        $this->assertSame(array_keys($lokasi), array_keys($dariPusat));
        $this->assertSame(array_keys($lokasi['menu'][0]), array_keys($dariPusat['menu'][0]));

        // Dan isi centangnya harus sama juga -- kalau tidak, "bentuk sama"
        // hanya benar di permukaan saja.
        $iniPusat = collect($dariPusat['menu'])->where('selected', true)->pluck('key')->sort()->values()->all();
        $iniLokasi = collect($lokasi['menu'])->where('selected', true)->pluck('key')->sort()->values()->all();

        $this->assertSame($iniLokasi, $iniPusat);
    }

    #[Test]
    public function lokasi_yang_tidak_ada_di_database_pusat_menghasilkan_404(): void
    {
        $this->actingAsSuperadmin();

        $this->getJson('/api/central/locations/tidak-ada-sama-sekali/menu-access')
            ->assertNotFound();
    }

    /**
     * Path boleh berupa `slug` maupun `id`.
     *
     * Bukan skema, hanya untuk kenyamanan: panel pusat menyimpan `id` di baris
     * tabelnya sementara halaman lain memakai `slug`, jadi ada momen ketika
     * hanya salah satu yang ada di tangan.
     */
    #[Test]
    public function lokasi_yang_disebut_berdasarkan_id_dan_slug_sama_saja(): void
    {
        $jabatanId = $this->jabatanId('Bendahara');
        $this->actingAsSuperadmin();

        $lewatSlug = $this->putJson($this->url("/{$jabatanId}"), ['menu_keys' => ['master.pelanggan']]);
        $lewatSlug->assertOk();

        $lewatId = $this->putJson(
            "/api/central/locations/{$this->tenant->id}/menu-access/{$jabatanId}",
            ['menu_keys' => ['pelaporan']]
        );
        $lewatId->assertOk();

        $this->assertSame(['pelaporan'], $this->tersimpan($this->tenant, $jabatanId));
    }

    // ================================================================
    // Menyimpan
    // ================================================================

    #[Test]
    public function hak_akses_bisa_disimpan(): void
    {
        $jabatanId = $this->jabatanId('Bendahara');
        $this->actingAsSuperadmin();

        $this->putJson($this->url("/{$jabatanId}"), [
            'menu_keys' => ['master.pelanggan', 'transaksi.jurnal-umum'],
        ])->assertOk();

        $this->assertSame(
            ['master.pelanggan', 'transaksi.jurnal-umum'],
            $this->tersimpan($this->tenant, $jabatanId),
        );
    }

    #[Test]
    public function yang_tersimpan_ditampilkan_tercentang(): void
    {
        $jabatanId = $this->jabatanId('Bendahara');

        $this->actingAsSuperadmin();
        $this->putJson($this->url("/{$jabatanId}"), ['menu_keys' => ['master.pelanggan']])
            ->assertOk();

        $menu = collect($this->getJson($this->url())->assertOk()->json('data'))
            ->firstWhere('id', $jabatanId)['menu'];

        /*
         * `selected` dihitung dari daftar yang TERSIMPAN, bukan dari `always`.
         *
         * Kalau dihitung dari `always`, centangan admin hilang setelah disimpan:
         * halaman terbuka dengan hanya "Beranda" tercentang, seolah simpan tidak
         * pernah terjadi -- dan `adaPerubahan` di frontend selalu `true`, jadi
         * tombol Simpan juga tidak pernah mati.
         */
        $this->assertTrue(collect($menu)->firstWhere('key', 'master.pelanggan')['selected']);
        $this->assertFalse(collect($menu)->firstWhere('key', 'pelaporan')['selected']);
    }

    /**
     * Mengirim daftar LENGKAP akan MENIMPA, bukan menambah.
     *
     * Kalau backend memakai diff, `pelaporan` akan tertinggal karena pernah
     * dicentang lalu disimpan lagi -- dan frontend tidak pernah mengirim daftar
     * kosong untuk mencabut semuanya, jadi tidak ada yang bisa memberi tahu
     * bahwa keadaannya sudah salah.
     */
    #[Test]
    public function menyimpan_menyalin_daftar_lama(): void
    {
        $jabatanId = $this->jabatanId('Bendahara');
        $this->actingAsSuperadmin();

        $this->putJson($this->url("/{$jabatanId}"), [
            'menu_keys' => ['master.pelanggan', 'pelaporan'],
        ])->assertOk();

        $this->putJson($this->url("/{$jabatanId}"), ['menu_keys' => ['master.pelanggan']])
            ->assertOk();

        $this->assertSame(['master.pelanggan'], $this->tersimpan($this->tenant, $jabatanId));
    }

    /**
     * Daftar kosong yang DIKIRIM superadmin (semua dicabut dengan sengaja) itu
     * sah dan harus disimpan.
     *
     * Ini yang membedakan "semua dicabut dengan sengaja" dari "semua tidak
     * dikenal". Keduanya tidak menghasilkan baris yang sama, jadi keduanya harus
     * punya perlakuan yang berbeda.
     */
    #[Test]
    public function daftar_kosong_yang_disengaja_tetap_diterima(): void
    {
        $jabatanId = $this->jabatanId('Bendahara');
        $this->actingAsSuperadmin();

        $this->putJson($this->url("/{$jabatanId}"), ['menu_keys' => ['master.pelanggan']])
            ->assertOk();

        $this->putJson($this->url("/{$jabatanId}"), ['menu_keys' => []])->assertOk();

        // Tidak boleh null: kalau null, semua menu terbuka kembali.
        $this->assertNotNull($this->tersimpan($this->tenant, $jabatanId));
    }

    #[Test]
    public function menu_yang_tidak_dikenal_diabaikan_saat_menyimpan(): void
    {
        $jabatanId = $this->jabatanId('Bendahara');
        $this->actingAsSuperadmin();

        $this->putJson($this->url("/{$jabatanId}"), [
            'menu_keys' => ['master.pelanggan', 'menu-yang-tidak-ada'],
        ])
            ->assertOk()
            ->assertJsonPath('data.diabaikan', ['menu-yang-tidak-ada']);

        $this->assertSame(['master.pelanggan'], $this->tersimpan($this->tenant, $jabatanId));
    }

    /**
     * Daftar yang isinya tidak dikenal sama sekali harus ditolak dengan jelas.
     *
     * Kalau dibalas `success: true`, frontend akan memuat ulang daftar lama,
     * centangnya kembali seperti semula, dan superadmin tidak pernah tahu bahwa
     * seluruh permintaannya tidak terbaca. Gejalanya: klik Simpan, toast sukses,
     * tidak ada yang berubah, dan tidak ada petunjuk kenapa.
     */
    #[Test]
    public function daftar_yang_tidak_dikenal_semuanya_ditolak_jelas(): void
    {
        $jabatanId = $this->jabatanId('Bendahara');
        $this->actingAsSuperadmin();

        $this->putJson($this->url("/{$jabatanId}"), ['menu_keys' => ['master.pelanggan']])
            ->assertOk();

        $res = $this->putJson($this->url("/{$jabatanId}"), [
            'menu_keys' => ['menu-yang-tidak-ada'],
        ])->assertStatus(422);

        $this->assertSame('MENU_KEYS_UNKNOWN', $res->json('code'));
        $this->assertSame(['master.pelanggan'], $this->tersimpan($this->tenant, $jabatanId));
    }

    /**
     * Daftar yang seluruh isinya tidak dikenal TIDAK boleh membuat jabatan
     * kembali jadi "belum pernah dikonfigurasi".
     *
     * Kalau tidak ada baris yang tersimpan, `storedKeysForJabatan()`
     * mengembalikan `null`, yang berarti semua menu terbuka lagi -- jadi satu
     * typo saja bisa membuka kembali semua yang baru saja dicabut. Ditolak 422
     * supaya keadaan itu tidak pernah terjadi.
     */
    #[Test]
    public function daftar_yang_seluruhnya_tidak_dikenal_tidak_membuka_kembali_semua_menu(): void
    {
        $jabatanId = $this->jabatanId('Bendahara');
        $this->actingAsSuperadmin();

        $this->putJson($this->url("/{$jabatanId}"), ['menu_keys' => ['master.pelanggan']])
            ->assertOk();

        $this->putJson($this->url("/{$jabatanId}"), ['menu_keys' => ['menu-yang-tidak-ada']])
            ->assertStatus(422);

        $this->assertNotNull(
            $this->tersimpan($this->tenant, $jabatanId),
            'Daftar yang seluruhnya tidak dikenal tidak boleh membuat jabatan kembali '
            .'jadi "belum pernah dikonfigurasi" -- itu membuka semua menu.',
        );
    }

    /**
     * `menu_keys` yang tidak dikirim sama sekali tidak boleh dibaca sebagai
     * "hapus semua".
     *
     * Rule `present` yang menahannya, dan test ini yang menjaganya tetap ada.
     * Tanpa rule itu, `menu_keys` yang lupa ditulis akan menghapus seluruh hak
     * akses lama -- dan membalas sukses, jadi tidak ada yang tahu akses sudah
     * hilang sampai seseorang mencoba membuka menu yang tadinya bisa.
     *
     * Yang diperiksa adalah akibatnya (tabel utuh), bukan kode error-nya:
     * bentuk 422-nya milik `validate()`, yang bisa berubah sendiri tanpa
     * membuat aturan ini salah.
     */
    #[Test]
    public function menu_keys_yang_tidak_dikirim_tidak_berarti_hapus_semua(): void
    {
        $jabatanId = $this->jabatanId('Bendahara');
        $this->actingAsSuperadmin();

        $this->putJson($this->url("/{$jabatanId}"), ['menu_keys' => ['master.pelanggan']])
            ->assertOk();

        $this->putJson($this->url("/{$jabatanId}"), [])->assertStatus(422);

        $this->assertSame(['master.pelanggan'], $this->tersimpan($this->tenant, $jabatanId));
    }

    /**
     * `menu_keys: []` -- hasil tombol "Hapus semua" -- tetap sah.
     *
     * Dipisah dari test di atas karena keduanya sama-sama "tidak ada menu yang
     * dipilih", dan bedanya justru yang paling rawan hilang: begitu rule
     * `present` salah tulis menjadi `required`, test pertama masih lulus (422
     * tetap 422) tapi test INI yang gagal -- dangejala yang muncul adalah
     * tombol "Hapus semua" di UI tidak pernah berhasil, bukan error yang jelas.
     */
    #[Test]
    public function menu_keys_kosong_diterima_sebagai_hapus_semua(): void
    {
        $jabatanId = $this->jabatanId('Bendahara');
        $this->actingAsSuperadmin();

        $this->putJson($this->url("/{$jabatanId}"), ['menu_keys' => []])->assertOk();
    }

    // ================================================================
    // Reset
    // ================================================================

    /**
     * Reset menghapus barisnya, bukan menyimpan daftar lengkap.
     *
     * `null` = belum pernah dikonfigurasi, BUKAN "tidak boleh apa-apa". Kalau
     * ini `[]`, semua user jabatan itu langsung kehilangan semua menu tanpa
     * pernah menyadarinya -- dan menu yang ditambahkan kemudian tidak otomatis
     * terbuka untuknya, padahal sebelumnya iya.
     */
    #[Test]
    public function reset_mengembalikan_jabatan_ke_semua_menu(): void
    {
        $jabatanId = $this->jabatanId('Bendahara');
        $this->actingAsSuperadmin();

        $this->putJson($this->url("/{$jabatanId}"), ['menu_keys' => ['master.pelanggan']])
            ->assertOk();

        $this->postJson($this->url("/{$jabatanId}/reset"))
            ->assertOk()
            ->assertJsonPath('data.configured', false);

        $this->assertNull($this->tersimpan($this->tenant, $jabatanId));
    }

    #[Test]
    public function jabatan_yang_tidak_ada_di_lokasi_itu_menghasilkan_404(): void
    {
        $this->actingAsSuperadmin();

        // `999999` jauh di luar jangkauan auto-increment test mana pun.
        $this->putJson($this->url('/999999'), ['menu_keys' => ['master.pelanggan']])
            ->assertNotFound()
            ->assertJsonPath('code', 'JABATAN_NOT_FOUND');
    }

    #[Test]
    public function jabatan_berupa_huruf_menghasilkan_404_bukan_500(): void
    {
        $this->actingAsSuperadmin();

        // `whereNumber('jabatan')` yang menahan ini di level route. Tanpa itu
        // PHP melempar TypeError saat mengisi parameter bertipe `int`, dan
        // hasilnya 500 dengan pesan yang tidak menyinggung apa pun.
        $this->putJson($this->url('/bukan-angka'), ['menu_keys' => ['master.pelanggan']])
            ->assertNotFound();
    }

    // ================================================================
    // Kebersihan lintas lokasi
    // ================================================================

    /**
     * `database.default` harus kembali seperti semula setelah request.
     *
     * `TenantManager::runFor()` memulihkannya di blok `finally`, jadi request
     * yang gagal PUNYA kalanya. Kalau tidak dipulihkan, request berikutnya --
     * termasuk render halaman error -- membaca database lokasi dan menampilkan
     * isi lokasi yang salah di panel pusat.
     *
     * Yang diuji di sini adalah nilai config-nya, bukan isi tabel, karena
     * `php artisan test` menjalankan semua test dalam satu proses: sisa yang
     * tertinggal akan merusak test berikutnya, bukan yang sekarang.
     */
    #[Test]
    public function koneksi_default_kembali_ke_database_pusat_setelah_request(): void
    {
        $jabatanId = $this->jabatanId('Bendahara');
        $this->actingAsSuperadmin();

        $defaultSebelum = config('database.default');

        $this->getJson($this->url())->assertOk();

        $this->assertSame($defaultSebelum, config('database.default'));

        // Dan setelah request yang BENULLAHpun.
        $this->putJson($this->url('/999999'), ['menu_keys' => ['master.pelanggan']])
            ->assertNotFound();

        $this->assertSame($defaultSebelum, config('database.default'));
    }

    /**
     * Jejak audit harus mencatat AKTOR yang benar, dari database PUSAT.
     *
     * Panel pusat dan aplikasi lokasi punya dua basis data berbeda dengan tabel
     * `personal_access_tokens` yang sama-sama autoincrement dari 1. Kalau audit
     * menebak aktor lewat `auth()` alih-alih memakai user dari request,
     * aktornya bisa tercatat kosong -- dan perubahan yang benar-benar terjadi
     * jadi tidak bisa ditelusuri siapa yang melakukannya.
     *
     * `actor_id` yang diperiksa, bukan `actor_name`: nama bisa saja sama antar
     * akun, id tidak mungkin. Yang diuji adalah "aktorNYA terisi", bukan
     * "namanya benar".
     */
    #[Test]
    public function audit_mencatat_aktor_superadmin_dan_lokasi_yang_benar(): void
    {
        $jabatanId = $this->jabatanId('Bendahara');
        $this->actingAsSuperadmin();

        $this->putJson($this->url("/{$jabatanId}"), ['menu_keys' => ['master.pelanggan']])
            ->assertOk();

        $log = DB::connection(self::CENTRAL)
            ->table('audit_logs')
            ->where('action', 'menu_access.update')
            ->where('tenant_id', $this->tenant->id)
            ->first();

        $this->assertNotNull($log, 'Perubahan hak akses harus tercatat di audit log pusat.');
        $this->assertSame('superadmin', $log->actor_role);
        $this->assertSame((string) $this->superadmin->id, (string) $log->actor_id);
        $this->assertSame((string) $jabatanId, (string) $log->subject_id);
    }

    // ================================================================
    // Isolasi antar lokasi -- dua database sungguhan
    // ================================================================

    /**
     * Simpan di satu lokasi TIDAK boleh mengubah lokasi lain.
     *
     * Ini inti dari "berjalan sesuai lokasi masing-masing", dan test lain di
     * file ini TIDAK bisa membuktikannya: semuanya memakai satu tenant yang
     * menunjuk database uji yang sama. Dengan begitu, perubahan yang bocor ke
     * lokasi lain tidak akan terlihat -- karena "lokasi lain" itu memang
     * database yang sama.
     *
     * Yang diuji di sini adalah arah TULIS: menyimpan untuk lokasi B tidak
     * boleh mengubah baris yang sudah ada di lokasi A. Arah baca punya testnya
     * sendiri di bawah, karena gejalanya berbeda dan tidak bisa dilihat oleh
     * assertion yang sama.
     *
     * Karena kedua tenant memakai nomor jabatan yang SENGAJA sama, kegagalan
     * "menulis ke lokasi yang salah" di sini tidak terlihat sebagai error --
     * ia terlihat sebagai "baris di lokasi A berubah nilainya". Itu yang
     * diperiksa di sini.
     */
    #[Test]
    public function satu_lokasi_tidak_menimpa_hak_akses_lokasi_lain(): void
    {
        $lokasiB = $this->buatLokasiTerpisah();

        /*
         * Jabatan dengan NOMOR YANG SAMA di kedua lokasi, dan itu disengaja.
         *
         * Nomor diambil dari lokasi A karena di situ `AUTO_INCREMENT` sudah
         * menentukan sendiri; lokasi B dipaksa memakai nomor itu juga. Kalau
         * nomor keduanya dibiarkan berbeda, test ini jadi jauh lebih lemah:
         * perubahan yang bocor ke lokasi lain akan terlihat sebagai "baris
         * tidak ditemukan", bukan sebagai "baris di lokasi lain ikut berubah".
         */
        $jabatanA = $this->jabatanId('Uji-Bendahara');
        $jabatanB = $this->jabatanIdDi($lokasiB, 'Bendahara', $jabatanA);

        $this->assertSame(
            $jabatanA,
            $jabatanB,
            'Dua lokasi harus punya nomor jabatan yang sama supaya kebocoran '
            .'antar lokasi tidak bisa terlihat sebagai "baris yang berbeda".',
        );

        $this->actingAsSuperadmin();

        $this->putJson($this->url("/{$jabatanA}"), ['menu_keys' => ['master.pelanggan']])
            ->assertOk();

        $this->putJson($this->urlLokasi($lokasiB, "/{$jabatanB}"), ['menu_keys' => ['pelaporan']])
            ->assertOk();

        $this->assertSame(
            ['master.pelanggan'],
            $this->tersimpan($this->tenant, $jabatanA),
            'Lokasi A berubah padahal yang disimpan untuk lokasi B.',
        );

        $this->assertSame(
            ['pelaporan'],
            $this->tersimpan($lokasiB, $jabatanB),
            'Lokasi B tidak ikut tersimpan seperti seharusnya.',
        );
    }

    /**
     * Membaca lokasi B tidak boleh melaporkan isi lokasi A.
     *
     * Dipisah dari test di atas karena gejalanya berbeda. Di sana, kebocoran
     * terlihat sebagai "baris yang tertimpa". Di sini, kalau `runFor()` tidak
     * benar-benar pindah koneksi, `GET` lokasi B akan membalas DENGAN DATA
     * LOKASI A -- dan karena nomor jabatannya sama, respons itu terlihat
     * benar.
     *
     * Yang membuatnya bocor: `MenuAccessService` menyimpan cache
     * `$allowedCache` per-request, dan kuncinya HANYA `jabatan_id`. Dua lokasi
     * yang punya jabatan nomor sama akan berbagi satu entri cache di dalam
     * satu request. Test ini menjalankan dua request terpisah seperti user
     * sungguhan (buka lokasi A, lalu buka lokasi B), jadi setiap request punya
     * cache baru -- dan itu justru yang sedang diuji: apakah `runFor()`
     * memindahkan koneksinya dengan benar.
     *
     * `assertOk()` dipakai pada keduanya: kegagalan di sini harus berarti
     * "halaman Hak Akses lokasi B tidak bisa dibuka", bukan "halaman gagal
     * memuat karena alasan lain". 503 dan 404 punya testnya masing-masing.
     */
    #[Test]
    public function membaca_satu_lokasi_tidak_menampilkan_data_lokasi_lain(): void
    {
        $lokasiB = $this->buatLokasiTerpisah();

        $jabatanA = $this->jabatanId('Uji-Bendahara');
        $jabatanB = $this->jabatanIdDi($lokasiB, 'Jabatan KHUSUS Lokasi B');

        $this->actingAsSuperadmin();

        $this->putJson($this->url("/{$jabatanA}"), ['menu_keys' => ['master.pelanggan']])
            ->assertOk();

        // Buka lokasi A DULUAN, supaya kalau ada cache yang bertahan, cache itu
        // sudah terisi oleh lokasi A sebelum lokasi B dibaca.
        $this->getJson($this->url())->assertOk();

        $nama = collect($this->getJson($this->urlLokasi($lokasiB))->assertOk()->json('data'))
            ->pluck('nama')
            ->all();

        $this->assertContains('Jabatan KHUSUS Lokasi B', $nama);
        $this->assertNotContains(
            'Uji-Bendahara',
            $nama,
            'Lokasi B menampilkan jabatan milik lokasi A -- berarti pembacaan tidak '
            .'pindah ke database lokasi yang benar.',
        );

        // Dan jabatan di lokasi B harus tampil sebagai "belum pernah
        // dikonfigurasi", bukan meniru centangan lokasi A.
        $terpilih = collect($this->getJson($this->urlLokasi($lokasiB))->assertOk()->json('data'))
            ->firstWhere('id', $jabatanB);

        $this->assertFalse(
            $terpilih['configured'],
            'Jabatan di lokasi B terbaca sudah dikonfigurasi, padahal tabelnya '
            .'kosong. Datanya datang dari lokasi A.',
        );
    }

    /**
     * Reset di satu lokasi tidak boleh mengosongkan lokasi lain.
     *
     * Arah ketiga yang belum tersentuh, dan paling mudah luput karena
     * `reset()` menghapus baris -- jadi efeknya bukan "tertimpa nilai lain",
     * tapi "berkas di lokasi lain ikut hilang".
     *
     * Kalau ini bocor, gejalanya lebih buruk dari yang terlihat di UI: di
     * lokasi A, jabatan kembali ke "semua menu boleh" tanpa ada yang menekan
     * apa pun di sana. Terbatasi di lokasi A saja, admin tidak akan pernah
     * tahu bagian mana yang sebenarnya perlu diperbaiki.
     */
    #[Test]
    public function reset_satu_lokasi_tidak_mengosongkan_lokasi_lain(): void
    {
        $lokasiB = $this->buatLokasiTerpisah();

        $jabatanA = $this->jabatanId('Uji-Bendahara');
        $jabatanB = $this->jabatanIdDi($lokasiB, 'Bendahara', $jabatanA);

        $this->actingAsSuperadmin();

        $this->putJson($this->url("/{$jabatanA}"), ['menu_keys' => ['master.pelanggan']])
            ->assertOk();

        $this->putJson($this->urlLokasi($lokasiB, "/{$jabatanB}"), ['menu_keys' => ['pelaporan']])
            ->assertOk();

        $this->postJson($this->url("/{$jabatanA}/reset"))->assertOk();

        $this->assertNull($this->tersimpan($this->tenant, $jabatanA));

        $this->assertSame(
            ['pelaporan'],
            $this->tersimpan($lokasiB, $jabatanB),
            'Reset di lokasi A ikut menghapus hak akses lokasi B.',
        );
    }

    /**
     * Jabatan yang tidak ada di lokasi B harus 404, bukan diam-diam menulis
     * ke tabel yang sama dengan milik lokasi A.
     *
     * Dua lokasi memang bisa punya jabatan dengan `id` yang sama -- dan itulah
     * justru bahayanya: `jabatan_id` berimpit antar lokasi, jadi `id` saja
     * tidak pernah menentukan jabatan milik siapa. Kalau `JABATAN_NOT_FOUND`
     * dicheckSETELAH penulisan, `PUT` untuk id yang tidak ada di lokasi B akan
     * diam-diam mengubah jabatan dengan id sama di lokasi A.
     *
     * Dipakai `999999` yang dijamin tidak ada di database uji mana pun.
     */
    #[Test]
    public function jabatan_yang_hanya_ada_di_lokasi_lain_tidak_bisa_disentuh(): void
    {
        $lokasiB = $this->buatLokasiTerpisah();

        $jabatanA = $this->jabatanId('Uji-Bendahara');

        $this->actingAsSuperadmin();

        $this->putJson($this->url("/{$jabatanA}"), ['menu_keys' => ['master.pelanggan']])
            ->assertOk();

        $this->putJson($this->urlLokasi($lokasiB, '/999999'), ['menu_keys' => ['pelaporan']])
            ->assertNotFound()
            ->assertJsonPath('code', 'JABATAN_NOT_FOUND');

        // Dan lokasi A tetap utuh.
        $this->assertSame(
            ['master.pelanggan'],
            $this->tersimpan($this->tenant, $jabatanA),
            'Permintaan untuk jabatan yang tidak ada di lokasi B mengubah lokasi A.',
        );
    }

    // ================================================================
    // Menambah jabatan dari panel pusat
    // ================================================================

    /**
     * Jabatan baru harus muncul di daftar yang sama, dengan bentuk yang sama.
     *
     * Bentuk respons `store()` sengaja dibuat sama dengan elemen di `index()`
     * supaya frontend bisa langsung menempelkannya ke daftar tanpa memuat ulang
     * lokasi. Kalau bentuknya berbeda, satu field yang lupa dipindahkan akan
     * tampil sebagai jabatan dengan daftar menu kosong -- dan itu terlihat
     * seperti jabatan yang memang dibatasi, bukan seperti bug.
     */
    #[Test]
    public function jabatan_baru_muncul_di_daftar_dengan_bentuk_yang_sama(): void
    {
        $this->actingAsSuperadmin();

        $res = $this->postJson($this->url(), ['nama_jabatan' => 'Uji-Petugas Sampah'])
            ->assertCreated()
            ->assertJsonPath('data.nama', 'Uji-Petugas Sampah');

        $idBaru = (int) $res->json('data.id');

        $dariDaftar = collect($this->getJson($this->url())->assertOk()->json('data'))
            ->firstWhere('id', $idBaru);

        $this->assertNotNull($dariDaftar, 'jabatan baru harus muncul di daftar index');

        $this->assertSame(
            array_keys($res->json('data')),
            array_keys($dariDaftar),
            'bentuk dari store() harus sama dengan elemen di index()'
        );
    }

    /**
     * Jabatan baru WAJIB mulai dengan semua menu terbuka.
     *
     * Kalau tidak, admin lokasi yang kebetulan memakai jabatan itu langsung
     * terkunci dari menu yang tidak pernah dicentang siapa pun -- dan yang
     * menguncinya bukan sistemnya, tapi request pembuatan jabatan.
     */
    #[Test]
    public function jabatan_baru_mulai_dengan_semua_menu_terbuka(): void
    {
        $this->actingAsSuperadmin();

        $res = $this->postJson($this->url(), ['nama_jabatan' => 'Uji-Baru'])->assertCreated();

        $this->assertFalse(
            $res->json('data.configured'),
            'belum boleh berstatus sudah dikonfigurasi'
        );

        /*
         * Dibilang dari server, bukan dari respons yang sama. Mengarang angka di
         * respons tidak akan pernah memastikan tabelnya memang kosong -- dan
         * tabel kosong itu yang dibaca `allowedKeysFor()`.
         */
        $this->assertNull(
            $this->tersimpan($this->tenant, (int) $res->json('data.id')),
            'tabel jabatan_menu_access harus tetap kosong untuk jabatan baru'
        );
    }

    /**
     * Duplikat ditolak, termasuk yang beda huruf besar-kecil.
     *
     * `findByName()` membandingkan dengan `LOWER()`, jadi "bendahara" dan
     * "Bendahara" itu satu jabatan. Kalau keduanya diterima, yang kedua
     * tersimpan sebagai baris tersendiri, dan user yang ditugaskan ke salah
     * satunya diam-diam tidak ikut aturan yang disetel di yang lain.
     */
    #[Test]
    public function jabatan_dengan_nama_yang_sudah_ada_ditolak(): void
    {
        $this->jabatanId('Bendahara');

        $this->actingAsSuperadmin();

        $this->postJson($this->url(), ['nama_jabatan' => 'Uji-Bendahara'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'JABATAN_DUPLIKAT');

        $this->postJson($this->url(), ['nama_jabatan' => 'uji-bendahara'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'JABATAN_DUPLIKAT');

        $jumlah = app(TenantManager::class)->runFor(
            $this->tenant,
            fn () => Jabatan::query()->where('nama_jabatan', 'like', '%bendahara%')->count()
        );

        $this->assertSame(1, $jumlah, 'hanya boleh ada satu baris untuk nama itu');
    }

    #[Test]
    public function nama_jabatan_wajib_diisi(): void
    {
        $this->actingAsSuperadmin();

        // `required` menolak string kosong DAN string yang isinya cuma spasi,
        // karena TrimStrings menyala lebih dulu di middleware Laravel.
        foreach ([['nama_jabatan' => ''], ['nama_jabatan' => '    '], []] as $salah) {
            $this->postJson($this->url(), $salah)->assertStatus(422);
        }
    }

    /**
     * Jabatan milik LOKASI, jadi menambahkannya di satu lokasi tidak boleh
     * menambahkannya di lokasi lain -- dan lokasi lain tetap boleh memakai
     * nama yang sama persis.
     */
    #[Test]
    public function jabatan_ditambahkan_di_satu_lokasi_tidak_muncul_di_lokasi_lain(): void
    {
        $lain = $this->buatLokasiTerpisah();

        $this->actingAsSuperadmin();

        $this->postJson($this->url(), ['nama_jabatan' => 'Uji-Khusus A'])->assertCreated();

        $namaDiLain = collect(
            $this->getJson($this->urlLokasi($lain))->assertOk()->json('data')
        )->pluck('nama');

        $this->assertFalse(
            $namaDiLain->contains('Uji-Khusus A'),
            'jabatan dari lokasi A tidak boleh bocor ke lokasi B'
        );

        // Kontrol: nama yang sama TIDAK bentrok antar lokasi.
        $this->postJson($this->urlLokasi($lain), ['nama_jabatan' => 'Uji-Khusus A'])
            ->assertCreated();
    }

    /**
     * Hanya superadmin yang boleh membuat jabatan.
     *
     * Admin lokasi tidak cukup: ia akan menulis ke `jabatans` milik lokasi
     * orang lain, yang tidak pernah terlihat di aplikasi lokasi yang ia pakai.
     */
    #[Test]
    public function admin_lokasi_tidak_bisa_menambah_jabatan(): void
    {
        Sanctum::actingAs($this->adminLokasi($this->prefix.'lokasi@test.local'), ['*'], 'sanctum');

        $this->postJson($this->url(), ['nama_jabatan' => 'Uji-Tidak Boleh'])
            ->assertForbidden();

        $ada = app(TenantManager::class)->runFor(
            $this->tenant,
            fn () => Jabatan::query()->where('nama_jabatan', 'Uji-Tidak Boleh')->exists()
        );

        $this->assertFalse($ada, 'tabel jabatans tidak boleh tersentuh');
    }

    #[Test]
    public function tambah_jabatan_di_lokasi_yang_tidak_ada_menghasilkan_404(): void
    {
        $this->actingAsSuperadmin();

        $this->postJson('/api/central/locations/tidak-ada-sama-sekali/menu-access', [
            'nama_jabatan' => 'Uji-Apapun',
        ])->assertNotFound();
    }

    /**
     * Daftar jabatan harus menyebut role apa saja yang memakainya.
     *
     * Tanpa kolom ini, panel pusat tidak bisa memberi tahu superadmin bahwa
     * jabatan Caters dan Teknisi tidak ikut aturan jabatan: centangnya akan
     * tersimpan dan TIDAK mengubah apa pun, karena `enforce_roles` hanya berisi
     * `admin`. Superadmin akan menyimpulkan centangnya gagal, lalu mencoba
     * menyimpannya berulang kali.
     */
    #[Test]
    public function daftar_jabatan_menyebut_role_yang_memakainya(): void
    {
        $jabatanAdmin = $this->jabatanId('Direktur Uji');

        // Dua jabatan: satu dipakai user admin, satu dipakai user teknisi.
        $jabatanTeknisi = Jabatan::create(['nama_jabatan' => 'Uji-Teknisi'])->id;

        app(TenantManager::class)->runFor($this->tenant, function () use ($jabatanAdmin, $jabatanTeknisi) {
            User::query()->delete();

            User::query()->create([
                'name' => 'Uji Admin',
                'email' => $this->prefix.'a@test.local',
                'password' => 'x',
                'role' => 'admin',
                'jabatan_id' => $jabatanAdmin,
            ]);

            User::query()->create([
                'name' => 'Uji Teknisi',
                'email' => $this->prefix.'b@test.local',
                'password' => 'x',
                'role' => 'teknisi',
                'jabatan_id' => $jabatanTeknisi,
            ]);
        });

        $this->actingAsSuperadmin();

        $data = collect($this->getJson($this->url())->assertOk()->json('data'));

        $this->assertSame(['admin'], $data->firstWhere('id', $jabatanAdmin)['roles']);

        $this->assertSame(
            ['teknisi'],
            $data->firstWhere('id', $jabatanTeknisi)['roles'],
            'jabatan role non-admin harus terdeteksi, supaya halaman bisa memperingatkan'
        );

        // Kontrol: jabatan tanpa user harus roles kosong, bukan null -- null akan
        // membuat frontend menganggapnya "dipakai role lain".
        $tanpaUser = Jabatan::create(['nama_jabatan' => 'Uji-Kosong'])->id;

        $kosong = collect($this->getJson($this->url())->assertOk()->json('data'))
            ->firstWhere('id', $tanpaUser);

        $this->assertSame([], $kosong['roles']);
    }
}
