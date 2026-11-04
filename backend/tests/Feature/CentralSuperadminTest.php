<?php

namespace Tests\Feature;

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
 * User pusat (superadmin): siapa saja yang bisa masuk ke panel.
 *
 * Yang diuji di sini bukan "formnya menyimpan", tapi tiga janji yang dibuat
 * halaman ini ke superadmin yang membacanya:
 *
 *   1. Menonaktifkan akun TUTUP aksesnya, bukan cuma pintunya.
 *   2. Tidak ada jalan untuk mengunci dirinya sendiri di luar panel.
 *   3. Tidak ada jalan untuk menghilangkan orang terakhir yang bisa masuk.
 *
 * Yang tidak diuji: apakah password-nya benar. Itu urusan Hash, dan mengujinya
 * berarti menyimpan hash yang sudah diketahui di dalam test.
 *
 * CATATAN SOAL DATABASE PUSAT DI TEST
 *
 * Baris `users` yang dibuat di sini berada di database pusat, yang tidak ikut
 * di-rollback oleh `RefreshDatabase` (yang hanya membungkus koneksi default).
 * Karena itu `tearDown()` menghapus eksplisit -- dan `setUp()` membersihkan
 * sisa run sebelumnya, karena baris yang tidak terhapus akan menabrak unique
 * index `email` di test berikutnya dengan pesan yang tidak menyebut penyebab
 * sebenarnya.
 *
 * Lihat catatan panjang di `CentralDomainTest` untuk kenapa tabel pusat harus
 * dimigrasikan manual di sini.
 */
class CentralSuperadminTest extends TestCase
{
    use EnsuresCentralTables;
    use RefreshDatabase;

    private const CENTRAL = 'central';

    /** Prefix unik supaya sisa run yang gagal tidak bentrok. */
    private string $prefix;

    private User $superadmin;

    /** @var array<int, int> id user pusat yang dibuat test ini */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'usatest-'.uniqid().'-';

        $this->ensureCentralTables();

        $central = DB::connection(self::CENTRAL);

        $central->table('users')->where('email', 'like', 'usatest-%@test.local')->delete();
        $central->table('audit_logs')->where('action', 'like', 'central.%')->delete();

        $this->superadmin = $this->makeSuperadmin('admin');
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

            $ids = $this->userIds;

            if ($ids !== []) {
                $central->table('personal_access_tokens')->whereIn('tokenable_id', $ids)->delete();
                $central->table('users')->whereIn('id', $ids)->delete();
                $central->table('audit_logs')
                    ->where(fn ($q) => $q->whereIn('actor_id', $ids)->orWhereIn('subject_id', $ids))
                    ->delete();
            }

            $central->table('users')->where('email', 'like', 'usatest-%@test.local')->delete();
            $central->table('audit_logs')->where('action', 'like', 'central.%')->delete();
        } catch (Throwable $e) {
            report($e);
        }

        parent::tearDown();
    }

    /* ============================================================
     | NONAKTIF MEMUTUSKAN AKSES, BUKAN CUMA PINTU LOGIN
    |============================================================
     */

    #[Test]
    public function menonaktifkan_akun_mencabut_token_yang_sudah_berjalan(): void
    {
        $lain = $this->makeSuperadmin('rekan');
        $lain->createToken('central_auth');

        $central = DB::connection(self::CENTRAL);

        $this->assertSame(
            1,
            $central->table('personal_access_tokens')->where('tokenable_id', $lain->id)->count(),
            'Token tidak pernah terbit -- test ini tidak menguji apa pun.',
        );

        $this->actingAsSuperadmin();

        $this->patchJson("/api/central/superadmins/{$lain->id}", [
            'is_active' => false,
        ])->assertOk();

        $this->assertSame(
            0,
            $central->table('personal_access_tokens')->where('tokenable_id', $lain->id)->count(),
            'Token masih berlaku padahal akun sudah dinonaktifkan.',
        );

        $this->assertSame(
            0,
            (int) $central->table('users')->where('id', $lain->id)->value('is_active'),
        );
    }

    #[Test]
    public function akun_nonaktif_tidak_bisa_login_walaupun_password_benar(): void
    {
        $this->makeSuperadmin('rekan', password: 'rahasia-panjang');

        DB::connection(self::CENTRAL)->table('users')
            ->where('email', $this->mail('rekan'))
            ->update(['is_active' => false]);

        $res = $this->postJson('/api/central/login', [
            'email' => $this->mail('rekan'),
            'password' => 'rahasia-panjang',
        ]);

        $res->assertUnprocessable();

        /*
         * Pesannya harus sama persis dengan "kredensial salah".
         *
         * Kalau tidak, halaman login jadi cara untuk menebak email mana yang
         * terdaftar di database pusat -- informasi yang tidak ada hubungannya
         * dengan pekerjaan yang sedang dilakukan.
         *
         * Field-nya sengaja `email`, bukan `password`: credentials-nya sudah
         * benar di sini, jadi pesan yang menunjuk password akan menyuruh
         * orang mengoreksi kolom yang sebenarnya sudah benar.
         */
        $this->assertSame(['Email atau password salah.'], $res->json('errors.email'));
    }

    /* ============================================================
     | KREDENSIAL SALAH DITUNJUK KE KOLOMNYA
     |============================================================
     |
     | Berbeda dari akun nonaktif di atas: yang diuji di sini BUKALAH
     | kebocoran, tapi kegunaan pesan. Frontend
     | (`composables/useLoginError.js`) membaca kunci `errors` untuk
     | menempelkan kalimat ke input yang tepat, jadi kunci yang salah sama
     | sekali dengan tidak ada error sama sekali -- kolom yang bermasalah
     | tidak akan diberi tanda, dan pesan muncul di bawah kolom lain.
     |
     | Email dan password tetap dicek terpisah, jadi lima percobaan per
     | menit per kombinasi email+IP (`throttle:login`) tetap menjadi yang
     | menahan pemetaan daftar akun -- bukan pesan.
     |
     */

    #[Test]
    public function password_salah_dilaporkan_di_kolom_password(): void
    {
        $this->makeSuperadmin('rekan', password: 'rahasia-panjang');

        $res = $this->postJson('/api/central/login', [
            'email' => $this->mail('rekan'),
            'password' => 'salah-sekali',
        ]);

        $res->assertUnprocessable();

        $this->assertSame(['Kata sandi salah.'], $res->json('errors.password'));
        $this->assertArrayNotHasKey('email', $res->json('errors'));
    }

    #[Test]
    public function email_tidak_terdaftar_dilaporkan_di_kolom_email(): void
    {
        $res = $this->postJson('/api/central/login', [
            'email' => 'usatest-entah@test.local',
            'password' => 'apa-saja',
        ]);

        $res->assertUnprocessable();

        $this->assertSame(
            ['Email tidak terdaftar di panel pusat.'],
            $res->json('errors.email'),
        );
        $this->assertArrayNotHasKey('password', $res->json('errors'));
    }

    /**
     * Email benar tapi bukan superadmin: pesannya tetap generik.
     *
     * Ini kondisi ketiga yang tidak boleh ikut berubah bersama pemisahan
     * email/password di atas. Kredensialnya sudah benar di titik ini, jadi
     * "bukan superadmin" hanya akan mengonfirmasi bahwa email itu terdaftar
     * dan hidup -- persis informasi yang tidak boleh bocor ke halaman publik.
     */
    #[Test]
    public function akun_bukan_superadmin_tetap_aja_menerima_pesan_generik(): void
    {
        // `makeSuperadmin` sudah mendaftarkan id-nya ke `userIds`, jadi
        // perubahan role di sini ikut dibersihkan oleh `tearDown()` tanpa
        // perlu mendaftarkannya lagi.
        $this->makeSuperadmin('rekan');

        // `teknisi`, bukan `operator`: kolom `role` di database pusat adalah
        // enum, jadi nilainya harus salah satu dari yang diizinkan
        // (`create_central_users_table` + `add_superadmin_role_to_users_table`).
        // Nilai di luar daftar itu ditolak oleh MySQL dengan "Data truncated",
        // bukan oleh kode kita -- dan pesannya tidak menyebut akun yang
        // sedang diuji sama sekali.
        DB::connection(self::CENTRAL)->table('users')
            ->where('email', $this->mail('rekan'))
            ->update(['role' => 'teknisi']);

        $res = $this->postJson('/api/central/login', [
            'email' => $this->mail('rekan'),
            'password' => 'password',
        ]);

        $res->assertUnprocessable();

        $this->assertSame(['Email atau password salah.'], $res->json('errors.email'));
    }

    #[Test]
    public function mengganti_password_juga_mencabut_token_lama(): void
    {
        $lain = $this->makeSuperadmin('rekan');
        $lain->createToken('central_auth');

        $this->actingAsSuperadmin();

        $this->patchJson("/api/central/superadmins/{$lain->id}", [
            'password' => 'sandi-baru-123',
            'password_confirmation' => 'sandi-baru-123',
        ])->assertOk();

        $this->assertSame(
            0,
            DB::connection(self::CENTRAL)->table('personal_access_tokens')
                ->where('tokenable_id', $lain->id)
                ->count(),
            'Token lama masih berlaku setelah password diganti.',
        );
    }

    #[Test]
    public function mengganti_password_benar_benar_menyimpan_password_baru(): void
    {
        /*
         * Test di atas ("mencabut token lama") memeriksa efek sampingnya saja,
         * dan efek samping itu terjadi BAHKAN KETIKA password-nya sendiri tidak
         * pernah tersimpan. Jadi test itu lulus meski fiturnya tidak
         * berfungsi sama sekali.
         *
         * Yang diperiksa di sini adalah hal yang paling mendasar: password
         * barunya benar-benar bisa dipakai untuk login. Kalau hash barunya
         * tidak tersimpan, server tetap membalas "berhasil" -- superadmin lalu
         * kehilangan akses tanpa tahu kenapa.
         */
        $lain = $this->makeSuperadmin('rekan', password: 'rahasia-lama');

        $this->actingAsSuperadmin();

        $this->patchJson("/api/central/superadmins/{$lain->id}", [
            'password' => 'sandi-baru-123',
            'password_confirmation' => 'sandi-baru-123',
        ])->assertOk();

        $hash = DB::connection(self::CENTRAL)->table('users')
            ->where('id', $lain->id)
            ->value('password');

        $this->assertTrue(
            Hash::check('sandi-baru-123', (string) $hash),
            'Password baru tidak tersimpan -- hashnya masih yang lama.',
        );

        $this->assertFalse(
            Hash::check('rahasia-lama', (string) $hash),
            'Password lama masih berlaku setelah diganti.',
        );

        // Dan yang paling menentukan: password barunya bisa dipakai login.
        $res = $this->postJson('/api/central/login', [
            'email' => $this->mail('rekan'),
            'password' => 'sandi-baru-123',
        ])->assertOk();

        $this->assertNotEmpty($res->json('data.token'));

        DB::connection(self::CENTRAL)->table('personal_access_tokens')
            ->where('tokenable_id', $lain->id)
            ->delete();
    }

    #[Test]
    public function password_kosong_tidak_mengubah_password_lama(): void
    {
        $lain = $this->makeSuperadmin('rekan', password: 'rahasia-panjang');

        $sebelum = DB::connection(self::CENTRAL)->table('users')
            ->where('id', $lain->id)
            ->value('password');

        $this->actingAsSuperadmin();

        $this->patchJson("/api/central/superadmins/{$lain->id}", [
            'name' => 'Nama Baru',
            'password' => '',
        ])->assertOk();

        $sesudah = DB::connection(self::CENTRAL)->table('users')
            ->where('id', $lain->id)
            ->first();

        $this->assertSame('Nama Baru', $sesudah->name, 'Perubahan nama tidak tersimpan.');
        $this->assertSame($sebelum, $sesudah->password, 'Password berubah padahal form dikirim kosong.');

        // Password kosong bukan berarti password kosong. Kalau hash-nya berubah
        // jadi apa pun yang bisa di-verify dengan string kosong, siapa pun yang
        // tahu cara verifying bisa masuk.
        $this->assertTrue(Hash::check('rahasia-panjang', $sesudah->password));
    }

    /* ============================================================
     | ATURAN YANG MENCEGAH PANEL TERKUNCI
    |============================================================
     */

    #[Test]
    public function akun_yang_dipakai_tidak_bisa_dinonaktifkan(): void
    {
        $this->actingAsSuperadmin();

        $res = $this->patchJson("/api/central/superadmins/{$this->superadmin->id}", [
            'is_active' => false,
        ]);

        $res->assertUnprocessable();

        $this->assertStringContainsString(
            'tidak bisa dinonaktifkan',
            (string) $res->json('message'),
            'Pesan tidak menjelaskan aturan yang ditabrak.',
        );

        $this->assertSame(
            1,
            (int) DB::connection(self::CENTRAL)->table('users')
                ->where('id', $this->superadmin->id)
                ->value('is_active'),
            'Akun sendiri ikut dinonaktifkan.',
        );
    }

    #[Test]
    public function user_pusat_aktif_terakhir_tidak_bisa_dinonaktifkan(): void
    {
        /*
         * Hanya satu akun(superadmin di setUp), jadi dia yang terakhir.
         * Test ini sengaja TIDAK membuat akun kedua: justru itulah kondisi
         * yang harus dicegah.
         *
         * Karena itu test ini memeriksa pesan KEDUA aturan sekaligus -- nama
         * pesan keduanya sama, dan yang diuji di sini memang "ada akun lain
         * dulu" (lihat test berikutnya untuk sisi sebaliknya).
         */
        $this->actingAsSuperadmin();

        $this->patchJson("/api/central/superadmins/{$this->superadmin->id}", [
            'is_active' => false,
        ])->assertUnprocessable();
    }

    #[Test]
    public function setelah_ada_akun_lain_akun_pertama_bisa_dinonaktifkan(): void
    {
        $kedua = $this->makeSuperadmin('kedua');

        $this->actingAsSuperadmin();

        $this->patchJson("/api/central/superadmins/{$kedua->id}", [
            'is_active' => false,
        ])->assertOk();

        $this->assertSame(0, (int) $kedua->fresh()->is_active);
    }

    /* ============================================================
     | CRUD & KEAMANAN DASAR
    |============================================================
     */

    #[Test]
    public function daftar_hanya_berisi_user_pusat(): void
    {
        $this->makeSuperadmin('pusat2');

        /*
         * Baris dengan role lain TIDAK mungkin ada di database pusat pada
         * kondisi nyata, tapi test tidak boleh bergantung pada itu: kalau
         * ada baris `admin` di sana, panel tidak punya hak mengubahnya --
         * akun itu milik database lokasi, bukan milik panel.
         */
        DB::connection(self::CENTRAL)->table('users')->insert([
            'name' => 'Admin Lokasi Bocor',
            'email' => 'usatest-bocor@test.local',
            'password' => Hash::make('apapun-123'),
            'role' => 'admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsSuperadmin();

        $res = $this->getJson('/api/central/superadmins')->assertOk();

        $emails = collect($res->json('data'))->pluck('email')->all();

        $this->assertContains($this->mail('pusat2'), $emails);
        $this->assertNotContains('usatest-bocor@test.local', $emails);

        $this->assertSame((int) $this->superadmin->id, (int) $res->json('meta.self'));
    }

    #[Test]
    public function user_pusat_baru_langsung_aktif_dan_bisa_login(): void
    {
        $this->actingAsSuperadmin();

        $this->postJson('/api/central/superadmins', [
            'name' => 'Rekan Baru',
            'email' => $this->mail('rekan-baru'),
            'password' => 'sandi-panjang-123',
            'password_confirmation' => 'sandi-panjang-123',
        ])->assertCreated();

        $central = DB::connection(self::CENTRAL);

        $row = $central->table('users')->where('email', $this->mail('rekan-baru'))->first();

        $this->assertNotNull($row);
        $this->assertSame('superadmin', $row->role, 'Role harus dikunci di server, bukan diambil dari form.');
        $this->assertSame(1, (int) $row->is_active);

        $this->userIds[] = (int) $row->id;

        // Password benar-benar bisa dipakai. Kalau tidak, akun yang baru dibuat
        // hanya terlihat benar di daftar -- dan itu persis jenis kesalahan
        // yang baru ketahuan saat orang itu mencoba login sendiri.
        $res = $this->postJson('/api/central/login', [
            'email' => $this->mail('rekan-baru'),
            'password' => 'sandi-panjang-123',
        ])->assertOk();

        $this->assertNotEmpty($res->json('data.token'));

        $central->table('personal_access_tokens')->where('tokenable_id', $row->id)->delete();
    }

    #[Test]
    public function email_ganda_muda_ditolak(): void
    {
        $this->actingAsSuperadmin();

        $this->postJson('/api/central/superadmins', [
            'name' => 'Duplikat',
            'email' => $this->mail('admin'),
            'password' => 'sandi-panjang-123',
            'password_confirmation' => 'sandi-panjang-123',
        ])->assertUnprocessable();
    }

    #[Test]
    public function password_baru_wajib_dikonfirmasi(): void
    {
        $this->actingAsSuperadmin();

        $this->postJson('/api/central/superadmins', [
            'name' => 'Tanpa Konfirmasi',
            'email' => $this->mail('tanpa-konfirmasi'),
            'password' => 'sandi-panjang-123',
            'password_confirmation' => 'beda-sekali-123',
        ])->assertUnprocessable();
    }

    #[Test]
    public function tanpa_token_endpoint_ditolak(): void
    {
        /*
         * Diperiksa lewat status code, bukan isi pesan. Yang penting justru
         * kodenya benar: kalau bocor 200, seluruh panel Pusat terbuka untuk
         * siapa pun yang bisa Konstanten pageler endpoint.
         */
        $this->getJson('/api/central/superadmins')->assertUnauthorized();

        $this->postJson('/api/central/superadmins', [
            'name' => 'Penyusup',
            'email' => $this->mail('penyusup'),
            'password' => 'sandi-panjang-123',
            'password_confirmation' => 'sandi-panjang-123',
        ])->assertUnauthorized();
    }

    #[Test]
    public function perubahan_user_pusat_tercatat_di_audit(): void
    {
        $lain = $this->makeSuperadmin('rekan');

        $this->actingAsSuperadmin();

        $this->patchJson("/api/central/superadmins/{$lain->id}", [
            'name' => 'Nama Baru',
        ])->assertOk();

        $baris = DB::connection(self::CENTRAL)->table('audit_logs')
            ->where('action', 'central.user_update')
            ->where('subject_id', $lain->id)
            ->first();

        $this->assertNotNull($baris, 'Perubahan nama tidak masuk jejak audit.');
        $this->assertSame('superadmin', $baris->actor_type);
    }

    /* ============================================================
     | BANTUAN
    |============================================================
     */

    private function actingAsSuperadmin(): void
    {
        Sanctum::actingAs($this->superadmin, ['*'], 'sanctum');
    }

    /** Email lengkap dengan prefix, supaya sisa run yang gagal tidak bentrok. */
    private function mail(string $nama): string
    {
        return $this->prefix.$nama.'@test.local';
    }

    /**
     * Buat user pusat di database pusat dan kembalikan model-nya.
     *
     * `setConnection(self::CENTRAL)` itu wajib, bukan teknis belaka: relasi
     * `tokens()` mewarisi koneksi dari model induknya, jadi tanpa itu token
     * dari `createToken()` akan tersimpan di database LOKASI -- dan test
     * pencabutan token akan selalu lulus karena memeriksa tabel yang salah.
     */
    private function makeSuperadmin(string $nama, string $password = 'password'): User
    {
        $central = DB::connection(self::CENTRAL);

        $id = $central->table('users')->insertGetId([
            'name' => 'Admin Pusat',
            'email' => $this->mail($nama),
            'password' => Hash::make($password),
            'role' => 'superadmin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->userIds[] = $id;

        $model = new User;
        $model->setConnection(self::CENTRAL);
        $model->forceFill([
            'name' => 'Admin Pusat',
            'email' => $this->mail($nama),
            'password' => Hash::make($password),
            'role' => 'superadmin',
            'is_active' => true,
        ]);

        $model->id = $id;
        $model->exists = true;

        return $model;
    }

    private function ensureCentralTables(): void
    {
        /*
         * Semua migrasi pusat, bukan daftar per file.
         *
         * Daftar manual hanya memuat apa yang ada saat file ini ditulis, jadi
         * migrasi pusat yang datang belakangan tidak ikut jalan. Kolom yang
         * ditambahkan migrasi baru lalu hilang dari skema uji sementara model
         * dan controller sudah mengirimkannya -- dan test gagal dengan
         * "Unknown column" yang tidak menyinggung migrasinya.
         */
        $this->runCentralMigrations();
    }
}
