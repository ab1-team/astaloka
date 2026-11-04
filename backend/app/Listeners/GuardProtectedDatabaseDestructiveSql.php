<?php

namespace App\Listeners;

use Illuminate\Database\Events\ConnectionEstablished;
use RuntimeException;

/**
 * Penjaga TERAKHIR: menolak SQL destruktif di database yang dilindungi.
 *
 * Kenapa perlu penjaga ketiga
 * ===========================
 *
 * `GuardDestructiveCommands` dan `GuardCentralDatabaseWrites` keduanya
 * bergantung pada event `CommandStarting`. Event itu hanya terkirim kalau
 * dispatcher Symfony-nya sudah terpasang -- dan
 * `Illuminate\Foundation\Console\Kernel` SENGAJA tidak memasangnya saat
 * `runningUnitTests()` bernilai true. Akibatnya kedua penjaga itu diam-diam
 * tidak aktif selama `php artisan test` berjalan.
 *
 * Akibatnya `RefreshDatabase` yang menjalankan `migrate:fresh` -> `db:wipe`
 * -> `dropAllTables()` bisa menjatuhkan SELURUH tabel database pusat dalam
 * satu statement, tanpa satu pun penjaga yang menyadarinya.
 *
 * Yang membedakan penjaga ini
 * ===========================
 *
 * Penjaga ini tidak bergantung pada perintah apa pun. Ia memasang kait
 * `beforeExecuting` pada setiap koneksi MySQL, sehingga setiap SQL yang
 * akan dijalankan diperiksa -- termasuk SQL yang dibangun di luar artisan,
 * dipanggil dari dalam job, atau ditulis tangan.
 *
 * Dan yang diperiksa adalah nama database yang BENAR-BENAR dibuka koneksi
 * itu, bukan yang tertulis di config. Config bisa berubah di runtime --
 * `TenantManager::activate()` menimpanya setiap kali sebuah lokasi
 * diaktifkan -- jadi hanya nama database yang benar-benar dipakai PDO yang
 * bisa dipercaya.
 *
 * Yang ditolak
 * ============
 *
 *   1. `drop table` untuk LEBIH DARI SATI tabel. Itulah bentuk yang dipakai
 *      `dropAllTables()`: `drop table `a`, `b`, `c`, ...`. Satu statement,
 *      seluruh isi database hilang.
 *
 *   2. `drop table` TANPA `if exists`. Bentuk tunggal ini adalah
 *      `dropAllTables()` pada database yang hanya punya satu tabel -- dan
 *      `drop table if exists` (bentuk yang dipakai method `down()` setiap
 *      migrasi) tetap boleh.
 *
 *   3. `drop database` yang menunjuk database yang dilindungi.
 *
 * Yang TIDAK ditolak
 * ==================
 *
 * `truncate` TIDAK ditolak. Seeder pusat memakainya untuk mengisi ulang
 * `akun_level_1..3` -- itu mengisi ulang isi, bukan menghapus database.
 *
 * `drop database` atas database LOKASI juga tetap boleh: itu jalur resmi
 * menghapus lokasi (`TenantManager::dropDatabase()`). Yang diperiksa di
 * sini adalah nama TARGET-nya, bukan nama database yang dipakai koneksinya.
 *
 * Database uji tetap bebas: namanya mengandung `testing`, dan itu memang
 * jalur yang dipakai `php artisan test` untuk menyiapkan ulang state.
 */
class GuardProtectedDatabaseDestructiveSql
{
    public function handle(ConnectionEstablished $event): void
    {
        $connection = $event->connection;

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        /*
         * Kait dipasang di SEMUA koneksi MySQL, bukan hanya yang kelihatan
         * dilindungi.
         *
         * Alasannya: keputusan "database ini dilindungi atau tidak" bisa
         * berubah di tengah proses -- `TenantManager::activate()` mengganti
         * database aktif, dan config pusat pun bisa ditimpa. Kalau
         * keputusannya diambil sekali di sini, kait untuk database yang baru
         * aktif setelah itu bisa jadi tidak terpasang -- persis celah yang
         * harus ditutup.
         *
         * Biayanya kecil: kaitnya hanya memeriksa satu awalan string, dan
         * pemeriksaan lengkap hanya jalan untuk statement `drop`.
         */
        $connection->beforeExecuting(function ($query) use ($connection): void {
            if (! is_string($query)) {
                return;
            }

            $this->assertBolehDijalankan($query, (string) $connection->getDatabaseName());
        });
    }

    /**
     * Tolak statement yang akan menghapus isi database yang dilindungi.
     *
     * Dipisah dari `handle()` supaya aturannya bisa diuji tanpa membuka
     * koneksi ke database mana pun -- persis seperti listener lain di repo
     * ini.
     *
     * @throws RuntimeException Kalau statement itu merusak database yang dilindungi.
     */
    public function assertBolehDijalankan(string $query, string $database): void
    {
        $sql = strtolower(trim($query));

        if (! str_starts_with($sql, 'drop ')) {
            return;
        }

        /*
         * `drop database` diperiksa berdasarkan TARGET-nya, bukan berdasarkan
         * database tempat statement itu dijalankan.
         *
         * `TenantManager::dropDatabase()` menghapus database LOKASI lewat
         * koneksi `central` -- jadi statement-nya dijalankan di database
         * pusat, tapi targetnya lokasi. Menolak berdasarkan lokasi
         * statement akan memblokir jalur resmi penghapusan lokasi.
         *
         * Aturannya persis sama dengan yang dipakai `dropDatabase()` di
         * `TenantManager`: yang ditolak hanya database PUSAT. Database lokasi
         * boleh dihapus -- itu memang jalur resminya, dan isinya bisa
         * dibangun ulang lewat provisioning.
         */
        if (preg_match('/^drop\s+database\s+(?:if\s+exists\s+)?`?([a-z0-9_]+)`?/i', $sql, $m) === 1) {
            if ($this->databasePusatDilindungi($m[1])) {
                $this->tolak($sql, $m[1], 'sebuah database');
            }

            return;
        }

        /*
         * `drop table` diperiksa berdasarkan database tempat statement
         * dijalankan -- itu satu-satunya sumber yang bisa dipercaya, karena
         * config bisa berubah di runtime.
         *
         * Tapi config hanya tahu database lokasi yang SEDANG AKTIF. Lokasi
         * lain tidak ada di config mana pun, jadi aturan prefix dipakai untuk
         * menutup celah itu.
         */
        if (! $this->databaseTerlindungi($database)) {
            return;
        }

        if (preg_match('/^drop\s+(?:temporary\s+)?table\s+(?:if\s+exists\s+)?(.+)$/i', $sql, $m) !== 1) {
            return;
        }

        $tabel = array_filter(array_map('trim', explode(',', $m[1])));

        /*
         * Lebih dari satu tabel = `dropAllTables()`: seluruh isi database
         * hilang dalam satu statement. Satu tabel pun tetap ditolak kalau
         * `if exists` tidak dipakai -- `drop table if exists` adalah idiom
         * `down()` setiap migrasi, sedangkan `drop table` polos adalah
         * `dropAllTables()` pada database yang hanya punya satu tabel.
         */
        if (count($tabel) > 1 || stripos($sql, 'if exists') === false) {
            $this->tolak($sql, $database, 'tabel');
        }
    }

    /**
     * Apakah database ini yang wajib dilindungi dari `drop table`.
     *
     * Tiga syarat, semuanya wajib:
     *
     *   1. Namanya bukan database uji. `php artisan test` menyiapkan state
     *      lewat `migrate:fresh`, jadi database uji HARUS boleh dijatuhkan.
     *
     *   2. Namanya salah satu database yang dikonfigurasi untuk aplikasi
     *      ini -- jadi database pusat dan database lokasi yang sedang aktif.
     *
     *   3. Namanya berawalan prefix tenancy -- jadi database lokasi yang
     *      SEDANG TIDAK aktif ikut terlindungi, karena config tidak
     *      mengetahuinya.
     *
     * Syarat kedua dan ketiga membaca config yang dibaca SAAT INI, bukan saat
     * kait dipasang -- supaya perubahan runtime pada config ikut
     * diperhitungkan.
     */
    public function databaseTerlindungi(string $database): bool
    {
        if ($database === '') {
            return false;
        }

        if ($this->databaseUji($database)) {
            return false;
        }

        return $this->databaseDilindungi($database) || $this->databaseLokasi($database);
    }

    /**
     * Apakah nama ini milik database UJI.
     *
     * Database uji boleh dijatuhkan -- itu justru jalur yang dipakai
     * `php artisan test` untuk menyiapkan ulang state. Namanya selalu
     * mengandung `testing`, persis seperti aturan di `Tests\TestCase`.
     */
    public function databaseUji(string $database): bool
    {
        return $database !== '' && stripos($database, 'testing') !== false;
    }

    /**
     * Apakah database ini salah satu database yang dikonfigurasi.
     *
     * Pemeriksaan daftarnya ada di {@see databaseYangDilindungi()}; yang dicek
     * di sini hanya syarat "bukan database uji".
     */
    public function databaseDilindungi(string $database): bool
    {
        if ($database === '') {
            return false;
        }

        if ($this->databaseUji($database)) {
            return false;
        }

        return in_array($database, $this->databaseYangDilindungi(), true);
    }

    /**
     * Apakah database ini database PUSAT yang wajib dilindungi.
     *
     * Lebih sempit dari {@see databaseDilindungi()}: yang diperiksa di sini
     * hanya nama database pusat -- dengan atau tanpa suffix, karena
     * `TENANCY_CENTRAL_DATABASE` dan `database.connections.central.database`
     * bisa saja menunjuk nama yang berbeda satu sama lain.
     *
     * Bedanya penting untuk `drop database`: menghapus database LOKASI itu
     * jalur resmi (`TenantManager::dropDatabase()`), sedangkan menghapus
     * database PUSAT berarti kehilangan registry seluruh sistem -- tidak ada
     * satu pun lokasi yang bisa dibangun ulang tanpa database itu.
     */
    public function databasePusatDilindungi(string $database): bool
    {
        if ($database === '') {
            return false;
        }

        if ($this->databaseUji($database)) {
            return false;
        }

        $pusat = [];

        $namaPusat = (string) config('tenancy.central_database');
        if ($namaPusat !== '') {
            $pusat[] = $namaPusat;
        }

        $koneksiPusat = (string) config('tenancy.central_connection', 'central');
        $namaKoneksiPusat = config('database.connections.'.$koneksiPusat.'.database');
        if (is_string($namaKoneksiPusat) && $namaKoneksiPusat !== '') {
            $pusat[] = $namaKoneksiPusat;
        }

        return in_array($database, array_unique($pusat), true);
    }

    /**
     * Nama-nama database yang tidak boleh kehilangan isinya.
     *
     * Aturannya satu kalimat: SEMUA database MySQL yang dikonfigurasi dan
     * namanya bukan database uji.
     *
     * Kenapa bukan hanya database pusat? Karena database yang benar-benar
     * dibuka koneksi bisa database mana pun -- `TenantManager::activate()`
     * menukar isi koneksi `tenant` ke database lokasi yang sedang aktif, dan
     * config ikut diperbarui saat itu. Daftar yang hanya berisi "pusat"
     * akan membiarkan database lokasi yang justru sedang aktif lolos.
     *
     * Kenapa membaca config, bukan daftar hard-coded? Karena nama database
     * lokasi tidak bisa diketahui dari luar: ia berasal dari baris registry
     * `tenants`, dan berubah-ubah per lokasi.
     *
     * @return list<string>
     */
    private function databaseYangDilindungi(): array
    {
        $nama = [];

        foreach ((array) config('database.connections') as $koneksi) {
            if (! is_array($koneksi)) {
                continue;
            }

            $driver = (string) ($koneksi['driver'] ?? '');

            if ($driver !== 'mysql' && $driver !== 'mariadb') {
                continue;
            }

            $database = $koneksi['database'] ?? null;

            if (is_string($database) && $database !== '') {
                $nama[] = $database;
            }
        }

        $pusat = (string) config('tenancy.central_database');
        if ($pusat !== '') {
            $nama[] = $pusat;
        }

        return array_values(array_unique($nama));
    }

    /**
     * Apakah nama ini milik database LOKASI.
     *
     * Database lokasi selalu berawalan prefix tenancy
     * (`TENANCY_DATABASE_PREFIX`, bawaannya `astaloka_`) -- itu satu-satunya
     * aturan yang berlaku untuk semua lokasi, karena slug-nya boleh apa saja.
     * Database pusat justru TIDAK memakai prefix itu, jadi tidak ada tumpang
     * tindih antara keduanya.
     *
     * Kenapa perlu aturan ini kalau config sudah cukup? Karena config hanya
     * tahu database lokasi yang SEDANG AKTIF. Lokasi lain -- yang aktifnya
     * di request berbeda -- tidak ada di config mana pun. Tanpa aturan ini,
     * `drop table` untuk seluruh tabel pada database lokasi yang tidak sedang
     * dilayani akan lolos.
     */
    public function databaseLokasi(string $database): bool
    {
        if ($database === '') {
            return false;
        }

        if ($this->databaseUji($database)) {
            return false;
        }

        $prefix = (string) config('tenancy.database_prefix', 'astaloka_');

        if ($prefix === '') {
            return false;
        }

        return str_starts_with($database, $prefix);
    }

    /**
     * Hentikan statement dengan pesan yang menjelaskan cara yang benar.
     */
    private function tolak(string $sql, string $database, string $jenis): void
    {
        throw new RuntimeException(sprintf(
            "\n  DITOLAK: statement `drop` dijalankan pada database `%s`.\n\n"
            ."  Statement itu menghapus %s secara permanen, dan `%s` berisi\n"
            ."  data yang tidak bisa dipulihkan.\n\n"
            ."  Database uji (`*_testing*`) tetap boleh dijatuhkan -- itu jalur\n"
            ."  yang dipakai `php artisan test` untuk menyiapkan ulang state.\n",
            $database,
            $jenis,
            $database
        ));
    }
}
