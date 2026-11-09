<?php

namespace App\Services;

use App\Models\Central\Tenant;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Mengatur koneksi database lokasi yang sedang aktif, dan membuat database
 * baru saat sebuah lokasi didaftarkan.
 *
 * Cara kerjanya sengaja dibuat "tukar koneksi default", bukan mengikat
 * `$connection` pada tiap model. Alasannya: database lokasi punya skema yang
 * PERSIS sama dengan aplikasi aslinya, dan aplikasi itu tidak pernah
 * menyebut nama koneksi di query-nya -- `Customer::query()` memakai
 * `database.default`. Satu-satunya cara supaya seluruh 800-odd file yang
 * sudah ada langsung ikut ter-isolasi tanpa disentuh adalah dengan mengganti
 * default-nya.
 *
 * Konsekuensinya: `Setting::first()` tidak perlu diubah sama sekali, FK dan
 * trigger MySQL tetap utuh, dan logika pembayaran tidak tersentuh.
 */
class TenantManager
{
    /** Nama koneksi yang dipakai seluruh request untuk data lokasi. */
    public const CONNECTION = 'tenant';

    private ?Tenant $current = null;

    /** Lokasi yang sedang aktif di request ini, kalau sudah di-resolve. */
    public function current(): ?Tenant
    {
        return $this->current;
    }

    /**
     * Arahkan koneksi default ke database milik lokasi.
     *
     * Dipanggil oleh middleware `ResolveTenant` sebelum auth, jadi Sanctum
     * mencari token di database lokasi yang benar sejak awal.
     */
    public function activate(Tenant $tenant): void
    {
        $creds = $tenant->credentials();

        Config::set('database.connections.'.self::CONNECTION, array_merge(
            Config::get('database.connections.'.self::CONNECTION, []),
            [
                'driver' => 'mysql',
                'host' => $creds['host'],
                'port' => $creds['port'],
                'database' => $creds['database'],
                'username' => $creds['username'],
                'password' => $creds['password'],
            ]
        ));

        // Koneksi yang sudah dibuka dengan nama database lama harus dibuang,
        // kalau tidak PDO akan tetap memakai database sebelumnya meski
        // config yang sudah diganti.
        DB::purge(self::CONNECTION);

        // Dan ini inti mechanism-nya: seluruh model yang tidak menyebut
        // `$connection` ikut ter-redirect ke lokasi aktif.
        Config::set('database.default', self::CONNECTION);

        $this->current = $tenant;
    }

    /**
     * Kembalikan koneksi default ke database PUSAT.
     *
     * Dipakai oleh `EnsureSuperadmin` dan setelah operasi lintas-lokasi,
     * supaya request berikutnya tidak diam-diam membaca database lokasi.
     *
     * `central` yang jadi tujuan, bukan `mysql`. Keduanya menunjuk
     * DB_DATABASE -- yaitu database LOKASI -- jadi menulis `mysql` di sini
     * akan mengembalikan default ke lokasi, persis kebalikan dari maksudnya.
     */
    public function deactivate(): void
    {
        $centralName = (string) config('tenancy.central_connection', 'central');

        /*
         * Buang koneksi tenant YANG SUDAH DIBUKA supaya PDO tidak tetap
         * memegang database lokasi lama. Tanpa purge, `DB::connection(...)`
         * berikutnya masih memakai handle lama -- gejalanya query "sumber
         * daya" seperti information_schema.TRIGGERS mengembalikan hasil
         * dari database yang sudah ditinggalkan.
         *
         * `purge()` menutup PDO. Kalau koneksi itu sedang dipakai transaksi
         * -- `RefreshDatabase` membungkus tiap test dalam transaksi, dan
         * middleware ini ikut jalan di dalamnya -- transaksi itu TIDAK ikut
         * rollback. MySQL menahannya sebagai transaksi terlantar yang
         * masih memegang metadata lock, dan test berikutnya yang menjalankan
         * `drop table` lalu menggantung tanpa batas.
         *
         * Karena itu purge hanya dilakukan kalau memang ada database yang
         * perlu ditinggalkan. Kalau default sudah menunjuk database pusat,
         * tidak ada yang perlu di-putus dan transaksi harus dibiarkan utuh.
         */
        $default = (string) Config::get('database.default');

        if ($default !== $centralName) {
            DB::purge(self::CONNECTION);
            DB::purge($centralName);
        }

        Config::set('database.default', $centralName);

        $this->current = null;
    }

    /**
     * Jalankan callback dengan lokasi tertentu aktif, lalu pulihkan.
     *
     * Dipakai superadmin untuk membuka data satu lokasi tanpa lingering
     * koneksi di request berikutnya.
     *
     * @template TReturn
     *
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    public function runFor(Tenant $tenant, callable $callback)
    {
        $sebelumnya = $this->current;
        $defaultSebelum = Config::get('database.default');

        $this->activate($tenant);

        try {
            return $callback();
        } finally {
            // finally, bukan sukses: kalau callback melempar exception,
            // koneksi tetap harus dibersihkan atau request berikutnya
            // (mis. setelah render error) akan membaca database lokasi.
            DB::purge(self::CONNECTION);
            Config::set('database.default', $defaultSebelum);
            $this->current = $sebelumnya;
        }
    }

    /**
     * Jalankan callback untuk setiap lokasi aktif, satu per satu.
     *
     * Dipakai oleh scheduler. Tanpa ini, perintah terjadwal hanya berjalan
     * untuk SATU lokasi -- yaitu lokasi yang kebetulan menjadi
     * `database.default` pada proses itu. Lokasi lainnya tidak pernah
     * menjalankan generate piutang sama sekali, dan tidak ada error apa pun
     * yang menandakan itu.
     *
     * Dua detail yang membuat ini aman untuk dipakai scheduler:
     *
     *   1. Kegagalan satu lokasi TIDAK menghentikan lokasi berikutnya.
     *      Database lokasi bisa hilang, restore belum selesai, atau sedang
     *      dipakai migrasi. Kalau exception-nya diteruskan, satu lokasi rusak
     *      membuat semua lokasi lain dilewati -- dan lokasi yang rusak
     *      biasanya bukan yang paling penting.
     *
     *   2. Registry dibaca dari koneksi PUSAT secara eksplisit, bukan lewat
     *      `database.default`. Model `Tenant` mengunci koneksinya sendiri,
     *      tapi pemanggilnya (scheduler, atau command lain) bisa sedang
     *      berada di dalam `runFor()` -- dan `DB::purge()` di akhir tiap
     *      iterasi tidak boleh meninggalkan daftar lokasi dalam keadaan
     *      setengah terbaca.
     *
     * @template TReturn
     *
     * @param  callable(Tenant):TReturn  $callback
     * @param  null|callable(Tenant,\Throwable):mixed  $onFailure
     * @return array<int,array{tenant:Tenant,ok:bool,value?:TReturn,error?:string}>
     */
    public function runForEachActive(callable $callback, ?callable $onFailure = null): array
    {
        $hasil = [];

        $tenants = Tenant::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($tenants as $tenant) {
            try {
                $hasil[] = [
                    'tenant' => $tenant,
                    'ok' => true,
                    'value' => $this->runFor($tenant, fn () => $callback($tenant)),
                ];
            } catch (\Throwable $e) {
                Log::error('Pemrosesan lintas-lokasi gagal untuk satu lokasi.', [
                    'tenant_id' => $tenant->getKey(),
                    'slug' => $tenant->slug,
                    'error' => $e->getMessage(),
                ]);

                if ($onFailure) {
                    $onFailure($tenant, $e);
                }

                $hasil[] = [
                    'tenant' => $tenant,
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $hasil;
    }

    /**
     * Nama database untuk sebuah slug, TANPA membuatnya.
     *
     * SATU-SATUNYA tempat aturan ini hidup. Sebelumnya aturan yang sama
     * ditulis ulang di tiga tempat -- `createDatabase()`, `MakeTenant::
     * buildDatabaseName()`, dan `LocationController::databaseNameFor()` --
     * dan ketiganya tidak cocok: dua melakukan `substr()` lalu `rtrim()`,
     * satu melakukan `rtrim()` lalu `substr()`. Untuk slug pendek hasilnya
     * sama, jadi bug-nya tidak terlihat; tapi begitu nama melewati batas 64
     * karakter, `rtrim()`-lalu-`substr()` bisa meninggalkan underscore
     * menggantung di ujung (`astaloka_aaa...a_`) sementara yang lain
     * membuangnya. Registry lalu menyimpan nama yang berbeda dari database
     * yang benar-benar dibuat, dan lokasi itu tidak akan pernah bisa dibuka
     * -- tanpa error apa pun yang menyinggungnya.
     *
     * Dipisah dari `createDatabase()` supaya pemanggil yang hanya butuh
     * menghitung nama (validasi, display) tidak perlu membuka koneksi
     * MySQL, dan supaya ada SATU fungsi yang dijamin sama dengan yang
     * dipakai saat database benar-benar dibuat.
     *
     * @throws RuntimeException Kalau slug tidak menghasilkan nama yang valid
     */
    public static function databaseNameFor(string $slug): string
    {
        $prefix = (string) config('tenancy.database_prefix', 'astaloka_');
        $batas = (int) config('tenancy.max_database_name_length', 64);

        $slugUntukDb = preg_replace('/[^a-z0-9_]/', '_', strtolower($slug)) ?? '';

        if ($slugUntukDb === '' || trim($slugUntukDb, '_') === '') {
            throw new RuntimeException("Slug `{$slug}` tidak menghasilkan nama database yang valid.");
        }

        /*
         * Urutan: potong DULUAN, baru bersihkan ekor.
         *
         * Untuk prefix bawaan (`astaloka_`, 9 karakter) hasilnya sama saja
         * dengan urutan terbalik -- `rtrim()` tidak pernah menyisakan
         * underscore untuk dibuang, dan `substr()` tidak pernah memotong
         * di underscore. Yang berubah baru begitu prefix diganti lewat
         * `TENANCY_DATABASE_PREFIX`: prefix yang TIDAK berakhir underscore
         * membuat `substr()` bisa memotong tepat di tanda pemisah, dan
         * `rtrim()` setelahnya membuangnya.
         *
         * Urutan ini tidak boleh punya definisi kedua. Satu nama, satu
         * tempat -- itulah yang membuat salinan lama tidak mungkin kembali
         * dan diam-diam tidak cocok lagi.
         */
        return rtrim(substr($prefix.$slugUntukDb, 0, $batas), '_');
    }

    /**
     * Buat database MySQL baru untuk sebuah lokasi.
     *
     * Nama database dihitung oleh {@see databaseNameFor()} dan dibuat lewat
     * `createDatabaseNamed()`. Keduanya dipisah supaya nama yang ditulis ke
     * registry dijamin identik dengan yang benar-benar ada di MySQL.
     *
     * Catatan untuk pemanggil: nilai `$slug` di sini BUKAN nama database
     * yang dipakai. Kalau pemanggil punya nama sendiri (mis. dari
     * `--database=` di CLI), ia harus memanggil `createDatabaseNamed()`
     * -- bukan `createDatabase()`.
     */
    public function createDatabase(string $slug): string
    {
        return $this->createDatabaseNamed(static::databaseNameFor($slug));
    }

    /**
     * Buat database dengan nama yang sudah jadi (sudah tervalidasi).
     *
     * Dipisah dari createDatabase() supaya pemanggil yang sudah punya nama
     * final -- misalnya memindahkan database lama ke nama baru -- tidak
     * harus melewati normalisasi slug dua kali.
     */
    public function createDatabaseNamed(string $nama): string
    {
        $this->validateDatabaseName($nama);

        // CREATE DATABASE tidak bisa diparameterisasi, jadi nama wajib
        // divalidasi ketat lebih dulu (lihat validateDatabaseName).
        $sudahAda = DB::connection('central')->select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$nama]
        );

        if ($sudahAda !== []) {
            throw new RuntimeException("Database `{$nama}` sudah dipakai lokasi lain.");
        }

        DB::connection('central')->statement(
            "CREATE DATABASE `{$nama}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );

        return $nama;
    }

    /**
     * Hapus database lokasi. Permanen -- tidak ada recycle bin.
     *
     * Mengembalikan true kalau database benar-benar dihapus, false kalau
     * memang tidak ada. Pemanggil butuh informasi itu untuk log audit: saat
     * provisioning gagal, "database sudah dibuang" dan "database memang
     * belum pernah ada" adalah dua kondisi yang berbeda, dan membingungkan
     * keduanya membuat lokasi setengah jadi yang menggantung terlihat bersih
     * di log.
     *
     * Melempar exception kalau nama tidak valid atau menunjuk database pusat
     * -- kegagalan itu tidak boleh disamarkan jadi false.
     */
    public function dropDatabase(string $nama): bool
    {
        $this->validateDatabaseName($nama);

        if ($nama === Config::get('tenancy.central_database')) {
            throw new RuntimeException('Refused: nama database pusat tidak boleh dihapus.');
        }

        if (! $this->databaseExists($nama)) {
            return false;
        }

        DB::connection('central')->statement("DROP DATABASE `{$nama}`");

        return true;
    }

    /** Apakah database dengan nama ini sudah ada di MySQL. */
    public function databaseExists(string $nama): bool
    {
        $ada = DB::connection('central')->select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$nama]
        );

        return $ada !== [];
    }

    /**
     * Nama database hanya boleh [A-Za-z0-9_].
     *
     * Nama database disisipkan apa adanya ke `CREATE DATABASE` karena MySQL
     * tidak menerima placeholder di situ. Parameternya tidak bisa dianggap
     * aman hanya karena berasal dari registry -- registry itu bisa saja berisi
     * sisa input user yang tidak tervalidasi, dan satu backtick cukup untuk
     * mengubah statement.
     */
    private function validateDatabaseName(string $nama): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $nama)) {
            throw new RuntimeException(
                "Nama database tidak valid: `{$nama}`. Hanya huruf, angka, dan underscore yang diizinkan."
            );
        }

        if (strlen($nama) > 64) {
            throw new RuntimeException("Nama database terlalu panjang (maks 64 karakter): `{$nama}`.");
        }
    }

    /**
     * Jalankan seluruh migration aplikasi ke database lokasi tertentu.
     *
     * Dipanggil setelah CREATE DATABASE, supaya database lokasi baru punya
     * skema yang sama persis dengan lokasi lain.
     *
     * `--path` WAJIB disebutkan di sini, dan itu bukan sekadar kelengkapan.
     *
     * Migrasi lokasi TIDAK memakai `Schema::connection('central')` seperti
     * migrasi pusat -- semuanya mengikuti `database.default`. Foldernya sudah
     * dipindah ke luar `database/migrations/` (lihat
     * `tenancy.migrations_path`), jadi pemanggilan tanpa `--path` akan
     * memindai folder yang sekarang kosong: perintahnya keluar dengan status
     * SUKSES sambil mencetak "Nothing to migrate", database lokasi baru tetap
     * kosong, dan provisioning berlanjut ke seeder -- yang kemudian gagal
     * dengan "Table 'astaloka_x.settings' doesn't exist".
     *
     * Gejalanya itu muncul sebagai lokasi yang gagal disiapkan, padahal
     * penyebabnya perintah migration yang tidak menjalankan apa pun. Dua
     * DatabasePath yang saling menuding, dan tidak ada satu pun yang salah
     * arah.
     *
     * Path-nya relatif dan ber-pemisah `/`, bukan hasil `database_path()`,
     * karena Migrator mencocokkannya dengan `glob()` ber-pemisah `/`.
     * Backslash di Windows membuat tidak satu pun file yang cocok -- dan
     * hasilnya sama saja: "Nothing to migrate" dengan status sukses.
     */
    public function migrateTenantDatabase(Tenant $tenant): void
    {
        $path = (string) config('tenancy.migrations_path', 'database/migrations_tenant');

        $this->runFor($tenant, function () use ($path) {
            Artisan::call('migrate', [
                '--database' => self::CONNECTION,
                '--path' => $path,
                '--force' => true,
            ]);
        });
    }

    /**
     * Jalankan seeder master di database lokasi baru.
     *
     * Seeder dipanggil satu per satu, bukan `db:seed`, karena `db:seed`
     * tanpa `--class` memakai DatabaseSeeder yang isinya peranggilan untuk
     * aplikasi single-tenant -- termasuk pelanggan contoh, meter reading, dan
     * struk. Menjalankannya di lokasi baru akan mengisi database produksi
     * dengan data fiktif.
     *
     * Daftar dan urutannya dari `config('tenancy.provision.seeders')`. Semua
     * seeder di sana WAJIB idempoten: provisioning juga dijalankan ulang lewat
     * `tenancy:reseed-master` untuk lokasi yang sudah terlanjur ada, dan
     * skemanya sudah terisi data.
     *
     * Seeder yang mengisi data di database PUSAT -- seperti level 1-3
     * chart of accounts -- TIDAK dipanggil di sini. Lihat {@see seedCentralDatabase()}.
     */
    public function seedTenantDatabase(Tenant $tenant): void
    {
        $seeders = (array) config('tenancy.provision.seeders', []);

        if ($seeders === []) {
            return;
        }

        $this->runFor($tenant, function () use ($seeders) {
            foreach ($seeders as $seeder) {
                if (! is_string($seeder) || ! class_exists($seeder)) {
                    continue;
                }

                Artisan::call('db:seed', [
                    '--class' => $seeder,
                    '--database' => self::CONNECTION,
                    '--force' => true,
                ]);
            }
        });
    }

    /**
     * Buat empat akun demo di database lokasi, satu per role.
     *
     *   admin@gmail.com / pelanggan@gmail.com / surveyor@gmail.com /
     *   teknisi@gmail.com, semuanya password `password`
     *
     * DIPANGGIL TERPISAH DARI `seedTenantDatabase()`, dan itu bukan sekadar
     * urutan pemanggilan -- keduanya punya sifat idempotensi yang BERBEDA, jadi
     * gabung di daftar `provision.seeders` akan merusak salah satunya.
     *
     * `provision.seeders` dijalankan ulang oleh `tenancy:reseed-master` untuk
     * semua lokasi, termasuk lokasi produksi yang sudah berjalan. Akun demo
     * yang ikut di sana akan mendapat passwordnya dikembalikan ke `password`
     * setiap kali perintah itu dijalankan -- membatalkan password yang sudah
     * diganti pemilik lokasi, hanya karena seseorang menjalankan perintah
     * pemeliharaan.
     *
     * Yang dilindungi di sini justru sebaliknya: baris yang sudah ada TIDAK
     * disentuh sama sekali. `DemoUserSeeder` hanya membuat yang belum ada.
     *
     * @return int Jumlah akun yang benar-benar dibuat pada panggilan ini
     */
    public function seedDemoUsers(Tenant $tenant): int
    {
        if (! config('tenancy.demo_users.enabled', true)) {
            return 0;
        }

        /*
         * Kedua hitungan DI DALAM satu `runFor()`.
         *
         * Versi sebelumnya menghitung di luar, lalu menghitung lagi setelahnya.
         * Itu selalu mengembalikan 0: `runFor()` mengembalikan `$this->current`
         * ke `null` di blok `finally`-nya, jadi `countDemoUsers()` yang
         * memanggilnya dari luar selalu melihat "tidak ada lokasi aktif" dan
         * mengembalikan 0 -- dua kali. Selisihnya nol, dan akun yang
         * BENAR-BENAR dibuat dilaporkan sebagai "tidak ada yang dibuat".
         *
         * Gejalanya yang paling buruk kalau ditelusuri: akun demo-nya ada
         * semua dan bisa dipakai, sementara command-nya menyebut "dilewati".
         * Yang hilang justru informasi yang paling dibutuhkan -- akun mana
         * yang barusan dibuat.
         */
        return $this->runFor($tenant, function () {
            $sebelumnya = $this->countDemoUsers();

            Artisan::call('db:seed', [
                '--class' => DemoUserSeeder::class,
                '--database' => self::CONNECTION,
                '--force' => true,
            ]);

            return max(0, $this->countDemoUsers() - $sebelumnya);
        });
    }

    /**
     * Berapa akun demo yang sudah ada di database lokasi yang aktif.
     *
     * HANYA BOLEH dipanggil dari dalam `runFor()`. Di luar, tidak ada lokasi
     * aktif untuk dibaca -- lihat catatan di `seedDemoUsers()`.
     */
    private function countDemoUsers(): int
    {
        $emails = array_values(array_filter(
            array_map('strval', (array) config('tenancy.demo_users.emails', []))
        ));

        if ($emails === []) {
            return 0;
        }

        return DB::connection(self::CONNECTION)
            ->table('users')
            ->whereIn('email', $emails)
            ->count();
    }

    /**
     * Jalankan seeder master di DATABASE PUSAT.
     *
     * Level 1-3 chart of accounts ada di sini, bukan di database lokasi, jadi
     * seedernya tidak ikut dipanggil oleh {@see seedTenantDatabase()}. Mereka
     * dijalankan sekali, idempoten, untuk seluruh sistem -- bukan sekali per
     * lokasi.
     *
     * Panggil lewat `tenancy:migrate-central` (--seed) atau langsung lewat
     * perintah CLI saat struktur chart of accounts perlu di-rebuild. Tidak
     * dipanggil otomatis saat tenant baru dibuat, karena pusat tidak perlu
     * menunggu ada lokasi baru untuk diisi.
     */
    public function seedCentralDatabase(): void
    {
        $seeders = (array) config('tenancy.provision.central_seeders', []);

        if ($seeders === []) {
            return;
        }

        foreach ($seeders as $seeder) {
            if (! is_string($seeder) || ! class_exists($seeder)) {
                continue;
            }

            Artisan::call('db:seed', [
                '--class' => $seeder,
                '--database' => config('tenancy.central_connection', 'central'),
                '--force' => true,
            ]);
        }
    }

    /**
     * Tulis identitas lokasi ke tabel `settings` miliknya sendiri.
     *
     * Tanpa langkah ini, laporan apa pun yang mencantumkan nama lembaga akan
     * keluar dengan isi default dari `SettingsSeeder` -- yaitu "ASTALOKA
     * Tirto Mulo" untuk semua lokasi, karena seeder itu masih memakai data contoh.
     * Lokasi baru harus punya identitas sendiri sejak baris pertama, bukan
     * menunggu admin ingat menggantinya lewat menu pengaturan.
     *
     * `id = 1` dipilih karena SELURUH kode aplikasi membaca `Setting::first()`
     * (20-an pemanggilan di BillingService, PelaporanController, SopController,
     * dst). Mengubahnya jadi key-value akan membuat semua pemanggilan itu ikut
     * harus diubah -- perubahan yang jauh lebih berisiko daripada menyelaraskan
     * kolom yang memang sudah tersedia di tabel.
     *
     * Aman dipanggil berulang: `updateOrInsert` menulis ulang isi yang sama,
     * jadi lokasi yang identitasnya sudah dikoreksi manual tidak ikut
     * ditimpa kalau perintah ini dijalankan tanpa tujuan eksplisit.
     *
     * TANGGAL GENERATE PIUTANG TIDAK DIISI DI SINI
     * ---------------------------------------------
     *
     * `toleransi_tunggakan` TIDAK boleh diisi di sini -- dan dulu memang
     * diisi, dengan `invoice_grace_months`, yang satuanannya BULAN
     * (nilai bawaan 1).
     *
     * Dua satuan yang berbeda mengisi satu kolom yang sama:
     *
     *   - `invoice_grace_months` : BULAN. Masa tenggang tagihan langganan
     *     ASTALOKA ke lokasi.
     *   - `toleransi_tunggakan` : TANGGAL dalam bulan (1-31). Tanggal
     *     generate jurnal piutang tunggakan.
     *
     * Yang terjadi akibatnya: setiap kali perintah ini dijalankan
     * (`tenancy:reseed-master --identity`, atau provisioning lokasi baru),
     * tanggal generate yang sudah diset admin DITIMPA jadi 1.
     *
     * Gejalanya persis seperti keluhan yang diterima: tanggal generate sudah
     * diset 28, tapi generate tidak pernah berjalan pada tanggal itu -- karena
     * nilai 28 sudah hilang jauh sebelum tanggal 28 tiba. Dan karena
     * `toleransi_tunggakan` juga dipakai sebagai batas tagihan unpaid
     * sebelum suspend, batas itu ikut bergeser tanpa disadari.
     *
     * Kolomnya TIDAK ikut ditulis di sini, sehingga nilai yang sudah diset
     * admin tidak pernah tertimpa. Untuk lokasi yang belum punya nilai
     * sama sekali, kolom itu dibiarkan memakai nilai bawaan database (0) --
     * dan 0 berarti "belum dijadwalkan": `BillingTolerance::generateDay()`
     * mengubahnya jadi 0, sehingga generate TIDAK berjalan sama sekali sampai
     * admin mengisinya lewat halaman SOP. Itu lebih aman daripada diam-diam
     * memakai angka yang tidak pernah dipilih siapa pun.
     *
     * Nilai bawaan untuk lokasi BARU tidak diambil dari
     * `invoice_grace_months` -- satu-satunya angka yang memang batas
     * tagihan menunggak ada di `SettingsSeeder` (`3`), dan itu pun hanya
     * berlaku kalau seeder master-nya ikut jalan. Provisionsa lokasi baru
     * menjalankan `seedTenantDatabase()` sebelum `applyTenantIdentity()`,
     * jadi baris `settings` sudah ada dengan nilai `3` dari situ.
     *
     * TAPI supaya titik ini tidak bergantung pada urutan pemanggilan
     * perintah, nilai bawaannya ditulis eksplisit -- hanya kalau baris
     * `settings` belum ada sama sekali. Kalau barisnya sudah ada, nilai
     * `toleransi_tunggakan` yang ada di sana dibiarkan apa adanya.
     *
     * CATATAN SOAL DUA KOLOM SOP
     * --------------------------
     *
     * `toleransi_tunggakan` = TANGGAL jatuh tempo (1-31).
     * `batas_tagihan`       = JUMLAH BULAN tunggakan sebelum suspend.
     *
     * Keduanya punya satuan berbeda, jadi nilai bawaannya juga berbeda:
     * 10 untuk tanggal dan 3 untuk jumlah bulan -- sama dengan
     * `SettingsSeeder`.
     */
    public function applyTenantIdentity(Tenant $tenant): void
    {
        $this->runFor($tenant, function () use ($tenant) {
            $tabel = DB::connection(self::CONNECTION)->table('settings');

            $sudahAda = $tabel->where('id', 1)->exists();

            $data = [
                'key' => 'sop',
                'value' => '',
                'nama' => $tenant->nama,
                'nama_lembaga' => $tenant->nama_lembaga ?: $tenant->nama,
                'alamat' => $tenant->alamat,
                'email' => $tenant->email,
                'telepon' => $tenant->telepon,
                'domain' => $tenant->domain,
                'status_pembayaran' => true,
                // TANGGAL jatuh tempo.
                'toleransi_tunggakan' => 10,
                // JUMLAH BULAN tunggakan sebelum suspend.
                'batas_tagihan' => 3,
                'logo' => null,
                'pesan_tagihan' => 'Yth. {customer}, tagihan Anda bulan ini sebesar {jumlah_tagihan}. Mohon segera melakukan pembayaran sebelum jatuh tempo. Terima kasih.',
                'pesan_pembayaran' => 'Yth. {customer}, pembayaran tagihan sebesar {tagihan} telah kami terima. Terima kasih.',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            /*
             * Baris `settings` yang sudah ada: HANYA kolom identitas lokasi
             * yang ditulis ulang. `toleransi_tunggakan` dan `batas_tagihan`
             * -- dua hal yang diatur admin lewat halaman SOP -- TIDAK ikut
             * sentuh.
             */
            if ($sudahAda) {
                $tabel->where('id', 1)->update([
                    'nama' => $data['nama'],
                    'nama_lembaga' => $data['nama_lembaga'],
                    'alamat' => $data['alamat'],
                    'email' => $data['email'],
                    'telepon' => $data['telepon'],
                    'domain' => $data['domain'],
                    'updated_at' => $data['updated_at'],
                ]);

                return;
            }

            /*
             * Baris `settings` belum ada sama sekali: baris baru dibuat
             * lengkap dengan titik awal yang sama dengan `SettingsSeeder`.
             *
             * `toleransi_tunggakan` = TANGGAL jatuh tempo.
             * `batas_tagihan` = JUMLAH BULAN sebelum suspend.
             *
             * Keduanya hanya nilai awal; admin bisa mengubahnya lewat SOP.
             */
            $tabel->insert($data);
        });
    }
}
