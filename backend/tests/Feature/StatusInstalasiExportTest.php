<?php

namespace Tests\Feature;

use App\Models\InstallationPackage;
use App\Models\InstallationTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Endpoint ekspor Excel daftar status instalasi.
 *
 * Tombol "Export Excel" di `/app/instalasi/status` sebelumnya tidak
 * memanggil endpoint apa pun. Test ini mengunci kontrak endpoint barunya:
 *
 *   - hanya admin yang boleh mengunduh
 *   - kategori memfilter status (dan TIDAK menyertakan `draft`)
 *   - pencarian memfilter nama / alamat / kode instalasi
 *   - file yang dihasilkan benar-benar .xlsx yang bisa dibaca
 *   - route-nya terdaftar SEBELUM wildcard `installation-tickets/{id}`,
 *     supaya tidak tertelan dan jadi 404
 */
class StatusInstalasiExportTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    private function createUser(string $role): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => $role.'@pdam.test',
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    /** Admin dibuat sekali per test -- email-nya unik. */
    private function adminUser(): User
    {
        if ($this->admin === null) {
            $this->admin = $this->createUser('admin');
        }

        return $this->admin;
    }

    private function createPackage(): InstallationPackage
    {
        return InstallationPackage::create([
            'name' => 'Paket Test',
            'installation_fee' => 1_500_000,
            'monthly_abodemen' => 15_000,
            'late_penalty' => 10_000,
        ]);
    }

    private function createTicket(string $status, string $name, string $address = 'Jl. Test No. 1'): InstallationTicket
    {
        return InstallationTicket::create([
            'package_id' => $this->createPackage()->id,
            'created_by' => $this->adminUser()->id,
            'applicant_name' => $name,
            'nik' => '3300000000000001',
            'phone' => '08123456789',
            'address' => $address,
            'order_date' => '2026-01-15',
            'status' => $status,
            'lat' => -7.797068,
            'lng' => 110.370529,
        ]);
    }

    #[Test]
    public function route_export_terdaftar_sebelum_wildcard_tiket(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/installation-tickets/'));

        $uris = $routes->map(fn ($route) => $route->uri())->values()->all();

        $export = array_search('api/installation-tickets/export-status', $uris, true);
        $wildcard = array_search('api/installation-tickets/{installationTicket}', $uris, true);

        $this->assertNotFalse($export, 'route export-status harus terdaftar');
        $this->assertNotFalse($wildcard, 'route wildcard tiket harus terdaftar');
        $this->assertLessThan(
            $wildcard,
            $export,
            'route export-status harus SEBELUM wildcard, kalau tidak path-nya jadi 404',
        );
    }

    #[Test]
    public function admin_mengunduh_file_excel(): void
    {
        Sanctum::actingAs($this->adminUser(), ['*']);

        $this->createTicket('pending', 'Siti Nurhaliza');

        $response = $this->get('/api/installation-tickets/export-status?kategori=permohonan');

        $response->assertStatus(200);
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        $body = $response->getContent();
        $this->assertNotEmpty($body);

        // File harus benar-benar bisa dibaca sebagai workbook.
        $path = tempnam(sys_get_temp_dir(), 'ekspor-status-');
        file_put_contents($path, $body);

        $spreadsheet = IOFactory::createReader('Xlsx')->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Status Instalasi', $sheet->getTitle());
        $this->assertSame('No', $sheet->getCell('A8')->getValue());
        $this->assertSame('Kode Instalasi', $sheet->getCell('B8')->getValue());

        unlink($path);
    }

    #[Test]
    public function kategori_menyaring_status_yang_sesuai(): void
    {
        Sanctum::actingAs($this->adminUser(), ['*']);

        $this->createTicket('pending', 'Budi Permohonan');
        $this->createTicket('completed', 'Budi Aktif');
        $this->createTicket('draft', 'Budi Draft');

        $response = $this->get('/api/installation-tickets/export-status?kategori=permohonan');

        $response->assertStatus(200);

        $path = tempnam(sys_get_temp_dir(), 'ekspor-status-');
        file_put_contents($path, $response->getContent());

        $sheet = IOFactory::createReader('Xlsx')
            ->load($path)
            ->getActiveSheet();

        $this->assertSame(9, $sheet->getHighestRow(), 'hanya satu baris data untuk kategori permohonan');
        $this->assertSame('Budi Permohonan', $sheet->getCell('C9')->getValue());

        unlink($path);
    }

    #[Test]
    public function pencarian_memfilter_nama_dan_alamat(): void
    {
        Sanctum::actingAs($this->adminUser(), ['*']);

        $this->createTicket('pending', 'Siti Nurhaliza', 'Kp. Ngabean No. 45');
        $this->createTicket('pending', 'Budi Santoso', 'Kp. Sukamaju No. 12');

        $response = $this->get('/api/installation-tickets/export-status?kategori=permohonan&search=Siti');

        $response->assertStatus(200);

        $path = tempnam(sys_get_temp_dir(), 'ekspor-status-');
        file_put_contents($path, $response->getContent());

        $sheet = IOFactory::createReader('Xlsx')
            ->load($path)
            ->getActiveSheet();

        $this->assertSame(9, $sheet->getHighestRow());
        $this->assertSame('Siti Nurhaliza', $sheet->getCell('C9')->getValue());

        unlink($path);
    }

    #[Test]
    public function kategori_tidak_dikenal_ditolak(): void
    {
        Sanctum::actingAs($this->adminUser(), ['*']);

        $this->getJson('/api/installation-tickets/export-status?kategori=entah')
            ->assertStatus(422);
    }

    #[Test]
    public function role_bukan_admin_ditolak(): void
    {
        Sanctum::actingAs($this->createUser('teknisi'), ['*']);

        $this->get('/api/installation-tickets/export-status?kategori=permohonan')
            ->assertStatus(403);
    }
}
