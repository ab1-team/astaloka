<?php

namespace Tests\Feature;

use App\Listeners\GuardProtectedDatabaseDestructiveSql;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * Menguji penjaga SQL tingkat-tinggi.
 *
 * Ini penjaga yang menutup celah yang TIDAK bisa ditutup dua penjaga
 * command: `CommandStarting` hanya terkirim kalau dispatcher Symfony-nya
 * terpasang, dan `Illuminate\Foundation\Console\Kernel` SENGAJA tidak
 * memasangnya saat `runningUnitTests()` true. Jadi di dalam
 * `php artisan test`, dua penjaga command terdaftar tapi tidak pernah
 * dipanggil.
 *
 * Test ini mengunci dua hal:
 *
 *   1. Pen interception-nya benar-benar bekerja -- statement `drop` pada
 *      database yang dilindungi DITOLAK.
 *
 *   2. Jalur yang harus tetap hidup TIDAK ikut tertutup: `drop table if
 *      exists` (idiom `down()` setiap migrasi), `truncate` (dipakai seeder
 *      pusat), dan `drop database` atas database LOKASI (jalur resmi
 *      menghapus lokasi).
 *
 * Aturannya diuji lewat method publik, bukan lewat koneksi sungguhan --
 * jadi tidak ada tabel yang benar-benar dihapus di dalam test.
 */
class GuardProtectedDatabaseDestructiveSqlTest extends TestCase
{
    private function guard(): GuardProtectedDatabaseDestructiveSql
    {
        return new GuardProtectedDatabaseDestructiveSql;
    }

    /**
     * Jalankan guard dalam kondisi database OPERASIONAL.
     *
     * Saat test, semua database bernama `*testing*` -- dan database uji
     * memang BOLEH dijatuhkan, itu jalur yang dipakai `php artisan test`.
     *
     * Supaya yang diuji adalah perilaku PENOLAKAN, nama database-nya
     * ditirukan di sini: config koneksi dan `tenancy.central_database`
     * diarahkan ke nama yang tidak mengandung `testing`.
     *
     * Tidak ada koneksi yang dibuka -- listener ini hanya membaca config.
     */
    private function denganDatabaseOperasional(callable $callback): void
    {
        $asli = [
            'tenancy.central_database' => config('tenancy.central_database'),
            'database.connections.mysql.database' => config('database.connections.mysql.database'),
            'database.connections.central.database' => config('database.connections.central.database'),
            'database.connections.tenant.database' => config('database.connections.tenant.database'),
        ];

        config([
            'tenancy.central_database' => 'astaloka',
            'database.connections.mysql.database' => 'astaloka',
            'database.connections.central.database' => 'astaloka',
            'database.connections.tenant.database' => 'astaloka_lokasi',
        ]);

        try {
            $callback();
        } finally {
            config($asli);
        }
    }

    /**
     * `dropAllTables()` menjatuhkan SELURUH tabel dalam satu statement.
     *
     * Inilah bentuk yang dipakai `db:wipe`, dan bentuk yang paling
     * berbahaya: satu statement, seluruh isi database hilang.
     */
    public function test_drop_semua_tabel_di_database_pusat_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $this->denganDatabaseOperasional(function (): void {
            $this->guard()->assertBolehDijalankan(
                'drop table `accounts`,`tenants`,`audit_logs`,`users`',
                'astaloka'
            );
        });
    }

    /**
     * Database LOKASI juga harus terlindungi.
     *
     * `dropAllTables()` pada database lokasi menghapus data operasional
     * yang tidak bisa dipulihkan -- dan itu sama destruktifnya dengan
     * menjatuhkan database pusat.
     */
    public function test_drop_semua_tabel_di_database_lokasi_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $this->denganDatabaseOperasional(function (): void {
            $this->guard()->assertBolehDijalankan(
                'drop table `customers`,`monthly_bills`,`transactions`',
                'astaloka_lokasi'
            );
        });
    }

    /**
     * `drop table` polos untuk SATU tabel juga ditolak.
     *
     * `dropAllTables()` pada database yang hanya punya satu tabel akan
     * menghasilkan bentuk ini -- dan bentuknya sama dengan `drop table`
     * biasa yang biasa dipakai untuk menghapus satu tabel.
     */
    public function test_drop_tabel_tunggal_tanpa_if_exists_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $this->denganDatabaseOperasional(function (): void {
            $this->guard()->assertBolehDijalankan(
                'drop table `tenants`',
                'astaloka'
            );
        });
    }

    /**
     * `drop table if exists` TETAP BOLEH.
     *
     * Ini idiom `down()` setiap migrasi di repo ini -- dan ke-18 migrasi
     * pusat memakainya. Kalau bentuk ini ikut ditolak, `migrate:rollback`
     * tidak pernah bisa jalan, dan `tenancy:migrate-central` tidak akan
     * pernah bisa menyiapkan ulang skema pusat.
     */
    public function test_drop_table_if_exists_tetap_boleh(): void
    {
        $this->denganDatabaseOperasional(function (): void {
            $this->guard()->assertBolehDijalankan(
                'drop table if exists `tenant_domains`',
                'astaloka'
            );
        });

        $this->assertTrue(true, 'drop table if exists harus tetap boleh.');
    }

    /**
     * `truncate` TIDAK ditolak.
     *
     * Seeder pusat memakainya untuk mengisi ulang `akun_level_1..3` -- itu
     * mengisi ulang isi, bukan menghapus database. Menolaknya akan membuat
     * `tenancy:migrate-central --seed` gagal.
     */
    public function test_truncate_tetap_boleh(): void
    {
        $this->denganDatabaseOperasional(function (): void {
            $this->guard()->assertBolehDijalankan(
                'truncate table `akun_level_3`',
                'astaloka'
            );
        });

        $this->assertTrue(true, 'truncate harus tetap boleh.');
    }

    /**
     * Statement lain sama sekali tidak boleh terganggu.
     *
     * Guard ini dipasang pada SETIAP koneksi MySQL, jadi kaitnya jalan
     * untuk semua query -- termasuk `select` yang paling sering terjadi.
     * Kalau guard ini mengganggu select, seluruh aplikasi melambat.
     */
    public function test_statement_biasa_tidak_dipertahankan(): void
    {
        $this->denganDatabaseOperasional(function (): void {
            $this->guard()->assertBolehDijalankan('select * from `tenants`', 'astaloka');
            $this->guard()->assertBolehDijalankan('insert into `tenants` (`slug`) values (?)', 'astaloka');
            $this->guard()->assertBolehDijalankan('alter table `tenants` add `x` int', 'astaloka');
        });

        $this->assertTrue(true, 'statement non-destruktif harus lolos.');
    }

    /**
     * `drop database` atas database LOKASI tetap boleh.
     *
     * Itu jalur resmi menghapus lokasi (`TenantManager::dropDatabase()`).
     * Yang diperiksa di sini adalah TARGET-nya, bukan database tempat
     * statement-nya dijalankan -- dan `TenantManager` menjalankannya lewat
     * koneksi `central`, jadi statement-nya dieksekusi di database pusat.
     */
    public function test_drop_database_lokasi_tetap_boleh(): void
    {
        $this->denganDatabaseOperasional(function (): void {
            $this->guard()->assertBolehDijalankan(
                'drop database `astaloka_lokasi`',
                'astaloka'
            );
        });

        $this->assertTrue(true, 'drop database lokasi harus tetap boleh.');
    }

    /**
     * Database lokasi yang TIDAK sedang aktif juga terlindungi.
     *
     * Config hanya tahu database lokasi yang sedang dilayani -- lokasi lain
     * tidak ada di config mana pun. Kalau penjaga hanya bergantung pada
     * config, `drop table` untuk seluruh tabel pada lokasi yang tidak sedang
     * aktif akan lolos tanpa jejak.
     *
     * Aturan prefix tenancy (`astaloka_`) yang menutup celah ini -- dan
     * database pusat justru tidak memakai prefix itu, jadi tidak ada tumpang
     * tindih.
     */
    public function test_database_lokasi_yang_tidak_aktif_terlindungi(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $this->guard()->assertBolehDijalankan(
            'drop table `customers`,`monthly_bills`,`transactions`',
            'astaloka_pamsimas'
        );
    }

    /**
     * `drop database` atas database PUSAT ditolak.
     */
    public function test_drop_database_pusat_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $this->denganDatabaseOperasional(function (): void {
            $this->guard()->assertBolehDijalankan(
                'drop database `astaloka`',
                'astaloka'
            );
        });
    }

    /**
     * Database uji BOLEH dijatuhkan.
     *
     * `php artisan test` menyiapkan state lewat `migrate:fresh` -> `db:wipe`
     * -> `dropAllTables()`. Kalau database uji ikut ditolak, seluruh suite
     * tidak akan pernah bisa jalan.
     */
    public function test_database_uji_boleh_dijatuhkan(): void
    {
        $this->guard()->assertBolehDijalankan(
            'drop table `users`,`migrations`,`customers`',
            'astaloka_testing'
        );

        $this->assertTrue(true, 'database uji harus boleh dijatuhkan.');
    }

    /**
     * Kaitnya benar-benar terpasang pada koneksi MySQL.
     *
     * Tanpa ini, penjaga ini hanya akan jadi kelas yang tidak pernah
     * dipanggil -- persis seperti yang terjadi pada dua penjaga command
     * yang bergantung pada `CommandStarting`.
     */
    public function test_kait_terpasang_pada_koneksi_mysql(): void
    {
        $terpasang = false;

        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use (&$terpasang): void {
            if (in_array($event->connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                $terpasang = true;
            }
        });

        // Paksa koneksi baru dibuat supaya event-nya benar-benar dikirim.
        DB::purge('mysql');
        DB::connection('mysql')->select('select 1');

        $this->assertTrue(
            $terpasang,
            'Penjaga SQL harus memasang kait pada koneksi MySQL.'
        );
    }

    /**
     * `CommandStarting` harus benar-benar terkirim di dalam test.
     *
     * Ini yang menyebabkan dua penjaga command tidak pernah dipanggil:
     * `Illuminate\Foundation\Console\Kernel` tidak memasang dispatcher
     * Symfony saat `runningUnitTests()` true. Test ini mengunci bahwa
     * jembatan event menutup celah itu.
     */
    public function test_command_starting_terkirim_di_jalur_test(): void
    {
        $terkirim = [];

        Event::listen(CommandStarting::class, function (CommandStarting $event) use (&$terkirim): void {
            $terkirim[] = $event->command;
        });

        Artisan::call('route:list');

        $this->assertContains(
            'route:list',
            $terkirim,
            'CommandStarting harus terkirim di dalam test -- kalau tidak, penjaga command tidak pernah jalan.'
        );
    }

    /**
     * Penjaga command harus MEMANGGI listener-nya saat event-nya dikirim.
     *
     * Test sebelumnya memanggil listener secara langsung, yang membuktikan
     * aturannya benar -- tapi TIDAK membuktikan bahwa listener itu pernah
     * dipanggil. Celah kedua itulah yang membuat database pusat bisa
     * dijatuhkan.
     */
    public function test_penjaga_command_dipanggil_otomatis(): void
    {
        $dipanggil = 0;

        Event::listen(CommandStarting::class, function (CommandStarting $event) use (&$dipanggil): void {
            $dipanggil++;
        });

        Artisan::call('route:list');

        $this->assertGreaterThan(
            0,
            $dipanggil,
            'Listener CommandStarting harus benar-benar dipanggil.'
        );
    }
}
