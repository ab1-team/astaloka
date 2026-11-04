<?php

namespace App\Providers;

use App\Console\Commands\MigrateCentral;
use App\Console\Commands\SchedulePauseResilient;
use App\Console\Commands\ScheduleResumeResilient;
use App\Console\Commands\ScheduleRunResilient;
use App\Listeners\BridgeConsoleCommandEvents;
use App\Listeners\GuardCentralDatabaseWrites;
use App\Listeners\GuardDestructiveCommands;
use App\Listeners\GuardProtectedDatabaseDestructiveSql;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\MonthlyBill;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Observers\PaymentObserver;
use App\Observers\TransactionObserver;
use App\Services\TenantManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Application;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerTenantManager();
    }

    /**
     * `TenantManager` WAJIB singleton, bukan `new` per pemakaian.
     *
     * Instance menyimpan satu hal yang tidak ada duplikatnya di tempat
     * lain: `$current`, yaitu lokasi yang sedang aktif untuk request ini.
     * Middleware `ResolveTenant` memanggil `activate()` pada instance yang
     * dia dapat dari container; kalau controller memanggil
     * `app(TenantManager::class)->current()`, dia harus dapat instance yang
     * SAMA.
     *
     * Tanpa baris di bawah, container membuat instance baru setiap kali
     * `TenantManager` di-resolve. Semua yang masih bekerja -- karena
     * `database.default` memang diubah secara global di `Config`, bukan di
     * objek itu sendiri -- tapi `current()` selalu mengembalikan null, dan
     * audit kehilangan `tenant_id` untuk semua aksi di aplikasi lokasi.
     * Gejalanya berupa data yang hilang tanpa error, bukan exception.
     *
     * Dibuat per-request (`scoped`), bukan seumur aplikasi: pada Octane /
     * queue worker, instance yang bertahan lintas request akan membawa
     * lokasi aktif dari request sebelumnya ke request berikutnya -- user
     * satu bisa diam-diam membaca database milik user lain.
     */
    private function registerTenantManager(): void
    {
        $this->app->scoped(TenantManager::class);
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->registerDestructiveCommandGuard();
        $this->registerCentralDatabaseWriteGuard();
        $this->registerProtectedDatabaseSqlGuard();
        $this->registerConsoleEventBridge();
        $this->registerSchedulerCommandOverride();
        Relation::enforceMorphMap([
            'payment' => Payment::class,
            'monthly_bill' => MonthlyBill::class,
            'customer' => Customer::class,
            'user' => User::class,
            'bill_payment' => BillPayment::class,
        ]);

        Transaction::observe(TransactionObserver::class);
        Payment::observe(PaymentObserver::class);
    }

    /**
     * Ganti `schedule:run` bawaan dengan versi yang tidak crash saat
     * database tidak terjangkau.
     *
     * Kenapa ini perlu
     * ==============
     *
     * `schedule:run` dipanggil SETIAP MENIT oleh Task Scheduler. Sebelum
     * menjalankan job apa pun, framework membaca cache (flag pause, flag
     * interrupt, mutex `withoutOverlapping`). Cache aplikasi memakai store
     * `database`, jadi:
     *
     *   - MySQL mati / belum selesai start -> query cache melempar
     *     `SQLSTATE[HY000] [2002]`, `schedule:run` CRASH sebelum job dicek;
     *   - job yang SEHARUSNYA jalan tidak jalan -- termasuk generate piutang
     *     pada tanggalnya, yang gate-nya bersifat PERSIS, jadi satu malam
     *     yang terlewat berarti bulan itu tidak pernah punya jurnal;
     *   - satu baris ERROR ditulis ke log SETIAP MENIT, jadi log membanjir
     *     dan error yang sesungguhnya jadi sulit ditemukan.
     *
     * Cache yang dipakai scheduler tidak WAJIB di database -- isinya cuma
     * "apakah job ini sedang jalan". Karena itu `config('cache.schedule_store')`
     * diset ke `file` (lihat `config/cache.php`), dan command ini memastikan
     * command yang membacanya memakai store itu.
     *
     * `schedule:pause` dan `schedule:resume` juga di-override, karena
     * perintah bawaan menulis flag pause ke cache DEFAULT (`database`) --
     * sedangkan scheduler membacanya dari store `file`. Dua store berbeda,
     * dua kunci berbeda: flag yang ditulis `schedule:pause` tidak pernah
     * dibaca scheduler, jadi perintahnya menganggur tanpa error.
     *
     * Kenapa overriding, bukan mengubah `vendor/`
     * ==========================================
     *
     * `composer install` menimpa file di `vendor/`, jadi perubahan di sana
     * hilang tanpa jejak. Overriding lewat `ArtisanStarting` membuat
     * perbedaannya menetap di repo ini dan terlihat saat review kode.
     *
     * @see ScheduleRunResilient
     * @see SchedulePauseResilient
     * @see ScheduleResumeResilient
     */
    private function registerSchedulerCommandOverride(): void
    {
        Event::listen(ArtisanStarting::class, function (ArtisanStarting $event): void {
            $artisan = $event->artisan;

            if (! $artisan instanceof Application) {
                return;
            }

            $artisan->resolveCommands([
                ScheduleRunResilient::class,
                SchedulePauseResilient::class,
                ScheduleResumeResilient::class,
            ]);
        });
    }

    /**
     * Catatan penting soal pemisahan folder migrasi.
     *
     * Folder `database/migrations/` SENGAJA TIDAK berisi apa pun, dan
     * folder `database/migrations_central/` SENGAJA TIDAK didaftarkan lewat
     * `loadMigrationsFrom()`. Keduanya bukan kelalaian -- keduanya adalah
     * penjaga.
     *
     * `migrate` menulis catatan ke tabel `migrations` pada koneksi yang SEDANG
     * AKTIF, bukan ke koneksi yang dipakai di dalam migrasi. Folder default
     * Migrator juga tetap `database/migrations/`, yang tidak bisa ia ubah
     * sendiri.
     *
     * Empat arah kesalahan, dan semuanya nyata:
     *
     *   1. Folder LOKASI ikut dipindai saat menulis ke PUSAT -> database
     *      pusat mewarisi catatan "sudah jalan" untuk migrasi lokasi.
     *
     *   2. Folder LOKASI dipindai dengan target PUSAT -> database `astaloka`
     *      berakhir berisi 36 tabel LOKASI (`customers`, `monthly_bills`,
     *      `villages`, trigger `*_amount_debit`), sementara tabel pusatnya
     *      (`tenants`, `audit_logs`, ...) tidak ada sama sekali. INILAH yang
     *      terjadi di repo ini sebelum foldernya dipisah.
     *
     *   3. Folder PUSAT dipindai dengan target LOKASI -> tabelnya tetap jadi
     *      di pusat (karena `Schema::connection('central')`), tapi catatannya
     *      masuk ke `migrations` lokasi. Lokasi mewarisi booking untuk tabel
     *      yang tidak pernah dibuat di sana.
     *
     *   4. Folder PUSAT ikut dipindai saat menulis ke lokasi -> sama dengan
     *      nomor 3.
     *
     * Arah 2 adalah yang paling merusak karena tidak meninggalkan jejak:
     * `migrate` keluar dengan status sukses, dan penjaga `hasTable()` di
     * migrasi pusat (mis. `create_central_users_table`) menganggap tabel yang
     * sudah ada itu memang miliknya -- jadi ia skip dan tetap mencatat dirinya
     * "sudah jalan". Migrasi pusat berikutnya tidak akan pernah dicoba lagi.
     *
     * Karena itu kedua folder dipisah dari default Migrator, dan masing-masing
     * punya perintahnya sendiri yang menyebut `--path` secara eksplisit:
     *
     *   tenant  ->  TenantManager::migrateTenantDatabase()
     *               php artisan tenancy:migrate-central --seed  (untuk pusat)
     *
     * Pasangan target + path itu dijaga oleh
     * {@see GuardCentralDatabaseWrites}, yang menolak perintah yang menulis
     * ke salah satu database dengan folder milik yang lain.
     *
     * @see MigrateCentral
     */

    /**
     * Pasang pengaman terhadap perintah artisan yang menghapus data.
     *
     * Di-daftarkan lewat event `CommandStarting`, bukan sebagai middleware,
     * karena yang perlu dicegah adalah perintah CLI. Middleware hanya jalan
     * untuk HTTP request, sehingga `migrate:fresh` yang dijalankan di terminal
     * akan lolos tanpa ada yang menghentikannya.
     *
     *PENDAFTARANNYA TIDAK BERGANTUNG KONDISI APAPUN, dan itu justru inti
     * perbaikannya.
     *
     * Sebelumnya guard ini hanya terdaftar saat `runningInConsole()` atau
     * `runningUnitTests()` bernilai true. Dua-duanya terlihat logis, tapi
     * keduanya TIDAK menjamin event-nya benar-benar terkirim.
     *
     * `CommandStarting` hanya dikirim oleh
     * `Illuminate\Foundation\Console\Kernel::rerouteSymfonyCommandEvents()`,
     * dan method itu SENGAJA dilewati saat `runningUnitTests()` true --
     * framework menganggap event console tidak relevan di dalam test.
     *
     * Akibatnya, di DALAM `php artisan test`, kedua penjaga ini terdaftar tapi
     * TIDAK PERNAH DIPANGGIL. Bukan "kurang ketat" -- benar-benar mati.
     *
     * Dan itu bukan cacat teoretis: `RefreshDatabase` menjalankan
     * `migrate:fresh`, yang memanggil `db:wipe` secara internal, yang
     * memanggil `dropAllTables()` -- menjatuhkan SELURUH tabel database
     * pusat dalam satu statement. Tidak ada penjaga yang menyadarinya,
     * dan tidak ada pesan error apa pun.
     *
     * Verifikasi: `php artisan test` yang memanggil `Artisan::call()` dan
     * `$this->artisan()` TIDAK PERNAH menerima satu pun `CommandStarting`.
     *
     * Karena itu penjaga ketiga yang benar-benar tidak bergantung pada
     * perintah -- {@see GuardProtectedDatabaseDestructiveSql} -- dipasang
     * sebagai jaring pengaman terakhir. See juga {@see registerConsoleEventBridge()}.
     *
     * @see GuardDestructiveCommands
     */
    private function registerDestructiveCommandGuard(): void
    {
        Event::listen(CommandStarting::class, GuardDestructiveCommands::class);
    }

    /**
     * Pasang pengaman terhadap migrasi lokasi yang ditulis ke database pusat.
     *
     * Terpisah dari {@see registerDestructiveCommandGuard()} karena mengatur
     * dua bahaya yang berbeda: yang satu-soal-menghapus-data, yang satu-soal-
     * menulis-di-database-yang-salah. Menjadikannya satu method dengan satu
     * daftar perintah berarti salah satu aturan harus mengorbankan syaratnya
     * demi yang lain.
     *
     * `@see \App\Listeners\GuardCentralDatabaseWrites`
     */
    private function registerCentralDatabaseWriteGuard(): void
    {
        Event::listen(CommandStarting::class, GuardCentralDatabaseWrites::class);
    }

    /**
     * Pasang penjaga SQL tingkat-tinggi -- jaring pengaman terakhir.
     *
     * Berbeda dengan dua penjaga di atas, penjaga ini tidak memanggil perintah
     * apa pun. Ia memasang kait pada setiap koneksi MySQL, sehingga setiap
     * statement `drop` yang akan dijalankan diperiksa terhadap nama database
     * yang BENAR-BENAR dibuka koneksi itu.
     *
     * Karena tidak bergantung pada event console, ia tetap aktif di dalam
     * `php artisan test` -- di situlah dua penjaga lain mati.
     *
     * @see GuardProtectedDatabaseDestructiveSql
     */
    private function registerProtectedDatabaseSqlGuard(): void
    {
        Event::listen(ConnectionEstablished::class, GuardProtectedDatabaseDestructiveSql::class);
    }

    /**
     * Pasang jembatan event console.
     *
     * Tanpa ini, `CommandStarting` TIDAK PERNAH terkirim di dalam test --
     * dan dua penjaga command yang terdaftar tapi tidak pernah dipanggil
     * sama saja dengan tidak ada penjaga.
     *
     * Di luar test, dispatcher milik Kernel menimpa dispatcher yang dipasang
     * jembatan ini, jadi event tidak terkirim dua kali.
     *
     * @see BridgeConsoleCommandEvents
     */
    private function registerConsoleEventBridge(): void
    {
        Event::listen(ArtisanStarting::class, BridgeConsoleCommandEvents::class);
    }

    /**
     * Batasi percobaan brute-force pada endpoint autentikasi.
     *
     * Tanpa limiter ini, POST /login terbuka penuh: penyerang bisa
     * mencoba kombinasi email/password tanpa batas. Rate limit dihitung
     * per kombinasi email+IP supaya satu penyerang tidak bisa mengunci
     * akun orang lain dengan sengaja (keyBy email saja = DoS akun).
     */
    private function configureRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by(
                $email.'|'.$request->ip()
            );
        });

        // Lapisan kedua per-IP, untuk menangkap penyerang yang memakai
        // banyak email berbeda dari satu mesin.
        RateLimiter::for('login-ip', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // Endpoint berat yang menyimpan file, supaya tidak bisa dipakai
        // memenuhi disk atau membebani server dengan request beruntun.
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(30)->by($this->rateLimitKey($request));
        });

        // Dipakai oleh throttleApi() di bootstrap/app.php, yang memasang
        // middleware `throttle:api` ke seluruh grup api. Tanpa daftar ini
        // setiap request yang tidak bisa dilayani limiter akan 500 dengan
        // MissingRateLimiterException, bukan 429.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($this->rateLimitKey($request));
        });
    }

    /**
     * Kunci rate limiter yang tidak bertabrakan antar lokasi.
     *
     * Dulu kunci ini hanya `user->id`. Masalahnya: `id` itu milik database
     * LOKASI, dan setiap lokasi punya tabel `users` sendiri yang mulai dari
     * 1. Jadi `admin` di lokasi A dan `admin` di lokasi B sama-sama `id = 1`
     * -- dan satu lokasi bisa menghabiskan kuota request milik lokasi lain,
     * atau sebaliknya, keduanya saling memblokir tanpa ada yang menyinggung.
     *
     * Betulan bocor? Tidak. Betulan SUSAH DIJELASKAN? Ya: user di lokasi A
     * mendapat 429 padahal tidak pernah mengirim banyak request, dan tidak
     * ada satu pun pesan yang menyebut lokasi lain.
     *
     * Karena itu id user digabung dengan id lokasi aktif. `TenantManager`
     * disimpan sebagai `scoped`, jadi instance-nya di-resolve dari container
     * SAAT limiter dievaluasi -- bukan saat provider di-boot. Provider adalah
     * singleton, jadi instance yang ditangkap di boot akan membawa lokasi
     * aktif dari request pertama ke semua request berikutnya -- persis kelas
     * kebocoran yang `scoped` justru ada untuk mencegah.
     *
     * Middleware `ResolveTenant` memanggil `activate()` SEBELUM limiter
     * dievaluasi, jadi `$current` sudah benar pada saat kunci ini dihitung.
     *
     * Tanpa lokasi aktif -- panel pusat, atau domain yang tidak terdaftar --
     * dipakai `pusat` sebagai penanda. Nilai itu tidak mungkin sama dengan
     * id lokasi (yang berupa angka), jadi tidak ada tabrakan.
     */
    private function rateLimitKey(Request $request): string
    {
        $userId = $request->user()?->id;

        if ($userId === null) {
            return $request->ip();
        }

        $tenantId = app(TenantManager::class)->current()?->getKey() ?? 'pusat';

        return $tenantId.':'.$userId;
    }
}
