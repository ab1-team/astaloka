<?php

namespace Tests\Feature;

use App\Console\Commands\SchedulePauseResilient;
use App\Console\Commands\ScheduleResumeResilient;
use App\Console\Commands\ScheduleRunResilient;
use Illuminate\Cache\Repository;
use Illuminate\Console\Application;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;
use Throwable;

/**
 * Test untuk perbaikan "scheduler crash saat database tidak terjangkau".
 *
 * LATAR BELAKANG
 * ==============
 *
 * `schedule:run` dipanggil SETIAP MENIT oleh Task Scheduler. Sebelum
 * menjalankan job apa pun, framework membaca cache: flag pause, flag
 * interrupt, dan mutex `withoutOverlapping()`.
 *
 * Cache aplikasi memakai store `database`, jadi saat MySQL mati (atau belum
 * selesai start), pembacaan itu melempar `SQLSTATE[HY000] [2002]` dan
 * `schedule:run` CRASH -- sebelum job apa pun sempat dicek.
 *
 * Akibatnya generate piutang tunggakan pada tanggal generate-nya bisa
 * terlewat. Gate tanggal generate bersifat PERSIS (`===`), jadi satu malam
 * yang terlewat berarti bulan itu TIDAK PERNAH punya jurnal piutang.
 *
 * Perbaikannya dua bagian:
 *
 *   1. `config('cache.schedule_store')` = `file` -- cache internal scheduler
 *      tidak butuh database sama sekali.
 *   2. `ScheduleRunResilient` overriding `schedule:run` bawaan -- kalau
 *      pembacaan cache GAGAL, scheduler tidak boleh mati.
 *
 * Plus satu bagian yang mudah terlewat: `schedule:pause` dan
 * `schedule:resume` harus menulis/menghapus flag di store yang SAMA dengan
 * yang dibaca scheduler. Kalau tidak, `schedule:pause` menganggur tanpa
 * error -- scheduler tetap jalan padahal perintahnya bilang "paused".
 *
 * @see ScheduleRunResilient
 * @see SchedulePauseResilient
 * @see ScheduleResumeResilient
 */
class SchedulerResilienceTest extends TestCase
{
    public function test_cache_store_scheduler_tidak_memakai_database(): void
    {
        $this->assertSame(
            'file',
            Config::get('cache.schedule_store'),
            'Mutex scheduler harus pakai store yang tidak butuh database.'
        );
    }

    public function test_cache_store_scheduler_bisa_ditimpa_lewat_env(): void
    {
        // Nilai harus dibaca dari env, supaya instalasi multi-server bisa
        // memakainya Redis untuk mutex lintas server.
        $this->assertSame(
            'file',
            env('SCHEDULE_CACHE_STORE', 'file'),
            'Nilai store scheduler harus bisa datang dari environment.'
        );
    }

    public function test_command_schedule_run_yang_dipakai_adalah_override(): void
    {
        $command = $this->scheduleRunCommand();

        $this->assertInstanceOf(
            ScheduleRunResilient::class,
            $command,
            'schedule:run harus memakai override yang tahan database mati.'
        );
    }

    public function test_schedule_run_tidak_melempar_exception_saat_cache_gagal(): void
    {
        $command = $this->scheduleRunCommand();

        // Simulasikan cache yang tidak bisa dijangkau -- sama seperti
        // MySQL mati.
        $this->swapBrokenCache();

        // `isPaused()` harus mengembalikan false, bukan melempar.
        $paused = $this->callProtected($command, 'isPaused');

        $this->assertFalse(
            $paused,
            'Scheduler tidak boleh dianggap paused saat cache tidak terjangkau.'
        );
    }

    public function test_scheduler_tetap_berjalan_saat_database_tidak_bisa_dijangkau(): void
    {
        // Simulasikan kondisi sebenarnya: MySQL mati.
        //
        // Test ini tidak mematikan MySQL sungguhan -- ia memaksa koneksi
        // `central` dan `mysql` untuk melempar error, lalu memastikan
        // `schedule:run` tetap selesai tanpa exception.
        $this->breakDatabaseConnections();

        $exitCode = $this->artisan('schedule:run', ['--whisper' => true])->run();

        $this->assertSame(
            0,
            $exitCode,
            'schedule:run harus selesai dengan sukses walau database mati.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Flag pause: harus ditulis dan dibaca di store yang SAMA
    |--------------------------------------------------------------------------
    |
    | Ini yang paling mudah terlewat dan paling menyesatkan.
    |
    | Perintah bawaan Laravel menulis flag pause ke cache DEFAULT
    | (`database`), sementara scheduler membacanya dari store `file`.
    | Dua store berbeda, dua kunci berbeda -- flag yang ditulis tidak
    | pernah dibaca.
    |
    | Gejalanya: `schedule:pause` mencetak "paused" dengan exit code 0,
    | lalu scheduler tetap menjalankan job setiap menit tanpa error.
    |
    */

    public function test_schedule_pause_menulis_flag_ke_store_yang_dibaca_scheduler(): void
    {
        $this->artisan('schedule:pause')->run();

        $flagInSchedulerStore = $this->schedulerCache()
            ->get('illuminate:schedule:paused', false);

        $this->assertTrue(
            $flagInSchedulerStore,
            'schedule:pause harus menulis flag ke store yang dibaca scheduler.'
        );
    }

    public function test_schedule_resume_menghapus_flag_dari_store_yang_dibaca_scheduler(): void
    {
        $this->artisan('schedule:pause')->run();

        $this->assertTrue(
            $this->schedulerCache()->get('illuminate:schedule:paused', false),
            'Flag harus ada sebelum resume.'
        );

        $this->artisan('schedule:resume')->run();

        $this->assertFalse(
            $this->schedulerCache()->get('illuminate:schedule:paused', false),
            'schedule:resume harus menghapus flag dari store yang dibaca scheduler.'
        );
    }

    public function test_scheduler_menghormati_flag_pause(): void
    {
        $this->artisan('schedule:pause')->run();

        $command = $this->scheduleRunCommand();

        $paused = $this->callProtected($command, 'isPaused');

        $this->assertTrue(
            $paused,
            'Scheduler harus menghormati flag pause yang ditulis schedule:pause.'
        );
    }

    public function test_schedule_pause_terdaftar_sebagai_override(): void
    {
        $artisan = $this->artisanApplication();

        $this->assertInstanceOf(
            SchedulePauseResilient::class,
            $artisan->find('schedule:pause'),
            'schedule:pause harus memakai override yang menulis ke store yang benar.'
        );
    }

    public function test_schedule_resume_terdaftar_sebagai_override(): void
    {
        $artisan = $this->artisanApplication();

        $this->assertInstanceOf(
            ScheduleResumeResilient::class,
            $artisan->find('schedule:resume'),
            'schedule:resume harus memakai override yang menghapus dari store yang benar.'
        );
    }

    /**
     * Ambil command `schedule:run` yang benar-benar terdaftar di Artisan.
     */
    private function scheduleRunCommand(): ScheduleRunResilient
    {
        $artisan = $this->artisanApplication();

        $commands = $artisan->all();

        $this->assertArrayHasKey(
            'schedule:run',
            $commands,
            'Command schedule:run harus terdaftar.'
        );

        $command = $commands['schedule:run'];

        $this->assertInstanceOf(ScheduleRunResilient::class, $command);

        return $command;
    }

    /**
     * Cache store yang dipakai scheduler.
     */
    private function schedulerCache(): Repository
    {
        return $this->app['cache']->store(Config::get('cache.schedule_store'));
    }

    /**
     * Ambil instance `Illuminate\Console\Application` yang dipakai Artisan.
     *
     * `Application` tidak bisa di-resolve langsung dari container --
     * konstruktornya butuh argumen `$version` yang tidak di-binding.
     *
     * Yang dipakai: instance yang disimpan Kernel. Kernel meng-cache-nya
     * di property `$artisan`, jadi event `ArtisanStarting` HANYA terbakar
     * sekali -- pada saat instance itu pertama kali dibuat. Test yang
     * memanggil `Artisan::call()` lebih dulu akan mendapat `null` dari
     * listener, karena Artisan-nya sudah jadi.
     */
    private function artisanApplication(): Application
    {
        $kernel = $this->app->make(Kernel::class);

        $reflection = new \ReflectionProperty($kernel, 'artisan');
        $reflection->setAccessible(true);

        $artisan = $reflection->getValue($kernel);

        // Instance-nya belum dibuat -- pancing supaya dibangun.
        if (! $artisan instanceof Application) {
            Artisan::call('list', ['--raw' => true]);

            $artisan = $reflection->getValue($kernel);
        }

        $this->assertInstanceOf(
            Application::class,
            $artisan,
            'Instance Artisan harus bisa diambil dari Kernel.'
        );

        return $artisan;
    }

    /**
     * Panggil method protected dari sebuah object.
     */
    private function callProtected(object $object, string $method): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object);
    }

    /**
     * Ganti cache store scheduler dengan yang selalu gagal.
     *
     * Cache store ini melempar exception di setiap operasi baca -- persis
     * seperti database yang tidak terjangkau.
     */
    private function swapBrokenCache(): void
    {
        Config::set('cache.schedule_store', 'broken-scheduler-cache');

        $this->app['cache']->extend('broken-scheduler-cache', function () {
            return new class extends Repository
            {
                public function __construct() {}

                public function get($key, $default = null)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function put($key, $value, $ttl = null)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function forever($key, $value)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function add($key, $value, $ttl = null)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function has($key)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function forget($key)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function pull($key, $default = null)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function increment($key, $value = 1)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function decrement($key, $value = 1)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }
            };
        });

        // Buang instance yang sudah dibangun, supaya store baru dipakai.
        $this->app->forgetInstance('cache');
    }

    /**
     * Paksa koneksi database yang dipakai aplikasi melempar error.
     *
     * Dipakai untuk mensimulasikan MySQL mati tanpa benar-benar
     * mematikan servernya.
     */
    private function breakDatabaseConnections(): void
    {
        foreach (['central', 'mysql', 'tenant'] as $connection) {
            try {
                $this->app['db']->purge($connection);
            } catch (Throwable) {
                // Koneksi mungkin belum pernah dibuat -- abaikan.
            }
        }

        $this->app['db']->extend('central', function () {
            return $this->brokenConnection('central');
        });

        $this->app['db']->extend('mysql', function () {
            return $this->brokenConnection('mysql');
        });
    }

    /**
     * Buat koneksi yang selalu gagal saat dipakai.
     */
    private function brokenConnection(string $name): Connection
    {
        $pdo = new \PDO('mysql:host=127.0.0.1;port=1;dbname=tidak_ada', 'root', '', [
            \PDO::ATTR_TIMEOUT => 1,
        ]);

        return new MySqlConnection($pdo, $name, '', []);
    }
}
