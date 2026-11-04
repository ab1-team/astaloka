<?php

namespace App\Console\Commands;

use App\Models\Central\Tenant;
use App\Models\Central\TenantDomain;
use App\Services\DomainNormalizer;
use App\Services\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Daftarkan lokasi baru: buat database, jalankan migration, buat admin.
 *
 * Padanan CLI dari provisioning yang nanti dipanggil halaman admin pusat.
 * Dipisah supaya provisioning bisa diuji di terminal tanpa harus login ke
 * panel pusat.
 *
 * Urutan langkah penting. Database dibuat DULUAN, baru registry, baru
 * migration, baru admin. Registry ditulis sebelum migration karena
 * `migrate()` dijalankan lewat `TenantManager::runFor()`, yang butuh baris
 * tenant untuk tahu database mana yang jadi tujuan.
 *
 * Sebaliknya, baris tenant yang gagal ditulis DIBUANG kalau database tidak
 * sempat terbentuk. Kalau tidak, leftover-nya akan menggantung: setiap request
 * ke lokasi itu gagal dengan error koneksi, tanpa cara memperbaikinya selain
 * edit manual.
 */
class MakeTenant extends Command
{
    protected $signature = 'tenancy:make
        {slug : Identitas lokasi, mis. tirtomulo}
        {--nama= : Nama tampilan}
        {--nama-lembaga= : Nama lembaga resmi (kop laporan)}
        {--domain= : Host/subdomain untuk produksi}
        {--database= : Nama database (default: <prefix><slug>)}
        {--alamat=}
        {--email= : Email kontak lokasi}
        {--telepon=}
        {--admin-email= : Email admin lokasi pertama}
        {--admin-password= : Password admin lokasi}
        {--admin-name= : Nama admin lokasi}
        {--skip-migrate : Buat database saja, tanpa migration/seed}
        {--force : Timpa database yang sudah ada}';

    protected $description = 'Daftarkan lokasi tenant baru (buat database + migration + admin)';

    public function handle(TenantManager $manager): int
    {
        $slug = $this->askSlug();

        if (! $slug) {
            $this->error('Slug tidak valid.');

            return self::FAILURE;
        }

        $dbName = (string) ($this->option('database') ?: $this->buildDatabaseName($slug));

        /*
         * `--domain` dinormalisasi DAN didaftarkan ke `tenant_domains`.
         *
         * Kolom legacy `tenants.domain` saja tidak cukup: resolver mencari
         * `tenant_domains` lebih dulu, dan `ensureAvailable()` hanya memeriksa
         * tabel itu. Lokasi yang dibuat lewat CLI dengan domain legacy saja
         * karena itu bisa "dibajak" lokasi lain lewat halaman Domain -- host
         * yang sama didaftarkan ke lokasi kedua, dan resolver memilih yang
         * punya baris di `tenant_domains`.
         *
         * Normalisasi di sini juga wajib: `--domain=https://x.test/` yang
         * disimpan apa adanya tidak akan pernah cocok dengan host request.
         */
        $domainMentah = $this->option('domain');
        $domain = null;

        if (is_string($domainMentah) && trim($domainMentah) !== '') {
            [$domain, $domainError] = DomainNormalizer::clean($domainMentah);

            if ($domainError !== null) {
                $this->error("Domain `{$domainMentah}` tidak valid: {$domainError}");

                return self::FAILURE;
            }

            $tenantSebelum = Tenant::where('slug', $slug)->first();

            [$boleh, $pesan] = DomainNormalizer::ensureAvailable(
                $domain,
                false,
                $tenantSebelum?->id ?? 0
            );

            if (! $boleh) {
                $this->error($pesan ?? "Domain `{$domain}` tidak bisa dipakai.");

                return self::FAILURE;
            }
        }

        if (Tenant::where('database', $dbName)->exists()) {
            $this->error("Database `{$dbName}` sudah terdaftar untuk lokasi lain.");

            return self::FAILURE;
        }

        $sudahAda = $this->databaseExists($dbName);

        if ($sudahAda && ! $this->option('force')) {
            $this->error("Database `{$dbName}` sudah ada di MySQL.");
            $this->line('   Pakai nama lain, atau --force untuk MEMAKAI ULANG database itu.');
            $this->line('   --force tidak menghapus isinya, tapi migration akan jalan di atas data lama.');

            return self::FAILURE;
        }

        $tenant = null;

        try {
            // 1. database
            $this->line("<fg=cyan>[1/4]</> Menyiapkan database `{$dbName}`...");
            if (! $sudahAda) {
                /*
                 * `createDatabaseNamed()`, bukan `createDatabase()`.
                 *
                 * `createDatabase($slug)` MENGHITUNG ULANG nama dari slug dan
                 * mengabaikan `$dbName` sepenuhnya -- jadi `--database=` yang
                 * sudah dibaca di atas akan diabaikan, tenant ditulis dengan
                 * nama pilihan operator, sementara yang benar-benar dibuat
                 * adalah `astaloka_<slug>`. Lokasi itu terdaftar normal,
                 * aktif, dan tidak bisa dibuka karena databasenya tidak ada.
                 *
                 * Nama yang dipakai di sini sudah tervalidasi oleh
                 * `createDatabaseNamed()`, dan sama dengan yang ditulis ke
                 * registry pada langkah 2.
                 */
                $manager->createDatabaseNamed($dbName);
                $this->line('      dibuat.');
            } else {
                $this->line('      sudah ada, dipakai ulang.');
            }

            // 2. registry -- ditulis SEBELUM migration supaya migration
            //    bisa memakai TenantManager::runFor() yang butuh baris ini.
            $tenant = Tenant::updateOrCreate(
                ['slug' => $slug],
                [
                    'nama' => (string) ($this->option('nama') ?: Str::headline($slug)),
                    'nama_lembaga' => $this->option('nama-lembaga'),
                    'database' => $dbName,
                    'domain' => $domain,
                    'alamat' => $this->option('alamat'),
                    'email' => $this->option('email'),
                    'telepon' => $this->option('telepon'),
                    'tgl_registrasi' => now()->toDateString(),
                    'tgl_pakai' => now()->toDateString(),
                    'is_active' => true,
                ]
            );
            $this->line("      tenant #{$tenant->id} terdaftar.");

            /*
             * Daftarkan host yang sama di `tenant_domains` supaya tiga jalur
             * (CLI, form lokasi, halaman Domain) menulis ke sumber yang sama.
             * Baris yang sudah ada dibiarkan apa adanya -- `--force` pada
             * lokasi yang sudah punya domain tidak boleh menurunkan status
             * utamanya hanya karena perintah ini dijalankan ulang.
             */
            if ($domain !== null) {
                $baris = TenantDomain::firstOrNew(['host' => $domain]);

                $baris->tenant_id = $tenant->id;
                $baris->is_wildcard = false;
                $baris->is_active = true;

                if (! $baris->exists) {
                    $baris->is_primary = ! $tenant->domains()
                        ->where('is_active', true)
                        ->exists();
                }

                $baris->save();

                $this->line("      domain `{$domain}` terdaftar.");
            }

            // 3. migration + seeder master
            if ($this->option('skip-migrate')) {
                $this->line('<fg=yellow>[3/4]</> Migration dilewati (--skip-migrate).');
                $this->line('      Isi master datanya nanti dengan:');
                $this->line("        php artisan tenancy:reseed-master {$slug}");
            } else {
                $this->line('<fg=cyan>[3/4]</> Menjalankan migration ke database lokasi...');
                $manager->migrateTenantDatabase($tenant);
                $this->line('      selesai.');

                // Master data (CoA, jabatan, jenis transaksi, arus kas).
                // WAJIB, bukan opsional: database yang sudah punya skema tapi
                // belum punya master data akan membuka aplikasi yang langsung
                // kosong -- jurnal tanpa chart of accounts, form jabatan tanpa
                // pilihan -- dan itu terlihat sebagai "lokasi rusak".
                $this->line('<fg=cyan>[3b/4]</> Menjalankan seeder master...');
                $manager->seedTenantDatabase($tenant);
                $this->line('      selesai.');
            }

            // 4. admin lokasi + akun demo + identitas settings
            $this->makeLocationAdmin($tenant, $dbName, $sudahAda, (bool) $this->option('skip-migrate'));

            $this->makeDemoUsers($tenant, (bool) $this->option('skip-migrate'));
        } catch (Throwable $e) {
            $this->error('Provisioning GAGAL: '.$e->getMessage());

            // Baris tenant yang sudah tertulis harus dibuang kalau database
            // belum sempat jadi, kalau tidak leftover-nya akan menggantung.
            if ($tenant && ! $this->databaseExists($dbName)) {
                $tenant->delete();
                $this->line('      registry dibersihkan (database tidak terbentuk).');
            } elseif ($tenant) {
                $this->line("      tenant #{$tenant->id} TETAP tersimpan.");
                $this->line('      Perbaiki masalahnya lalu ulangi migration dengan:');
                $this->line("        php artisan migrate --database={$dbName} --force");
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Lokasi `{$slug}` siap dipakai.");
        $this->line("   database : {$dbName}");

        $domain = $tenant->domain;
        if ($domain) {
            $this->line("   alamat   : https://{$domain}");
        } else {
            $this->line('   alamat   : (belum diisi) -- pakai ?tenant='.$slug.' saat lokal');
        }

        return self::SUCCESS;
    }

    private function askSlug(): ?string
    {
        $slug = strtolower(trim((string) $this->argument('slug')));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '' || ! preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug)) {
            return null;
        }

        if (in_array($slug, (array) config('tenancy.reserved_slugs', []), true)) {
            $this->error("Slug `{$slug}` dicadangkan untuk sistem.");

            return null;
        }

        return $slug;
    }

    /**
     * Nama database diturunkan dari slug.
     *
     * Diarahkan ke `TenantManager::databaseNameFor()` dan bukan diulang di
     * sini. Versi sebelumnya menyalin aturan prefix/normalisasi/pemotongan
     * ke command ini DAN ke `LocationController`, dan kedua salinan itu
     * tidak sama urutan operasinya begitu slug melewati 64 karakter --
     * registry lalu menyimpan nama yang berbeda dari database yang benar-benar
     * dibuat. Satu definisi di `TenantManager` adalah satu-satunya bentuk yang
     * bisa tetap benar.
     *
     * Static karena murni fungsi tanpa keadaan: tidak butuh instance, tidak
     * menyentuh koneksi MySQL.
     */
    private function buildDatabaseName(string $slug): string
    {
        return TenantManager::databaseNameFor($slug);
    }

    private function databaseExists(string $name): bool
    {
        $ada = DB::connection(config('tenancy.central_connection'))->select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$name]
        );

        return $ada !== [];
    }

    /**
     * Buat admin pertama lokasi.
     *
     * Password TIDAK dibuat bila tidak diberikan -- akun tanpa password tidak
     * bisa dipakai, dan lebih baik dilewati daripada diam-diam memberi
     * password lemah yang tidak diketahui pemiliknya.
     */
    private function makeLocationAdmin(Tenant $tenant, string $dbName, bool $sudahAda, bool $skipMigrate = false): void
    {
        $this->line('<fg=cyan>[4/4]</> Admin lokasi...');

        // Tanpa migration, tabel `users` dan `settings` di database lokasi
        // belum ada. INSERT ke `users` maupun applyTenantIdentity() sama-sama
        // gagal dengan `Table 'astaloka_x.settings' doesn't exist` -- dan
        // kegagalannya muncul SESUDAH langkah [3] dicetak "--skip-migrate"
        // sebagai pilihan yang disengaja, jadi operator membaca
        // "Provisioning GAGAL" lalu mengira lokasi barunya rusak. Padahal itu
        // konsekuensi normal dari flag yang dia sendiri yang minta.
        if ($skipMigrate) {
            $this->line('      dilewati (--skip-migrate: database lokasi belum punya skema).');
            $this->line('      Isi dulu skemanya, baru tambah admin:');
            $this->line("        php artisan migrate --database={$dbName} --force");
            $this->line("        php artisan tenancy:make-user {$tenant->slug} --email=... --password=...");
            $this->writeTenantIdentity($tenant, $sudahAda, true);

            return;
        }

        $email = (string) ($this->option('admin-email') ?: '');
        $password = (string) ($this->option('admin-password') ?: '');

        if ($email === '' || $password === '') {
            $this->line('      dilewati (--admin-email / --admin-password tidak diisi).');
            $this->line('      Buat manual:');
            $this->line("        php artisan tenancy:make-user {$tenant->slug} --email=... --password=...");
        } elseif ($sudahAda) {
            $this->line('      database sudah punya isi lama, admin TIDAK dibuat ulang.');
            $this->line('      Cek dulu user yang ada di database lokasi sebelum menambah admin baru.');
        } elseif (strlen($password) < 8) {
            $this->warn('      password minimal 8 karakter -- admin dilewati.');
        } else {
            app(TenantManager::class)->runFor($tenant, function () use ($email, $password) {
                DB::connection(TenantManager::CONNECTION)->table('users')->insert([
                    'name' => (string) ($this->option('admin-name') ?: 'Admin Lokasi'),
                    'email' => $email,
                    'password' => Hash::make($password),
                    'role' => 'admin',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $this->line("      admin dibuat: {$email}");
        }

        $this->writeTenantIdentity($tenant, $sudahAda);
    }

    /**
     * Buat empat akun demo di database lokasi.
     *
     * Dipanggil SETELAH admin lokasi, bukan sebelumnya. Alasannya urutan: akun
     * demo memakai email yang sama untuk semua lokasi
     * (`admin@gmail.com`, dst), dan bila admin form memakai email yang sama
     * persis, `DemoUserSeeder` akan melihat email itu sudah dipakai dan
     * berdiam. Sebaliknya kalau admin dibuat lebih dulu, baris admin form
     * baris admin form sudah ada lebih dulu dan email demo tetap mendapat
     * barisnya sendiri --
     * dua akun berbeda, satu email, dan `users.email` punya unique index.
     *
     * Kegagalan TIDAK menggagalkan perintah: akun contoh bukan bagian dari
     * lokasi yang bisa dipakai. Ditangkap dan dilaporkan, bukan dilempar,
     * supaya operator melihat lokasi baru tetap siap -- beserta cara
     * menambahkan akunnya manual kalau ternyata memang dibutuhkan.
     */
    private function makeDemoUsers(Tenant $tenant, bool $skipMigrate = false): void
    {
        if ($skipMigrate) {
            $this->line('      dilewati (--skip-migrate: database lokasi belum punya skema).');
            $this->line("        php artisan tenancy:make-demo-users {$tenant->slug}");

            return;
        }

        try {
            $dibuat = app(TenantManager::class)->seedDemoUsers($tenant);
        } catch (Throwable $e) {
            $this->warn('      akun demo GAGAL dibuat: '.$e->getMessage());
            $this->line("        php artisan tenancy:make-demo-users {$tenant->slug}");

            return;
        }

        if ($dibuat === 0) {
            $this->line('      dilewati (sakelar TENANCY_DEMO_USERS mati, atau akunnya sudah ada).');

            return;
        }

        $password = (string) config('tenancy.demo_users.password', 'password');

        foreach ((array) config('tenancy.demo_users.emails', []) as $role => $email) {
            $email = trim((string) $email);

            if ($email !== '') {
                $this->line("      {$role}: {$email} / {$password}");
            }
        }
    }

    /**
     * Tulis nama lembaga, alamat, dan domain lokasi ke tabel `settings`.
     *
     * Dijalankan di luar blok pembuatan admin, karena ada dua kondisi yang
     * harus menghasilkan dua perilaku berbeda:
     *
     *   - Admin tidak dibuat (email/password kosong) tapi lokasi tetap
     *     harus punya nama lembaga sendiri di kop laporan.
     *   - Database dipakai ulang (`--force`), yang mungkin sudah punya
     *     identitas yang sudah dikoreksi manual. Menimpanya akan menghapus
     *     pekerjaan yang tidak terlihat dari baris perintah ini.
     *   - Migration dilewati (`--skip-migrate`), jadi tabel `settings` belum
     *     ada sama sekali. Menulisnya pasti gagal.
     *
     * Karena itu hanya lokasi yang benar-benar baru DAN sudah berskema yang
     * ditulis.
     */
    private function writeTenantIdentity(Tenant $tenant, bool $sudahAda, bool $skipMigrate = false): void
    {
        if ($skipMigrate) {
            $this->line('      identitas settings TIDAK ditulis (database lokasi belum punya skema).');
            $this->line('      Jalankan manual setelah migration:');
            $this->line("        php artisan tenancy:reseed-master {$tenant->slug} --identity");

            return;
        }

        if ($sudahAda) {
            $this->line('      database dipakai ulang, tabel settings TIDAK ditimpa.');
            $this->line('      Jalankan manual kalau memang perlu:');
            $this->line("        php artisan tenancy:reseed-master {$tenant->slug} --identity");

            return;
        }

        app(TenantManager::class)->applyTenantIdentity($tenant);

        $this->line('      identitas settings ditulis.');
    }
}
