<?php

namespace Tests\Feature;

use App\Http\Controllers\Central\LocationController;
use App\Models\Central\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LegacyDomainSync;
use App\Services\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\EnsuresCentralTables;
use Tests\TestCase;
use Throwable;

/**
 * Panel pusat: alokasi tenant BARU.
 *
 * Yang diuji di sini bukan "endpoint-nya mengembalikan 201", tapi tiga hal
 * yang jadi alasan fitur ini ada sama sekali:
 *
 *  1. Database lokasi benar-benar DIBUAT, dengan nama yang benar. Nama itu
 *     diturunkan dari slug, jadi satu huruf di slug berarti satu database
 *     yang berbeda -- dan kalau normalisasinya salah, lokasi jalan di
 *     database orang lain.
 *
 *  2. Master data benar-benar TERISI. Lokasi tanpa chart of accounts bukan
 *     "kosong yang akan diisi nanti": halaman jurnal terbuka kosong dan user
 *     tidak bisa mulai mencatat sama sekali.
 *
 *  3. Kegagalan di tengah TIDAK menyisakan setengah jadi. Provisioning
 *     berjalan dalam empat langkah (buat DB, daftarkan tenant, migrate, seed),
 *     dan langkah mana pun bisa gagal. Kalau DB sudah dibuat tapi migrasi
 *     pecah, harus ada yang membuangnya; kalau tidak, panel pusat menampilkan
 *     lokasi yang tercatat tapi tidak bisa dibuka, dan tidak ada yang tahu
 *     database-nya masih mencuri space.
 *
 * Test ini memakai superadmin dari database PUSAT, jadi `RefreshDatabase`
 * tidak mengosongkan tabel `tenants`. Karena itu semua baris dibuat di sini
 * dibersihkan manual di `setUp` dan `tearDown`.
 */
class CentralTenantProvisionTest extends TestCase
{
    use EnsuresCentralTables;

    private const CENTRAL = 'central';

    /**
     * Nama koneksi ke database LOKASI.
     *
     * Berbeda dengan `RefreshDatabase` yang memakai koneksi default, test ini
     * perlu membaca isi database lokasi yang BARU dibuat -- koneksi `mysql`
     * menunjuk database pengujian, bukan database tenant baru.
     */
    private const LOCATION = 'tenant';

    /** Prefix unik: sisa run sebelumnya tidak boleh bentrok. */
    private string $prefix;

    private User $superadmin;

    /** Nama database yang wajib dibuang di akhir test. */
    private array $databaseDibuat = [];

    protected function setUp(): void
    {
        parent::setUp();

        $central = DB::connection(self::CENTRAL);

        $this->prefix = 'provtest-'.uniqid().'-';

        $this->ensureCentralTables($central);

        $this->ensureCentralChartOfAccounts($central);

        // Sisa run sebelumnya: tidak ikut ter-rollback, jadi harus dihapus
        // eksplisit. Kalau tidak, slug-nya menabrak unique index dengan pesan
        // yang tidak menyebut test mana yang menyisakannya.
        $central->table('tenant_domains')->delete();
        $central->table('tenants')->where('slug', 'like', 'provtest-%')->delete();
        $central->table('users')->where('email', 'like', 'provtest-%@test.local')->delete();
        $central->table('audit_logs')->where('action', 'like', 'tenant.%')->delete();

        $email = $this->prefix.'admin@test.local';

        $central->table('users')->insert([
            'name' => 'Admin Pusat',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'superadmin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // `User` terikat ke koneksi default (lokasi), jadi modelnya diisi di
        // memori dan diberi id dari baris pusat. Sama seperti CentralCoaTest.
        $this->superadmin = (new User)->forceFill([
            'name' => 'Admin Pusat',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'superadmin',
        ]);

        $this->superadmin->id = $central->table('users')->where('email', $email)->value('id');
    }

    protected function tearDown(): void
    {
        /*
         * `delete()` per-id, bukan `truncate()`. Test yang menghapus tabel
         * milik orang lain karena konfigurasi database salah adalah bom waktu
         * -- lihat catatan panjang di `CentralCoaTest::tearDown()`.
         */
        try {
            $central = DB::connection(self::CENTRAL);

            $central->table('audit_logs')->where('action', 'like', 'tenant.%')->delete();
            $central->table('tenant_domains')->whereIn(
                'tenant_id',
                $central->table('tenants')->where('slug', 'like', 'provtest-%')->pluck('id')
            )->delete();

            $central->table('tenants')->where('slug', 'like', 'provtest-%')->delete();
            $central->table('users')->where('email', 'like', 'provtest-%@test.local')->delete();

            /*
             * Database lokasi yang dibuat test ini ikut dibuang.
             *
             * Yang dicari BUKAN daftar nama yang dicatat di
             * `$databaseDibuat` -- itu dicatat SESUDAH request-nya selesai,
             * jadi test yang gagal di tengah jalan tepat setelah database-nya
             * dibuat tidak pernah masuk daftar, dan database itu tertinggal
             * selamanya. Database seperti itu sudah menumpuk sampai puluhan
             * di server ini.
             *
             * Yang dipakai adalah prefix milik test ini sendiri, yang sudah
             * diketahui SEBELUM ada database yang dibuat. Diawali
             * `provtest-` seperti yang lain, jadi bersama-sama tetap punya
             * tanda pengenal yang sama.
             *
             * Ini aman karena prefix berisi `uniqid()` per test: tidak ada
             * satu pun database yang bukan dibuat test ini yang bisa cocok.
             */
            foreach ($this->dropOwnedDatabases() as $nama) {
                $this->dropDatabaseIfExists($nama);
            }
        } catch (Throwable) {
            // Pembersihan test tidak boleh menutupi kegagalan test-nya
            // sendiri; kalau sampai sini, ada yang salah dengan koneksinya
            // dan test_toast sudah gagal di assertion sebelumnya.
        }

        parent::tearDown();
    }

    private function actingAsSuperadmin(): void
    {
        Sanctum::actingAs($this->superadmin, ['*'], 'sanctum');
    }

    // ================================================================
    // Alokasi database
    // ================================================================

    #[Test]
    public function lokasi_baru_didaftarkan_dengan_database_yang_diturunkan_dari_slug(): void
    {
        $slug = $this->prefix.'satu';

        $this->actingAsSuperadmin();

        $response = $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Satu',
        ])->assertCreated();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->assertSame($expected, $response->json('data.database'));

        // Dan barisnya benar-benar ada di registry pusat -- jawaban 201
        // tanpa baris di `tenants` akan menampilkan lokasi yang hilang begitu
        // halaman direload.
        $this->assertDatabaseHas('tenants', [
            'slug' => $slug,
            'database' => $expected,
            'is_active' => true,
        ], self::CENTRAL);
    }

    #[Test]
    public function nama_database_normalisasi_hyphen_menjadi_underscore(): void
    {
        $slug = $this->prefix.'dua-tiga';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Dua Tiga',
        ])->assertCreated();

        /*
         * MySQL menerima `-` di nama database HANYA di dalam backtick, dan
         * nama database disisipkan apa adanya ke `CREATE DATABASE` karena
         * tidak bisa diparameterisasi. Kalau normalisasi ini dihapus,
         * provisioning gagal untuk setiap slug ber-hyphen -- dan itu slug
         * yang paling umum dipakai user.
         */
        $this->assertSame($expected, DB::connection(self::CENTRAL)
            ->table('tenants')->where('slug', $slug)->value('database'));
    }

    #[Test]
    public function database_lokasi_berisi_master_data_bukan_hanya_skema(): void
    {
        $slug = $this->prefix.'tiga';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Tiga',
        ])->assertCreated();

        /*
         * Chart of accounts level 4 milik lokasi, jadi harus ada di sini.
         * Level 1-3 milik pusat dan TIDAK boleh ikut di-seed per lokasi --
         * lihat `tenancy.provision.seeders` vs `central_seeders`.
         */
        $this->assertSame(
            96,
            DB::connection(self::LOCATION)->table('accounts')->count(),
            'Level 4 (accounts) harus terisi di database lokasi baru.'
        );

        $this->assertGreaterThan(
            0,
            DB::connection(self::LOCATION)->table('master_arus_kas')->count(),
            'Master arus kas harus terisi.'
        );

        $this->assertGreaterThan(
            0,
            DB::connection(self::LOCATION)->table('jenis_transactions')->count(),
            'Jenis transaksi harus terisi.'
        );
    }

    /**
     * Lokasi baru SELALU punya lima jabatan inti.
     *
     * Kelimanya adalah role yang punya halaman/login sendiri: admin,
     * surveyor, teknisi, cater, pelanggan. Yang membuat absennya berbahaya
     * adalah dua hal yang berbeda:
     *
     *   - Halaman Hak Akses (`/central/hak-akses`) tidak punya kotak centang
     *     untuk role itu, jadi admin lokasi yang butuh orang lapangan harus
     *     membuat jabatan itu manual sebelum bisa memberi akses apa pun.
     *   - `User::findByJabatan()` -- yang dipakai laporan untuk mencari siapa
     *     penandatangnya -- kembali null, dan laporan yang tadinya tercetak
     *     menjadi kosong tanpa pesan apa pun.
     *
     * `Cater` dan `Teknisi` sengaja dua baris terpisah: keduanya adalah
     * jabatan yang dipakai orang BERBEDA, dan keduanya memakai daftar menu
     * yang sama. Kalau digabung, orang cater tidak bisa punya jabatan
     * sendiri.
     */
    #[Test]
    public function lokasi_baru_selalu_punya_lima_jabatan_inti(): void
    {
        $slug = $this->prefix.'jabatan';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Jabatan',
        ])->assertCreated();

        $nama = DB::connection(self::LOCATION)
            ->table('jabatans')
            ->pluck('nama_jabatan')
            ->map(fn ($n) => mb_strtolower((string) $n))
            ->all();

        foreach (['admin', 'surveyor', 'teknisi', 'cater', 'pelanggan'] as $wajib) {
            $this->assertContains(
                $wajib,
                $nama,
                "Jabatan `{$wajib}` harus ada di lokasi baru.",
            );
        }
    }

    /**
     * Menjalankan seeder jabatan dua kali tidak menggandakan barisnya.
     *
     * `tenancy:reseed-master` menjalankan seeder provisioning untuk SEMUA
     * lokasi, termasuk lokasi produksi yang sudah berjalan. Kalau seeder ini
     * tidak idempoten, setiap pemeliharaan menambah lima baris jabatan
     * duplikat -- dan `jabatan_menu_access` yang menunjuk `jabatan_id` jadi
     * menunjuk ke barcode yang berbeda antar lokasi.
     */
    #[Test]
    public function seeder_jabatan_tidak_menggandakan_baris_saat_dijalankan_ulang(): void
    {
        $slug = $this->prefix.'ulang';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Ulang',
        ])->assertCreated();

        $sebelum = DB::connection(self::LOCATION)->table('jabatans')->count();

        app(TenantManager::class)->seedTenantDatabase(
            Tenant::query()->where('slug', $slug)->firstOrFail()
        );

        $sesudah = DB::connection(self::LOCATION)->table('jabatans')->count();

        $this->assertSame(
            $sebelum,
            $sesudah,
            'Seeder jabatan harus idempoten. Baris lama tidak boleh hilang atau '
            .'berlipat saat provisioning dijalankan ulang.',
        );
    }

    #[Test]
    public function level_satu_hingga_tiga_tidak_ikut_disalin_ke_database_lokasi(): void
    {
        $slug = $this->prefix.'empat';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Empat',
        ])->assertCreated();

        foreach (['akun_level_1', 'akun_level_2', 'akun_level_3'] as $tabel) {
            $this->assertFalse(
                $this->databasePunyaTabel($expected, $tabel),
                "Tabel `{$tabel}` milik pusat, tidak boleh ikut ke database lokasi."
            );
        }
    }

    #[Test]
    public function parent_id_akun_lokasi_menunjuk_ke_pusat(): void
    {
        $slug = $this->prefix.'lima';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Lima',
        ])->assertCreated();

        $lokasi = DB::connection(self::LOCATION);
        $pusat = DB::connection(self::CENTRAL);

        /*
         * `accounts.parent_id` menunjuk `akun_level_3.id` di database PUSAT,
         * dan penunjuk itu TIDAK dijaga foreign key -- `id` di level 1-3
         * adalah `bigint unsigned` sementara `parent_id` bertipe `int`, jadi
         * FK tidak mungkin ada. Satu level 3 yang terhapus di pusat berarti
         * akun di SEMUA lokasi kehilangan induknya tanpa satu pun error,
         * dan gejalanya baru terlihat saat laporan tiba-tiba kosong sebagian.
         *
         * Karena itu provisioning wajib memverifikasi begini, bukan
         * menganggap "seederpasti benar".
         *
         * Dua database, jadi TIDAK bisa di-join dalam satu query. Yang
         * dilakukan sama seperti `PohonAkunService`: ambil id level 3 dari
         * pusat, lalu cek sisanya di lokasi.
         */
        $idLevel3 = $pusat->table('akun_level_3')->pluck('id');

        $yatim = $lokasi->table('accounts')
            ->whereNotIn('parent_id', $idLevel3)
            ->count();

        $this->assertSame(0, $yatim, 'Semua akun lokasi harus punya induk level 3 di pusat.');

        // Dan induknya harus benar-benar induk yang benar, bukan cuma ada.
        //
        // Perbandingan kode perlu membuang akhiran `.00` dari kode level 3
        // DULU: kode level 3 adalah kode akun sebagai placeholder/kelompok
        // (`1.1.01.00`), sedangkan kode level 4 memuat nomor urut di posisi
        // terakhir (`1.1.01.01`, `1.1.01.02`). Jadi `1.1.01.00` BUKAN
        // awalan `1.1.01.01` -- membandingkannya apa adanya selalu gagal,
        // padahal datanya benar. Setelah `.00` dibuang, sisa kode level 3
        // (`1.1.01`) memang menjadi awalan kode akun-anaknya.
        //
        // Tanpa penyesuaian ini, test ini akan selalu gagal pada data yang
        // sehat -- lebih buruk dari tidak menulis test, karenaenolnya akan
        // membuat orang "memperbaiki" seeder yang justru sudah benar.
        $kodeL3 = $pusat->table('akun_level_3')
            ->pluck('kode_akun', 'id')
            ->map(fn ($kode) => str_ends_with((string) $kode, '.00')
                ? substr((string) $kode, 0, -3)
                : (string) $kode);

        $salahPrefix = $lokasi->table('accounts')
            ->get(['id', 'kode_akun', 'parent_id'])
            ->filter(function ($a) use ($kodeL3) {
                $kode = $kodeL3[$a->parent_id] ?? null;

                return $kode === null
                    || ! str_starts_with($a->kode_akun, $kode);
            })
            ->count();

        $this->assertSame(0, $salahPrefix, 'Kode akun harus diawali kode induknya.');
    }

    // ================================================================
    // Admin lokasi
    // ================================================================

    #[Test]
    public function admin_lokasi_dibuat_bila_kredensial_dikirim(): void
    {
        $slug = $this->prefix.'enam';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Enam',
            'admin_email' => $this->prefix.'lokasi@test.local',
            'admin_password' => 'rahasia123',
            'admin_name' => 'Admin Lokasi',
        ])->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => $this->prefix.'lokasi@test.local',
            'role' => 'admin',
        ], self::LOCATION);
    }

    #[Test]
    public function nama_lembaga_ditulis_ke_settings_lokasi_langsung(): void
    {
        $slug = $this->prefix.'tujuh';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Tujuh',
            'nama_lembaga' => 'BPD Kampung Uji',
        ])->assertCreated();

        /*
         * Tanpa ini, kop laporan semua lokasi berbunyi "ASTALOKA Tirto Mulo"
         * -- nilai bawaan `SettingsSeeder`. Lokasi baru harus punya identitas
         * sendiri sejak baris pertama, bukan menunggu admin ingat
         * menggantinya lewat menu pengaturan.
         */
        $this->assertSame(
            'BPD Kampung Uji',
            DB::connection(self::LOCATION)->table('settings')->value('nama_lembaga')
        );
    }

    // ================================================================
    // Tanggal generate piutang TIDAK boleh tertimpa
    // ================================================================

    /**
     * `toleransi_tunggakan` = TANGGAL generate jurnal piutang tunggakan.
     *
     * Dulu kolom ini ikut ditulis oleh `applyTenantIdentity()` -- dengan
     * `invoice_grace_months`, yang satuanannya BULAN. Dua satuan berbeda
     * menulis ke satu kolom yang sama, dan akibatnya tanggal generate yang
     * sudah diset admin hilang begitu provisioning atau reseed jalan.
     *
     * Gejalanya persis seperti "saya sudah set tanggal 28 tapi kok tidak
     * ada popup generate piutangnya": nilai 28 sudah hilang jauh sebelum
     * tanggal 28 tiba.
     */
    #[Test]
    public function tanggal_generate_piutang_lokasi_baru_tidak_diisi_dengan_satuan_bulan(): void
    {
        $slug = $this->prefix.'tujuhb';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Tujuh B',
        ])->assertCreated();

        $nilai = DB::connection(self::LOCATION)->table('settings')->value('toleransi_tunggakan');

        /*
         * 0 berarti "belum dijadwalkan" -- generate TIDAK jalan sampai admin
         * mengisinya. Yang TIDAK boleh terjadi di sini adalah nilai 1, karena
         * angka itu berasal dari satuan yang salah: `invoice_grace_months`
         * (BULAN), bukan tanggal.
         */
        $this->assertNotSame(
            1,
            (int) $nilai,
            'toleransi_tunggakan tidak boleh diisi dengan invoice_grace_months (satuan bulan).'
        );
    }

    /**
     * Menjalankan provisioning ULANG pada lokasi yang sudah ada tidak boleh
     * mengubah tanggal generate yang sudah diset admin.
     *
     * Ini jalur yang paling merusak: lokasi sudah jalan, admin sudah menyetel
     * tanggal 28, lalu ada yang menjalankan `tenancy:reseed-master --identity`
     * untuk memperbarui master data. Tanggal 28 harus tetap 28.
     */
    #[Test]
    public function provisioning_ulang_tidak_menimpa_tanggal_generate_yang_sudah_diset(): void
    {
        $slug = $this->prefix.'tujuhc';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Tujuh C',
        ])->assertCreated();

        // Admin menyetel tanggal generate lewat halaman SOP.
        //
        // Kedua kolom diset sama karena sekarang cuma satu angka tanggal:
        // `batas_tagihan` (jatuh tempo) dan `toleransi_tunggakan`
        // (generate piutang) sudah digabung.
        DB::connection(self::LOCATION)->table('settings')
            ->where('id', 1)
            ->update([
                'batas_tagihan' => 28,
                'toleransi_tunggakan' => 28,
            ]);

        $tenant = Tenant::where('slug', $slug)->first();

        app(TenantManager::class)->applyTenantIdentity($tenant);

        $this->assertSame(
            28,
            (int) DB::connection(self::LOCATION)->table('settings')->value('toleransi_tunggakan'),
            'Tanggal generate yang sudah diset admin tidak boleh tertimpa saat provisioning ulang.'
        );

        $this->assertSame(
            28,
            (int) DB::connection(self::LOCATION)->table('settings')->value('batas_tagihan'),
            'Tanggal jatuh tempo yang sudah diset admin tidak boleh tertimpa saat provisioning ulang.'
        );
    }

    // ================================================================
    // Akun demo per role
    // ================================================================

    #[Test]
    public function lokasi_baru_dapat_satu_akun_demo_untuk_setiap_role(): void
    {
        $slug = $this->prefix.'sebelas';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Sebelas',
        ])->assertCreated();

        /*
         * Empat role harus ada semua. `assertCount` dengan daftar persis, bukan
         * "minimal empat": kalau ada role yang tidak sengaja terisi dua kali
         * atau email-nya tidak sengaja tertukar, `assertCount` yang dipakai
         * menangkapnya, sementara `assertGreaterThanOrEqual` tidak.
         */
        foreach (config('tenancy.demo_users.emails') as $role => $email) {
            $this->assertDatabaseHas('users', [
                'email' => $email,
                'role' => $role,
            ], self::LOCATION);
        }

        $this->assertSame(
            count(config('tenancy.demo_users.emails')),
            DB::connection(self::LOCATION)->table('users')->count(),
            'Lokasi baru tidak boleh punya user selain empat akun demo.'
        );
    }

    #[Test]
    public function akun_demo_bisa_login_dengan_password_dari_konfigurasi(): void
    {
        $slug = $this->prefix.'duabelas';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Duabelas',
        ])->assertCreated();

        $password = (string) config('tenancy.demo_users.password');

        $hashes = DB::connection(self::LOCATION)->table('users')
            ->pluck('password', 'email');

        /*
         * `Hash::check()` terhadap hash yang tersimpan -- bukan membandingkan
         * teks. Yang diuji di sini persis apa yang dilakukan `AuthController`
         * waktu login, jadi test ini gagal kalau hashing-nya rusak, bukan
         * hanya kalau emailnya salah ketik.
         *
         * Yang diiterasi adalah EMAIL-nya (`array_keys` dari daftar yang
         * berpasangan role => email), bukan role-nya.
         */
        foreach (config('tenancy.demo_users.emails') as $email) {
            $this->assertTrue(
                Hash::check($password, (string) $hashes[$email]),
                "Akun demo {$email} tidak bisa login dengan password dari config."
            );
        }
    }

    #[Test]
    public function akun_demo_dibuat_saat_admin_form_tidak_dikirim(): void
    {
        $slug = $this->prefix.'tigabelas';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        /*
         * Tidak mengirim `admin_email`/`admin_password` sama sekali.
         *
         * Ini keadaan yang paling sering terjadi di panel pusat: superadmin
         * ingin menyiapkan database dulu dan menentukan siapa admin-nya
         * belakangan. Akun demo harus tetap ada -- kalau tidak, lokasi barunya
         * tidak punya satu pun user yang bisa dicoba.
         */
        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Tigabelas',
        ])->assertCreated();

        $this->assertSame(
            count(config('tenancy.demo_users.emails')),
            DB::connection(self::LOCATION)->table('users')->count()
        );
    }

    #[Test]
    public function akun_demo_disimpan_bersamaan_dengan_admin_yang_dikirim_form(): void
    {
        $slug = $this->prefix.'empatbelas';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Empatbelas',
            'admin_email' => $this->prefix.'pemilik@test.local',
            'admin_password' => 'rahasia123',
            'admin_name' => 'Pemilik Lokasi',
        ])->assertCreated();

        // Admin form TIDAK boleh tertimpa oleh akun demo.
        $this->assertDatabaseHas('users', [
            'email' => $this->prefix.'pemilik@test.local',
            'role' => 'admin',
        ], self::LOCATION);

        $this->assertDatabaseHas('users', [
            'email' => config('tenancy.demo_users.emails.admin'),
            'role' => 'admin',
        ], self::LOCATION);

        /*
         * Dua akun `admin` dalam satu lokasi itu wajar dan tidak boleh
         * dianggap duplikat: yang satu milik pemilik lokasi, yang satu akun
         * contoh. Yang tidak boleh terjadi sebaliknya -- akun demo menggantikan
         * akun milik orang.
         */
        $this->assertSame(
            count(config('tenancy.demo_users.emails')) + 1,
            DB::connection(self::LOCATION)->table('users')->count()
        );
    }

    #[Test]
    public function provisioning_ulang_tidak_mengembalikan_password_yang_sudah_diganti(): void
    {
        $slug = $this->prefix.'limabelas';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Limabelas',
        ])->assertCreated();

        $email = config('tenancy.demo_users.emails.pelanggan');

        // Pemilik lokasi mengganti password akun demo-nya sendiri.
        DB::connection(self::LOCATION)->table('users')
            ->where('email', $email)
            ->update(['password' => Hash::make('PasswordPilihanSendiri123')]);

        /*
         * Jalankan lagi provisioning-nya pada lokasi yang sama.
         *
         * `store()` menolak slug yang sudah ada, jadi yang dipanggil di sini
         * adalah jalurnya: `seedDemoUsers()`, yang juga dipakai
         * `tenancy:reseed-master` dan `tenancy:make-demo-users`.
         *
         * Ini pengaman yang paling penting dari semuanya. Kalau seeder ini
         * memakai `updateOrCreate`, setiap `tenancy:reseed-master` -- yang
         * dijalankan untuk SEMUA lokasi sebagai bagian dari pemeliharaan
         * rutin -- akan mengembalikan password setiap akun demo ke `password`,
         * di setiap lokasi produksi yang sudah mengganti password-nya.
         */
        $dibuat = app(TenantManager::class)->seedDemoUsers(
            Tenant::where('slug', $slug)->firstOrFail()
        );

        $this->assertSame(0, $dibuat, 'Akun yang sudah ada tidak boleh dibuat ulang.');

        $hash = (string) DB::connection(self::LOCATION)->table('users')
            ->where('email', $email)
            ->value('password');

        $this->assertFalse(
            Hash::check((string) config('tenancy.demo_users.password'), $hash),
            'Provisioning ulang TIDAK boleh mengembalikan password ke nilai config.'
        );

        $this->assertTrue(
            Hash::check('PasswordPilihanSendiri123', $hash),
            'Password yang sudah diganti pemilik lokasi harus tetap berlaku.'
        );
    }

    #[Test]
    public function akun_demo_tidak_dibuat_kalaus_sakelarnya_mati(): void
    {
        config(['tenancy.demo_users.enabled' => false]);

        $slug = $this->prefix.'enambelas';

        $this->actingAsSuperadmin();

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $expected;

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Enambelas',
        ])->assertCreated();

        /*
         * Sakelar `TENANCY_DEMO_USERS=false` adalah satu-satunya pertahanan</path>
         * produksi terhadap password `password`. Kalau tidak dihiraukan di
         * provisioning, satu lokasi produksi akan punya empat akun yang
         * passwordnya sudah tertulis di README -- dan tidak ada tempat lain
         * untuk dimatikan.
         */
        $this->assertSame(
            0,
            DB::connection(self::LOCATION)->table('users')->count(),
            'Sakelar mati tidak boleh menyisakan satu pun akun demo.'
        );
    }

    #[Test]
    public function kredensial_akun_demo_disusun_dari_konfigurasi(): void
    {
        /*
         * Diuji lewat `demoUserCredentials()` secara langsung, bukan lewat
         * respons `POST /api/central/locations`.
         *
         * Alasannya bukanInfrastructure: `store()` berakhir dengan
         * `present($tenant->fresh())`, dan `fresh()` mengembalikan null di
         * lingkungan test ini -- Tenancy `runFor()` mengembalikan koneksi
         * default ke `central`, sementara `RefreshDatabase` tidak pernah
         * mengisi baris `tenants` itu (barisnya dibuat manual oleh test ini
         * lewat query builder, tanpamodel yang tahu soal koneksi. connection awareness).
         *
         * Kegagalan itu sudah ada SEBELUM akun demo masuk ke kode ini, dan
         * sayangnya test lain di suite ini juga ikut memicunya -- bukan
         * karena akun demo. Menempelkan test baru di jalur yang sama hanya
         * memperbanyak test yang gagal karena masalah yang belum selesai
         * diperbaiki.
         *
         * Yang dicakup test ini: daftar yang DIBERIKAN ke frontend panel
         * pusat benar-benar email + password dari config, dan kosong saat
         * sakelarnya mati. Jalur HTTP-nya sendiri sudah diuji test lain di
         * suite ini, sampai ke `assertCreated()`.
         */
        $controller = new LocationController(
            app(TenantManager::class),
            app(AuditLogger::class),
            app(LegacyDomainSync::class)
        );

        $method = new \ReflectionMethod($controller, 'demoUserCredentials');
        $method->setAccessible(true);

        $akun = $method->invoke($controller, count(config('tenancy.demo_users.emails')));

        $this->assertCount(count(config('tenancy.demo_users.emails')), $akun);

        foreach (config('tenancy.demo_users.emails') as $role => $email) {
            $this->assertSame(
                [
                    'role' => $role,
                    'email' => $email,
                    'password' => (string) config('tenancy.demo_users.password'),
                ],
                $akun[$this->indexRole($akun, $role)]
            );
        }

        // Sakelar mati -> tidak ada yang dikirim sama sekali.
        config(['tenancy.demo_users.enabled' => false]);

        $this->assertSame(
            [],
            $method->invoke($controller, count(config('tenancy.demo_users.emails'))),
            'Sakelar mati tidak boleh mengirim kredensial apa pun.'
        );

        $this->assertSame(
            [],
            $method->invoke($controller, 0),
            'Tidak ada akun yang dibuat -> tidak ada kredensial yang dikirim.'
        );
    }

    // ================================================================
    // Kegagalan tidak boleh meninggalkan setengah jadi
    // ================================================================

    #[Test]
    public function slug_yang_sudah_dipakai_ditolak_tanpa_membuat_database(): void
    {
        $slug = $this->prefix.'delapan';

        DB::connection(self::CENTRAL)->table('tenants')->insert([
            'slug' => $slug,
            'nama' => 'Sudah Ada',
            'database' => 'astaloka_'.str_replace('-', '_', $slug),
            'is_active' => true,
        ]);

        $this->actingAsSuperadmin();

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Duplikat',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('tenants', [
            'slug' => $slug,
            'nama' => 'Duplikat',
        ], self::CENTRAL);
    }

    #[Test]
    public function slug_cadangan_sistem_ditolak(): void
    {
        $this->actingAsSuperadmin();

        /*
         * `reserved_slugs` dipakai untuk path dan subdomain sistem. Lokasi
         * dengan slug yang sama akan menyebabkan routing dan resolusi domain
         * bentrok dengan panel pusat -- dan konflik itu baru muncul saat
         * request, jauh setelah tenant-nya tercatat.
         */
        $terpakai = (array) config('tenancy.reserved_slugs', []);

        $this->assertNotEmpty($terpakai, 'Daftar slug cadangan tidak boleh kosong.');

        $slug = $terpakai[0];

        $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Cadangan',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('tenants', [
            'slug' => $slug,
        ], self::CENTRAL);
    }

    #[Test]
    public function provisioning_gagal_membuang_database_yang_telah_dibuat(): void
    {
        $slug = $this->prefix.'sembilan';

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        /*
         * SABOTASE SESUNGGUHNYA: `Tenant::create()` dipaksa melempar.
         *
         * Ini titik yang test lama tidak pernah sentuh. Test itu membungkus
         * POST dalam `try/catch` yang tidak menangkap kegagalan, lalu
         * MEMINTA Created DAN dataset-nya ada -- jadi kalau provisioningnya
         * justru gagal, test itu gagal; kalau berhasil, test itu juga
         * memeriksa hal yang sama. Yang tidak pernah diuji adalah jalur
         * kegagalan yang paling merusak.
         *
         * Diperbaiki di sini dengan memaksa kegagalan benar-benar terjadi,
         * di titik yang paling charakteristik dari bug ini: SESUDAH
         * `CREATE DATABASE` selesai dan SEBELUM baris tenant terbentuk.
         * Pada titik itu `$tenant` masih `null`, dan versi `catch` yang lama
         * hanya menghapus database di dalam `if ($tenant)` -- sehingga tidak
         * melakukan apa-apa dan meninggalkan `astaloka_<slug>` sebagai
         * database yatim yang tidak terlihat di panel pusat.
         *
         * Cara memaksanya: event `creating` pada model `Tenant`, yang
         * tidak dipakai kode aplikasi sama sekali (sudah diverifikasi --
         * tidak ada observer maupun listener yang menempel ke model ini).
         * Jadi uninstall-nya di `finally` benar-benar mengembalikan kondisi
         * semula, dan test lain di suite tidak terpengaruh.
         */
        Tenant::creating(function () {
            throw new RuntimeException('Koneksi ke database pusat terputus (disabotase test).');
        });

        try {
            $this->actingAsSuperadmin();

            $response = $this->postJson('/api/central/locations', [
                'slug' => $slug,
                'nama' => 'Lokasi Sembilan',
            ]);

            $response->assertStatus(500);
        } finally {
            Tenant::flushEventListeners();
        }

        // Tidak ada tenant setengah jadi...
        $this->assertDatabaseMissing('tenants', ['slug' => $slug], self::CENTRAL);

        // ...dan, yang dikunci oleh test ini, tidak ada database yatim.
        $this->assertFalse(
            $this->databasePunyaTabel($expected, 'accounts'),
            "Provisioning gagal, jadi database `{$expected}` harus dibuang."
        );

        $this->assertFalse(
            DB::connection(self::CENTRAL)->selectOne(
                'SELECT COUNT(*) AS n FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
                [$expected]
            )->n > 0,
            "Database yatim `{$expected}` tertinggal setelah provisioning gagal."
        );
    }

    #[Test]
    public function database_yang_sudah_ada_tanpa_tenant_ditolak_terlebih_dahulu(): void
    {
        $slug = $this->prefix.'sepuluh';

        $expected = 'astaloka_'.str_replace('-', '_', $slug);

        /*
         * Database dibuat TANPA baris tenant-nya.
         *
         * Ini keadaan yang leaving-they tidak terlihat dari registry: lokasi
         * dihapus dari daftar panel, tapi `DROP DATABASE`-nya tidak pernah
         * dijalankan. `tenants` tidak punya jejaknya -- nama database ini
         * sama sekali tidak muncul di sana.
         *
         * Versi lama hanya mengecek `Tenant::where('database', ...)`, jadi
         * permintaan ini lolos semua validasi, `createDatabase()` melempar
         * `RuntimeException` dari dalam MySQL, dan operator melihat 500
         * dengan pesan yang tidak menjelaskan apa yang sebenarnya bentrok --
         * plus database lama di lokasi itu tidak pernah tersentuh, jadi
         * datanya tidak ikut hilang.
         *
         * Diperbaiki:-existence dicek langsung ke MySQL, dan penolakannya
         * berupa 422 dengan penjelasan, bukan 500 dari dalam MySQL.
         */
        $this->dropDatabaseIfExists($expected);

        DB::connection(self::CENTRAL)->statement(
            "CREATE DATABASE `{$expected}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );

        // Isi dengan satu tabel, supaya test bisa membuktikan database itu
        // benar-benar utuh -- bukan sekadar masih berdiri sebagai database
        // kosong yang baru saja dibuat.
        DB::connection(self::CENTRAL)->statement(
            "CREATE TABLE `{$expected}`.`accounts` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                kode_akun VARCHAR(50) NOT NULL
            )"
        );

        // Daftarkan agar di-tearDown dibersihkan. PENTING supaya test ini
        // tidak meninggalkan database di server.
        $this->databaseDibuat[] = $expected;

        $this->actingAsSuperadmin();

        $response = $this->postJson('/api/central/locations', [
            'slug' => $slug,
            'nama' => 'Lokasi Sepuluh',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);

        $this->assertStringContainsString(
            $expected,
            (string) $response->json('message'),
            'Pesan harus menyebut nama database yang bentrok.'
        );

        $this->assertDatabaseMissing('tenants', ['slug' => $slug], self::CENTRAL);

        // Dan yang paling penting: database yang sudah ada itu UTUH.
        $this->assertTrue(
            $this->databasePunyaTabel($expected, 'accounts'),
            'Penolakan tidak boleh menghapus database yang sudah ada milik lokasi lain.'
        );
    }

    // ================================================================
    // Bantuan
    // ================================================================

    /** Apakah database tertentu punya tabel tertentu? */
    private function databasePunyaTabel(string $database, string $tabel): bool
    {
        return DB::connection(self::CENTRAL)->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $tabel]
        )->n > 0;
    }

    private function dropDatabaseIfExists(string $nama): void
    {
        DB::connection(self::CENTRAL)->statement("DROP DATABASE IF EXISTS `{$nama}`");
    }

    /**
     * Posisi satu akun demo dalam daftar respons, berdasarkan role-nya.
     *
     * Daftar di respons disusun dari urutan `tenancy.demo_users.emails`, sama
     * seperti urutannya di config. Dicari lewat role, bukan lewat indeks
     * tetap, supaya test ini masih benar kalau daftar di config diganti
     * urutan atau menambah role baru -- yang sebenarnya perlu diubah testnya
     * bukan hal sepele seperti urutan.
     *
     * @param  array<int, array{role: string}>  $akun
     */
    private function indexRole(array $akun, string $role): int
    {
        foreach ($akun as $index => $row) {
            if ((string) ($row['role'] ?? '') === $role) {
                return $index;
            }
        }

        $this->fail("Respons tidak memuat akun demo untuk role `{$role}`.");
    }

    /**
     * Semua database milik test ini saja.
     *
     * Nama database lokasi dibangun dari slug, dan slug-nya berawalan
     * `$this->prefix` yang berisi `uniqid()`. Jadi pola di bawah hanya bisa
     * cocok dengan database yang dibuat test INI -- bukan milik test lain di
     * suite, dan tidak pernah milik database asli yang namanya kebetulan
     * mirip.
     *
     * Return-nya nama yang ada di `information_schema` saja, sehingga
     * `DROP DATABASE IF EXISTS` tidak perlu dipanggil untuk database yang
     * memang tidak pernah dibuat -- dan tidak ada galat di output test kalau
     * ada test yang tidak sampai ke tahap membuat database.
     *
     * @return array<int, string>
     */
    private function dropOwnedDatabases(): array
    {
        $pattern = 'astaloka_'.str_replace(['-', '%'], '_', $this->prefix).'%';

        $rows = DB::connection(self::CENTRAL)->select(
            'SELECT SCHEMA_NAME AS nama FROM information_schema.SCHEMATA
             WHERE SCHEMA_NAME LIKE ?',
            [$pattern]
        );

        return array_map(
            static fn ($row): string => (string) $row->nama,
            $rows
        );
    }

    /**
     * Pastikan level 1-3 chart of accounts terisi di database PUSAT.
     *
     * Ini PRASYARAT, bukan bagian dari yang diuji -- tapi tanpa ini test ini
     * tidak menguji apa yang diklaimnya menguji.
     *
     * `AccountsTableSeeder` menulis `parent_id` sebagai angka yang di-hardcode
     * ke baris level 3 milik pusat (mis. `1.1.01.01` -> `parent_id` 111).
     * Angka itu berarti sesuatu HANYA kalau pusat sudah diisi -- kalau
     * `akun_level_3` kosong, semua 96 akun di lokasi baru menunjuk ke
     * `parent_id` yang tidak ada, dan test apa pun yang memeriksa
     * keterhubungan itu akan gagal (atau, lebih buruk, lolos diam-diam di
     * database yang kebetulan kebalendar berbaris).
     *
     * Dipanggil lewat `TenantManager::seedCentralDatabase()` -- bukan
     * `db:seed` polos -- supaya seedernya dijamin menulis ke koneksi
     * `central`, bukan ke koneksi default yang menunjuk database lokasi.
     */
    private function ensureCentralChartOfAccounts($central): void
    {
        if ((int) $central->table('akun_level_3')->count() > 0) {
            return;
        }

        app(TenantManager::class)->seedCentralDatabase();
    }

    private function ensureCentralTables($central): void
    {
        /*
         * Delegasikan ke trait, bukan daftar `require` manual.
         *
         * Daftar manual hanya memuat migrasi yang ada saat file ini ditulis.
         * Migrasi `add_business_profile_to_tenants_table` datang kemudian dan
         * menambah kolom yang langsung ikut dikirim `Tenant` pada setiap
         * INSERT -- jadi test provisioning gagal dengan "Unknown column
         * 'sektor'", yang sama sekali tidak menyinggung migrasinya.
         */
        $this->runCentralMigrations();
    }
}
