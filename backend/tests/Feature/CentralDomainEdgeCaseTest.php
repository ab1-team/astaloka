<?php

namespace Tests\Feature;

use App\Models\Central\Tenant;
use App\Models\Central\TenantDomain;
use App\Models\User;
use App\Services\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\EnsuresCentralTables;
use Tests\TestCase;
use Throwable;

/**
 * Kasus tepi domain lokasi yang tidak tertangkap test utama.
 *
 * Ketiganya punya gejala yang sama: tidak ada error, tidak ada log, dan
 * permintaan tetap dijawab 200 -- hanya database lokasi yang dilayani yang
 * salah. Itu sebabnya semuanya diuji lewat resolver, bukan hanya bentuk
 * respons JSON.
 */
class CentralDomainEdgeCaseTest extends TestCase
{
    use EnsuresCentralTables;
    use RefreshDatabase;

    private const CENTRAL = 'central';

    private string $prefix;

    private User $superadmin;

    /** @var array<int, int> */
    private array $tenantIds = [];

    /** @var list<string> */
    private array $databaseDibuat = [];

    protected function setUp(): void
    {
        parent::setUp();

        $central = DB::connection(self::CENTRAL);

        $this->prefix = 'ujidomain-'.uniqid().'-';

        $this->runCentralMigrations();

        // Sama seperti `CentralDomainTest`: database pusat tidak ikut
        // di-refresh `RefreshDatabase`, jadi sisa run sebelumnya harus
        // dibersihkan eksplisit.
        $central->table('tenant_domains')->delete();
        $central->table('audit_logs')->where('action', 'like', 'tenant.domain%')->delete();
        $central->table('tenants')->where('slug', 'like', 'ujidomain-%')->delete();
        $central->table('users')->where('email', 'like', 'ujidomain-%@test.local')->delete();

        $email = $this->prefix.'admin@test.local';

        $central->table('users')->insert([
            'name' => 'Admin Pusat',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'superadmin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->superadmin = (new User)->forceFill([
            'name' => 'Admin Pusat',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'superadmin',
        ]);
        $this->superadmin->id = $central->table('users')
            ->where('email', $email)
            ->value('id');
    }

    protected function tearDown(): void
    {
        try {
            $central = DB::connection(self::CENTRAL);

            foreach ($this->tenantIds as $id) {
                $central->table('audit_logs')->where('tenant_id', $id)->delete();
                $central->table('tenant_domains')->where('tenant_id', $id)->delete();
                $central->table('tenants')->where('id', $id)->delete();
            }

            $central->table('users')->where('email', 'like', 'ujidomain-%@test.local')->delete();

            foreach ($this->databaseDibuat as $nama) {
                $central->statement("DROP DATABASE IF EXISTS `{$nama}`");
            }
        } catch (Throwable $e) {
            report($e);
        }

        parent::tearDown();
    }

    /**
     * Wildcard tidak boleh menjadi domain utama legacy.
     *
     * `*.x.test` tidak punya host yang bisa dibuka; menuliskannya ke
     * `tenants.domain` membuat kop laporan mencetak string wildcard dan
     * `punyaDomain()` menganggap lokasi punya alamat.
     */
    #[Test]
    public function wildcard_tidak_menjadi_domain_utama_legacy(): void
    {
        $tenant = $this->makeTenant('wildcard', null);

        $this->actingAsSuperadmin();

        $this->postJson("/api/central/locations/{$tenant->id}/domains", [
            'host' => '*.wild.test',
        ])->assertCreated();

        $this->assertNull($tenant->fresh()->domain);
    }

    /**
     * Mematikan satu-satunya domain harus menghentikan layanan.
     *
     * Tanpa ini, `is_active=false` tidak mengubah apa pun untuk lokasi yang
     * kolom legacy-nya menunjuk host yang sama: exact match hilang dari
     * `tenant_domains`, lalu resolver menemukannya lagi di `tenants.domain`.
     */
    #[Test]
    public function mematikan_satu_satunya_domain_menghentikan_layanan(): void
    {
        $tenant = $this->makeTenant('mati', 'mati-satu.test');
        $domain = $this->makeDomain($tenant->id, 'mati-satu.test', primary: true);

        $this->assertSame($tenant->id, $this->resolveFor('mati-satu.test')?->id);

        $this->actingAsSuperadmin();
        $this->patchJson("/api/central/domains/{$domain->id}", [
            'is_active' => false,
        ])->assertOk();

        $this->assertNull($tenant->fresh()->domain, 'Kolom legacy harus ikut dilepas.');
        $this->assertNull($this->resolveFor('mati-satu.test'), 'Domain nonaktif masih dilayani.');
    }

    /**
     * Host milik lokasi yang dibuat lewat CLI tidak boleh diklaim lokasi lain.
     *
     * `tenancy:make` versi lama hanya menulis `tenants.domain`. Kalau
     * pemeriksaan keunikan hanya melihat `tenant_domains`, lokasi kedua bisa
     * mendaftarkan host itu dan resolver langsung memindahkan request ke sana.
     */
    #[Test]
    public function domain_legacy_lokasi_lain_tidak_bisa_diklaim(): void
    {
        $this->makeTenant('lama', 'cli-saja.test');
        $baru = $this->makeTenant('baru', null);

        $this->actingAsSuperadmin();

        $this->postJson("/api/central/locations/{$baru->id}/domains", [
            'host' => 'cli-saja.test',
        ])->assertStatus(422);

        $this->assertSame(0, $baru->domains()->count());
    }

    /** Domain persis pertama otomatis ditandai utama. */
    #[Test]
    public function domain_persis_pertama_menjadi_utama(): void
    {
        $tenant = $this->makeTenant('utama', null);

        $this->actingAsSuperadmin();

        $this->postJson("/api/central/locations/{$tenant->id}/domains", [
            'host' => 'utama.test',
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_primary', true);
    }

    /**
     * Lokasi dengan domain legacy yang barisnya nonaktif tidak boleh dianggap
     * "punya domain" saat diaktifkan.
     */
    #[Test]
    public function lokasi_dengan_domain_nonaktif_tidak_bisa_diaktifkan(): void
    {
        $tenant = $this->makeTenant(
            'aktif',
            'aktif.test',
            database: (string) config('database.connections.mysql.database'),
        );

        $tenant->update(['is_active' => false]);
        $this->makeDomain($tenant->id, 'aktif.test', primary: true, active: false);

        $this->actingAsSuperadmin();

        $res = $this->patchJson("/api/central/locations/{$tenant->id}/activate")
            ->assertStatus(422);

        $this->assertStringContainsString('belum punya domain', (string) $res->json('message'));
    }

    /**
     * `tenancy:make --domain` harus menulis ke dua tempat: kolom legacy dan
     * `tenant_domains`.
     *
     * Tanpa baris di `tenant_domains`, host itu tidak terlindungi dari
     * pendaftaran oleh lokasi lain -- dan resolver memilih lokasi yang punya
     * baris di tabel itu.
     */
    #[Test]
    public function perintah_tenancy_make_mendaftarkan_domain(): void
    {
        $slug = $this->prefix.'cli';
        $database = 'astaloka_'.str_replace('-', '_', $slug);

        $this->databaseDibuat[] = $database;

        $this->artisan('tenancy:make', [
            'slug' => $slug,
            '--database' => $database,
            '--domain' => 'https://CLI-Domain.test/login',
            '--skip-migrate' => true,
        ])->assertSuccessful();

        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        $this->tenantIds[] = $tenant->id;

        $this->assertSame('cli-domain.test', $tenant->domain);

        $domain = $tenant->domains()->where('host', 'cli-domain.test')->first();

        $this->assertNotNull($domain);
        $this->assertTrue((bool) $domain->is_primary);
        $this->assertTrue((bool) $domain->is_active);
    }

    private function actingAsSuperadmin(): void
    {
        Sanctum::actingAs($this->superadmin, ['*'], 'sanctum');
    }

    private function makeTenant(
        string $slug,
        ?string $domain = null,
        ?string $database = null,
    ): Tenant {
        $id = DB::connection(self::CENTRAL)->table('tenants')->insertGetId([
            'slug' => $this->prefix.$slug,
            'nama' => ucfirst($slug),
            'database' => $database
                ?? 'astaloka_'.str_replace('-', '_', $this->prefix.$slug),
            'domain' => $domain,
            'domain_alternatif' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->tenantIds[] = $id;

        return Tenant::query()->findOrFail($id);
    }

    private function makeDomain(
        int $tenantId,
        string $host,
        bool $primary = false,
        bool $active = true,
    ): TenantDomain {
        return TenantDomain::query()->create([
            'tenant_id' => $tenantId,
            'host' => $host,
            'is_wildcard' => false,
            'is_primary' => $primary,
            'is_active' => $active,
        ]);
    }

    private function resolveFor(string $host): ?Tenant
    {
        $request = Request::create('http://localhost/api/me', 'GET');
        $request->headers->set('HOST', $host);

        return app(TenantResolver::class)->resolve($request);
    }
}
