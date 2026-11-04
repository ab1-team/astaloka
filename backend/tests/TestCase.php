<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use RuntimeException;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    /**
     * Cegah test menyentuh database yang bukan database uji.
     *
     * Latar belakang: ini bukan hal yang bisa dianggap remeh. `phpunit.xml`
     * menunjuk `astaloka_testing` dan `astaloka_testing_central`, tapi `.env`
     * menunjuk `astaloka_lokasi` dan `astaloka` -- dan `APP_ENV=testing` tidak
     * membuat Laravel mengabaikan `.env`. `config/database.php` membaca
     * `env()`, dan `env()` mengembalikan nilai dari `.env` selama variabel
     * tersebut belum diset di environment asli.
     *
     * Test punya akses ke `DB::connection('central')` tanpa batas sama sekali,
     * dan beberapa test memanggil `->delete()` tanpa filter pada
     * `tenant_domains`. Kalau `TENANCY_CENTRAL_DATABASE` pernah menunjuk
     * `astaloka`, satu `php artisan test` menghapus seluruh daftar domain
     * setiap lokasi -- termasuk domain yang sudah dipakai produksi dan
     * tersimpan di bookmark.
     *
     * Dan itu bukan risiko teoretis. `AUTO_INCREMENT` di `tenant_domains`
     * menunjukkan 4 sementara tabelnya kosong: tiga INSERT pernah terjadi
     * lalu semua barisnya hilang, sementara tabelnya sendiri tidak pernah
     * di-drop. Dan itu terjadi karena satu test suite yang berjalan sampai
     * selesai, bukan karena proses yang mati di tengah jalan -- tidak ada sisa
     * yang harus dibersihkan, tidak ada log, dan tidak ada yang melihatnya.
     *
     * `App\Listeners\GuardDestructiveCommands` hanya mengejar perintah artisan
     * yang menghapus SELURUH tabel. Ia tidak melihat `->delete()` tanpa filter
     * di dalam test, yang menghapus sebagian baris -- justru itu yang paling
     * berbahaya karena tidak ada yang bisa memulihkannya.
     */
    protected function setUp(): void
    {
        /*
         * `releaseAbandonedTransactions()` tidak butuh app yang hidup, jadi ia
         * jalan lebih dulu lewat PDO langsung.
         */
        $this->releaseAbandonedTransactions();

        parent::setUp();

        /*
         * Pengaman database, BERJALAN DI SETIAP TEST.
         *
         * Hook `beforeRefreshingDatabase()` hanya dipanggil saat
         * `RefreshDatabase` MEMBUAT ULANG database -- yaitu test PERTAMA di satu
         * proses. Setiap test berikutnya dalam proses yang sama tidak
         * me-refresh, jadi hook-nya tidak pernah jalan. Pengaman yang hanya
         * terpasang di sana akan lolos di dozens test pertama lalu hilang tepat di
         * tempat paling berbahaya.
         */
        $this->assertTestingDatabases();

        /*
         * Setelah `assertTestingDatabases()`, bukan sebelumnya: langkah itu
         * menolak database yang namanya tidak mengandung `testing`, jadi
         * database hanya boleh dibuat SETELAH pemeriksaan itu -- kalau tidak,
         * baris ini bisa membuat database yang baru saja dinyatakan tidak
         * boleh disentuh.
         */
        $this->createTestingCentralDatabaseIfMissing();
    }

    /**
     * Arahkan Migrator ke folder migrasi LOKASI, untuk seluruh perintah
     * migrate yang dipanggil tanpa `--path`.
     *
     * Kenapa tidak override `migrateFreshUsing()` saja
     * ==================================================
     *
     * `migrateFreshUsing()` kelihatan seperti titik override yang paling
     * langsung, dan justru itu yang tidak bisa diandalkan. Hampir semua test
     * memakai trait `RefreshDatabase` secara langsung di kelasnya sendiri:
     *
     *     class TutupBukuHistoryTest extends TestCase
     *     {
     *         use RefreshDatabase;
     *
     * Method dari trait yang dipanggil DI KELAS ITU sendirian beating method
     * yang diwarisi dari `Tests\TestCase` -- itu aturan precedence PHP, bukan
     * aturan Laravel. Hasilnya override kita tidak pernah dipanggil, dan suite
     * tetap gagal dengan:
     *
     *     Table 'astaloka_testing_iso.users' doesn't exist
     *
     * Kenapa gejalanya tidak jelas
     * =========================
     *
     * `migrate:fresh` adalah dua langkah: drop semua tabel, lalu jalankan
     * migrasi. Langkah pertama tetap berhasil -- jadi database test berakhir
     * dengan NOL tabel, tanpa satu pun error di halaman. Tidak ada yang gagal
     * sampai test pertama benar-benar menyentuh tabel, dan pesan yang muncul
     * ("table doesn't exist") sama sekali tidak menyinggung bahwa penyebabnya
     * folder migrasi yang salah dibaca.
     *
     * Yang dilakukan di sini
     * =====================
     *
     * Folder migrasi lokasi didaftarkan ke Migrator lewat `path()`, jadi
     * `--path` tidak lagi harus disebut satu per satu. `MigrateCommand`
     * menggabungkan `--path` dari CLI dengan path yang sudah terdaftar,
     * sehingga `migrate`, `migrate:fresh`, `migrate:refresh`, dan
     * `migrate:rollback` semuanya ikut benar tanpa ada yang harus diubah.
     *
     * Hook-nya `createApplication()` dipilih karena itu satu-satunya tempat
     * yang berjalan SEBELUM trait apa pun di `setUp()`, dan hanya ada di
     * kelas dasar -- tidak pernah tertimpa trait test.
     *
     * Folder PUSAT sengaja TIDAK didaftarkan di sini. Migrator punya satu
     * daftar path yang tidak bisa dibedakan per database; mendaftarkan
     * keduanya berarti `migrate:fresh` ikut menjalankan migrasi pusat ke
     * database LOKASI -- bentuk kebocoran yang persis sedang diperbaiki.
     * Jalur pusat disiapkan terpisah di
     * {@see createTestingCentralDatabaseIfMissing()}, yang memanggil
     * migrasinya langsung ke koneksi `central`.
     *
     * Path-nya relatif dan ber-pemisah `/`, bukan `database_path()`: yang
     * diberikan Migrator memang bentuk itu, dan `glob()` tidak akan
     * mencocokkan backslash Windows. Alasan lengkapnya ada di
     * `config/tenancy.php`.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $this->isolasiDatabasePusatParalel();

        $app->make('migrator')->path(
            $app->basePath((string) config(
                'tenancy.migrations_path',
                'database/migrations_tenant'
            ))
        );

        return $app;
    }

    /**
     * Beri setiap worker paralel database PUSAT sendiri.
     *
     * Laravel hanya mengganti nama database pada koneksi default (`mysql`)
     * saat `--parallel` dipakai -- lihat `TestDatabases::testDatabase()`.
     * Koneksi `central` tidak dikenalinya, jadi seluruh worker menulis ke
     * `astaloka_testing_central` yang sama. Akibatnya test yang membuat
     * superadmin, lokasi, atau domain di database pusat saling menghapus
     * pekerjaan satu sama lain: hasilnya deretan kegagalan yang tidak
     * reproducible saat file yang sama dijalankan sendiri.
     *
     * Penamaan mengikuti konvensi Laravel (`..._test_<token>`) supaya
     * `periksaDatabase()` tetap mengenalinya sebagai database uji, dan
     * `createTestingCentralDatabaseIfMissing()` membuat + memigrasikannya
     * sekali per proses.
     *
     * Pembersihan database per-token dilakukan manual kalau perlu: callback
     * `tearDownProcess` Laravel dijalankan dari proses INDUK dengan aplikasi
     * yang dibuat lewat `Tests\CreatesApplication`, bukan lewat kelas ini --
     * jadi tidak ada tempat yang aman untuk mendaftarkannya dari sini.
     */
    private function isolasiDatabasePusatParalel(): void
    {
        $token = ParallelTesting::token();

        if (! $token) {
            return;
        }

        $central = (string) config('tenancy.central_database');

        // Pengaman: jangan menyentuh apa pun yang bukan database uji.
        if ($central === '' || stripos($central, 'testing') === false) {
            return;
        }

        $nama = $central.'_test_'.$token;

        config([
            'tenancy.central_database' => $nama,
            'database.connections.central.database' => $nama,
        ]);
    }

    /**
     * Pengaman SEBELUM `RefreshDatabase` pertama kali men-drop tabel.
     *
     * Lapisan kedua, bukan yang utama. Lapisan utama ada di `setUp()` dan sudah
     * menolak di setiap test. Hook ini hanya menutup celah yang lebih sempit:
     * test PERTAMA di satu proses, ketika `migrate:fresh` benar-benar akan
     * berjalan.
     *
     * Diperlukan karena `migrate:fresh` menghapus SELURUH tabel, termasuk
     * `tenants` beserta satu-satunya salinan registry lokasi -- dan tidak ada
     * yang bisa memulihkannya. Penolakan di `setUp()` berikutnya terlambat.
     *
     * Dua syarat harus terpenuhi di titik ini, dan keduanya tidak terpenuhi di
     * tempat lain:
     *
     *   1. `config()` sudah berfungsi. Di `setUp()` SEBELUM `parent::setUp()`,
     *      aplikasi belum ada sama sekali dan `config()` melempar
     *      `Target class [config] does not exist` -- pesan yang tidak menjelaskan
     *      apa pun soal database.
     *
     *   2. `migrate:fresh` belum jalan.
     *
     * Tanpa `: void`. Hook ini datang dari trait `RefreshDatabase`, yang tidak
     * mendeklarasikan tipe return. Menulis `: void` di sini menjadi tanda tangan
     * yang tidak kompatibel, dan PHP menolaknya saat file di-load -- jadi
     * seluruh suite gagal dengan fatal error, bukan hanya test ini.
     *
     * @return void
     */
    protected function beforeRefreshingDatabase()
    {
        parent::beforeRefreshingDatabase();

        $this->assertTestingDatabases();
    }

    /**
     * Buat database PUSAT untuk test, kalau belum ada.
     *
     * `RefreshDatabase` hanya menjalankan `migrate:fresh` pada koneksi
     * `database.default`, yaitu database LOKASI (`astaloka_testing`). Yang
     * kedua dipanggil `migrate:fresh` BUAT sendiri kalau belum ada, jadi
     * database lokasi aman dihapus kapan saja.
     *
     * Database PUSAT (`astaloka_testing_central`) tidak disentuh trait sama
     * sekali -- tidak di-refresh, tidak dibuat. `migrate:fresh` tidak pernah
     * mengarah ke sana, jadi database itu harus sudah ada sebelum suite jalan.
     *
     * Gejalanya kalau database pusat dihapus: 215 dari 372 test gagal dengan
     * `SQLSTATE[HY000] [1049] Unknown database 'astaloka_testing_central'`,
     * muncul dari query paling remeh yang ada -- pencarian domain saat request
     * dikirim. Tidak ada satu pun pesan yang menyebut bahwa yang hilang adalah
     * DATABASENYA, bukan kode atau testnya.
     *
     * Itu sebabnya langkah ini ada. Menjalankan `tenancy:migrate-central`
     * manual sebelum test hanya menyelesaikan masalah sekali: database yang
     * dihapus berikutnya akan menggulanginya -- dan karena penghapusan total
     * terasa aman (`--force` dipakai, jadi tidak ada peringatan), database
     * testing ini mudah sekali hilang tanpa disadari sampai suite gagal
     * dengan pesan yang sama sekali tidak menyinggung penyebabnya.
     *
     * Pembuatan memakai PDO yang TIDAK menyebutkan database sama sekali
     * (DSN tanpa `dbname`). Ini bukan detail kecil: memakai
     * `DB::connection('mysql')` kelihatan wajar, tapi koneksi itu menunjuk
     * `astaloka_testing` -- dan kalau THAT yang dihapus, PDO gagal membuka
     * koneksi dengan `Unknown database 'astaloka_testing'` sebelum sempat
     * menjalankan `CREATE DATABASE`. Test pun gagal dengan pesan yang
     * menunjuk database yang salah, padahal yang bermasalah justru database
     * pusat yang sedang dibuat.
     *
     * Selalu idempoten, dan hanya berjalan untuk database yang namanya
     * mengandung `testing` -- pengaman `periksaDatabase()` di atas sudah
     * menolak yang lain SEBELUM baris ini dieksekusi.
     */
    private function createTestingCentralDatabaseIfMissing(): void
    {
        $central = (string) config('tenancy.central_database');

        if (stripos($central, 'testing') === false) {
            return;
        }

        $cfg = config('database.connections.central');

        if (! is_array($cfg) || ($cfg['database'] ?? null) !== $central) {
            return;
        }

        /*
         * Tanpa `dbname` di DSN. Koneksi ini hanya dipakai untuk satu
         * perintah, lalu dibuang -- tidak pernah dipakai untuk query apa pun
         * yang butuh tabel.
         */
        $dsn = sprintf(
            'mysql:host=%s;port=%d',
            $cfg['host'] ?? '127.0.0.1',
            (int) ($cfg['port'] ?? 3306)
        );

        $pdo = new \PDO($dsn, (string) ($cfg['username'] ?? 'root'), (string) ($cfg['password'] ?? ''));

        $ada = $pdo->prepare(
            'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
        );
        $ada->execute([$central]);

        /*
         * Dua hal yang harus selalu benar, dan TIDAK selalu soal database-nya
         * sudah ada sebelumnya:
         *
         *   1. Database-nya ada. Yang baru dibuat masih kosong, jadi harus
         *      lewat `CREATE DATABASE`.
         *   2. Skemanya ada. Database yang sudah ada dari run sebelumnya bisa
         *      saja belum punya tabel pusat, jadi "database-nya ada" tidak
         *      boleh disimpulkan berarti "sudah siap dipakai".
         *
         * Karena itu tidak ada `return` di tengah jalan. Yang membuat proses
         * berhenti hanya penanda di bawah, dan penanda itu baru dipasang
         * SETELAH kedua langkah selesai.
         */
        if ($ada->fetchColumn() === false) {
            /*
             * `CREATE DATABASE` tidak bisa diparameterisasi, jadi nama harus
             * divalidasi lebih dulu. Bukan formalitas: config boleh berisi
             * apa saja, dan satu backtick cukup untuk mengubah statement.
             * `periksaDatabase()` di atas tidak ikut menjamin apa soal ini.
             */
            if (! preg_match('/^[A-Za-z0-9_]+$/', $central)) {
                return;
            }

            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $central
            ));
        }

        /*
         * Migrasi pusat, sekali saja per proses.
         *
         * Penandanya `RefreshDatabaseState::$migrated`, bukan pengecekan tabel
         * per test: setiap migrasi pusat sudah dijaga idempoten, tapi 15
         * migrasi yang jalan di setiap test tetap jauh lebih mahal dari satu
         * pengecekan.
         *
         * Penanda itu dipasang di sini, dan bukan asal dianggap sudah terisi:
         * kalau penandanya dipasang tanpa migrasi yang benar-benar jalan, test
         * yang tidak memakai trait sama sekali akan menganggap database sudah
         * siap -- padahal tidak ada yang pernah menyiapkannya.
         *
         * `DB::purge('central')` di akhir wajib: koneksi `central` bisa sudah
         * memegang handle yang gagal (dicoba sebelum database dibuat), dan
         * PDO yang sudah dibuka tidak pernah berpindah database sendiri.
         */
        if (static::$centralTestDatabaseReady) {
            return;
        }

        static::$centralTestDatabaseReady = true;

        DB::purge('central');

        $files = glob(base_path(
            (string) config('tenancy.central_migrations_path', 'database/migrations_central').'/*.php'
        )) ?: [];

        sort($files);

        foreach ($files as $file) {
            (require $file)->up();
        }
    }

    /**
     * Penanda: database pusat test sudah disiapkan di proses ini.
     *
     * BUKAN `RefreshDatabaseState::$migrated`, dan itu bukan pilihan gaya.
     * Static property milik trait itu diset true oleh `parent::setUp()` --
     * yang berjalan SEBELUM baris pemanggilnya di `setUp()`. Jadi memakainya
     * sebagai penanda membuat langkah ini selalu melompat tanpa pernah
     * menjalankan satu pun migrasi, tepat pada database yang justru sedang
     * disiapkan.
     *
     * Sifat static-nya juga yang membuatnya benar untuk seluruh proses:
     * dipanggil sekali saja, bukan di setiap test.
     */
    private static bool $centralTestDatabaseReady = false;

    /**
     * Pintu masuk pengaman, dipanggil sekali dari `setUp()`.
     *
     * `periksaDatabase()` yang melakukan pekerjaannya; pemisahan ini ada
     * supaya nama yang dipanggil `setUp()` terbaca sebagai ASSERTION -- kalau
     * ia gagal, test gagal, bukan dilewati diam-diam.
     */
    private function assertTestingDatabases(): void
    {
        $this->periksaDatabase();
    }

    /**
     * Tolak apa pun yang bukan database uji.
     *
     * Dipisah dari `assertTestingDatabases()` dengan akses `protected`, bukan
     * `private`, supaya `GuardTestingDatabasesTest` bisa memanggilnya langsung.
     * Menguji aturan lewat test jauh lebih murah daripada menjalankan
     * `artisan test` tanpa `phpunit.xml` sungguhan -- yang butuh proses
     * terpisah -- dan menguji hal yang sama persis.
     */
    protected function periksaDatabase(): void
    {
        $targets = [];

        foreach ((array) config('database.connections') as $name => $connection) {
            if (! is_array($connection)) {
                continue;
            }

            /*
             * Hanya MySQL-family yang datanya benar-benar ada di server yang
             * sama dengan `migrate:fresh`.
             *
             * Koneksi `sqlite` di `database.php` memakai
             * `env('DB_DATABASE', database_path('database.sqlite'))` -- ia
             * MEWARSILI nilai `DB_DATABASE` yang sebenarnya untuk MySQL, lalu
             * memperlakukannya sebagai NAMA FILE. Yang ada di sana bukan nama
             * database sama sekali, jadi ikut memperiksanya berarti menolak
             * suite karena file SQLite yang tidak pernah dibuka.
             */
            $driver = (string) ($connection['driver'] ?? '');

            if ($driver !== 'mysql' && $driver !== 'mariadb') {
                continue;
            }

            /*
             * Koneksi READ-ONLY yang tidak pernah ditulis test tidak bisa
             * merusak apa pun, dan tidak diperiksa.
             *
             * `database.php` mendefinisikan koneksi `legacy` ke server produksi
             * lama untuk import data. Namanya memang tidak mengandung
             * `testing`, dan memang tidak akan pernah jadi database uji --
             * tapi kalau ikut diperiksa, pengaman akan menolak SELURUH suite
             * hanya karena definisinya. Pengaman yang menolak hal yang benar
             * akan dimatikan orang, dan begitu hilang ia tidak melindungi apa pun.
             *
             * Daftar ini bisa ditambah, tapi hanya untuk koneksi yang terbukti
             * read-only -- bukan untuk koneksi yang "kita rasa tidak dipakai".
             */
            if (in_array($name, self::KONEKSI_READ_ONLY, true)) {
                continue;
            }

            $database = $connection['database'] ?? null;

            if (! is_string($database) || $database === '' || $database === ':memory:') {
                continue;
            }

            $targets['database.connections.'.$name] = $database;
        }

        $targets['tenancy.central_database'] = (string) config('tenancy.central_database');

        foreach ($targets as $asal => $database) {
            if (stripos($database, 'testing') !== false) {
                continue;
            }

            throw new RuntimeException(sprintf(
                "\n  TEST DITOLAK: koneksi `%s` menunjuk database `%s`.\n\n"
                ."  Test hanya boleh jalan pada database yang namanya mengandung\n"
                ."  `testing`. `%s` adalah database operasional yang berisi data\n"
                ."  nyata, dan test yang menunjuk ke sana AKAN MENGHAPUS isinya:\n"
                ."  `RefreshDatabase` menjalankan `migrate:fresh`, dan beberapa test\n"
                ."  memanggil `->delete()` tanpa filter pada tabel database pusat.\n\n"
                ."  Kalau ini muncul di server produksi, `.env` yang dipakai HARUS\n"
                ."  menunjuk ke database pengujian. Untuk menjalankan test:\n"
                ."      \$env:DB_DATABASE='astaloka_testing'\n"
                ."      \$env:TENANCY_CENTRAL_DATABASE='astaloka_testing_central'\n"
                ."      php artisan test\n",
                $asal,
                $database,
                $database
            ));
        }
    }

    /**
     * Koneksi yang memang tidak untuk ditulis, jadi tidak diperiksa.
     *
     * @var list<string>
     */
    private const KONEKSI_READ_ONLY = [
        // Server produksi lama, untuk import data sekali jalan.
        // Lihat `config/database.php` -- koneksinya read-only by design.
        'legacy',
    ];

    /**
     * Kapan transaksi tanpa query dianggap sudah terlantar.
     *
     * Lima menit dipilih karena jauh lebih panjang dari test mana pun
     * di suite ini (yang terlama sekitar 23 detik), tapi jauh lebih pendek
     * dari "menunggu sampai suite selesai". Proses yang dibunuh paksa
     * meninggalkan transaksinya dalam hitungan detik setelah mati, jadi
     * tidak ada yang perlu ditunggu lama sekali.
     */
    private const ABANDONED_AFTER_SECONDS = 300;

    /**
     * Tutup koneksi MySQL yang ditinggalkan proses lain.
     *
     * `RefreshDatabase` menjalankan `migrate:fresh` di awal suite, dan itu
     * menjatuhkan seluruh tabel dalam satu `drop table a, b, c, ...`.
     * MySQL mengeksekusikan itu sebagai satu operasi: satu tabel yang
     * terkunci membuat SELURUH perintah menunggu -- tanpa error, tanpa batas
     * waktu, dan tanpa satu baris output pun.
     *
     * Kunci itu datang dari koneksi yang ditinggalkan. Test yang dibunuh paksa
     * -- timeout, Ctrl+C, proses di-kill -- meninggalkan transaksi MySQL yang
     * masih hidup, dan owning process-nya sudah tidak ada. Tidak ada yang akan
     * menutupnya sendiri.
     *
     * Yang dibedakan di sini transaksi yang sedang dipakai dari yang terlantar.
     * Transaksi yang bekerja punya `trx_query` terisi; koneksi yang ditinggalkan
     * idle dengan query kosong. Membunuh yang salah akan menewaskan test yang
     * sedang berjalan -- jadi sengaja konservatif di sini penting, dan alasan
     * itu juga yang membuat langkah ini tidak menghapus atau mengubah apa pun.
     *
     * TAPI `trx_query` kosong BUKAN bukti koneksi itu terlantar, dan itulah
     * bug yang pernah ada di sini. `RefreshDatabase` membungkus SETIAP test
     * dalam satu transaksi, jadi di antara dua statement -- tepat saat test
     * sedang melakukan hal lain, misalnya menunggu response HTTP -- transaksi
     * itu idle dan `trx_query`-nya kosong. Miliknya proses lain yang sedang
     * berjalan, tapi kode ini membacanya sebagai sambungan yang ditinggalkan
     * dan membunuhnya.
     *
     * Gejalanya persis seperti koneksi yang mati sendiri:
     *
     *     SQLSTATE[HY000]: General error: 2006 MySQL server has gone away
     *
     * ...dan muncul sporadis di test yang tidak ada hubungannya sama
     * sekali dengan apa pun yang diuji -- beberapa dari enam test yang
     * gagal, dipilih hampir acak. Setelah test selesai, rollback-nya jadi
     * tidak punya koneksi untuk dipakai, dan test berikutnya mewarisi
     * keadaan setengah jadi yang tidak jelas asalnya.
     *
     * Yang membedakan keduanya dengan benar adalah WAKTU, bukan `trx_query`.
     * Transaksi milik test yang sedang hidup berumur hitungan detik;
     * transaksi dari proses yang dibunuh paksa berumur menit -- dan tidak
     * akan pernah ditutup oleh siapa pun.
     *
     * Yang SENGAJA tidak ada: `truncate`, `drop`, atau `delete`. Test ini hanya
     * menutup koneksi. Tutupnya jelas oleh OS begitu prosesnya mati, jadi ini
     * tidak mengubah apa pun selain membebaskan lock sooner.
     *
     * Dijalankan SEBELUM app di-bootstrap, jadi koneksi diambil langsung lewat
     * PDO. Lewat `DB::` akan memaksa container boot -- yang justru menunda
     * pemanggilan ini ke titik yang tidak berguna.
     */
    private function releaseAbandonedTransactions(): void
    {
        $host = (string) (getenv('DB_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('DB_PORT') ?: '3306');
        $user = (string) (getenv('DB_USERNAME') ?: 'root');
        $pass = (string) (getenv('DB_PASSWORD') ?: '');

        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%s', $host, $port),
                $user,
                $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]
            );
        } catch (Throwable) {
            // Tidak ada MySQL, atau kredensial salah. Bukan alasan menggagalkan
            // test di sini -- masalah aslinya akan muncul dengan pesan yang jauh
            // lebih berguna daripada error dari langkah ini.
            return;
        }

        $trx = $pdo->query('SELECT trx_mysql_thread_id AS id, trx_query
                           FROM information_schema.innodb_trx');

        if ($trx === false) {
            return;
        }

        foreach ($trx->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            // Yang sedang menjalankan query belum selesai -- jangan disentuh.
            if (trim((string) $row['trx_query']) !== '') {
                continue;
            }

            // Query kosong belum berarti terlantar: transaksi milik test yang
            // sedang hidup juga idle di antara dua statement. Yang membedakan
            // keduanya adalah UMUR, jadi itu yang dipakai di sini.
            if ($this->transactionIsYoungerThan($pdo, (int) $row['id'], self::ABANDONED_AFTER_SECONDS)) {
                continue;
            }

            try {
                $pdo->exec('KILL '.(int) $row['id']);
            } catch (Throwable) {
                // Koneksinya sudah tutup sendiri di antara SELECT dan KILL.
            }
        }
    }

    /**
     * Apakah transaksi itu baru saja dimulai?
     *
     * Yang ditanyakan lewat `information_schema.innodb_trx.trx_started`,
     * bukan lewat `PROCESSLIST.TIME`, karena yang dibutuhkan adalah umur
     * transaksi -- kapan dimulai -- bukan berapa lama statement terakhir
     * berjalan. Yang terakhir tidak ada artinya untuk transaksi yang sedang
     * idle, dan justru itu kasus yang paling sering muncul di sini.
     *
     * Kembalinya `false` kalau umurnya tidak bisa dibaca, supaya koneksi
     * yang tidak bisa diperiksa dibiarkan hidup. Membunuh koneksi karena
     * gagal membaca umurnya sama dengan membakar test yang sedang jalan
     * demi hemat beberapa detik di suite yang sudah berjalan setengah jam.
     */
    private function transactionIsYoungerThan(\PDO $pdo, int $id, int $seconds): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS umur
                 FROM information_schema.innodb_trx
                 WHERE trx_mysql_thread_id = ?'
            );
            $stmt->execute([$id]);

            $umur = $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }

        // Umur tidak terbaca (baris sudah hilang, atau kolomnya berubah di
        // versi MySQL lain): anggap sudah lama, supaya perilaku lama -- yaitu
        // membiarkan transaksi -- yang dipakai.
        if ($umur === false || $umur === null) {
            return false;
        }

        return (int) $umur < $seconds;
    }
}
