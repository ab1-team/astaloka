<?php

namespace Tests\Feature;

use App\Models\Central\Tenant;
use App\Models\Central\TenantDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\EnsuresCentralTables;
use Tests\TestCase;
use Throwable;

/**
 * Status aktif lokasi, dan penghapusan permanennya.
 *
 * BUG YANG DIKUNCI DI SINI
 * ========================
 *
 * Lokasi yang sudah dinonaktifkan TIDAK bisa diaktifkan kembali.
 *
 * Penyebabnya: satu-satunya endpoint yang menyentuh `is_active` selain
 * `update()` adalah `destroy()` -- dan `destroy()` SELALU menulis
 * `is_active = false`. Frontend memanggilnya untuk kedua arah: tombol
 * "Aktifkan kembali" mengirim DELETE yang sama dengan tombol "Nonaktifkan".
 *
 * Gejalanya tidak mencolok: request-nya berhasil (200), toast "berhasil"
 * muncul, dan hanya kolom status yang tetap "Nonaktif". Tidak ada error
 * di mana pun yang Says "kenapa tidak aktif lagi".
 *
 * Test di sini memanggil endpoint-nya secara langsung -- lewat HTTP,
 * dengan superadmin yang benar-benar login -- supaya regresinya ketahuan
 * kalau nanti ada yang menulis ulang `destroy()` atau memindahkan toggle
 * ke tempat lain.
 *
 * CATATAN SOAL DATABASE PUSAT DI TEST
 *
 * `RefreshDatabase` hanya me-refresh koneksi default (database LOKASI).
 * Tabel pusat ada di database lain, jadi baris yang dibuat test ini tidak
 * ikut ter-rollback dan harus dihapus manual di `tearDown()`.
 */
class CentralLocationStatusTest extends TestCase
{
    use EnsuresCentralTables;
    use RefreshDatabase;

    private const CENTRAL = 'central';

    /** Prefix unik: sisa run sebelumnya tidak boleh bentrok. */
    private string $prefix;

    private User $superadmin;

    /** @var array<int, int> id lokasi yang dibuat test ini */
    private array $tenantIds = [];

    /** @var array<int, string> nama database yang dibuat test ini */
    private array $databaseDibuat = [];

    /**
     * Aksi audit yang dibuat test ini.
     *
     * Pembersihan dibatasi ke daftar ini, bukan `action LIKE 'tenant.%'`.
     * Test lain di repo ini memakai pola yang lebih longgar, tapi
     * `audit_logs` adalah jejak append-only -- menyapu semua baris
     * `tenant.*` berarti menghapus bukti yang bukan milik test ini.
     */
    private array $auditActions = [
        'tenant.activate',
        'tenant.force_delete',
        'tenant.deactivate',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $central = DB::connection(self::CENTRAL);

        $this->prefix = 'statustest-'.uniqid().'-';

        $this->runCentralMigrations(self::CENTRAL);

        // Sisa run sebelumnya. Baris-baris ini tidak ikut ter-rollback.
        $central->table('tenant_domains')->delete();
        $central->table('tenants')->where('slug', 'like', 'statustest-%')->delete();
        $central->table('users')->where('email', 'like', 'statustest-%@test.local')->delete();
        $central->table('audit_logs')->whereIn('action', $this->auditActions)->delete();

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
        // memori dan hanya diberi id -- pola yang sama dipakai test lain.
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
        try {
            $central = DB::connection(self::CENTRAL);

            foreach ($this->tenantIds as $id) {
                $central->table('tenant_domains')->where('tenant_id', $id)->delete();
                $central->table('tenants')->where('id', $id)->delete();
            }

            /*
             * Database yang dibuat test ini harus ikut dibuang.
             *
             * `forceDestroy()` sudah menghapus sebagian dari mereka, jadi
             * `IF EXISTS` dipakai -- dan nama-nama ini semuanya berawalan
             * prefix test, jadi tidak mungkin menabrak database milik orang.
             */
            foreach ($this->databaseDibuat as $nama) {
                $central->statement("DROP DATABASE IF EXISTS `{$nama}`");
            }

            $central->table('users')->where('email', 'like', 'statustest-%@test.local')->delete();
            $central->table('audit_logs')->whereIn('action', $this->auditActions)->delete();
        } catch (Throwable $e) {
            report($e);
        }

        parent::tearDown();
    }

    /* ================================================================
     | AKTIVASI
     |============================================================
     */

    /**
     * Lokasi yang dinonaktifkan bisa diaktifkan kembali.
     *
     * Ini inti bug yang diperbaiki. Sebelumnya tidak ada endpoint yang bisa
     * menulis `is_active = true` selain form ubah -- dan tombol
     * "Aktifkan kembali" di daftar dan di halaman detail memanggil
     * `destroy()`, yang hanya bisa mematikan.
     */
    #[Test]
    public function lokasi_nonaktif_bisa_aktifkan_kembali(): void
    {
        $lokasi = $this->makeTenant('aktifkan');
        $lokasi->update(['is_active' => false]);

        $this->actingAsSuperadmin();

        $response = $this->patchJson("/api/central/locations/{$lokasi->id}/activate");

        $response->assertOk();

        $this->assertTrue(
            $lokasi->fresh()->is_active,
            'Lokasi harus aktif kembali setelah endpoint activate dipanggil.',
        );
    }

    /**
     * Endpoint activate harus logged.
     *
     * Kalau tidak, "siapa yang menghidupkan lagi lokasi ini?" tidak punya
     * jawaban -- dan itu pertanyaan yang paling sering muncul setelah
     * lokasi dinonaktifkan karena alasan bisnis.
     */
    #[Test]
    public function aktivasi_tercatat_di_jejak_audit(): void
    {
        $lokasi = $this->makeTenant('audit');
        $lokasi->update(['is_active' => false]);

        $this->actingAsSuperadmin();

        $this->patchJson("/api/central/locations/{$lokasi->id}/activate")->assertOk();

        $ada = DB::connection(self::CENTRAL)
            ->table('audit_logs')
            ->where('action', 'tenant.activate')
            ->where('subject_id', $lokasi->id)
            ->exists();

        $this->assertTrue($ada, 'Aktivasi harus tercatat di audit_logs.');
    }

    /**
     * Mengaktifkan lokasi yang sudah aktif tidak membuat masalah.
     *
     * Idempoten, sama seperti `destroy()`: aktivasi kedua tidak menghasilkan
     * log baru dan tidak mengubah apa pun.
     */
    #[Test]
    public function aktivasi_lokasi_yang_sudah_aktif_adalah_noop(): void
    {
        $lokasi = $this->makeTenant('noop');

        $this->actingAsSuperadmin();

        $this->patchJson("/api/central/locations/{$lokasi->id}/activate")->assertOk();

        $this->assertTrue($lokasi->fresh()->is_active);
    }

    /**
     * Lokasi tanpa database tidak boleh diaktifkan.
     *
     * Mengaktifkannya hanya memindahkan masalah: dari "tidak aktif" jadi
     * "aktif tapi setiap request gagal". Backend menolak lebih awal, dengan
     * pesan yang menyebut apa yang kurang.
     */
    #[Test]
    public function lokasi_tanpa_database_tidak_bisa_aktifkan(): void
    {
        // Database-nya SENGAJA tidak dibuat: inilah keadaan yang harus
        // ditolak.
        $lokasi = $this->makeTenant('nodatabase', buatDatabase: false);
        $lokasi->update(['is_active' => false]);

        $this->actingAsSuperadmin();

        $response = $this->patchJson("/api/central/locations/{$lokasi->id}/activate");

        $response->assertStatus(422);

        $this->assertFalse(
            $lokasi->fresh()->is_active,
            'Lokasi tanpa database tidak boleh jadi aktif.',
        );
    }

    /**
     * Lokasi tanpa domain juga tidak boleh diaktifkan.
     *
     * Tanpa domain, tidak ada satu pun request yang bisa sampai ke lokasi
     * itu -- jadi status "aktif" hanya akan tampil di panel tanpa efek apa
     * pun di aplikasi.
     */
    #[Test]
    public function lokasi_tanpa_domain_tidak_bisa_aktifkan(): void
    {
        // Tanpa domain di KEDUA sumber: tidak ada baris di `tenant_domains`
        // dan kolom legacy `domain` juga kosong.
        $lokasi = $this->makeTenant('nodomain', punyaDomain: false);
        $lokasi->update(['is_active' => false]);

        $this->actingAsSuperadmin();

        $response = $this->patchJson("/api/central/locations/{$lokasi->id}/activate");

        $response->assertStatus(422);

        $this->assertFalse(
            $lokasi->fresh()->is_active,
            'Lokasi tanpa domain tidak boleh jadi aktif.',
        );
    }

    /**
     * Domain yang dimatikan di halaman Domain tidak dihitung sebagai domain.
     *
     * Kalau dihitung, lokasi bisa diaktifkan padahal tidak ada satu pun host
     * yang menunjuk ke situ -- persis keadaan yang harus dicegah.
     */
    #[Test]
    public function domain_yang_dimatikan_tidak_memenuhi_syarat_aktivasi(): void
    {
        // Domain legacy-nya dikosongkan, dan satu-satunya domain lokasi ini
        // adalah baris di `tenant_domains` yang dimatikan.
        $lokasi = $this->makeTenant('domainmati', punyaDomain: false);
        $domain = $this->makeDomain($lokasi->id, 'mati.test');
        $domain->update(['is_active' => false]);

        $lokasi->update(['is_active' => false]);

        $this->actingAsSuperadmin();

        $this->patchJson("/api/central/locations/{$lokasi->id}/activate")->assertStatus(422);

        $this->assertFalse($lokasi->fresh()->is_active);
    }

    /**
     * Endpoint activate hanya boleh untuk superadmin.
     *
     * Panel pusat menyimpan data lintas lokasi, jadi ini bukan endpoint yang
     * bisa dibiarkan terbuka untuk user biasa.
     */
    #[Test]
    public function endpoint_activate_menolak_bukan_superadmin(): void
    {
        $lokasi = $this->makeTenant('bukan_superadmin');
        $lokasi->update(['is_active' => false]);

        Sanctum::actingAs(
            User::create([
                'name' => 'User Lokasi',
                'email' => $this->prefix.'user@test.local',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]),
            ['*'],
            'sanctum',
        );

        $this->patchJson("/api/central/locations/{$lokasi->id}/activate")->assertForbidden();

        $this->assertFalse($lokasi->fresh()->is_active);
    }

    /* ================================================================
     | HAPUS PERMANEN
     |============================================================
     */

    /**
     * Hapus permanen membuang baris registry DAN database lokasinya.
     */
    #[Test]
    public function hapus_permanen_membuang_registry_dan_database(): void
    {
        $lokasi = $this->makeTenant('hapus');
        $namaDb = $lokasi->database;

        $this->buatDatabase($namaDb);

        $this->actingAsSuperadmin();

        $response = $this->deleteJson("/api/central/locations/{$lokasi->id}/force-delete", [
            'slug' => $lokasi->slug,
        ]);

        $response->assertOk();

        $this->assertNull(
            Tenant::query()->find($lokasi->id),
            'Baris registry lokasi harus hilang setelah dihapus permanen.',
        );

        $this->assertFalse(
            $this->databaseAda($namaDb),
            "Database `{$namaDb}` harus ikut terhapus.",
        );
    }

    /**
     * Slug yang diketik harus persis sama dengan slug lokasi.
     *
     * Ini yang membedakan "hapus permanen" dari "klik tidak sengaja":
     * tanpa konfirmasi slug, satu klik yang salah menghapus seluruh data
     * lokasi dan tidak ada cara memulihkannya.
     */
    #[Test]
    public function hapus_permanen_menolak_slug_yang_salah(): void
    {
        $lokasi = $this->makeTenant('salahslug');
        $namaDb = $lokasi->database;

        $this->buatDatabase($namaDb);

        $this->actingAsSuperadmin();

        $response = $this->deleteJson("/api/central/locations/{$lokasi->id}/force-delete", [
            'slug' => 'slug-yang-salah',
        ]);

        $response->assertStatus(422);

        $this->assertNotNull(
            Tenant::query()->find($lokasi->id),
            'Lokasi tidak boleh terhapus kalau slug yang diketik salah.',
        );

        $this->assertTrue(
            $this->databaseAda($namaDb),
            "Database `{$namaDb}` tidak boleh terhapus kalau slug yang diketik salah.",
        );
    }

    /**
     * Tanpa slug sama sekali, permintaan ditolak.
     */
    #[Test]
    public function hapus_permanen_menolak_tanpa_slug(): void
    {
        $lokasi = $this->makeTenant('tanpaslug');

        $this->actingAsSuperadmin();

        $this->deleteJson("/api/central/locations/{$lokasi->id}/force-delete")->assertStatus(422);

        $this->assertNotNull(Tenant::query()->find($lokasi->id));
    }

    /**
     * Penghapusan permanen tercatat di jejak audit.
     *
     * Jejak ini harus bertahan SETELAH tenant-nya hilang -- itu gunanya
     * mencatat `--force`: objek yang dihapus tidak ada lagi, tapi
     * kesaksian bahwa ia pernah ada harus tetap ada.
     */
    #[Test]
    public function hapus_permanen_tercatat_di_jejak_audit(): void
    {
        $lokasi = $this->makeTenant('audit_hapus');
        $namaDb = $lokasi->database;

        $this->buatDatabase($namaDb);

        $this->actingAsSuperadmin();

        $this->deleteJson("/api/central/locations/{$lokasi->id}/force-delete", [
            'slug' => $lokasi->slug,
        ])->assertOk();

        $ada = DB::connection(self::CENTRAL)
            ->table('audit_logs')
            ->where('action', 'tenant.force_delete')
            ->where('subject_id', $lokasi->id)
            ->exists();

        $this->assertTrue($ada, 'Penghapusan permanen harus tercatat di audit_logs.');
    }

    /**
     * Hapus permanen hanya boleh untuk superadmin.
     */
    #[Test]
    public function hapus_permanen_menolak_bukan_superadmin(): void
    {
        $lokasi = $this->makeTenant('hapus_bukan_superadmin');
        $namaDb = $lokasi->database;

        $this->buatDatabase($namaDb);

        Sanctum::actingAs(
            User::create([
                'name' => 'User Lokasi',
                'email' => $this->prefix.'user2@test.local',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]),
            ['*'],
            'sanctum',
        );

        $this->deleteJson("/api/central/locations/{$lokasi->id}/force-delete", [
            'slug' => $lokasi->slug,
        ])->assertForbidden();

        $this->assertNotNull(Tenant::query()->find($lokasi->id));
        $this->assertTrue($this->databaseAda($namaDb));
    }

    /**
     * DELETE biasa TIDAK boleh menghapus database.
     *
     * Perbedaan ini adalah inti dari dua endpoint: `destroy()` mematikan
     * lokasi tanpa menyentuh data, `forceDestroy()` menghapus semuanya.
     * Kalau `destroy()` ikut menghapus database, "Nonaktifkan" akan berarti
     * "hapus" -- persis hal yang tidak boleh terjadi.
     */
    #[Test]
    public function nonaktifkan_tidak_menghapus_database(): void
    {
        $lokasi = $this->makeTenant('matikan');
        $namaDb = $lokasi->database;

        $this->buatDatabase($namaDb);

        $this->actingAsSuperadmin();

        $this->deleteJson("/api/central/locations/{$lokasi->id}")->assertOk();

        $this->assertFalse($lokasi->fresh()->is_active);
        $this->assertTrue(
            $this->databaseAda($namaDb),
            "Database `{$namaDb}` harus tetap ada setelah lokasi dinonaktifkan.",
        );
    }

    /* ================================================================
     | BANTUAN
     |============================================================
     */

    private function actingAsSuperadmin(): void
    {
        Sanctum::actingAs($this->superadmin, ['*'], 'sanctum');
    }

    /**
     * Buat satu lokasi palsu di registry pusat, beserta database kosongnya.
     *
     * Dua hal bisa dimatikan lewat parameter, karena keduanya adalah syarat
     * aktivasi dan masing-masing punya testnya sendiri:
     *
     *   - `$buatDatabase: false` -- lokasi tanpa database. `activate()`
     *     menolak lokasi seperti ini, dan itu perilaku yang benar.
     *   - `$punyaDomain: false` -- lokasi tanpa domain. Tanpa domain tidak
     *     ada satu pun request yang bisa sampai ke lokasi, jadi status
     *     "aktif" hanya akan tampil di panel tanpa efek apa pun.
     *
     * Yang tidak dimatikan, lokasi punya domain legacy di kolom `domain`.
     * Ini penting: `activate()` membaca DUA sumber domain -- baris di
     * `tenant_domains` dan kolom legacy -- jadi test yang sengaja
     * menguji "tidak punya domain" harus benar-benar tidak punya di
     * keduanya. Kalau hanya `tenant_domains` yang dikosongkan, lokasi
     * tetap punya domain lewat kolom legacy dan penolakannya tidak akan
     * terjadi.
     *
     * Namanya memakai prefix test, jadi tidak akan pernah menabrak database
     * sungguhan.
     */
    private function makeTenant(
        string $slug,
        bool $buatDatabase = true,
        bool $punyaDomain = true,
    ): Tenant {
        $slugPenuh = $this->prefix.$slug;
        $namaDb = 'astaloka_'.str_replace('-', '_', $slugPenuh);

        if ($buatDatabase) {
            $this->buatDatabase($namaDb);
            $this->databaseDibuat[] = $namaDb;
        }

        $id = DB::connection(self::CENTRAL)->table('tenants')->insertGetId([
            'slug' => $slugPenuh,
            'nama' => ucfirst($slug),
            'database' => $namaDb,
            'domain' => $punyaDomain ? $slug.'.test' : null,
            'domain_alternatif' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->tenantIds[] = $id;

        return Tenant::query()->findOrFail($id);
    }

    private function makeDomain(int $tenantId, string $host): TenantDomain
    {
        return TenantDomain::query()->create([
            'tenant_id' => $tenantId,
            'host' => $host,
            'is_wildcard' => false,
            'is_primary' => false,
            'is_active' => true,
        ]);
    }

    /** Buat database kosong, supaya `databaseExists()` mengibaratkan benar. */
    private function buatDatabase(string $nama): void
    {
        DB::connection(self::CENTRAL)->statement(
            "CREATE DATABASE IF NOT EXISTS `{$nama}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    private function databaseAda(string $nama): bool
    {
        return DB::connection(self::CENTRAL)->select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$nama],
        ) !== [];
    }
}
