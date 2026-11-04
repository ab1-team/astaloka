<?php

namespace Tests\Feature;

use App\Models\InstallationPackage;
use App\Models\InstallationTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Halaman Data Instalasi (`/app/dataInstalasi`) harus menampilkan SEMUA
 * instalasi berstatus `completed`, berapa pun jumlahnya.
 *
 * BUG YANG DIKUNCI
 * =================
 *
 * Frontend dulu meminta `?per_page=200`. Jalur `per_page` dibatasi server
 * dengan `min($perPage, 200)`, jadi hanya 200 baris PERTAMA yang sampai --
 * sisanya hilang tanpa jejak, dan `total` ikut terpotong. Gejalanya:
 * database punya lebih dari 100 instalasi `completed`, tapi layar tidak
 * sampai semua.
 *
 * Test di bawah sengaja memakai angka DI ATAS batas lama (200) supaya
 * batas itu tidak bisa kembali diam-diam.
 */
class DataInstalasiSemuaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Data Instalasi',
            'email' => 'admin-di-'.uniqid().'@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }

    private function package(): InstallationPackage
    {
        return InstallationPackage::create([
            'name' => 'Paket Data Instalasi',
            'installation_fee' => 1_500_000,
            'monthly_abodemen' => 15_000,
            'late_penalty' => 10_000,
        ]);
    }

    /**
     * Seed banyak tiket `completed` sekaligus beberapa status lain.
     *
     * `created_at` diberi detik berbeda supaya urutan `created_at desc`
     * benar-benar bisa diuji.
     */
    private function seedTiket(User $admin, InstallationPackage $paket, int $jumlahCompleted): void
    {
        $rows = [];

        for ($i = 0; $i < $jumlahCompleted; $i++) {
            $rows[] = [
                'package_id' => $paket->id,
                'applicant_name' => 'Pelanggan '.$i,
                'nik' => '33000000000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'address' => 'Jl. Uji No. '.$i,
                'lat' => -7.797068,
                'lng' => 110.370529,
                'status' => 'completed',
                'created_by' => $admin->id,
                'created_at' => now()->subSeconds($jumlahCompleted - $i),
                'updated_at' => now()->subSeconds($jumlahCompleted - $i),
            ];
        }

        // Status lain yang TIDAK boleh ikut tampil di halaman ini.
        foreach (['pending', 'surveyed', 'unpaid', 'processing', 'suspended', 'terminated', 'draft'] as $i => $status) {
            $rows[] = [
                'package_id' => $paket->id,
                'applicant_name' => 'Lain '.$status,
                'nik' => '33999999999'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'address' => 'Jl. Lain No. '.$i,
                'lat' => -7.797068,
                'lng' => 110.370529,
                'status' => $status,
                'created_by' => $admin->id,
                'created_at' => now()->subSeconds(1000 + $i),
                'updated_at' => now()->subSeconds(1000 + $i),
            ];
        }

        InstallationTicket::insert($rows);
    }

    /**
     * `all=1` harus mengembalikan SELURUH tiket `completed` -- lebih dari
     * 200 baris, yaitu angka yang dulu dipotong.
     */
    #[Test]
    public function semua_tiket_completed_dikembalikan_tanpa_batas(): void
    {
        $admin = $this->admin();
        $paket = $this->package();

        $jumlah = 250;
        $this->seedTiket($admin, $paket, $jumlah);

        $totalDb = InstallationTicket::where('status', 'completed')->count();
        $this->assertSame($jumlah, $totalDb, 'seed harus menghasilkan 250 tiket completed');

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/installation-tickets?all=1&status=completed');

        $response->assertStatus(200)->assertJsonPath('success', true);

        $rows = $response->json('data');
        $this->assertIsArray($rows, 'jalur all=1 harus membalas array langsung');

        /*
         * INI inti testnya: 250 tiket completed, tidak boleh ada yang
         * terpotong di 200.
         */
        $this->assertCount(
            $jumlah,
            $rows,
            'semua tiket completed harus dikembalikan, tidak boleh ada yang terpotong'
        );

        // Hanya status yang diminta saja yang ikut.
        $statusTerdapat = array_unique(array_column($rows, 'status'));
        sort($statusTerdapat);
        $this->assertSame(['completed'], $statusTerdapat, 'hanya status completed yang boleh ikut');
    }

    /**
     * Status selain `completed` TIDAK boleh ikut -- kecuali kalau diminta
     * secara eksplisit lewat query string.
     *
     * Halaman ini hanya menampilkan instalasi yang sudah selesai. Kalau
     * `suspended` ikut masuk, tabelnya jadi tidak sesuai dengan filter
     * yang dikehendaki admin.
     */
    #[Test]
    public function status_lain_tidak_ikut_kecuali_diminta(): void
    {
        $admin = $this->admin();
        $paket = $this->package();

        $this->seedTiket($admin, $paket, 5);

        Sanctum::actingAs($admin, ['*']);

        // Hanya completed -> 5 baris (suspended/terminated/pending tidak ikut).
        $hanyaCompleted = $this->getJson('/api/installation-tickets?all=1&status=completed');
        $hanyaCompleted->assertStatus(200);
        $this->assertCount(5, $hanyaCompleted->json('data'));

        // Dieksplisitkan -> suspended & terminated ikut lagi.
        $eksplisit = $this->getJson('/api/installation-tickets?all=1&status=completed,suspended,terminated');
        $eksplisit->assertStatus(200);

        $statusTerdapat = array_unique(array_column($eksplisit->json('data'), 'status'));
        sort($statusTerdapat);
        $this->assertSame(
            ['completed', 'suspended', 'terminated'],
            $statusTerdapat,
            'status yang diminta eksplisit harus ikut'
        );
    }

    /**
     * Filter status harus dipisah koma DAN dipangkas spasinya.
     *
     * Tanpa itu, `status=completed, suspended` menghasilkan `where status =
     * 'completed, suspended'` -- tidak ada baris yang cocok, dan tabel
     * terlihat kosong padahal datanya ada.
     */
    #[Test]
    public function filter_status_berima_daftar_dipisah_koma(): void
    {
        $admin = $this->admin();
        $paket = $this->package();

        $this->seedTiket($admin, $paket, 5);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/installation-tickets?all=1&status=completed,%20suspended,%20terminated');

        $response->assertStatus(200);

        $rows = $response->json('data');
        // 5 completed + 1 suspended + 1 terminated.
        $this->assertCount(7, $rows, 'filter dengan spasi di sekitar koma harus tetap cocok');
    }

    /**
     * `all=1` tidak boleh menarik relasi BERAT.
     *
     * Relasi berat (tagihan bulanan + pembacaan meter + payments) di
     * EACH baris membuat respons membengkak: diukur pada 200 pelanggan
     * dengan 5 tahun riwayat, responsnya 7,83 MB dan 8,3 detik -- cukup
     * untuk membuat PHP kehabisan memory dan membalas 500, yang di layar
     * terlihat sebagai tabel kosong.
     */
    #[Test]
    public function jalur_all_tidak_menarik_relasi_berat(): void
    {
        $admin = $this->admin();
        $paket = $this->package();

        $this->seedTiket($admin, $paket, 3);

        Sanctum::actingAs($admin, ['*']);

        $rows = $this->getJson('/api/installation-tickets?all=1&status=completed')
            ->assertStatus(200)
            ->json('data');

        $this->assertNotEmpty($rows);

        $first = $rows[0];

        // Relasi ringkas yang memang dipakai halaman ini.
        $this->assertArrayHasKey('package', $first);
        $this->assertArrayHasKey('customer', $first);

        // Relasi berat yang TIDAK boleh ikut.
        $this->assertArrayNotHasKey(
            'monthly_bills',
            $first,
            'jalur all=1 tidak boleh menarik tagihan bulanan per baris'
        );
        $this->assertArrayNotHasKey(
            'meter_readings',
            $first,
            'jalur all=1 tidak boleh menarik pembacaan meter per baris'
        );
        $this->assertArrayNotHasKey(
            'payments',
            $first,
            'jalur all=1 tidak boleh menarik pembayaran per baris'
        );
    }

    /**
     * Jalur `per_page` tetap boleh dipotong -- TAPI pemanggil yang butuh
     * semua baris harus memakai `all=1`, bukan `per_page` besar.
     *
     * Test ini mengunci BAWAHAN batasnya, supaya batasnya tidak diubah
     * tanpa sengaja: `per_page=500` tetap dibalas 200 baris, dan
     * `all=1` tetap semua -- bukan 200.
     */
    #[Test]
    public function per_page_terbatas_tapi_all_tidak(): void
    {
        $admin = $this->admin();
        $paket = $this->package();

        $this->seedTiket($admin, $paket, 250);

        Sanctum::actingAs($admin, ['*']);

        $terbatas = $this->getJson('/api/installation-tickets?per_page=500&status=completed');
        $terbatas->assertStatus(200);
        $this->assertCount(
            200,
            $terbatas->json('data.data'),
            'jalur per_page tetap dibatasi 200 baris'
        );
        $this->assertSame(
            250,
            $terbatas->json('data.total'),
            'total harus menyamai jumlah sebenarnya, bukan jumlah yang terpotong'
        );

        $semua = $this->getJson('/api/installation-tickets?all=1&status=completed');
        $semua->assertStatus(200);
        $this->assertCount(
            250,
            $semua->json('data'),
            'jalur all=1 harus mengembalikan seluruh baris'
        );
    }

    /**
     * Urutan harus stabil: `created_at` berpresisi detik, jadi banyak
     * tiket bisa berbagi timestamp. Tanpa tie-breaker `id`, baris yang
     * sama bisa muncul dua kali atau hilang antar-request.
     */
    #[Test]
    public function urutan_stabil_dengan_tie_breaker_id(): void
    {
        $admin = $this->admin();
        $paket = $this->package();

        // Semua ditulis pada detik yang SAMA.
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'package_id' => $paket->id,
                'applicant_name' => 'Serempak '.$i,
                'nik' => '33888888888'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'address' => 'Jl. Serempak No. '.$i,
                'lat' => -7.797068,
                'lng' => 110.370529,
                'status' => 'completed',
                'created_by' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        InstallationTicket::insert($rows);

        Sanctum::actingAs($admin, ['*']);

        $pertama = $this->getJson('/api/installation-tickets?all=1&status=completed')->json('data');
        $kedua = $this->getJson('/api/installation-tickets?all=1&status=completed')->json('data');

        $this->assertSame(
            array_column($pertama, 'id'),
            array_column($kedua, 'id'),
            'urutan harus sama antar-request'
        );
    }
}
