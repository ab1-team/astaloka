<?php

namespace Tests\Feature;

use App\Models\Central\Tenant;
use App\Models\Central\TenantDomain;
use App\Models\User;
use App\Services\DomainNormalizer;
use App\Services\LegacyDomainSync;
use App\Services\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\EnsuresCentralTables;
use Tests\TestCase;
use Throwable;

/**
 * Domain custom lokasi: dari domain masuk ke database lokasi yang benar.
 *
 * Yang diuji di sini BUKAN "formnya menyimpan", melainkan hal yang tidak
 * terlihat kalau cuma melihat kode: apakah `pamsides.test` benar-benar
 * sampai ke lokasi Pamsides, dan apakah `demo.astaloka.test` tidak sampai ke
 * lokasi lain.
 *
 * Kegagalan di area ini tidak pernah muncul sebagai error. Kalau resolver
 * salah memilih, request tetap dijawab 200 dengan isi database lokasi yang
 * salah. Superadmin melihat "panelnya jalan", petugas melihat "halamannya
 * terbuka", dan tidak ada satu pun log yang bilang terjadi pertukaran.
 *
 * Karena itu test di sini memanggil `TenantResolver` secara langsung.
 * Endpoint panel pusat yang menguji bentuk respons JSON diuji lewat HTTP
 * seperti biasa; yang paling perlu dijaga di sini adalah
 * `TenantResolver::fromHost()`.
 *
 * CATATAN SOAL DATABASE PUSAT DI TEST
 *
 * `RefreshDatabase` menjalankan `migrate:fresh` pada koneksi yang aktif, dan
 * `TENANCY_CENTRAL_DATABASE` menunjuk database BERBEDA dari koneksi default.
 * Jadi database pusat TIDAK ikut ter-refresh secara otomatis -- dan justru
 * itu masalahnya: kalau `astaloka_testing_central` baru dibuat dan belum
 * dimigrasikan, test gagal dengan "table doesn't exist" yang tidak mengarah
 * ke apa pun.
 *
 * Karena itu `setUp()` memanggil `ensureCentralTables()`: menjalankan migrasi
 * pusat yang dibutuhkan kalau tabelnya belum ada. Semuanya idempoten dan
 *murah, jadi lebih aman daripada menebak-nebak apakah suite sebelumnya sudah
 * berjalan sampai mana.
 *
 * Baris yang dibuat test ini berada di database pusat, jadi TIDAK ikut
 * ter-rollback oleh transaksi `RefreshDatabase` -- yang hanya membungkus
 * koneksi default. `tearDown()` karena itu menghapus eksplisit.
 */
class CentralDomainTest extends TestCase
{
    use EnsuresCentralTables;
    use RefreshDatabase;

    private const CENTRAL = 'central';

    /** Prefix unik supaya sisa run yang gagal tidak bentrok. */
    private string $prefix;

    private User $superadmin;

    /** @var array<int, int> id lokasi yang dibuat test ini */
    private array $tenantIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $central = DB::connection(self::CENTRAL);

        $this->prefix = 'domtest-'.uniqid().'-';

        /*
         * Tabel pusat dibuat ulang kalau belum ada. Lihat catatan panjang di
         * docblock class: `RefreshDatabase` bisa mengosongkan database pusat
         * di tengah suite.
         *
         * `tenants` dan `tenant_domains` dua-duanya dipastikan, karena
         * keduanya saling bergantung: `tenant_domains.tenant_id` punya
         * foreign key ke `tenants.id`, jadi `tenant_domains` tidak bisa dibuat
         * sebelum `tenants` ada.
         */
        $this->ensureCentralTables($central);

        // Sisa dari run sebelumnya yang gagal di tengah jalan. Baris seperti
        // ini tidak ikut ter-rollback oleh RefreshDatabase, jadi harus dihapus
        // eksplisit -- kalau tidak, test berikutnya menabrak unique index dan
        // gagal dengan pesan yang tidak menyebut penyebab sebenarnya.
        $central->table('tenant_domains')->delete();
        $central->table('tenants')->where('slug', 'like', 'domtest-%')->delete();
        $central->table('users')->where('email', 'like', 'domtest-%@test.local')->delete();

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
        // memori dan hanya diberi id -- lihat catatan di CentralCoaTest.
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
         * `delete()` per-id, bukan `truncate()` -- lihat catatan panjang di
         * `CentralCoaTest::tearDown()`. Test yang menghapus data milik orang
         * lain karena konfigurasi database salah adalah bom waktu.
         */
        try {
            $central = DB::connection(self::CENTRAL);

            foreach ($this->tenantIds as $id) {
                $central->table('tenant_domains')->where('tenant_id', $id)->delete();
                $central->table('tenants')->where('id', $id)->delete();
            }

            $central->table('users')->where('email', 'like', 'domtest-%@test.local')->delete();
            $central->table('audit_logs')
                ->where('action', 'like', 'tenant.domain%')
                ->delete();
        } catch (Throwable $e) {
            report($e);
        }

        parent::tearDown();
    }

    /* ============================================================
     | RESOLUSI HOST
    |============================================================
     */

    #[Test]
    public function domain_custom_mengarah_ke_lokasi_yang_terdaftar(): void
    {
        $tenant = $this->makeTenant('pamsides');
        $this->makeDomain($tenant->id, 'pamsides.test');

        $found = $this->resolveFor('pamsides.test');

        $this->assertNotNull($found, 'Domain kustom tidak berhasil diresolve.');
        $this->assertSame($tenant->id, $found->id);
    }

    #[Test]
    public function beberapa_domain_satu_lokasi_semua_diresolve(): void
    {
        // Tiga bentuk yang dipakai di requirement: domain milik sendiri,
        // subdomain di bawah domain pusat, dan domain sama sekali terpisah.
        $tenant = $this->makeTenant('multi');
        $this->makeDomain($tenant->id, 'pamsides.test');
        $this->makeDomain($tenant->id, 'demo.astaloka.test');
        $this->makeDomain($tenant->id, 'demo.internet.test');

        foreach (['pamsides.test', 'demo.astaloka.test', 'demo.internet.test'] as $host) {
            $found = $this->resolveFor($host);

            $this->assertNotNull($found, "`{$host}` tidak diresolve.");
            $this->assertSame($tenant->id, $found->id, "`{$host}` sampai ke lokasi yang salah.");
        }
    }

    #[Test]
    public function wildcard_menangkap_satu_label_di_bawahnya(): void
    {
        $tenant = $this->makeTenant('wild');
        $this->makeDomain($tenant->id, '*.astaloka.test', wildcard: true);

        $this->assertSame($tenant->id, $this->resolveFor('demo.astaloka.test')?->id);
        $this->assertSame($tenant->id, $this->resolveFor('pamsides.astaloka.test')?->id);
    }

    #[Test]
    public function wildcard_tidak_menangkap_dua_label_atau_apex(): void
    {
        $tenant = $this->makeTenant('wild2');
        $this->makeDomain($tenant->id, '*.astaloka.test', wildcard: true);

        // Dua label: `*.a.b` tidak boleh menjulurkan dua tingkat.
        $this->assertNull($this->resolveFor('a.b.astaloka.test'));
        // Apex: wildcard tidak capturing domain induknya sendiri. Kalau iya,
        // mendaftarkan wildcard akan ikut mengambil alih domain pusat.
        $this->assertNull($this->resolveFor('astaloka.test'));
    }

    #[Test]
    public function domain_persis_menang_dari_wildcard(): void
    {
        /*
         * Aturan ini yang membuat sistem bisa dipredict: wildcard menangkap
         * semua satu label di bawahnya, KECUALI yang punya domain persis
         * sendiri. Kalau tidak, menambahkan domain ke satu lokasi bisa
         * diam-diam merebut domain milik lokasi lain.
         */
        $wild = $this->makeTenant('wild3');
        $exact = $this->makeTenant('exact3');

        $this->makeDomain($wild->id, '*.astaloka.test', wildcard: true);
        $this->makeDomain($exact->id, 'demo.astaloka.test');

        $found = $this->resolveFor('demo.astaloka.test');

        $this->assertSame($exact->id, $found?->id);
    }

    #[Test]
    public function domain_kolom_lama_tetap_diresolve(): void
    {
        // Fallback. Lokasi yang ditambahkan lewat `php artisan tenancy:make`
        // tidak selalu punya baris di `tenant_domains`, jadi kolom lama harus
        // tetap dibaca.
        $tenant = $this->makeTenant('legacy', domain: 'legacy-domain.test');

        $this->assertSame($tenant->id, $this->resolveFor('legacy-domain.test')?->id);
    }

    #[Test]
    public function domain_dimatikan_tidak_lagi_diresolve(): void
    {
        $tenant = $this->makeTenant('off');
        $domain = $this->makeDomain($tenant->id, 'off.test');

        $this->assertSame($tenant->id, $this->resolveFor('off.test')?->id);

        $domain->update(['is_active' => false]);

        $this->assertNull($this->resolveFor('off.test'), 'Domain nonaktif masih dilayani.');
    }

    #[Test]
    public function domain_lokasi_nonaktif_tidak_diresolve(): void
    {
        $tenant = $this->makeTenant('inactive');
        $this->makeDomain($tenant->id, 'mati.test');
        $tenant->update(['is_active' => false]);

        $this->assertNull($this->resolveFor('mati.test'));
    }

    #[Test]
    public function host_yang_tidak_terdaftar_tidak_diresolve(): void
    {
        $this->makeTenant('lain');
        $this->makeDomain($this->tenantIds[0], 'terdaftar.test');

        $this->assertNull($this->resolveFor('astaloka.test'));
        $this->assertNull($this->resolveFor('tidak.terdaftar.test'));

        /*
         * Host dengan karakter wildcard LIKE harus TIDAK cocok. Dua host ini
         * sengaja dibuat tidak valid sebagai URL supaya `Request::create()`
         * tidak menolaknya duluan -- yang diuji adalah pencocokan, bukan
         * pembuatan request.
         */
        $this->assertNull($this->resolveForRawHost('foo%bar.test'));
        $this->assertNull($this->resolveForRawHost('foo_bar.test'));
    }

    /* ============================================================
     | VALIDASI & KEUNIKAN
    |============================================================
     */

    #[Test]
    public function host_sama_tidak_boleh_dipakai_dua_lokasi(): void
    {
        $a = $this->makeTenant('a');
        $b = $this->makeTenant('b');
        $this->makeDomain($a->id, 'bentrok.test');

        $this->actingAsSuperadmin();

        $res = $this->postJson("/api/central/locations/{$b->id}/domains", [
            'host' => 'bentrok.test',
        ]);

        $res->assertStatus(422);
        // Pesan harus menyebut lokasi pemilik domain yang sudah ada -- itulah
        // pertanyaan yang dijawab admin ("dipakai siapa?"), bukan sekadar
        // "Duplicate entry" dari driver MySQL.
        $this->assertStringContainsString(
            ucfirst('a'),
            $res->json('message'),
            'Pesan harus menyebut lokasi pemilik domain yang sudah ada.'
        );
    }

    #[Test]
    public function wildcard_dan_domain_persis_boleh_berdampingan(): void
    {
        $wild = $this->makeTenant('w');
        $exact = $this->makeTenant('e');

        $this->makeDomain($wild->id, '*.astaloka.test', wildcard: true);

        $this->actingAsSuperadmin();

        $this->postJson("/api/central/locations/{$exact->id}/domains", [
            'host' => 'demo.astaloka.test',
        ])->assertCreated();
    }

    #[Test]
    public function domain_pusat_tidak_boleh_didaftarkan(): void
    {
        config(['tenancy.central_hosts' => ['panel.astaloka.test']]);

        $tenant = $this->makeTenant('pusat');

        $this->actingAsSuperadmin();

        $res = $this->postJson("/api/central/locations/{$tenant->id}/domains", [
            'host' => 'panel.astaloka.test',
        ]);

        $res->assertStatus(422);
    }

    #[Test]
    public function wildcard_yang_akan_menangkap_domain_pusat_ditolak(): void
    {
        config(['tenancy.central_hosts' => ['panel.astaloka.test']]);

        $tenant = $this->makeTenant('wildpusat');

        $this->actingAsSuperadmin();

        $res = $this->postJson("/api/central/locations/{$tenant->id}/domains", [
            'host' => '*.astaloka.test',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('panel.astaloka.test', $res->json('message'));
    }

    #[Test]
    public function input_kotor_dinormalisasi_sebelum_disimpan(): void
    {
        $tenant = $this->makeTenant('kotor');

        $this->actingAsSuperadmin();

        $res = $this->postJson("/api/central/locations/{$tenant->id}/domains", [
            'host' => '  HTTPS://Demo.Astaloka.TEST/login  ',
        ]);

        $res->assertCreated();
        // Kalau normalisasi gagal, domain ini tidak akan pernah ketemu saat
        // request datang -- dan tidak ada error yang mengatakannya.
        $this->assertSame('demo.astaloka.test', $res->json('data.host'));
    }

    #[Test]
    public function input_tidak_layak_ditolak_dengan_alasan(): void
    {
        $tenant = $this->makeTenant('reject');

        $this->actingAsSuperadmin();

        foreach ([
            'localhost',
            'srv01',
            '192.168.1.10',
            'domain..test',
            '-lead.test',
        ] as $bad) {
            $this->postJson("/api/central/locations/{$tenant->id}/domains", [
                'host' => $bad,
            ])->assertStatus(422);
        }
    }

    /* ============================================================
     | ENDPOINT PANEL PUSAT
    |============================================================
     */

    #[Test]
    public function hanya_superadmin_yang_boleh_kelola_domain(): void
    {
        $tenant = $this->makeTenant('guard');

        // Tanpa login.
        $this->postJson("/api/central/locations/{$tenant->id}/domains", [
            'host' => 'guard.test',
        ])->assertUnauthorized();

        // Login, tapi bukan superadmin. User ini hidup di database lokasi --
        // persis akun admin lokasi.
        Sanctum::actingAs(User::create([
            'name' => 'Admin Lokasi',
            'email' => $this->prefix.'lokasi@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]), ['*'], 'sanctum');

        $this->postJson("/api/central/locations/{$tenant->id}/domains", [
            'host' => 'guard.test',
        ])->assertForbidden();
    }

    #[Test]
    public function index_menampilkan_seluruh_domain_lintas_lokasi(): void
    {
        $a = $this->makeTenant('idx-a');
        $b = $this->makeTenant('idx-b');
        $this->makeDomain($a->id, 'satu.test');
        $this->makeDomain($b->id, '*.dua.test', wildcard: true);

        $this->actingAsSuperadmin();

        $res = $this->getJson('/api/central/domains')->assertOk();

        $hosts = collect($res->json('data'))->pluck('host')->all();

        $this->assertContains('satu.test', $hosts);
        $this->assertContains('*.dua.test', $hosts);
        $this->assertSame(2, $res->json('meta.total'));
        $this->assertSame(2, $res->json('meta.aktif'));
        $this->assertSame(1, $res->json('meta.wildcard'));

        /*
         * Setiap baris harus menyebut lokasinya. Tanpa itu, admin yang melihat
         * `*.dua.test` tidak tahu domain itu milik lokasi mana -- dan seluruh
         * gunanya halaman ini adalah menjawab pertanyaan itu.
         */
        foreach ($res->json('data') as $baris) {
            $this->assertNotNull($baris['tenant_slug'], "Baris `{$baris['host']}` tidak menyebut lokasi.");
            $this->assertNotNull($baris['tenant_label']);
        }
    }

    #[Test]
    public function menghapus_domain_melepas_kepemilikan(): void
    {
        $a = $this->makeTenant('del-a');
        $b = $this->makeTenant('del-b');

        $domain = $this->makeDomain($a->id, 'pindah.test');

        $this->actingAsSuperadmin();

        $this->deleteJson("/api/central/domains/{$domain->id}")->assertOk();

        $this->assertNull($this->resolveFor('pindah.test'));

        // Host yang sama harus bisa dipakai lokasi lain setelah dilepas.
        $this->postJson("/api/central/locations/{$b->id}/domains", [
            'host' => 'pindah.test',
        ])->assertCreated();

        $this->assertSame($b->id, $this->resolveFor('pindah.test')?->id);
    }

    #[Test]
    public function menandai_domain_utama_membersihkan_yang_lama(): void
    {
        $tenant = $this->makeTenant('primary');

        $lama = $this->makeDomain($tenant->id, 'lama.test', primary: true);
        $baru = $this->makeDomain($tenant->id, 'baru.test');

        $this->actingAsSuperadmin();

        $this->patchJson("/api/central/domains/{$baru->id}", [
            'is_primary' => true,
        ])->assertOk();

        $this->assertFalse($lama->fresh()->is_primary);
        $this->assertTrue($baru->fresh()->is_primary);
    }

    #[Test]
    public function mengosongkan_form_domain_legacy_melepas_domain_persis(): void
    {
        /*
         * Form lama dan halaman Domain menulis ke tempat yang sama. Kalau
         * mengosongkan form lama tidak melepas barisnya, domain lama tetap
         * hidup DAN memblokir index unik ketika host baru ditambahkan lewat
         * halaman Domain -- jadi halaman itu akan menolak perubahan yang
         * sudah saja dilakukan admin di halaman lain.
         */
        $tenant = $this->makeTenant('formlama');

        $this->actingAsSuperadmin();

        $this->putJson("/api/central/locations/{$tenant->id}", [
            'nama' => $tenant->nama,
            'domain' => 'formlama.test',
        ])->assertOk();

        $this->assertSame($tenant->id, $this->resolveFor('formlama.test')?->id);

        $this->putJson("/api/central/locations/{$tenant->id}", [
            'nama' => $tenant->nama,
            'domain' => null,
        ])->assertOk();

        $this->assertNull($this->resolveFor('formlama.test'));
    }

    /**
     * Dua field domain harus menghasilkan DUA baris -- dan salah satunya utama.
     *
     * INI ADALAH REGRESI DARI BUG YANG PERNAH ADA.
     *
     * `syncLegacyDomain()` versi lama melepas baris "lama" sebuah slot dengan
     * cara menghapus SELURUH baris domain persis milik lokasi itu -- untuk
     * slot cadangan. Urutan slotnya `domain` dulu, lalu `domain_alternatif`,
     * jadi baris utama yang barusan dibuat ikut terhapus oleh langkah slot
     * cadangan.
     *
     * Gejalanya persis seperti yang dilaporkan: form-nya berisi "Domain
     * Utama" dan "Domain Alternatif", keduanya tersimpan di kolom legacy --
     * tapi hanya SATU domain yang muncul, dan itu pun bukan yang utama.
     *
     * Yang diuji di sini bukan cuma "dua baris ada" -- tapi juga peran
     * masing-masing. Tanpa peran yang benar, `syncPrimary()` lalu jatuh ke
     * domain lain, dan `tenants.domain` menunjuk ke host yang tidak lagi
     * jadi milik lokasi ini.
     */
    #[Test]
    public function dua_field_domain_menyimpan_dua_domain_dengan_satu_utama(): void
    {
        $tenant = $this->makeTenant('duaslot');

        $this->actingAsSuperadmin();

        $this->putJson("/api/central/locations/{$tenant->id}", [
            'nama' => $tenant->nama,
            'domain' => 'duaslot-utama.test',
            'domain_alternatif' => 'duaslot-alternatif.test',
        ])->assertOk();

        $baris = $tenant->domains()->orderBy('id')->get();

        $this->assertCount(
            2,
            $baris,
            'Kedua field domain harus menghasilkan dua baris di tenant_domains.'
        );

        $utama = $baris->firstWhere('is_primary', true);

        $this->assertNotNull($utama, 'Harus ada tepat satu domain utama.');
        $this->assertSame('duaslot-utama.test', $utama->host);
        $this->assertSame(1, $baris->where('is_primary', true)->count());

        /*
         * Dua-duanya harus bisa dilayani.
         *
         * Kolom legacy akan tetap dibaca resolver sebagai cadangan, jadi test
         * ini bisa lulus хотя tabelnya salah -- makanya kedua host diuji
         * lewat resolver, bukan lewat isi tabel saja.
         */
        $this->assertSame($tenant->id, $this->resolveFor('duaslot-utama.test')?->id);
        $this->assertSame($tenant->id, $this->resolveFor('duaslot-alternatif.test')?->id);
    }

    /**
     * Mengganti SATU slot tidak boleh menghapus slot yang lain.
     *
     * Bug yang sama, dilihat dari sisi lain: versi lama menghapus semua baris
     * domain persis milik lokasi setiap kali slot alternatif diisi ulang.
     * Jadi mengubah "Domain Alternatif" saja -- tanpa menyentuh "Domain
     * Utama" -- tetap menghapus domain utamanya.
     *
     * Efeknya: domain utama hilang dari daftar, dan `settings.domain` di
     * database lokasi menunjuk ke host yang sudah tidak dilayani.
     */
    #[Test]
    public function mengganti_satu_slot_tidak_menghapus_slot_lain(): void
    {
        $tenant = $this->makeTenant('gantislot');

        $this->actingAsSuperadmin();

        $this->putJson("/api/central/locations/{$tenant->id}", [
            'nama' => $tenant->nama,
            'domain' => 'gantislot-utama.test',
            'domain_alternatif' => 'gantislot-alternatif.test',
        ])->assertOk();

        // Hanya slot alternatif yang diganti.
        $this->putJson("/api/central/locations/{$tenant->id}", [
            'nama' => $tenant->nama,
            'domain_alternatif' => 'gantislot-alternatif-baru.test',
        ])->assertOk();

        $host = $tenant->domains()->pluck('host')->all();

        $this->assertContains('gantislot-utama.test', $host, 'Domain utama ikut terhapus.');
        $this->assertContains('gantislot-alternatif-baru.test', $host);
        $this->assertNotContains('gantislot-alternatif.test', $host, 'Host lama tidak dilepas.');

        $this->assertSame(
            1,
            $tenant->domains()->where('is_primary', true)->count(),
            'Tetap satu domain utama.'
        );
    }

    /**
     * Mengosongkan slot utama boleh membuat slot cadangan naik jadi utama.
     *
     * Kalau tidak, lokasi itu punya domain tapi tidak punya "domain utama" --
     * dan `settings.domain` di database lokasinya menunjuk ke host yang sudah
     * tidak jadi milik lokasi ini.
     */
    #[Test]
    public function mengosongkan_slot_utama_menaikkan_slot_cadangan(): void
    {
        $tenant = $this->makeTenant('naikslot');

        $this->actingAsSuperadmin();

        $this->putJson("/api/central/locations/{$tenant->id}", [
            'nama' => $tenant->nama,
            'domain' => 'naikslot-utama.test',
            'domain_alternatif' => 'naikslot-alternatif.test',
        ])->assertOk();

        $this->putJson("/api/central/locations/{$tenant->id}", [
            'nama' => $tenant->nama,
            'domain' => null,
        ])->assertOk();

        $this->assertNull(
            $tenant->domains()->where('host', 'naikslot-utama.test')->first(),
            'Domain utama lama harus dilepas.'
        );

        $utama = $tenant->domains()->where('is_primary', true)->first();

        $this->assertNotNull($utama, 'Lokasi harus punya domain utama.');
        $this->assertSame('naikslot-alternatif.test', $utama->host);
    }

    /**
     * Satu field yang salah tidak boleh membatalkan field yang sudah benar.
     *
     * Aturan lama memvalidasi slot satu per satu di dalam loop yang sama
     * yang menulis barisnya. Jadi host utama yang valid sudah ditulis ke
     * `tenant_domains` -- lalu host alternatif ditolak, dan `update()`
     * mengembalikan 422 TANPA menulis kolom legacy. Lokasi jadi setengah jadi:
     * domain baru ada di tabel, kolom lamanya masih kosong, dan tidak ada
     * yang melapor.
     *
     * Sekarang semua input divalidasi dulu, dan tidak ada satu baris pun
     * ditulis kalau ada satu saja yang tidak valid.
     */
    #[Test]
    public function satu_field_salah_tidak_meninggalkan_lokasi_setengah_jadi(): void
    {
        $tenant = $this->makeTenant('setengah');

        $this->actingAsSuperadmin();

        $res = $this->putJson("/api/central/locations/{$tenant->id}", [
            'nama' => $tenant->nama,
            'domain' => 'setengah-utama.test',
            'domain_alternatif' => 'localhost:5173',
        ])->assertStatus(422);

        $this->assertNotEmpty($res->json('errors.domain_alternatif'));

        $this->assertSame(
            0,
            $tenant->domains()->count(),
            'Domain yang sudah divalidasi tidak boleh terlanjur ditulis.'
        );

        $this->assertNull($tenant->fresh()->domain);
    }

    /**
     * Pendaftaran lokasi: satu slot yang tidak valid tidak boleh membatalkan
     * slot lain yang valid.
     *
     * Mode "sebagian" dipakai di sini karena database lokasi sudah dibuat
     * dan dimigrasi pada saat domain didaftarkan -- membatalkan semuanya
     * demi satu host yang berformat aneh akan meninggalkan database yatim
     * yang harus dibersihkan terpisah.
     *
     * Tapi satu slot yang tidak valid juga tidak boleh membuat lokasi baru
     * punya NOL domain: itu persis keadaan yang membuat lokasi lama
     * kehilangan domain utamanya.
     */
    #[Test]
    public function satu_slot_salah_tidak_menggagalkan_slot_lain_saat_pendaftaran(): void
    {
        $tenant = $this->makeTenant('daftarsetengah');

        $hasil = app(LegacyDomainSync::class)->sync($tenant, [
            'domain' => 'daftarsetengah-utama.test',
            'domain_alternatif' => 'localhost:5173',
        ], sebagian: true);

        $this->assertArrayHasKey('domain_alternatif', $hasil);
        $this->assertArrayNotHasKey('domain', $hasil);

        $host = $tenant->domains()->pluck('host')->all();

        $this->assertContains('daftarsetengah-utama.test', $host, 'Slot yang valid harus tetap terdaftar.');
        $this->assertNotContains('localhost', $host);
    }

    /**
     * Daftar lokasi harus menunjukkan KEDUA domain milik setiap lokasi.
     *
     * Tanpa relasi `domains` di respons daftar, kolom "Domain" di
     * `/central/lokasi` hanya menampilkan `row.domain` -- satu host saja --
     * sehingga domain alternatif tidak terlihat di mana pun. Gejalanya sama
     * seperti bug di atas, tapi di halaman yang berbeda: user tidak bisa
     * melihat domain kedua yang mereka isi sendiri.
     */
    #[Test]
    public function daftar_lokasi_menampilkan_semua_domain(): void
    {
        $tenant = $this->makeTenant('daftardomain');

        $this->makeDomain($tenant->id, 'daftardomain-utama.test', primary: true);
        $this->makeDomain($tenant->id, 'daftardomain-alternatif.test');

        $this->actingAsSuperadmin();

        $baris = collect($this->centralLocations())->firstWhere('id', $tenant->id);

        $this->assertNotNull($baris, 'Lokasi harus muncul di daftar.');

        $host = collect($baris['domains'] ?? [])->pluck('host')->all();

        $this->assertContains('daftardomain-utama.test', $host);
        $this->assertContains('daftardomain-alternatif.test', $host);
    }

    /**
     * Lokasi yang domainnya hilang dari `tenant_domains` harus bisa diperbaiki.
     *
     * `backfillLegacyDomains()` SENGAJA melewati lokasi yang sudah punya baris
     * apa pun -- supaya tidak menimpa pilihan admin yang lebih baru. Lokasi
     * yang rusak oleh bug lama justru punya baris (kurang satu), jadi ia
     * tidak pernah tersentuh oleh pemulihan biasa.
     *
     * Perbaikannya hanya menambah baris yang hilang; baris yang sudah ada
     * tidak boleh dihapus.
     */
    #[Test]
    public function perbaikan_domain_menambah_baris_yang_hilang_tanpa_menghapus_yang_ada(): void
    {
        $tenant = $this->makeTenant('perbaiki');

        $central = DB::connection(self::CENTRAL);

        $central->table('tenants')->where('id', $tenant->id)->update([
            'domain' => 'perbaiki-utama.test',
            'domain_alternatif' => 'perbaiki-alternatif.test',
        ]);

        // Keadaan rusak: hanya slot alternatif yang punya baris.
        $this->makeDomain($tenant->id, 'perbaiki-alternatif.test');

        $hasil = app(LegacyDomainSync::class)->repair($tenant->fresh());

        $this->assertSame(1, $hasil['disimpan'], 'Slot utama yang hilang harus ditambahkan.');

        $host = $tenant->domains()->pluck('host')->all();

        $this->assertContains('perbaiki-utama.test', $host);
        $this->assertContains('perbaiki-alternatif.test', $host);
        $this->assertCount(2, $host);

        $this->assertSame(
            1,
            $tenant->domains()->where('is_primary', true)->count(),
            'Harus ada tepat satu domain utama.'
        );
    }

    #[Test]
    public function form_domain_legacy_tidak_menghapus_wildcard(): void
    {
        $tenant = $this->makeTenant('wildform');
        $this->makeDomain($tenant->id, '*.astaloka.test', wildcard: true);

        $this->actingAsSuperadmin();

        $this->putJson("/api/central/locations/{$tenant->id}", [
            'nama' => $tenant->nama,
            'domain' => 'wildform.test',
        ])->assertOk();

        $this->assertNotNull(
            TenantDomain::query()
                ->where('tenant_id', $tenant->id)
                ->where('host', '*.astaloka.test')
                ->first(),
            'Wildcard terhapus saat form lokasi disimpan.'
        );
    }

    #[Test]
    public function form_domain_legacy_disimpan_dalam_bentuk_host_bersih(): void
    {
        /*
         * Kolom `tenants.domain` dibaca apa adanya oleh
         * `TenantResolver::fromHost()`, yang membandingkannya dengan host polos
         * dari request. Kalau form menyimpan input mentah, `https://Demo.ASTALOKA.test/login`
         * tersimpan lengkap dengan skema dan path-nya -- dan tidak ada request
         * yang punya host seperti itu.
         *
         * Gejalanya bukan error: domain terlihat tersimpan di panel, barisnya
         * juga dibuat di `tenant_domains`, tapi request di domain itu tetap
         * mendarat di halaman depan. Test ini memanggil resolver langsung
         * supaya kegagalan muncul sebagai assertion, bukan sebagai keluhan
         * pengguna di grup WhatsApp.
         */
        $tenant = $this->makeTenant('legacy');

        $this->actingAsSuperadmin();

        $this->putJson("/api/central/locations/{$tenant->id}", [
            'nama' => $tenant->nama,
            'domain' => 'HTTPS://Demo.ASTALOKA.test/login',
            'domain_alternatif' => 'https://Cadangan.ASTALOKA.test:8443?a=1',
        ])->assertOk();

        $bersih = $tenant->fresh();

        $this->assertSame('demo.astaloka.test', $bersih->domain);
        $this->assertSame('cadangan.astaloka.test', $bersih->domain_alternatif);

        $this->assertSame($tenant->id, $this->resolveFor('demo.astaloka.test')?->id);
        $this->assertSame($tenant->id, $this->resolveForRawHost('Demo.ASTALOKA.test')?->id);
    }

    #[Test]
    public function form_domain_legacy_membuang_bagian_yang_bukan_host(): void
    {
        /*
         * Kolom legacy bisa berisi data lama yang formatnya sudah rusak, jadi
         * normalisasi tidak boleh menolak -- tapi bagian yang jelas bukan host
         * tetap harus dibuang, karena `TenantResolver` membandingkan host polos
         * dengan isi kolom itu apa adanya. Skema, path, query, dan port tidak
         * pernah jadi bagian dari sebuah host, dan `localhost:5173` adalah host
         * Vite yang sangat mungkin ikut tersalin dari form.
         *
         * Host yang `syncLegacyDomain()` tolak (localhost, IP, bentuk lain
         * yang tidak layak) tidak sampai ke sini sama sekali -- supaya
         * `update()` tidak berubah jadi lebih permisif daripada halaman
         * Domain. Yang diuji di sini bentuk yang lolos validasi tapi masih
         * membawa sisa yang tidak perlu.
         */
        $tenant = $this->makeTenant('legacyaneh');

        $this->actingAsSuperadmin();

        $this->putJson("/api/central/locations/{$tenant->id}", [
            'nama' => $tenant->nama,
            'domain' => '  Tirto-Mulo.ASTALOKA.test/login?x=1  ',
            'domain_alternatif' => 'HTTPS://Cadangan.ASTALOKA.test:8443/daftar#isi',
        ])->assertOk();

        $bersih = $tenant->fresh();

        $this->assertSame('tirto-mulo.astaloka.test', $bersih->domain);
        $this->assertSame('cadangan.astaloka.test', $bersih->domain_alternatif);

        $this->assertSame($tenant->id, $this->resolveFor('tirto-mulo.astaloka.test')?->id);
    }

    #[Test]
    public function host_tidak_layak_masih_ditolak_setelah_normalisasi(): void
    {
        /*
         * Kolom legacy ditulis dalam bentuk bersih, bukan apa adanya seperti
         * input form. Kalau normalisasi membuat `update()` lebih permisif
         * daripada halaman Domain, dua halaman yang mengedit field yang sama
         * akan punya aturan berbeda -- dan yang ditolak biasanya justru yang
         * paling penting: niat admin.
         *
         * `localhost:5173` yang sudah dibersihkan portnya menjadi `localhost`,
         * yang tetap ditolak. Kalau normalisasi tidak ada, string itu justru
         * lolos karena port membuatnya tidak dikenali sebagai alamat lokal.
         */
        $tenant = $this->makeTenant('legacytolak');

        $this->actingAsSuperadmin();

        $res = $this->putJson("/api/central/locations/{$tenant->id}", [
            'nama' => $tenant->nama,
            'domain' => 'localhost:5173',
        ])->assertStatus(422);

        $this->assertNotEmpty($res->json('errors.domain'));

        /*
         * Penolakan harus lengkap: bukan hanya Balas 422, tapi juga tidak
         * meninggalkan baris di `tenant_domains`. Kalau barisnya somehow
         * terlanjur ditulis sebelum penolakan dikembalikan, lokasi itu
         * punya domain yang tidak pernah di-acc admin -- dan `TenantResolver`
         * akan mulai mengarahkannya ke sana pada request berikutnya.
         */
        $this->assertSame(
            0,
            DB::connection(self::CENTRAL)
                ->table('tenant_domains')
                ->where('tenant_id', $tenant->id)
                ->count(),
            'Host yang ditolak tetap menulis baris di `tenant_domains`.'
        );

        $this->assertNull($tenant->fresh()->domain);
    }

    /* ============================================================
     | BANTUAN
    |============================================================
     */

    private function actingAsSuperadmin(): void
    {
        Sanctum::actingAs($this->superadmin, ['*'], 'sanctum');
    }

    /**
     * Pastikan tabel yang dibutuhkan sudah ada di database pusat.
     *
     * Dipanggil dari `setUp()` setiap kali, bukan sekali per suite, karena
     * `RefreshDatabase` bisa mengosongkan database pusat di tengah jalan --
     * lihat catatan panjang di docblock class.
     *
     * Yang dijalankan hanya migrasi yang benar-benar dibutuhkan test ini, bukan
     * seluruh folder `migrations_central`. Menjalankan semuanya hanya membuka
     * permukaan kegagalan baru yang tidak berkaitan dengan domain.
     *
     * `tenants` diperiksa lebih dulu: `tenant_domains` punya foreign key ke
     * `tenants.id`, jadi HeidiSQL menolaknya kalau tabel induk belum ada.
     */
    private function ensureCentralTables($central): void
    {
        /*
         * Seluruh migrasi pusat, urut nama file.
         *
         * Sebelumnya daftar migrasi ditulis manual di sini, dan setiap migrasi
         * pusat yang datang belakangan harus ditambahkan satu per satu. Yang
         * terlewat tidak menghasilkan error yang berguna: kolom yang belum ada
         * di skema uji tapi sudah dikirim model membuat seluruh test gagal
         * dengan "Unknown column" yang tidak menyinggung migrasinya.
         *
         * Urutnya tetap terjaga karena diambil dari nama file, yang sudah
         * membawa timestamp-nya -- `tenants` selalu lebih dulu dari
         * `tenant_domains` yang ber-foreign key kepadanya.
         */
        $this->runCentralMigrations();

        $this->ensureCentralUsersActiveColumn();
    }

    /**
     * Pastikan `users` di database pusat punya kolom `is_active`.
     *
     * Migrasi ini ditambahkan setelah `CentralDomainTest` pertama kali
     * ditulis, jadi database testing yang sudah ada bisa belum punya kolom
     *nya -- dan test yang tidak butuh status user tidak boleh gagal hanya
     * karena itu.
     *
     * Dijalankan lewat migration yang sama dengan produksi, bukan `ALTER`
     * manual, supaya test ini tidak bisa lulus di database yang lebih longgar
     * dari yang dipakai server.
     */
    private function ensureCentralUsersActiveColumn(): void
    {
        $schema = Schema::connection(self::CENTRAL);

        if ($schema->hasColumn('users', 'is_active')) {
            return;
        }

        $migrasi = require base_path(
            (string) config('tenancy.central_migrations_path', 'database/migrations_central')
            .'/2026_10_14_000003_add_is_active_to_central_users_table.php'
        );

        $migrasi->up();
    }

    /**
     * Pemulihan domain yang hilang harus mengembalikan domain LAMA.
     *
     * Skenario ini bukan hipotesis. `AUTO_INCREMENT` di `tenant_domains`
     * pernah menunjukkan 4 dengan 0 baris -- tiga INSERT pernah terjadi lalu
     * semua hilang, karena ada test yang memanggil `->delete()` tanpa filter
     * pada koneksi pusat yang kelewat menunjuk database produksi. Kolom
     * `tenants.domain` tetap utuh karena tidak ada yang menghapusnya.
     *
     * Yang pulih di sini hanya domain yang berasal dari kolom legacy. Domain
     * yang ditambahkan lewat panel tidak ada salinannya di kolom mana pun,
     * jadi tidak bisa dipulihkan -- dan itulah batas yang jujur dari pemulihan
     * ini, bukan sesuatu yang bisa ditutup dengan migrasi.
     */
    #[Test]
    public function domain_yang_hilang_dipulihkan_dari_kolom_lama(): void
    {
        $tenant = $this->makeTenant('pulih', 'pulih-utama.test');

        $central = DB::connection(self::CENTRAL);
        $central->table('tenants')->where('id', $tenant->id)->update([
            'domain_alternatif' => 'pulih-alternatif.test',
        ]);

        // Keadaan yang sebenarnya terjadi: tabel ada, tapi isinya hilang.
        $central->table('tenant_domains')->where('tenant_id', $tenant->id)->delete();

        $this->assertSame(0, $tenant->domains()->count(), 'Kondisi awal harus kosong.');

        $hasil = DomainNormalizer::backfillLegacyDomains();

        $this->assertGreaterThanOrEqual(2, $hasil['disimpan']);

        $tersimpan = $tenant->domains()->pluck('host', 'is_primary')->all();

        $this->assertArrayHasKey(1, $tersimpan, 'Domain utama harus kembali.');
        $this->assertSame('pulih-utama.test', $tersimpan[1]);
        $this->assertSame('pulih-alternatif.test', $tersimpan[0]);
    }

    /**
     * Pemulihan dijalankan dua kali tidak boleh menggandakan atau merusak.
     *
     * `tenancy:migrate-central` bisa dijalankan berkali-kali, dan migrasi
     * yang sama bisa dievaluasi ulang kalau di-rollback lalu dijalankan lagi.
     * Kalau pemulihannya tidak idempoten, dua kali jalannya berarti domain
     * utama hilang atau muncul baris kembar.
     */
    #[Test]
    public function pemulihan_domain_bisa_dijalankan_berulang(): void
    {
        $tenant = $this->makeTenant('ulang', 'ulang-utama.test');

        $central = DB::connection(self::CENTRAL);

        $central->table('tenant_domains')->where('tenant_id', $tenant->id)->delete();

        $pertama = DomainNormalizer::backfillLegacyDomains();
        $kedua = DomainNormalizer::backfillLegacyDomains();

        $this->assertGreaterThanOrEqual(1, $pertama['disimpan'], 'Jalan pertama harus menyimpan.');
        $this->assertSame(0, $kedua['disimpan'], 'Jalan kedua tidak boleh menyimpan apa pun.');
        $this->assertGreaterThanOrEqual(1, $kedua['dilewati']);

        $this->assertSame(1, $tenant->domains()->count(), 'Tidak boleh ada baris kembar.');
    }

    /**
     * Domain yang sudah punya baris sendiri tidak boleh tertimpa pemulihan.
     *
     * Pemulihan menyalin dari kolom legacy, dan kolom legacy bisa tertinggal --
     * admin sudah mengganti domain utama lewat panel, sementara kolom lamanya
     * masih menunjuk domain lama. Pemulihan yang menimpa akan membalikkan
     * perubahan admin itu tanpa sepengetahuan siapa pun.
     */
    #[Test]
    public function pemulihan_tidak_menimpa_domain_yang_sudah_ada(): void
    {
        $tenant = $this->makeTenant('impan', 'lama-yang-tertinggal.test');

        $central = DB::connection(self::CENTRAL);
        $central->table('tenant_domains')->where('tenant_id', $tenant->id)->delete();

        // Admin sudah memilih domain baru lewat panel, sementara kolom
        // `domain` belum ikut ter-update.
        $this->makeDomain($tenant->id, 'baru-choosen.test', primary: true);

        DomainNormalizer::backfillLegacyDomains();

        $this->assertSame(
            1,
            $tenant->domains()->where('is_primary', true)->count(),
            'Tetap satu domain utama.'
        );

        $utama = $tenant->domains()->where('is_primary', true)->first();

        $this->assertSame(
            'baru-choosen.test',
            $utama->host,
            'Domain utama pilihan admin tidak boleh ditimpa kolom lama.'
        );
    }

    /**
     * Domain host yang sama milik dua lokasi tidak boleh membuat pemulihan gagal.
     *
     * Data lama tidak punya constraint apa pun, jadi duplikatnya mungkin ada --
     * dan pada kondisi itu hanya satu lokasi bisa memegangnya, karena index
     * unik `tenant_domains_host_unique` tidak mengizinkan dua pemilik.
     *
     * Yang diuji adalah sifat yang penting: kegagalan satu baris TIDAK boleh
     * membatalkan semuanya. Kalau pemulihan berhenti karena satu host duplikat,
     * lokasi yang tidak bermasalah tidak akan pernah mendapat domain di daftar
     * baru -- dan tidak ada yang akan memberitahu.
     */
    #[Test]
    public function pemulihan_melewati_domain_duplikat_dan_tetap_lanjut(): void
    {
        $pertama = $this->makeTenant('dupe-a', 'sama.test');
        $kedua = $this->makeTenant('dupe-b', 'sama.test');
        $lain = $this->makeTenant('dupe-c', 'tidak-bentrok.test');

        $central = DB::connection(self::CENTRAL);
        $central->table('tenant_domains')->delete();

        $hasil = DomainNormalizer::backfillLegacyDomains();

        // Dua host unik harus tersimpan: yang tidak bentrok dan satu dari dua
        // yang sama. Kalau hanya satu yang tersimpan, pemulihan berhenti di
        // baris duplikat -- persis kegagalan yang paling sulit didiagnosis.
        $this->assertSame(2, $hasil['disimpan']);

        $tersimpan = $central->table('tenant_domains')->pluck('host')->all();

        $this->assertContains('tidak-bentrok.test', $tersimpan);
        $this->assertContains('sama.test', $tersimpan);

        // Tepat satu pemilik, bukan dua: index unik bekerja seperti seharusnya.
        $this->assertSame(
            1,
            $central->table('tenant_domains')->where('host', 'sama.test')->count(),
            'Satu host hanya boleh dimiliki satu lokasi.'
        );

        // Lokasi yang kehilangan slot karena duplikat masih punya kolom legacy-nya.
        $this->assertSame('sama.test', $kedua->fresh()->domain);

        $this->assertNotNull($pertama->fresh());
        $this->assertNotNull($kedua->fresh());
        $this->assertNotNull($lain->fresh());
    }

    private function makeTenant(string $slug, ?string $domain = null): Tenant
    {
        $id = DB::connection(self::CENTRAL)->table('tenants')->insertGetId([
            'slug' => $this->prefix.$slug,
            'nama' => ucfirst($slug),
            'database' => 'astaloka_'.str_replace('-', '_', $this->prefix.$slug),
            'domain' => $domain,
            'domain_alternatif' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->tenantIds[] = $id;

        return Tenant::query()->findOrFail($id);
    }

    /* ================================================================
     | Endpoint publik halaman depan
     |============================================================
     *
     * `PublicLocationController` yang mengembalikan daftar lokasi untuk
     * landing page. Yang dikunci di sini bukan bentuk JSON-nya, tapi
     * DARI MANA host diambil -- karena dua sumber itu bisa berbeda, dan
     * gejalanya tidak pernah muncul sebagai error.
     */

    /**
     * Host yang dikirim halaman depan harus yang BENAR-BENAR dilayani.
     *
     * `TenantResolver` mencari di `tenant_domains` lebih dulu; jadi kalau
     * endpoint publik membaca kolom legacy saja, halaman depan bisa
     * mengirim orang ke domain yang tidak lagi dilayani -- dan orang itu
     * mendarat di lokasi lain, atau di halaman marketing.
     *
     * Yang menggeser kolom legacy ke domain lama di sini disengaja: itu
     * kondisi nyata yang meninggalkan `backfillLegacyDomains()` -- ia sengaja
     * melewati lokasi yang sudah punya baris di `tenant_domains`, jadi
     * kolom lama bisa tertinggal.
     */
    #[Test]
    public function endpoint_publik_mengikuti_domain_yang_aktif(): void
    {
        $lokasi = $this->makeTenant('aktif', 'lama.test');
        $this->makeDomain($lokasi->id, 'baru.test', primary: true);

        $data = $this->publicLocations();

        $baris = collect($data)->firstWhere('slug', $lokasi->slug);

        $this->assertNotNull($baris, 'Lokasi aktif harus muncul di daftar publik.');
        $this->assertSame('baru.test', $baris['host']);
    }

    /**
     * Lokasi tanpa baris di `tenant_domains` tetap harus muncul.
     *
     * Lokasi yang ditambahkan lewat `tenancy:make` tidak selalu melewati
     * controller, jadi bisa tidak punya domain di tabel baru. Kalau
     * endpoint publik hanya membaca `tenant_domains`, lokasi seperti ini
     * hilang dari dropdown -- padahal resolver tetap melayaninya lewat
     * kolom legacy.
     */
    #[Test]
    public function endpoint_publik_jatuh_ke_kolom_lama(): void
    {
        $lokasi = $this->makeTenant('legacy', 'cadangan.test');

        $data = $this->publicLocations();

        $baris = collect($data)->firstWhere('slug', $lokasi->slug);

        $this->assertNotNull($baris);
        $this->assertSame('cadangan.test', $baris['host']);
    }

    /**
     * Wildcard tidak boleh jadi tujuan redirect.
     *
     * `*.astaloka.test` tidak punya host yang bisa diklik, jadi mengarahkan
     * orang ke sana hanya menghasilkan error browser yang tidak menjelaskan
     * apa pun. Lokasi yang HANYA punya wildcard melaporkan host kosong, bukan
     * string wildcard.
     */
    #[Test]
    public function endpoint_publik_tidak_menghasilkan_tautan_wildcard(): void
    {
        $lokasi = $this->makeTenant('wild', null);
        $this->makeDomain($lokasi->id, '*.astaloka.test', wildcard: true, primary: true);

        $data = $this->publicLocations();

        $baris = collect($data)->firstWhere('slug', $lokasi->slug);

        $this->assertNotNull($baris);
        $this->assertSame('', $baris['host']);
    }

    /** Isi `data` dari endpoint publik, sudah di-decode. */
    private function publicLocations(): array
    {
        $response = $this->getJson('/api/central/lokasi')->assertOk();

        $this->assertTrue($response->json('success'));

        return $response->json('data');
    }

    /**
     * Isi `data` dari daftar lokasi panel pusat, sudah di-decode.
     *
     * Endpoint yang sama dengan `publicLocations()` -- `/api/central/lokasi` --
     * tapi dipanggil sebagai superadmin, jadi yang dikembalikan adalah
     * daftar LENGKAP beserta domainnya, bukan ringkasan publik.
     */
    private function centralLocations(): array
    {
        $this->actingAsSuperadmin();

        $response = $this->getJson('/api/central/locations')->assertOk();

        $this->assertTrue($response->json('success'));

        return $response->json('data');
    }

    private function makeDomain(
        int $tenantId,
        string $host,
        bool $wildcard = false,
        bool $primary = false,
    ): TenantDomain {
        return TenantDomain::query()->create([
            'tenant_id' => $tenantId,
            'host' => $host,
            'is_wildcard' => $wildcard,
            'is_primary' => $primary,
            'is_active' => true,
        ]);
    }

    /**
     * Resolve seperti yang dilakukan middleware, tanpa lewat HTTP.
     *
     * `HTTP_HOST` disetel eksplisit karena `Request::getHost()` memakainya --
     * test yang bergantung pada host bawaan CI akan lolos untuk alasan yang
     * salah.
     */
    private function resolveFor(string $host): ?Tenant
    {
        return $this->resolveForRawHost($host);
    }

    /**
     * Resolve dengan host yang perlu disetel langsung ke header.
     *
     * `Request::create("http://{$host}/")` menolak host yang bukan URL valid
     * (`%`, `_`), padahal host itulah yang sedang diuji. Karena itu request
     * dibuat dari URL tetap yang aman lalu header HOST-nya ditimpa.
     *
     * Header di-set eksplisit karena `Request::getHost()` memakainya -- test
     * yang bergantung pada host bawaan CI akan lolos untuk alasan yang salah.
     */
    private function resolveForRawHost(string $host): ?Tenant
    {
        $request = Request::create('http://localhost/api/me', 'GET');
        $request->headers->set('HOST', $host);

        return app(TenantResolver::class)->resolve($request);
    }

    /** Dipakai untuk memastikan kelas normalizer bisa dipanggil statis. */
    #[Test]
    public function normalizer_menolak_domain_tidak_layak(): void
    {
        [$host, $error] = DomainNormalizer::clean('HTTPS://Demo.Astaloka.TEST/');

        $this->assertSame('demo.astaloka.test', $host);
        $this->assertNull($error);

        [$host, $error] = DomainNormalizer::clean('localhost');

        $this->assertNull($host);
        $this->assertNotNull($error);
    }
}
