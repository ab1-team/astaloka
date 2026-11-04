<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use App\Models\Central\TenantDomain;
use App\Services\AuditLogger;
use App\Services\DomainNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panel pusat: kelola domain custom tiap lokasi.
 *
 * TUJUANNYA SATU: memastikan request yang datang di `pamsides.test` atau
 * `demo.astaloka.test` sampai ke database lokasi yang benar. Setiap endpoint di
 * sini ada untuk satu langkah di antara admin menekan tombol dan host itu
 * benar-benar dilayani.
 *
 * Yang SENGAJA tidak ada di sini:
 *
 *   - Provisioning DNS. Mendaftarkan `demo.astaloka.test` tidak membuat record
 *     A di DNS -- itu di luar kendali aplikasi. Yang bisa dilakukan aplikasi
 *     hanya menyimpan CENDAHARA dan menampilkannya kembali, supaya admin tahu
 *     persis record apa yang harus dibuat.
 *   - SSL otomatis. Butuh Caddy/CA dan mengubah deployment.
 *
 * Keduanya batas yang disengaja, bukan kekurangan sementara.
 *
 * Akses dijaga middleware `superadmin`, yang juga mengembalikan koneksi default
 * ke database pusat -- jadi `Tenant::query()` di sini selalu aman.
 */
class TenantDomainController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Daftar seluruh domain custom lintas lokasi.
     *
     * Halaman ini menjawab satu pertanyaan: "domain apa saja yang sudah
     * dipakai, dan oleh lokasi mana?". Karena itu `tenant` di-`with()`, bukan
     * diambil satu per satu -- tanpa itu jumlah query bertambah satu per baris.
     *
     * @queryParam search      Cari di host atau nama lokasi
     * @queryParam tenant_id   Filter satu lokasi
     * @queryParam status      active | inactive | all
     */
    public function index(Request $request): JsonResponse
    {
        $q = TenantDomain::query()
            ->with('tenant')
            ->orderByDesc('is_primary')
            ->orderBy('tenant_id')
            ->orderBy('host');

        if ($search = trim((string) $request->query('search', ''))) {
            // Pencarian menyeber ke nama lokasi, bukan cuma host: admin sering
            // ingat "domain Pamsides" tanpa ingat host-nya persis.
            $q->where(function ($w) use ($search) {
                $w->where('host', 'like', "%{$search}%")
                    ->orWhereHas('tenant', fn ($t) => $t->where('nama', 'like', "%{$search}%"));
            });
        }

        if ($tenantId = $request->query('tenant_id')) {
            $q->where('tenant_id', (int) $tenantId);
        }

        match ($request->query('status', 'all')) {
            'active' => $q->where('is_active', true),
            'inactive' => $q->where('is_active', false),
            default => null,
        };

        $domains = $q->get()->map(fn (TenantDomain $d) => $this->present($d));

        return response()->json([
            'success' => true,
            'data' => $domains,
            'meta' => [
                'total' => $domains->count(),
                'aktif' => $domains->where('is_active', true)->count(),
                'wildcard' => $domains->where('is_wildcard', true)->count(),
            ],
        ]);
    }

    /**
     * Domain milik satu lokasi.
     *
     * Mengembalikan `legacy` berisi isi kolom `domain`/`domain_alternatif`.
     * Kolom itu tidak dihapus dari database dan masih dibaca resolver sebagai
     * cadangan, jadi panel HARUS menampilkannya -- kalau disembunyikan, admin
     * tidak akan tahu kenapa domain lamanya masih jalan padahal tidak ada di
     * daftar.
     */
    public function indexForTenant(Tenant $location): JsonResponse
    {
        $location->load(['domains' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('host')]);

        return response()->json([
            'success' => true,
            'data' => [
                'domains' => $location->domains->map(fn (TenantDomain $d) => $this->present($d)),
                'legacy' => [
                    'domain' => $location->domain,
                    'domain_alternatif' => $location->domain_alternatif,
                ],
            ],
        ]);
    }

    /**
     * Tambah satu domain untuk satu lokasi.
     *
     * `is_primary` pertama yang diaktifkan otomatis menjadi utama. Tanpa itu,
     * lokasi yang baru didaftarkan tidak punya domain utama sama sekali --
     * `settings.domain` di database lokasinya tetap kosong, dan kop laporan
     * yang mencantumkan domain perusahaan keluar dengan kolom kosong.
     */
    public function store(Request $request, Tenant $location): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:191'],
            'is_wildcard' => ['nullable', 'boolean'],
        ]);

        // Diterima `is_wildcard` dari klien, tapi dihitung ulang dari host-nya.
        // Dua sumber kebenaran untuk satu fakta berarti form yang berbeda bisa
        // menyimpan domain yang sama dengan status wildcard berbeda -- dan
        // wildcard adalah penentu apakah domain itu menangkap subdomain lain.
        [$host, $wildcard, $error] = DomainNormalizer::cleanWildcard($data['host']);

        if ($error !== null) {
            return response()->json([
                'success' => false,
                'message' => $error,
                'errors' => ['host' => [$error]],
            ], 422);
        }

        [$boleh, $pesan] = DomainNormalizer::ensureAvailable($host, $wildcard, $location->id);

        if (! $boleh) {
            return response()->json([
                'success' => false,
                'message' => $pesan,
                'errors' => ['host' => [$pesan]],
            ], 422);
        }

        $adaUtama = $location->domains()
            ->where('is_active', true)
            ->where('is_primary', true)
            ->where('is_wildcard', false)
            ->exists();

        $domain = TenantDomain::create([
            'tenant_id' => $location->id,
            'host' => $host,
            'is_wildcard' => $wildcard,
            /*
             * Domain persis pertama yang aktif otomatis menjadi utama.
             * Wildcard tidak pernah dijadikan utama: ia tidak punya host yang
             * bisa dibuka atau dicetak, dan `syncPrimary()` juga melewatinya.
             */
            'is_primary' => ! $wildcard && ! $adaUtama,
            'is_active' => true,
        ]);

        DomainNormalizer::syncPrimary($location->fresh());

        $this->audit->record(
            'tenant.domain_create',
            tenant: $location,
            subjectType: TenantDomain::class,
            subjectId: $domain->id,
            description: "Domain `{$host}` didaftarkan untuk lokasi `{$location->slug}`.",
            properties: [
                'host' => $host,
                'is_wildcard' => $wildcard,
                'slug' => $location->slug,
            ],
        );

        return response()->json([
            'success' => true,
            'message' => "Domain `{$host}` ditambahkan.",
            'data' => $this->present($domain->fresh()),
        ], 201);
    }

    /** Ubah status aktif, atau jadikan domain utama. */
    public function update(Request $request, TenantDomain $domain): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        /*
         * Domain utama yang DIMATIKAN tidak boleh tetap utama -- kalau iya,
         * `settings.domain` di database lokasi menunjuk ke domain yang sudah
         * tidak dilayani, dan laporan menghasilkan alamat yang tidak bisa
         * dibuka. `syncPrimary()` di bawah menunjukkannya ke domain aktif
         * yang lain, atau melepas kolom legacy kalau tidak ada satupun.
         *
         * `is_primary` harus ikut dikosongkan di baris ini: tanpa itu domain
         * nonaktif tetap ditandai "Utama" di panel, dan saat baris yang sama
         * diaktifkan lagi ia langsung jadi utama tanpa pernah dipilih admin.
         */
        if (array_key_exists('is_active', $data) && $data['is_active'] === false) {
            $data['is_primary'] = false;
        }

        $sebelum = $domain->only(array_keys($data));

        if (array_key_exists('is_primary', $data) && $data['is_primary'] === true) {
            $this->clearPrimaryExcept($domain->tenant_id, $domain->id);
        }

        $domain->update($data);

        DomainNormalizer::syncPrimary($domain->tenant);

        $perubahan = AuditLogger::diff($sebelum, $domain->only(array_keys($data)));

        if ($perubahan !== []) {
            $this->audit->record(
                'tenant.domain_update',
                tenant: $domain->tenant,
                subjectType: TenantDomain::class,
                subjectId: $domain->id,
                description: "Domain `{$domain->host}` diperbarui ("
                    .implode(', ', array_keys($perubahan)).').',
                properties: ['host' => $domain->host, 'changes' => $perubahan],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Domain diperbarui.',
            'data' => $this->present($domain->fresh()),
        ]);
    }

    /**
     * Hapus domain.
     *
     * Penghapusan di sini disengaja, bukan soft delete: baris yang tersisa
     * dengan `is_active = false` menahan index unik `host` selamanya --
     * owner domain itu tidak akan pernah bisa mendaftarkannya kembali,
     * karena ada baris miliknya sendiri yang masih memegang nama itu.
     *
     * Kolom legacy `tenants.domain`/`domain_alternatif` tidak ikut terhapus.
     * Kalau domain yang dihapus ada di sana, resolver masih akan mencarinya
     * lewat fallback, dan panel akan menampilkannya sebagai "domain lama" --
     * jadi menghapusnya dari daftar baru bukan berarti benar-benar berhenti
     * dilayani. Menghapus sebagian terasa lebih memuaskan, tapi memindahkan
     * isi antara dua sumber kebenaran itu secara diam-diam lebih berbahaya:
     * perubahan yang tidak diminta bisa sampai ke `settings.domain`.
     */
    public function destroy(TenantDomain $domain): JsonResponse
    {
        $tenant = $domain->tenant;
        $host = $domain->host;

        $domain->delete();

        DomainNormalizer::syncPrimary($tenant->fresh());

        $this->audit->record(
            'tenant.domain_delete',
            tenant: $tenant,
            subjectType: TenantDomain::class,
            subjectId: $domain->id,
            description: "Domain `{$host}` dihapus dari lokasi `{$tenant->slug}`.",
            properties: ['host' => $host, 'slug' => $tenant->slug],
        );

        return response()->json([
            'success' => true,
            'message' => "Domain `{$host}` dihapus.",
        ]);
    }

    /**
     * Pastikan hanya satu domain utama per lokasi.
     *
     * is_primary = true bisa ada lebih dari satu kalau dua admin menekan tombol
     * hampir bersamaan. Penegakan ini menutupnya di sisi aplikasi; index unik
     * di `tenant_domains` sendiri tidak bisa dipakai untuk ini karena tujuannya
     * bukan keunikan host.
     */
    private function clearPrimaryExcept(int $tenantId, ?int $keepId = null): void
    {
        TenantDomain::query()
            ->where('tenant_id', $tenantId)
            ->where('is_primary', true)
            ->when($keepId !== null, fn ($q) => $q->where('id', '!=', $keepId))
            ->update(['is_primary' => false]);
    }

    private function present(TenantDomain $d): array
    {
        return [
            'id' => $d->id,
            'tenant_id' => $d->tenant_id,
            'tenant_slug' => $d->tenant?->slug,
            'tenant_label' => $d->tenant?->label(),
            'tenant_is_active' => $d->tenant?->is_active,
            'host' => $d->displayHost(),
            'is_wildcard' => $d->is_wildcard,
            'is_primary' => $d->is_primary,
            'is_active' => $d->is_active,
            'created_at' => $d->created_at?->toDateTimeString(),
        ];
    }
}
