<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pengaturan "kamera meteran dibuka otomatis saat popup input pemakaian".
 *
 * Yang diuji di sini BUKAN "formnya menyimpan" -- itu soal satu baris
 * `update()`. Yang diuji adalah empat hal yang kalau rusak tidak pernah
 * muncul sebagai error, dan muncul sebagai perilaku yang salah:
 *
 *   1. Bawaannya: setting yang belum pernah diisi berarti kamera TIDAK
 *      dibuka otomatis. Popup input meter yang otomatis menyalakan kamera
 *      bikin teknisi menunggu kamera siap untuk data yang sudah dibacanya
 *      secara manual.
 *   2. Endpoint bacanya terbuka untuk TEKNISI, bukan cuma admin. Popup
 *      input meter dipakai teknisi, dan `GET /settings/sop` itu `role:admin`
 *      -- jadi kalau flag ini hanya hidup di sana, teknisi selalu kena 403
 *      dan gejalanya bukan "403", melainkan kamera yang diam-diam tidak
 *      pernah terbuka.
 *   3. Menulisnya tetap khusus admin.
 *   4. Bayaran/null tidak bisa dipalsukan lewat payload bebas.
 *
 * CATATAN SOAL `NULL` vs `false`
 * ------------------------------
 *
 * Kolomnya nullable, dan itu bukan kemalasan. `NULL` berarti "belum pernah
 * diisi" (pakai perilaku bawaan), `false` berarti "admin sengaja
 * mematikan". Kalau keduanya collapsed jadi satu, maka lokasi yang
 * pengaturannya baru dibuka sekali lalu ditutup lagi akan terlihat sama
 * dengan lokasi yang tidak pernah menyentuhnya -- dan gejalanya bukan "beda
 * sedikit", tapi "kamera menyala sendiri untuk admin yang sudah jelas
 * mematikannya".
 */
class PemakaianAirCameraSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private User $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Kamera',
            'email' => 'admin-kamera@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $this->teknisi = User::create([
            'name' => 'Teknisi Kamera',
            'email' => 'teknisi-kamera@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'teknisi',
        ]);

        $this->pelanggan = User::create([
            'name' => 'Pelanggan Kamera',
            'email' => 'pelanggan-kamera@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'pelanggan',
        ]);
    }

    /**
     * Setting-nya belum pernah diisi -> kamera TIDAK dibuka otomatis.
     *
     * Ini bawaan yang sudah berlaku sejak popup input meter ada, dan
     * migrasi kolom baru ini tidak boleh mengubahnya untuk lokasi yang
     * sudah ada.
     */
    #[Test]
    public function setting_yang_belum_diisi_berarti_kamera_tidak_otomatis(): void
    {
        Sanctum::actingAs($this->teknisi, ['*'], 'sanctum');

        $res = $this->getJson('/api/settings/pemakaian-air')->assertOk();

        $this->assertTrue($res->json('success'));
        $this->assertFalse(
            $res->json('data.autoOpenMeterCamera'),
            'Setting yang belum pernah diisi harus berarti kamera TIDAK dibuka otomatis.'
        );
    }

    /**
     * Admin menyimpan `false` -> tersimpan sebagai `false` (bukan `null`).
     */
    #[Test]
    public function admin_bisa_mematikan_kamera_otomatis(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $this->postJson('/api/settings/sop/pemakaian-air', [
            'autoOpenMeterCamera' => false,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertFalse((bool) Setting::first()->auto_open_meter_camera);

        // Dibaca lewat endpoint yang dipakai frontend.
        $res = $this->getJson('/api/settings/pemakaian-air')->assertOk();
        $this->assertFalse($res->json('data.autoOpenMeterCamera'));
    }

    /**
     * Admin menyalakan -> tersimpan dan terbaca `true`.
     */
    #[Test]
    public function admin_bisa_menyalakan_kamera_otomatis(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $this->postJson('/api/settings/sop/pemakaian-air', [
            'autoOpenMeterCamera' => true,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertTrue((bool) Setting::first()->auto_open_meter_camera);

        $res = $this->getJson('/api/settings/pemakaian-air')->assertOk();
        $this->assertTrue($res->json('data.autoOpenMeterCamera'));
    }

    /**
     * Menonaktifkan lagi setelah pernah menyalakan -> tetap `false`.
     *
     * Ini yang tidak bisa dijamin kalau nilainya di-collapse jadi satu:
     * "pernah nyala" dan "tidak pernah nyala" akan terlihat sama.
     */
    #[Test]
    public function setting_bisa_dimatikan_setelah_pernah_menyala(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $this->postJson('/api/settings/sop/pemakaian-air', ['autoOpenMeterCamera' => true])
            ->assertOk();

        $this->postJson('/api/settings/sop/pemakaian-air', ['autoOpenMeterCamera' => false])
            ->assertOk();

        $this->assertFalse((bool) Setting::first()->auto_open_meter_camera);
    }

    /**
     * TEKNISI boleh membaca setting-nya.
     *
     * Endpoint ini yang dipakai popup input meter, dan popup itu dipakai
     * teknisi. Kalau hanya admin yang bisa membacanya, teknisi selalu kena
     * 403 dan kameranya tidak pernah terbuka untuk mereka.
     */
    #[Test]
    public function teknisi_boleh_membaca_setting_pemakaian_air(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $this->postJson('/api/settings/sop/pemakaian-air', ['autoOpenMeterCamera' => true])
            ->assertOk();

        Sanctum::actingAs($this->teknisi, ['*'], 'sanctum');

        $res = $this->getJson('/api/settings/pemakaian-air')->assertOk();

        $this->assertTrue(
            $res->json('data.autoOpenMeterCamera'),
            'Teknisi harus melihat nilai yang sama dengan yang disimpan admin.'
        );
    }

    /**
     * TEKNISI tidak boleh MENYIMPUL setting-nya.
     */
    #[Test]
    public function teknisi_tidak_bisa_menyimpan_setting_pemakaian_air(): void
    {
        Sanctum::actingAs($this->teknisi, ['*'], 'sanctum');

        $this->postJson('/api/settings/sop/pemakaian-air', ['autoOpenMeterCamera' => true])
            ->assertStatus(403);

        $this->assertNotTrue((bool) Setting::first()?->auto_open_meter_camera);
    }

    /**
     * Pelanggan juga tidak boleh menyimpannya.
     */
    #[Test]
    public function pelanggan_tidak_bisa_menyimpan_setting_pemakaian_air(): void
    {
        Sanctum::actingAs($this->pelanggan, ['*'], 'sanctum');

        $this->postJson('/api/settings/sop/pemakaian-air', ['autoOpenMeterCamera' => true])
            ->assertStatus(403);
    }

    /**
     * Tanpa login, endpoint ini tidak boleh terbuka.
     */
    #[Test]
    public function tanpa_login_ditolak(): void
    {
        $this->getJson('/api/settings/pemakaian-air')->assertStatus(401);
        $this->postJson('/api/settings/sop/pemakaian-air', ['autoOpenMeterCamera' => true])
            ->assertStatus(401);
    }

    /**
     * Payload yang memaksa flag harus dianggap tidak valid.
     *
     * Kolomnya boolean: tidak ada keadaan ketiga. Kalau ada string bebas,
     * `autoOpenMeterCamera` bisa tersimpan sebagai apa saja dan frontend
     * membacanya lewat `Boolean(...)` -- yang selalu non-nol berarti `true`.
     */
    #[Test]
    public function payload_bukan_boolean_ditolak(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $this->postJson('/api/settings/sop/pemakaian-air', [
            'autoOpenMeterCamera' => 'mungkin',
        ])->assertStatus(422);

        // Flag yang hilang juga ditolak -- kalau tidak, "tidak sengaja
        // mengirim" akan diam-diam berarti "matikan".
        $this->postJson('/api/settings/sop/pemakaian-air', [])
            ->assertStatus(422);
    }

    /**
     * `GET /settings/sop` (khusus admin) ikut membawa section ini.
     *
     * Admin mengisi formnya dari payload itu, jadi kalau section-nya lupa
     * ditambah, formnya akan selalu tampil dalam keadaan bawaan dan
     * "Simpan" akan menimpa pilihan admin dengan nilai bawaan.
     */
    #[Test]
    public function payload_sop_admin_ikut_membawa_section_pemakaian_air(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $this->postJson('/api/settings/sop/pemakaian-air', ['autoOpenMeterCamera' => true])
            ->assertOk();

        $res = $this->getJson('/api/settings/sop')->assertOk();

        $this->assertTrue(
            $res->json('data.pemakaianAir.autoOpenMeterCamera'),
            'Section `pemakaianAir` harus ikut di payload SOP admin.'
        );
    }
}
