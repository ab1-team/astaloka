<?php

namespace Tests\Feature;

use App\Models\Jabatan;
use App\Models\User;
use App\Services\MenuAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Hak akses menu per jabatan.
 *
 * Dua hal yang diuji, dan keduanya bisa lolos kalau hanya salah satu
 * yang diperiksa:
 *
 *  1. Menu yang tidak dicentang -> endpoint membalas 403.
 *  2. Menu yang DIPILIH -> halaman dan endpointnya utuh.
 *
 * Yang kedua justru lebih penting. Memasang pemblokiran tanpa memastikan
 * halaman yang BOLEH dibuka masih berfungsi adalah cara tercepat membuat
 * aplikasi rusak: bendahara kehilangan akses Akses Tagihan karena ada
 * satu endpoint bersama yang ikut terpotong, dan tidak ada yang bisa
 *arke memastikan itu karena test hanya memeriksa kasus negatifnya.
 *
 * MEMAKAI `Sanctum::actingAs`, BUKAN token asli.
 *
 * `RefreshDatabase` membungkus tiap test dalam transaksi pada koneksi
 * default. `ResolveTenant` kemudian memindahkan `database.default` ke
 * koneksi `tenant` -- koneksi PDO yang berbeda, meski menunjuk database
 * yang sama. User uji ditulis lewat koneksi default, jadi saat Sanctum
 * mencari token lewat koneksi `tenant`, datanya belum ter-commit: yang
 * terbaca hanya transaksi kosong, dan hasilnya "MySQL server has gone
 * away" atau 401 yang sama sekali tidak menyinggung test yang sedang
 * jalan. `actingAs` melewati seluruh pembacaan token itu.
 */
class MenuAccessTest extends TestCase
{
    use RefreshDatabase;

    private function jabatan(string $nama): Jabatan
    {
        return Jabatan::create(['nama_jabatan' => $nama]);
    }

    private function admin(?Jabatan $jabatan = null, string $role = 'admin'): User
    {
        return User::create([
            'name' => 'Admin Uji',
            // Unik: tanpa `RefreshDatabase` yang benar-benar me-reset,
            // email yang sama akan bentrok di baris kedua.
            'email' => 'menu-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => $role,
            'jabatan_id' => $jabatan?->id,
        ]);
    }

    /** Simpan daftar menu yang diizinkan untuk sebuah jabatan. */
    private function setMenu(Jabatan $jabatan, array $keys): void
    {
        app(MenuAccessService::class)->saveForJabatan((int) $jabatan->id, $keys);
    }

    /** @return array<int, array<string, mixed>> */
    private function katalog(): array
    {
        return collect(app(MenuAccessService::class)->catalog())->keyBy('key')->all();
    }

    // ---- Aturan dasar ---------------------------------------------------

    #[Test]
    public function jabatan_tanpa_konfigurasi_memiliki_semua_menu(): void
    {
        Sanctum::actingAs($this->admin($this->jabatan('Bendahara')));

        $res = $this->getJson('/api/menu-access/catalog')->assertOk();

        $terlarang = collect($res->json('data.items'))->where('allowed', false);

        $this->assertCount(0, $terlarang, 'Jabatan yang belum dikonfigurasi harus punya semua menu.');
    }

    /**
     * Flag `selected` harus mencerminkan daftar yang TERSIMPAN.
     *
     * Ini yang menentukan centangan mana yang tampil di halaman Hak Akses
     * saat admin membuka sebuah jabatan. Kalau `selected` dihitung dari
     * daftar `always` alih-alih dari daftar tersimpan, maka setelah admin
     * mencentang 20 menu dan menyimpannya, halamannya akan membuka dengan
     * HANYA "Beranda" tercentang -- seolah-olah simpan tidak pernah terjadi.
     *
     * Gejalanya berputar dua arah, dan keduanya merusak:
     *   - centang hilang setelah disimpan, dan
     *   - `adaPerubahan` selalu `true`, jadi tombol Simpan tidak pernah mati.
     */
    #[Test]
    public function flag_selected_mencerminkan_daftar_yang_tersimpan(): void
    {
        $jabatan = $this->jabatan('Bendahara');

        $this->setMenu($jabatan, ['master.pelanggan', 'transaksi.jurnal-umum', 'pelaporan']);

        Sanctum::actingAs($this->admin(null));

        $menu = collect($this->getJson('/api/menu-access')
            ->assertOk()
            ->json('data')['0']['menu']);

        $terpilih = collect($menu)
            ->where('selected', true)
            ->pluck('key')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            ['master.pelanggan', 'pelaporan', 'transaksi.jurnal-umum'],
            $terpilih,
            'Menu yang tersimpan harus tampil tercentang. Kalau daftar ini hanya '
            .'berisi menu terkunci, centangan admin hilang setelah disimpan.',
        );
    }

    /**
     * Menu yang TIDAK tersimpan harus tampil tidak tercentang -- arah
     * sebaliknya dari test di atas.
     */
    #[Test]
    public function menu_yang_tidak_tersimpan_tidak_ikut_tercentang(): void
    {
        $jabatan = $this->jabatan('Bendahara');

        // Hanya dua menu yang diizinkan. Kalau sisanya ikut tercentang,
        // admin mengira halamannya sudah benar lalu menekan Simpan,
        // padahal akses yang dimaksud belum diberikan.
        $this->setMenu($jabatan, ['master.pelanggan', 'transaksi.jurnal-umum']);

        Sanctum::actingAs($this->admin(null));

        $menu = collect($this->getJson('/api/menu-access')
            ->assertOk()
            ->json('data')['0']['menu']);

        $this->assertFalse(
            collect($menu)->firstWhere('key', 'pelaporan')['selected'],
            'Menu yang tidak tersimpan tidak boleh tampil tercentang.',
        );

        /*
         * Sebaliknya: menu yang TERSIMPAN harus tercentang, dan menu lain
         * yang tidak disebut harus ikut tidak tercentang.
         *
         * Baris kedua dari test ini sebelumnya salah arah -- ia
         *vd asserts `master.pelanggan` TIDAK tercentang, padahal
         * `master.pelanggan` justru salah satu key yang ditulis di
         * `setMenu()`. Jadi yang diuji menjadi kebalikan dari yang
         * dimaksud, dan karena itu test ini bisa lulus selama
         * `setMenu()` salah ketik tanpa ada yang melihat.
         */
        $this->assertTrue(collect($menu)->firstWhere('key', 'master.pelanggan')['selected']);

        $this->assertFalse(collect($menu)->firstWhere('key', 'master.desa')['selected']);
    }

    /**
     * Jabatan yang sudah dikonfigurasi ketat tapi tidak punya satu pun menu
     * boleh tetap "sudah dikonfigurasi" -- itulah hasil tombol "Hapus semua".
     */
    #[Test]
    public function hapus_semua_tetap_berstatus_sudah_dikonfigurasi(): void
    {
        $jabatan = $this->jabatan('Bendahara');

        $this->setMenu($jabatan, ['master.pelanggan', 'transaksi.jurnal-umum']);
        $this->setMenu($jabatan, []);

        Sanctum::actingAs($this->admin(null));

        $res = $this->getJson('/api/menu-access')->assertOk();

        $data = $res->json('data')[0];

        $this->assertTrue(
            $data['configured'],
            'Setelah "Hapus semua", jabatan harus tetap tercatat sudah '
            .'dikonfigurasi. Kalau tidak, semua menu terbuka kembali.',
        );

        // Dan memang tidak boleh ada menu lain yang terbuka.
        $terpilih = collect($data['menu'])
            ->where('selected', true)
            ->pluck('key')
            ->values()
            ->all();

        $this->assertSame([], $terpilih);
    }

    #[Test]
    public function admin_tanpa_jabatan_memiliki_semua_menu(): void
    {
        Sanctum::actingAs($this->admin(null));

        $res = $this->getJson('/api/menu-access/catalog')->assertOk();

        $terlarang = collect($res->json('data.items'))->where('allowed', false);

        $this->assertCount(0, $terlarang);
    }

    /**
     * Role selain admin, yang sekarang ikut aturan jabatan.
     *
     * @return array<string, array{string}>
     */
    public static function roleLainProvider(): array
    {
        return [
            'teknisi' => ['teknisi'],
            'surveyor' => ['surveyor'],
            'pelanggan' => ['pelanggan'],
        ];
    }

    /**
     * KEEMPAT role ikut aturan jabatan.
     *
     * Ini kebalikan dari yang berlaku sebelumnya. Dulu `enforce_roles` hanya
     * berisi `admin`, jadi role lain keluar dari `allowedKeysFor()` di baris
     * kedua -- sebelum `storedKeysForJabatan()` dipanggil -- dan tabel
     * `jabatan_menu_access` tidak pernah dibaca untuk mereka.
     *
     * Sekarang keempatnya ikut. Alasannya praktis: menugaskan seorang
     * surveyor ke jabatan tanpa bisa menghilangkan satu pun pekerjaannya
     * berarti jabatan itu tidak berguna untuk surveyor. Jabatan yang
     * memagari akses -- dan memagari endpoint-nya juga, bukan cuma
     * menyembunyikan menunya.
     *
     * KONDISI TERBURUK YANG DIPAKAI: jabatan disetel "Hapus semua", yaitu nol
     * menu boleh. Kalau aturan jabatan sampai bocor ke role lain, di sinilah
     * gejalanya muncul -- sidebar mereka ikut kosong dan endpoint mereka ikut
     * 403. Kalau jobatannya justru dibiarkan "semua boleh", test ini akan
     * lulus EVEN JIKA aturannya bocor, jadi pengujian dengan kondisi itu
     * tidak membuktikan apa pun.
     *
     * Test ini memakai `catalog()` karena endpoint itu terbuka untuk semua
     * role login -- jadi 403 di sini benar-benar berarti aturan jabatan yang
     * menahan, bukan `CheckRole`.
     */
    #[Test]
    #[DataProvider('roleLainProvider')]
    public function role_lain_juga_ikut_dibatasi_oleh_jabatan(string $role): void
    {
        $jabatan = $this->jabatan('Pengecek '.$role);
        $this->setMenu($jabatan, []);

        Sanctum::actingAs($this->admin($jabatan, $role));

        $res = $this->getJson('/api/menu-access/catalog')->assertOk();

        $terlarang = collect($res->json('data.items'))->where('allowed', false);

        $this->assertNotCount(
            0,
            $terlarang,
            "Role `{$role}` harus ikut aturan jabatan.",
        );
    }

    /**
     * KontROL: aturan yang sama MEMANG aktif untuk admin.
     *
     * Tanpa test ini, test di atas bisa hijau karena alasan yang salah --
     * misalnya filter tiba-tiba dimatikan untuk semua orang, atau
     * `enforce_roles` jadi daftar kosong. Keduanya membuat
     * "role lain ikut terpotong" benar secara literal, sementara fiturnya
     * sudah mati total.
     *
     * Jabatan, baris tabel, dan endpoint-nya SAMA persis dengan yang dipakai
     * test role lain; satu-satunya yang dibedakan adalah `role` pemanggil.
     * Jadi yang diuji benar-benar perbedaannya: role, bukan keadaan yang lain.
     */
    #[Test]
    public function admin_dengan_jabatan_yang_sama_memang_ikut_terbatas(): void
    {
        $jabatan = $this->jabatan('Pengecek admin');
        $this->setMenu($jabatan, []);

        Sanctum::actingAs($this->admin($jabatan, 'admin'));

        $res = $this->getJson('/api/menu-access/catalog')->assertOk();

        $terlarang = collect($res->json('data.items'))->where('allowed', false);

        $this->assertNotCount(
            0,
            $terlarang,
            'Kalau admin juga tidak ikut aturan jabatan, test role lain di atas '
            .'hijau karena filter-nya mati -- bukan karena aturannya benar.',
        );
    }

    /**
     * Yang ditegakkan `EnforceMenuAccess` (endpoint), bukan hanya katalog.
     *
     * Katalog hanya mengatur tampilan sidebar. Yang benar-benar snatched
     * adalah endpoint -- satu panggilan ke `POST /api/...` dari console
     * browser tidak pernah melewati Vue Router sama sekali.
     *
     * Endpoint yang dipilih: `GET /api/installation-tickets`. Ia MELEWATI dua
     * syarat yang tidak boleh dilanggar kalau test ini mau berarti:
     *
     *   - Dipakai menu yang nyata (`master.instalasi`, `instalasi.register`,
     *     `instalasi.status`), jadi benar-benar milik `api` sebuah entri
     *     katalog.
     *   - TIDAK ada di `always_allowed_api`. Kalau iya, middleware
     *     melepaskannya tanpa pernah membaca tabel, dan test ini akan hijau
     *     tanpa peduli apakah aturannya benar atau tidak.
     *
     * Dan bisa dipanggil surveyor maupun teknisi lewat
     * `role:admin,surveyor,teknisi` -- jadi 403 di sini tidak mungkin datang
     * dari `CheckRole` yang hanya memanggil `next()` kalau role-nya cocok.
     *
     * Pelanggan TIDAK ikut di sini karena memang tidak punya satu pun endpoint
     * milik menu: seluruh route-nya sudah masuk `always_allowed_api`. Tidak
     * ada yang bisa diuji di situ, dan memaksakan test dengan endpoint bebas
     * hanya menghasilkan pemeriksaan yang selalu lulus.
     */
    #[Test]
    public function endpoint_milik_menu_juga_ikut_terpotong_untuk_role_lain(): void
    {
        $jabatan = $this->jabatan('Petugas Lapangan');
        $this->setMenu($jabatan, []);

        foreach (['surveyor', 'teknisi'] as $role) {
            Sanctum::actingAs($this->admin($jabatan, $role));

            $status = $this->getJson('/api/installation-tickets')->status();

            $this->assertSame(
                403,
                $status,
                "`{$role}` harus ikut terpotong oleh aturan jabatan, sama seperti admin.",
            );
        }

        /*
         * Kontrol, di test yang sama dan dengan jabatan yang sama.
         *
         * Kalau Endpoint-nya ternyata masuk `always_allowed_api`, atau entri
         * katalognya kehilangan prefix-nya, admin juga akan lolos -- dan test
         * ini lalu hijau karena mengukur sesuatu yang memang tidak pernah
         * aktif. Pemeriksaan ini membuat penyusun test berikutnya tidak bisa
         * salah baca hasilnya sebagai "filter tidak bekerja untuk admin".
         */
        Sanctum::actingAs($this->admin($jabatan, 'admin'));

        $this->getJson('/api/installation-tickets')
            ->assertStatus(403)
            ->assertJsonPath('code', 'MENU_ACCESS_DENIED');
    }

    #[Test]
    public function menu_yang_tidak_dicentang_tidak_lagi_terlihat(): void
    {
        $jabatan = $this->jabatan('Bendahara');
        $this->setMenu($jabatan, ['master.pelanggan', 'transaksi.jurnal-umum']);

        Sanctum::actingAs($this->admin($jabatan));

        $items = collect($this->getJson('/api/menu-access/catalog')
            ->assertOk()->json('data.items'))->keyBy('key');

        $this->assertTrue($items['master.pelanggan']['allowed']);
        $this->assertTrue($items['transaksi.jurnal-umum']['allowed']);
        $this->assertFalse($items['pelaporan']['allowed']);
        $this->assertFalse($items['master.desa']['allowed']);
    }

    #[Test]
    public function label_menu_terlarang_tidak_terbocor(): void
    {
        $jabatan = $this->jabatan('Bendahara');
        $this->setMenu($jabatan, ['master.pelanggan']);

        Sanctum::actingAs($this->admin($jabatan));

        $terlarang = collect($this->getJson('/api/menu-access/catalog')
            ->assertOk()->json('data.items'))->firstWhere('key', 'pelaporan');

        $this->assertFalse($terlarang['allowed']);
        // Label & path dihapus supaya frontend bisa membedakan
        // "tidak ada untuk saya" dari "menu ini tidak dikenal".
        $this->assertArrayNotHasKey('label', $terlarang);
        $this->assertArrayNotHasKey('path', $terlarang);
    }

    #[Test]
    public function beranda_tidak_pernah_bisa_ditutup(): void
    {
        /*
         * `/app` (Beranda) sudah bukan entri katalog -- ia halaman tujuan
         * setelah login, bukan pekerjaan yang bisa dikunci. Jadi yang diuji
         * di sini bukan lagi "menu Beranda selalu terbuka", tapi justru
         * kebalikannya: Beranda tidak boleh muncul sebagai menu yang bisa
         * dicentang, karena halaman ini memang tempat mengaturnya.
         *
         * Yang DIPERTAHANKAN dari test lama: jika lokasi boleh dikunci
         * total, itu terkunci total -- tidak ada jalan keluar lain selain
         * mengedit database. Penegakannya ada di
         * `endpoint_menolak_403_bila_menu_tidak_dicentang`.
         */
        $jabatan = $this->jabatan('Bendahara');
        $this->setMenu($jabatan, []);

        Sanctum::actingAs($this->admin($jabatan));

        $items = collect($this->getJson('/api/menu-access/catalog')
            ->assertOk()->json('data.items'));

        // Beranda tidak boleh muncul sebagai entri yang bisa dicentang.
        $this->assertNull(
            $items->firstWhere('key', 'dashboard'),
            'Beranda bukan menu, jadi tidak boleh punya entri katalog.'
        );

        // Tidak boleh ada entri menu pun yang lolos tanpa centang.
        $this->assertTrue(
            $items->every(fn ($i) => $i['allowed'] === false),
            'Jabatan tanpa menu terpilih tidak boleh punya satu pun menu terbuka.'
        );
    }

    // ---- Penegakan di backend --------------------------------------------

    #[Test]
    public function endpoint_menolak_403_bila_menu_tidak_dicentang(): void
    {
        $jabatan = $this->jabatan('Bendahara');
        $this->setMenu($jabatan, ['master.pelanggan', 'transaksi.jurnal-umum']);

        Sanctum::actingAs($this->admin($jabatan));

        // `pelaporan` TIDAK dicentang.
        $this->getJson('/api/pelaporan')
            ->assertStatus(403)
            ->assertJson(['code' => 'MENU_ACCESS_DENIED']);
    }

    #[Test]
    public function endpoint_yang_boleh_tetap_berfungsi(): void
    {
        $jabatan = $this->jabatan('Bendahara');
        $this->setMenu($jabatan, ['master.pelanggan', 'transaksi.jurnal-umum']);

        Sanctum::actingAs($this->admin($jabatan));

        $res = $this->getJson('/api/transactions');

        // Yang diperiksa HANYA statusnya. 403 berarti pemblokiran merusak
        // halaman yang memang harus terbuka; error lain (mis. 500 karena
        // data uji kurang lengkap) bukan urusan test ini.
        $this->assertNotSame(403, $res->status());
    }

    #[Test]
    public function endpoint_bersama_tidak_ikut_terpotong(): void
    {
        $jabatan = $this->jabatan('Bendahara');
        // Hanya menu yang tidak memakai endpoint bersama.
        $this->setMenu($jabatan, []);

        Sanctum::actingAs($this->admin($jabatan));

        /*
         * `settings/lembaga-identity` dipanggil SidebarView di SETIAP mount
         * untuk judul sidebar, dan `unpaid-summary` dipanggil topnav di
         * semua halaman. Keduanya harus tetap terbuka walau semua menu lain
         * dicabut -- kalau tidak, bendahara melihat sidebar kosong dan
         * judulnya jatuh ke teks hardcoded.
         */
        foreach ([
            '/api/settings/lembaga-identity',
            '/api/monthly-bills/unpaid-summary',
            '/api/users/penandatangan',
            '/api/settings/sop',
            '/api/settings/kecamatan',
        ] as $url) {
            $res = $this->getJson($url);

            $this->assertNotSame(403, $res->status(), "Endpoint bersama `{$url}` ikut terblokir.");
        }
    }

    #[Test]
    public function endpoint_yang_dipakai_dua_menu_boleh_jika_salah_satu_dicentang(): void
    {
        $jabatan = $this->jabatan('Bendahara');
        // CoA TIDAK dicentang, tapi Jurnal Umum yang juga memakai
        // `GET /accounts` dicentang. Endpoint-nya harus tetap terbuka --
        // memotongnya akan membuat dropdown akun di Jurnal Umum kosong.
        $this->setMenu($jabatan, ['master.pelanggan', 'transaksi.jurnal-umum']);

        Sanctum::actingAs($this->admin($jabatan));

        $this->assertNotSame(403, $this->getJson('/api/accounts')->status());
    }

    /*
     * Test `endpoint_role_lain_tidak_ikut_terpotong` yang lama DIHAPUS.
     *
     * Ia memeriksa `/api/meter-readings/completed` -- dan prefix itu ADA di
     * `always_allowed_api`. Artinya middleware melepas request SEBELUM
     * `allowedKeysFor()` dipanggil, jadi tabel jabatan tidak pernah dibaca
     * dan test-nya akan hijau mutedlak: aturan jabatan bocor ke role lain
     * atau tidak, hasilnya sama saja.
     *
     * Test yang menggantikannya adalah
     * `endpoint_milik_menu_juga_ikut_terpotong_untuk_role_lain`, yang memakai
     * endpoint yang benar-benar dimiliki sebuah menu dan punya kontrol admin
     * di dalam test yang sama.
     */

    // ---- Halaman hak akses -----------------------------------------------

    #[Test]
    public function halaman_hak_akses_menampilkan_semua_jabatan(): void
    {
        $this->jabatan('Bendahara');
        $this->jabatan('Direktur');

        Sanctum::actingAs($this->admin(null));

        $res = $this->getJson('/api/menu-access')->assertOk();

        $nama = collect($res->json('data'))->pluck('nama');

        $this->assertTrue($nama->contains('Bendahara'));
        $this->assertTrue($nama->contains('Direktur'));
    }

    /**
     * Endpoint penulisan sudah TIDAK ADA di aplikasi lokasi.
     *
     * `MenuAccessController` sekarang hanya bisa dibaca. Menulis hak akses
     * hanya bisa lewat panel pusat, jadi `PUT`/`POST` di sini harus hilang
     * total -- kalau masih ada, ada dua tempat yang bisa menulis tabel yang
     * sama tanpa ada yang menyatukan keduanya, dan perubahan terakhir yang
     * menang tergantung siapa yang lebih dulu menekan Simpan.
     *
     * Diuji sebagai 404, bukan 403: `403` berarti route-nya masih hidup dan
     * hanya guarding-nya yang menahan, jadi begitu guard itu dilonggarkan
     * penulisan penuh bisa terjadi tanpa ada yang melihat.
     */
    #[Test]
    public function aplikasi_lokasi_tidak_lagi_bisa_menulis_hak_akses(): void
    {
        $jabatan = $this->jabatan('Bendahara');
        Sanctum::actingAs($this->admin(null));

        $this->putJson('/api/menu-access/'.$jabatan->id, ['menu_keys' => ['master.pelanggan']])
            ->assertNotFound();

        $this->postJson('/api/menu-access/'.$jabatan->id.'/reset')
            ->assertNotFound();

        // Dan tabelnya benar-benar tidak tersentuh.
        $this->assertNull(app(MenuAccessService::class)->storedKeysForJabatan((int) $jabatan->id));
    }

    #[Test]
    public function menyimpan_menyalin_daftar_lama(): void
    {
        $jabatan = $this->jabatan('Bendahara');
        $service = app(MenuAccessService::class);

        $service->saveForJabatan((int) $jabatan->id, ['master.pelanggan', 'pelaporan']);

        // Simpan lagi dengan daftar yang lebih pendek: `pelaporan` harus
        // hilang, bukan tertinggal karena memakai diff.
        $service->saveForJabatan((int) $jabatan->id, ['master.pelanggan']);

        $this->assertSame(['master.pelanggan'], $service->storedKeysForJabatan((int) $jabatan->id));
    }

    /*
     * Test di bawah ini DIHAPUS dari file ini.
     *
     * Simpan, reset, dan penolakan daftar yang tidak dikenal dulu diuji lewat
     * `PUT /api/menu-access/{jabatan}`. Endpoint itu sudah tidak ada, dan
     * aturan ENDPOINT-nya sendiri masih hidup -- hanya tempatnya yang pindah ke
     * panel pusat. Membiarkannya di sini akan menguji 404 berulang kali
     * sambil menyisakan aturan yang sebenarnya masih berlaku tanpa diuji.
     *
     * Semua aturan itu sekarang diuji di `CentralMenuAccessTest.php`, di depan
     * endpoint yang menggantikannya. Yang tersisa di file ini cukup aturannya
     * yang benar-benar milik aplikasi lokasi: membaca, menegakkan, dan katalog.
     */

    // ---- Jabatan -> role -------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function jabatanRoleProvider(): array
    {
        return [
            'admin' => ['admin', 'admin'],
            'direktur' => ['Direktur', 'admin'],
            'bendahara' => ['Bendahara', 'admin'],
            'sekretaris' => ['Sekretaris', 'admin'],
            'jabatan lain' => ['Petugas Kebersihan', 'admin'],
            'surveyor' => ['Surveyor', 'surveyor'],
            'pelanggan' => ['Pelanggan', 'pelanggan'],
            'teknisi' => ['Teknisi', 'teknisi'],
            'cater' => ['Cater', 'teknisi'],
            'cater jamak' => ['Caters', 'teknisi'],
        ];
    }

    /**
     * Nama jabatan menentukan role-nya.
     *
     * Ini yang membuat satu jabatan punya "wajah" sendiri di halaman Hak
     * Akses: `Cater` dan `Teknisi` sama-sama jatuh ke `teknisi`, jadi
     * keduanya melihat daftar centang yang PERSIS SAMA; `Bendahara` dan
     * `Sekretaris` jatuh ke `admin`.
     *
     * Cocoknya case-insensitive dan dipangkas spasi, jadi "Caters", "cater",
     * dan " CATER " tidak menjadi tiga jabatan berbeda.
     */
    #[Test]
    #[DataProvider('jabatanRoleProvider')]
    public function nama_jabatan_menentukan_role(string $nama, string $harapan): void
    {
        $this->assertSame(
            $harapan,
            app(MenuAccessService::class)->roleForJabatan($nama),
        );
    }

    /**
     * Jabatan yang namanya kosong TIDAK boleh jatuh ke default.
     *
     * `admin` adalah nilai default yang paling longgar, jadi jabatan kosong
     * akan mendapat daftar centang admin -- padahal jabatan tanpa nama
     * adalah data yang belum selesai, bukan jabatan kantor.
     *
     * Null di sini berarti "tidak diketahui", dan frontend memperlakukannya
     * sebagai "menu tidak diketahui" -- bukan sebagai admin.
     */
    #[Test]
    public function jabatan_tanpa_nama_tidak_dijadikan_admin(): void
    {
        $svc = app(MenuAccessService::class);

        $this->assertNull($svc->roleForJabatan(null));
        $this->assertNull($svc->roleForJabatan(''));
        $this->assertNull($svc->roleForJabatan('   '));
    }

    /**
     * Cater dan Teknisi MEMAKAI DAFTAR MENU YANG SAMA.
     *
     * Ini inti dari "ngikutin satu tempat teknisi": keduanya satu pekerjaan
     * di satu tempat -- mencatat meter dan menanggung jawab instalasi.
     * Kalau dipisah, keduanya jadi dua daftar yang isinya sama sekarang dan
     * akan selalu berbeda pada suatu saat, dan tidak ada yang bisa memastikan
     * mana yang benar.
     *
     * Yang dibandingkan bukan hanya key-nya, tapi juga label dan group-nya:
     * dua daftar yang berbeda labelnya masih berarti dua halaman berbeda
     * bagi orang yang membacanya.
     */
    #[Test]
    public function cater_dan_teknisi_memakai_daftar_menu_yang_sama(): void
    {
        $svc = app(MenuAccessService::class);

        $cater = $svc->menuForJabatan(1, 'Cater');
        $teknisi = $svc->menuForJabatan(2, 'Teknisi');

        $this->assertNotEmpty($cater, 'Jabatan Cater harus punya daftar menu.');

        $this->assertSame(
            array_column($cater, 'key'),
            array_column($teknisi, 'key'),
            'Cater dan Teknisi harus melihat daftar menu yang persis sama.',
        );

        $this->assertSame(
            array_column($cater, 'label'),
            array_column($teknisi, 'label'),
            'Label menu juga harus sama -- daftar yang sama dengan label berbeda '
            .'tetap berarti dua halaman berbeda bagi orang yang membacanya.',
        );
    }

    /**
     * Jabatan non-core jatuh ke daftar admin.
     *
     * Bendahara, Sekretaris, dst. bekerja di belakang layar, bukan di
     * lapangan, jadi daftar mereka adalah daftar admin. Yang diuji di sini
     * justru kebalikannya -- bahwa daftar itu benar-benar SAMA dengan daftar
     * admin, bukan "mirip".
     */
    #[Test]
    public function jabatan_non_core_memakai_daftar_admin(): void
    {
        $svc = app(MenuAccessService::class);

        $admin = $svc->menuForJabatan(1, 'admin');
        $bendahara = $svc->menuForJabatan(2, 'Bendahara');
        $seketaris = $svc->menuForJabatan(3, 'Sekretaris');

        $this->assertSame(array_column($admin, 'key'), array_column($bendahara, 'key'));
        $this->assertSame(array_column($admin, 'key'), array_column($seketaris, 'key'));
    }

    /**
     * Jabatan role non-admin TIDAK pernah melihat menu milik role lain.
     *
     * Kolom `roles` di katalog adalah BATAS ATAS. Jabatan Teknisi tidak
     * boleh melihat -- apalagi mencentang -- menu admin: mencabutnya tidak
     * akan mengubah apa pun, dan administrator yang salah klik lalu
     * menyimpannya akan mendapat pengaturan yang tidak pernah berlaku.
     */
    #[Test]
    public function jabatan_hanya_melihat_menu_miliknya_sendiri(): void
    {
        $svc = app(MenuAccessService::class);

        $teknisi = collect($svc->menuForJabatan(1, 'Teknisi'));
        $survey = collect($svc->menuForJabatan(2, 'Surveyor'));
        $admin = collect($svc->menuForJabatan(3, 'admin'));

        $this->assertTrue(
            $teknisi->every(fn ($m) => in_array('teknisi', $m['roles'], true)),
            'Jabatan Teknisi hanya boleh melihat menu yang roles-nya memuat `teknisi`.',
        );

        $this->assertTrue(
            $survey->every(fn ($m) => in_array('surveyor', $m['roles'], true)),
            'Jabatan Surveyor hanya boleh melihat menu yang roles-nya memuat `surveyor`.',
        );

        $this->assertTrue(
            $admin->every(fn ($m) => in_array('admin', $m['roles'], true)),
            'Jabatan admin hanya boleh melihat menu yang roles-nya memuat `admin`.',
        );
    }

    /**
     * Menu milik role lain TIDAK bisa diaktifkan lewat jabatan.
     *
     * `saveForJabatan()` menolak key yang tidak dikenal katalog, tapi
     * "dikenal" tidak berarti "milik role ini". Tanpa penyaringan di
     * `allowedKeysFor()`, jabatan Bendahara (role `admin`) bisa menyimpan
     * `survey.create` -- entri yang tidak pernah bisa dia pakai, karena
     * `CheckRole` dan `roles` di sidebar sudah menutupnya.
     *
     * Yang diuji di sini efeknya di tempat yang benar-benar dibaca
     * middleware: daftar yang dikembalikan untuk user itu.
     */
    #[Test]
    public function menu_milik_role_lain_tidak_pernah_aktif_akibat_jabatan(): void
    {
        $jabatan = $this->jabatan('Bendahara');

        // Simpan langsung ke tabel, seolah-olah admin pernah mencentang
        // menu milik role lain. `saveForJabatan()` tidak akan menolak ini.
        app(MenuAccessService::class)->saveForJabatan((int) $jabatan->id, [
            'master.pelanggan',
            'survey.create',
            'survey.daftar',
        ]);

        $user = $this->admin($jabatan, 'admin');

        $diizinkan = app(MenuAccessService::class)->allowedKeysFor($user);

        $this->assertContains('master.pelanggan', $diizinkan);
        $this->assertNotContains(
            'survey.create',
            $diizinkan,
            'Menu milik role lain tidak boleh aktif hanya karena disimpan di tabel.',
        );
        $this->assertNotContains(
            'survey.daftar',
            $diizinkan,
            'Menu milik role lain tidak boleh aktif hanya karena disimpan di tabel.',
        );
    }

    // ---- Penegakan per role ---------------------------------------------

    /**
     * Menu Surveyor, dan hanya itu, yang boleh menutup endpoint POST survey.
     *
     * `POST /installation-tickets/{id}/survey` dipakai oleh dua role:
     * admin (mengisi survey dari halaman Status Instalasi) dan surveyor
     * (halaman Input Survey). Yang diuji di sini adalah milik masing-masing
     * role: mencabut `survey.create` dari jabatan surveyor harus menutup
     * endpoint itu untuk surveyor, TETAPI tidak untuk admin.
     *
     * Kalau `survey.create` ikut menutup endpoint milik admin, maka admin
     * yang jabatan-nya dibatasi kehilangan kemampuan mengisi survey --
     * padahal `CheckRole` masih mengizinkan role-nya. Dua lapis pengaman
     * yang saling meniadakan: hasil akhirnya "menu boleh, endpoint tidak",
     * dan tidak ada yang bisa menebak itu disengaja.
     */
    #[Test]
    public function mencabut_survey_create_menutup_endpoint_survey_untuk_surveyor(): void
    {
        $jabatan = $this->jabatan('Surveyor');
        // `survey.daftar` (lihat daftar) tetap dicentang, `survey.create`
        // (simpan hasil survey) dicabut.
        $this->setMenu($jabatan, ['survey.daftar']);

        Sanctum::actingAs($this->admin($jabatan, 'surveyor'));

        $this->postJson('/api/installation-tickets/1/survey', [
            'trouble_type' => 'x',
        ])->assertStatus(403)
            ->assertJsonPath('code', 'MENU_ACCESS_DENIED');
    }

    /**
     * Jabatan HANYA bolehodendakan role-nya sendiri.
     *
     * `allowedKeysFor()` membuang key tersimpan yang tidak termasuk katalog
     * role pemanggil (aturan 5). Jadi user role `admin` yang ditugaskan ke
     * jabatan `Surveyor` -- yang memakai daftar centang surveyor -- tidak
     * mendapat apa-apa dari jabatan itu: daftar centang yang di-panel
     * pusat diperuntukkan untuk PEKERJAAN jabatan itu, bukan untuk semua
     * orang yang kebetulan memegang jabatan itu.     *
     * Yang diuji di sini efeknya di tempat yang benar-benar dibaca
     * middleware: daftar key yang diizinkan untuk user itu. Kalau aturan
     * ini longgar, admin yang ditugaskan ke jabatan Surveyor akan
     * mewarisi daftar centang surveyor -- dan endpoint milik admin
     * (yang tidak ada di daftar itu) ikut terblokir, karena dia tidak
     * punya satu pun menu admin yang boleh.
     *
     * Catatan penting: ini bukan "menu milik role lain aktif". Yang
     * diuji adalah kebalikannya -- daftar milik role lain TIDAK pernah
     * berlaku bagi pemanggil role ini, sehingga user role admin yang
     * memegang jabatan Surveyor justru kehilangan akses admin-nya.
     */
    #[Test]
    public function jabatan_dengan_role_berbeda_tidak_mewarisi_daftar_milik_role_lain(): void
    {
        $jabatan = $this->jabatan('Surveyor');
        $this->setMenu($jabatan, ['survey.daftar', 'survey.create']);

        $user = $this->admin($jabatan, 'admin');

        Sanctum::actingAs($user);

        $diizinkan = app(MenuAccessService::class)->allowedKeysFor($user);

        $this->assertNotContains(
            'survey.create',
            $diizinkan,
            'Daftar centang jabatan Surveyor tidak boleh berlaku bagi user '
            .'role admin.',
        );

        $this->assertNotContains(
            'survey.daftar',
            $diizinkan,
            'Daftar centang jabatan Surveyor tidak boleh berlaku bagi user '
            .'role admin.',
        );
    }

    /**
     * Admin yang memegang jabatan admin TIDAK ikut terpotong oleh
     * prefiks milik surveyor.
     *
     * Kontrol dari test sebelumnya, dengan kombinasi yang BENAR-BENAR ada
     * di aplikasi: user role `admin` dengan jabatan `admin`.
     *
     * Yang diuji: prefix `installation-tickets` milik admin (bentuk
     * polos, ikut cocok dengan turunannya) tidak sama dengan prefix
     * `installation-tickets*` milik surveyor (bentuk wildcard di akhir,
     * hanya cocok dengan `/installation-tickets` itu sendiri). Kalau keduanya
     * sama, mencabut `survey.create` akan ikut menutup endpoint survey
     * milik admin.
     */
    #[Test]
    public function admin_yang_memakai_jabatan_admin_tidak_ikut_terpotong_oleh_surveyor(): void
    {
        $jabatan = $this->jabatan('admin');
        // Admin TIDAK punya menu surveyor sama sekali -- dan itu benar:
        // `survey.create` role-nya `surveyor`, jadi tidak pernah bisa
        // diaktifkan untuk admin.
        $this->setMenu($jabatan, ['master.pelanggan', 'instalasi.status']);

        Sanctum::actingAs($this->admin($jabatan, 'admin'));

        // Endpoint survey milik admin harus tetap terbuka.
        $this->assertNotSame(
            403,
            $this->postJson('/api/installation-tickets/1/survey', [
                'trouble_type' => 'x',
            ])->status(),
            'Admin ikut terpotong oleh aturan jabatan surveyor.',
        );
    }

    /**
     * `survey.daftar` TIDAK boleh menjadi pemilik endpoint POST survey.
     *
     * Kalau iya, mencabut `survey.create` tidak akan menutup apa pun:
     * `survey.daftar` masih menjadi pemilik prefix yang sama, dan
     * middleware menolak hanya kalau SEMUA pemiliknya tidak diizinkan.
     *
     * Ini bentuk kesalahan yang paling mudah lolos: config-nya terlihat
     * benar, test masih hijau, dan gejalanya -- "mencabut menu
     * Input Survey tidak menutup endpointnya" -- baru muncul saat dipakai.
     */
    #[Test]
    public function survey_daftar_bukan_pemilik_endpoint_simpan_survey(): void
    {
        $svc = app(MenuAccessService::class);

        $pemilik = [];

        foreach ($svc->catalog() as $item) {
            foreach ((array) ($item['api'] ?? []) as $prefix) {
                $pre = '/'.ltrim($prefix, '/');

                $cocok = false;

                if ($pre === '/installation-tickets/1/survey') {
                    $cocok = true;
                } elseif (str_starts_with('/installation-tickets/1/survey', $pre.'/')) {
                    $cocok = true;
                } elseif (str_contains($pre, '*') && fnmatch($pre, '/installation-tickets/1/survey', FNM_PATHNAME)) {
                    $cocok = true;
                }

                if ($cocok) {
                    $pemilik[] = (string) $item['key'];
                }
            }
        }

        $this->assertContains('survey.create', $pemilik);
        $this->assertNotContains(
            'survey.daftar',
            $pemilik,
            '`survey.daftar` jadi pemilik endpoint POST survey -- mencabut '
            .'`survey.create` lalu tidak akan menutup apa pun.',
        );
    }

    /**
     * Teknisi boleh membuka detail tiket yang jadi bahan form Hasil Instalasi.
     *
     * `GET /installation-tickets/{id}` dipakai oleh halaman Hasil Instalasi
     * milik teknisi. Kalau `teknisi.hasil-instalasi` tidak ikut memiliki
     * prefix itu, maka satu-satunya pemiliknya adalah menu admin -- dan
     * `allowedKeysFor()` membuang menu admin dari daftar user teknisi.
     *
     * Gejalanya nyata dan sangat membingungkan: halamannya MUNCUL di
     * sidebar, tapi isinya tidak pernah datang karena endpointnya 403.
     */
    #[Test]
    public function teknisi_tetap_bisa_membuka_detail_tiket_dengan_menu_hasil_instalasi(): void
    {
        $jabatan = $this->jabatan('Teknisi');
        $this->setMenu($jabatan, ['teknisi.hasil-instalasi']);

        Sanctum::actingAs($this->admin($jabatan, 'teknisi'));

        $res = $this->getJson('/api/installation-tickets/1');

        $this->assertNotSame(
            403,
            $res->status(),
            'Detail tiket 403 untuk teknisi: halamannya tampil tapi isinya '
            .'tidak pernah datang.',
        );
    }

    /**
     * Menu Arsip Instalasi yang dicabut TIDAK boleh menutup endpoint milik
     * menu lain yang masih dicentang.
     *
     * `installation-tickets` (bentuk polos) dipakai empat menu. Kalau
     * `arsip.instalasi` dicabut, endpointnya harus tetap terbuka selama
     * salah satu pemilik lainnya masih diizinkan.
     */
    #[Test]
    public function mencabut_satu_menu_tidak_menutup_endpoint_yang_juga_dimiliki_menu_lain(): void
    {
        $jabatan = $this->jabatan('Bendahara');
        $this->setMenu($jabatan, ['master.instalasi']);

        Sanctum::actingAs($this->admin($jabatan, 'admin'));

        $this->assertNotSame(
            403,
            $this->getJson('/api/installation-tickets')->status(),
            'Satu menu yang dicabut menutup endpoint yang dipakai menu lain '
            .'yang masih tercentang.',
        );
    }

    /**
     * Admin tetap bisa mengelola laporan gangguan.
     *
     * Endpoint laporan gangguan (`GET /trouble-reports`) dimiliki menu
     * `master.pelanggan` yang role-nya `admin`. Kalau prefix `trouble-reports`
     * ikut dipakai menu `pelanggan.lapor-gangguan` (role `pelanggan`), maka
     * satu-satunya pemiliknya akan dibuang dari daftar user admin -- dan
     * admin yang jabatan-nya dibatasi ikut kehilangan kemampuan mengelola
     * laporan gangguan.
     */
    #[Test]
    public function admin_tetap_bisa_mengelola_laporan_gangguan(): void
    {
        $jabatan = $this->jabatan('Bendahara');
        $this->setMenu($jabatan, ['master.pelanggan']);

        Sanctum::actingAs($this->admin($jabatan, 'admin'));

        $this->assertNotSame(
            403,
            $this->getJson('/api/trouble-reports')->status(),
            'Admin tidak bisa membuka daftar laporan gangguan.',
        );
    }

    /**
     * Pelanggan yang menu "Lapor Gangguan"-nya dicabut ikut kehilangan
     * endpoint-nya.
     *
     * `POST /pelanggan/trouble-report` milik `pelanggan.lapor-gangguan`
     * saja -- bukan milik menu portal pelanggan yang lain. Kalau menu lain
     * ikut memakainya, mencabut "Lapor Gangguan" tidak akan menutup apa pun.
     */
    #[Test]
    public function pelanggan_kehilangan_endpoint_lapor_gangguan_setelah_menunya_dicabut(): void
    {
        $jabatan = $this->jabatan('Pelanggan');
        $this->setMenu($jabatan, ['pelanggan.tagihan', 'pelanggan.riwayat']);

        Sanctum::actingAs($this->admin($jabatan, 'pelanggan'));

        $this->postJson('/api/pelanggan/trouble-report', [
            'trouble_type' => 'Pipa pecah',
            'description' => 'Air flowing di jalan',
            'contact_phone' => '081200000000',
        ])->assertStatus(403)
            ->assertJsonPath('code', 'MENU_ACCESS_DENIED');
    }

    /**
     * Pelanggan yang MASIH punya menu "Lapor Gangguan" tetap bisa melapor.
     *
     * Kontrol dari test di atas: yang diuji bukan hanya penolakan, tapi
     * juga bahwa halaman yang BOLEH dibuka masih berfungsi.
     */
    #[Test]
    public function pelanggan_yang_punya_menu_lapor_gangguan_tetap_bisa_melapor(): void
    {
        $jabatan = $this->jabatan('Pelanggan');
        $this->setMenu($jabatan, ['pelanggan.lapor-gangguan']);

        Sanctum::actingAs($this->admin($jabatan, 'pelanggan'));

        $res = $this->postJson('/api/pelanggan/trouble-report', [
            'trouble_type' => 'Pipa pecah',
            'description' => 'Air flowing di jalan',
            'contact_phone' => '081200000000',
        ]);

        $this->assertNotSame(
            403,
            $res->status(),
            'Pelanggan yang menu Lapor Gangguan-nya dicentang justru 403.',
        );
    }

    /**
     * Teknisi boleh membuka daftar tagihan dengan menu "Daftar Tagihan".
     *
     * `GET /monthly-bills` dipakai `teknisi.daftar-tagihan`. Kalau menu itu
     * tidak ikut memilikinya, satu-satunya pemiliknya adalah menu admin --
     * dan user teknisi tidak pernah boleh memegangnya.
     */
    #[Test]
    public function teknisi_tetap_bisa_membuka_daftar_tagihan(): void
    {
        $jabatan = $this->jabatan('Teknisi');
        $this->setMenu($jabatan, ['teknisi.daftar-tagihan']);

        Sanctum::actingAs($this->admin($jabatan, 'teknisi'));

        $this->assertNotSame(
            403,
            $this->getJson('/api/monthly-bills')->status(),
            'Daftar tagihan 403 untuk teknisi: halamannya tampil tapi isinya '
            .'tidak pernah datang.',
        );
    }

    /**
     * "Daftar Tagihan" tetap terbuka walau jabatannya tidak memuat key-nya.
     *
     * Menu itu `hidden` + `terkunci`: tidak ada checkbox-nya di panel pusat,
     * jadi tidak mungkin superadmin mencentangnya. Kalau `terkunci` tidak
     * ikut, jabatan yang sudah dikonfigurasi tanpa key ini akan mendapat
     * 403 di halaman yang justru dibuka dari kartu dashboard -- dan tidak
     * ada cara memperbaikinya dari UI.
     *
     * Ini juga yang membedakan `terkunci` dari `hidden` biasa: `hidden`
     * hanya menyembunyikan checkbox, sementara aksesnya tetap bergantung
     * pada daftar tersimpan.
     */
    #[Test]
    public function daftar_tagihan_teknisi_terbuka_walau_tidak_dicentang(): void
    {
        $jabatan = $this->jabatan('Teknisi');

        // Daftar yang sengaja TIDAK memuat `teknisi.daftar-tagihan`.
        $this->setMenu($jabatan, ['instalasi.pemakaian']);

        Sanctum::actingAs($this->admin($jabatan, 'teknisi'));

        $this->assertNotSame(
            403,
            $this->getJson('/api/monthly-bills')->status(),
            '`teknisi.daftar-tagihan` tidak `terkunci`: halaman Daftar Tagihan '
            .'dari dashboard jadi 403 dan tidak ada checkbox untuk membukanya.',
        );
    }

    /**
     * Endpoint TULIS tagihan bulanan tidak ikut terbuka oleh menu "daftar".
     *
     * `monthly-bills*` (wildcard di akhir) hanya cocok dengan
     * `/monthly-bills` itu sendiri. Jadi `teknisi.daftar-tagihan` yang
     * memakai prefix itu TIDAK ikut membuka `/monthly-bills/{id}/pay` dan
     * `/monthly-bills/{id}` -- endpoint tulis milik admin.
     *
     * Yang diuji di sini bukan soal siapa yang boleh -- itu
     * urusan `CheckRole` -- tapi soal BENTUK prefix: kalau `monthly-bills*`
     * berubah menjadi `monthly-bills` polos, `teknisi.daftar-tagihan` akan
     * ikut menjadi pemilik endpoint tulis itu.
     */
    #[Test]
    public function menu_daftar_tagihan_tidak_jadi_pemilik_endpoint_tulis_tagihan(): void
    {
        $svc = app(MenuAccessService::class);

        $pemilik = [];

        foreach ($svc->catalog() as $item) {
            foreach ((array) ($item['api'] ?? []) as $prefix) {
                $pre = '/'.ltrim($prefix, '/');

                foreach (['/monthly-bills/12/pay', '/monthly-bills/12'] as $path) {
                    $cocok = false;

                    if ($path === $pre) {
                        $cocok = true;
                    } elseif (str_starts_with($path, $pre.'/')) {
                        $cocok = true;
                    } elseif (str_contains($pre, '*') && fnmatch($pre, $path, FNM_PATHNAME)) {
                        $cocok = true;
                    }

                    if ($cocok) {
                        $pemilik[(string) $item['key']][] = $path;
                    }
                }
            }
        }

        $this->assertArrayNotHasKey(
            'teknisi.daftar-tagihan',
            $pemilik,
            '`teknisi.daftar-tagihan` ikut menjadi pemilik endpoint tulis '
            .'tagihan -- mencabut menu lain tidak akan menutupnya.',
        );

        $this->assertArrayNotHasKey(
            'arsip.tagihan',
            $pemilik,
            '`arsip.tagihan` ikut menjadi pemilik endpoint tulis tagihan -- '
            .'menyalin halaman Arsip akan membuka jalur pembayaran.',
        );
    }

    // ---- Katalog ---------------------------------------------------------

    /**
     * Flag `tanpa_sidebar` harus ada di respons katalog.
     *
     * Menu `tanpa_sidebar` adalah halaman yang dibuka dari DASHBOARD role-nya,
     * bukan dari sidebar -- tapi tetap boleh dicentang di panel pusat Hak
     * Akses, jadi superadmin masih bisa mencabut haknya.
     *
     * Bedanya dari `hidden` itu penting, dan tidak bisa dibiarkan jadi
     * kebetulan: `hidden` ALSO disembunyikan dari daftar centang di panel
     * pusat (`CentralHakAksesIndex.vue` menyaring `hidden`), sehingga menu
     * `hidden` tidak bisa dicabut sama sekali.
     *
     * Kalau flag ini hilang dari respons, frontend tidak tahu mana yang
     * boleh disembunyikan -- dan semua menunya muncul lagi.
     */
    #[Test]
    public function katalog_mengirim_flag_tanpa_sidebar(): void
    {
        Sanctum::actingAs($this->admin(null));

        $res = $this->getJson('/api/menu-access/catalog')->assertOk();

        $items = collect($res->json('data.items'))->keyBy('key');

        // Menu yang memang `tanpa_sidebar` di katalog.
        foreach (['survey.daftar', 'teknisi.dashboard'] as $key) {
            $this->assertTrue(
                $items[$key]['tanpa_sidebar'] ?? false,
                "`{$key}` harus ditandai `tanpa_sidebar`.",
            );
        }

        // Menu yang TIDAK: flagnya harus `false`, bukan sekadar tidak ada.
        // Tidak adanya key akan membuat frontend membacanya `undefined`,
        // yang secara praktis sama dengan `false` -- tapi hanya selama
        // tidak ada kode yang memeriksa `=== true`.
        $this->assertFalse(
            $items['survey.create']['tanpa_sidebar'] ?? true,
            '`survey.create` tidak boleh `tanpa_sidebar`.',
        );

        /*
         * "Daftar Tagihan" teknisi sudah TIDAK `tanpa_sidebar` lagi: ia
         * `hidden`, yang juga berarti tidak muncul di sidebar. Membiarkan
         * `tanpa_sidebar` di sini akan menandainya "bisa dicentang di panel
         * pusat" -- padahal justru itu yang dihapus.
         */
        $this->assertFalse(
            $items['teknisi.daftar-tagihan']['tanpa_sidebar'] ?? true,
            '`teknisi.daftar-tagihan` tidak boleh `tanpa_sidebar` -- ia `hidden`, '
            .'jadi tidak boleh tampil di daftar centang panel pusat.',
        );
    }

    /**
     * "Hasil Instalasi" TIDAK boleh muncul di daftar centang Hak Akses.
     *
     * Ada dua menu untuk teknisi: Dashboard dan Input Pemakaian Air.
     * "Hasil Instalasi" bukan salah satunya -- halaman itu bagian dari
     * pekerjaan Dashboard (tombol tugas di kartu), bukan pekerjaan terpisah.
     *
     * Flag `hidden` yang dipakai, bukan `tanpa_sidebar`: `hidden` juga
     * menyembunyikannya dari daftar centang panel pusat, jadi superadmin
     * tidak akan melihat checkbox yang tidak ada artinya. Endpoint-nya
     * sendiri tetap milik menu ini, jadi halamannya tetap hidup -- yang
     * hilang cuma tempatnya untuk dicentang.
     */
    #[Test]
    public function hasil_instalasi_tidak_muncul_di_daftar_centang_teknisi(): void
    {
        Sanctum::actingAs($this->admin(null));

        $res = $this->getJson('/api/menu-access/catalog')->assertOk();

        $items = collect($res->json('data.items'))->keyBy('key');

        $this->assertTrue(
            $items['teknisi.hasil-instalasi']['hidden'] ?? false,
            '`teknisi.hasil-instalasi` harus `hidden` -- desain per-role teknisi '
            .'hanya punya dua menu: Dashboard dan Input Pemakaian Air.',
        );

        $this->assertFalse(
            $items['teknisi.hasil-instalasi']['tanpa_sidebar'] ?? true,
            '`teknisi.hasil-instalasi` tidak boleh `tanpa_sidebar` -- kalau begitu '
            .'ia tetap muncul di daftar centang Hak Akses.',
        );
    }

    /**
     * Flag `top_level` harus ada di respons katalog.
     *
     * Menu `top_level` berdiri sendiri di sidebar, di luar group-nya.
     * "Buat Survey Baru" milik surveyor begitu: di desain per-role ia link
     * tingkat atas, bukan anak group "Survey".
     */
    #[Test]
    public function katalog_mengirim_flag_top_level(): void
    {
        Sanctum::actingAs($this->admin(null));

        $res = $this->getJson('/api/menu-access/catalog')->assertOk();

        $items = collect($res->json('data.items'))->keyBy('key');

        $this->assertTrue(
            $items['survey.create']['top_level'] ?? false,
            '`survey.create` harus ditandai `top_level`.',
        );

        $this->assertFalse(
            $items['instalasi.pemakaian']['top_level'] ?? true,
            '`instalasi.pemakaian` tidak boleh `top_level` -- ia anak group "Master Instalasi".',
        );
    }

    /**
     * Flag `group_tetap` harus ada di respons katalog.
     *
     * Sidebar mem-flatten group yang cuma punya satu anak menjadi link
     * langsung. `group_tetap` membatalkan itu, karena susunan menu itu
     * bagian dari desain per-role: teknisi membuka group "Master
     * Instalasi", bukan langsung "Input Pemakaian Air".
     */
    #[Test]
    public function katalog_mengirim_flag_group_tetap(): void
    {
        Sanctum::actingAs($this->admin(null));

        $res = $this->getJson('/api/menu-access/catalog')->assertOk();

        $items = collect($res->json('data.items'))->keyBy('key');

        $this->assertTrue(
            $items['instalasi.pemakaian']['group_tetap'] ?? false,
            '`instalasi.pemakaian` harus ditandai `group_tetap`.',
        );

        $this->assertFalse(
            $items['survey.create']['group_tetap'] ?? true,
            '`survey.create` tidak boleh `group_tetap` -- ia `top_level`.',
        );
    }

    /**
     * Menu `tanpa_sidebar` tetap bisa dicabut di panel pusat Hak Akses.
     *
     * Ini yang membedakan `tanpa_sidebar` dari `hidden`. Kalau menu ini
     * ikut hilang dari daftar centang, superadmin tidak akan pernah bisa
     * mencabut hak aksesnya -- dan satu-satunya cara menutup endpoint-nya
     * adalah mencabut menu lain yang kebetulan memakai endpoint yang sama.
     */
    #[Test]
    public function menu_tanpa_sidebar_tetap_bisa_dicabut_di_panel_pusat(): void
    {
        $jabatan = $this->jabatan('Surveyor');

        // Simpan daftar yang TIDAK memuat `survey.daftar`.
        $this->setMenu($jabatan, ['survey.create']);

        Sanctum::actingAs($this->admin(null));

        $menu = collect($this->getJson('/api/menu-access')
            ->assertOk()
            ->json('data')['0']['menu']);

        $daftar = $menu->keyBy('key');

        $this->assertFalse(
            $daftar['survey.daftar']['selected'] ?? true,
            '`survey.daftar` harus tidak tercentang setelah dicabut.',
        );

        // Dan `hidden` TIDAK boleh ikut hilang dari daftar -- kalau iya,
        // ia tidak akan pernah bisa dicabut.
        $this->assertFalse(
            $daftar['survey.daftar']['hidden'] ?? true,
            '`survey.daftar` tidak boleh `hidden` -- `hidden` tidak bisa dicabut dari panel pusat.',
        );

        $this->assertTrue(
            $daftar['survey.daftar']['tanpa_sidebar'] ?? false,
            '`survey.daftar` harus `tanpa_sidebar`.',
        );
    }

    /**
     * Susunan sidebar per role harus sama dengan desain pamsides-v2.
     *
     * Yang diperiksa di sini adalah KATALOG -- sumber kebenarannya --
     * bukan hasil render frontend (itu diuji di
     * `frontend/test/sidebarSusunanPerRole.test.js`).
     *
     * Yang dijaga: menu yang TIDAK ADA di desain pamsides-v2 tidak boleh
     * muncul di sidebar role itu, dan menu yang ADA harus punya nama yang
     * sama persis.
     */
    #[Test]
    public function menu_per_role_sesuai_desain_pamsides(): void
    {
        $katalog = $this->katalog();

        /*
         * Sidebar teknisi di pamsides-v2: satu group "Master Instalasi"
         * dengan satu anak, "Input Pemakaian Air".
         *
         * Nama group, nama anak, dan `group_tetap`-nya diperiksa karena
         * ketiganya menentukan bentuk sidebar -- lihat test frontend.
         */
        $pemakaian = $katalog['instalasi.pemakaian'];

        $this->assertSame('Input Pemakaian Air', $pemakaian['label']);
        $this->assertSame('Master Instalasi', $pemakaian['group']);
        $this->assertTrue($pemakaian['group_tetap'] ?? false);
        $this->assertSame(['teknisi'], $pemakaian['roles']);

        /*
         * Sidebar surveyor: satu menu tingkat atas, "Buat Survey Baru".
         */
        $buatSurvey = $katalog['survey.create'];

        $this->assertSame('Buat Survey Baru', $buatSurvey['label']);
        $this->assertSame('plus-circle', $buatSurvey['icon']);
        $this->assertTrue($buatSurvey['top_level'] ?? false);
        $this->assertSame(['surveyor'], $buatSurvey['roles']);

        /*
         * Menu yang HANYA ada di astaloka tidak boleh jadi entri sidebar
         * role itu. Halamannya tetap boleh dibuka (dan tetap bisa
         * dicabut), tapi tidak boleh muncul sebagai menu.
         */
        foreach (['survey.daftar'] as $key) {
            $this->assertTrue(
                $katalog[$key]['tanpa_sidebar'] ?? false,
                "`{$key}` harus `tanpa_sidebar` -- desain per-role tidak memuatnya di sidebar.",
            );
        }

        /*
         * "Daftar Tagihan" teknisi dibuka dari KARTU di dashboard, bukan
         * dari menu sidebar -- jadi ia juga tidak boleh muncul di daftar
         * centang panel pusat.
         *
         * `terkunci` wajib menyertainya: endpoint `monthly-bills*` hanya
         * dimiliki menu ini untuk role teknisi. Kalau bisa dicabut (atau
         * tidak selalu diizinkan), halaman yang dibuka dari dashboard itu
         * akan 403 tanpa ada checkbox untuk memperbaikinya.
         */
        $daftarTagihan = $katalog['teknisi.daftar-tagihan'];

        $this->assertTrue(
            $daftarTagihan['hidden'] ?? false,
            '`teknisi.daftar-tagihan` harus `hidden` -- halamannya dibuka dari '
            .'dashboard, bukan dari entri sidebar, jadi tidak ada menu untuk dicentang.',
        );

        $this->assertTrue(
            $daftarTagihan['terkunci'] ?? false,
            '`teknisi.daftar-tagihan` harus `terkunci` -- endpoint `monthly-bills*` '
            .'hanya dimilikinya untuk role teknisi, dan checkbox-nya sudah tidak ada.',
        );

        /*
         * "Hasil Instalasi" tidak boleh jadi entri sidebar, DAN tidak boleh
         * muncul di daftar centang Hak Akses.
         *
         * Dua menu untuk teknisi: Dashboard dan Input Pemakaian Air.
         * "Hasil Instalasi" bukan salah satunya, jadi centangnya tidak
         * boleh menawarkan sesuatu yang tidak ada artinya.
         */
        $this->assertTrue(
            $katalog['teknisi.hasil-instalasi']['hidden'] ?? false,
            '`teknisi.hasil-instalasi` harus `hidden` -- daftar teknisi di panel '
            .'pusat hanya boleh berisi dua menu: Dashboard dan Input Pemakaian Air.',
        );

        /*
         * "Dashboard" teknisi -- entri KETIGA yang diminta.
         *
         * `terkunci` karena ini halaman tujuan setelah login: kalau boleh
         * dicabut, `allowsPath('/app/teknisi')` akan menolak dan router guard
         * akan mengarahkan teknisi ke `/app/teknisi` juga -- redirect ke
         * dirinya sendiri.
         */
        $dashboardTeknisi = $katalog['teknisi.dashboard'];

        $this->assertSame('Dashboard', $dashboardTeknisi['label']);
        $this->assertSame('Utama', $dashboardTeknisi['group']);
        $this->assertSame(['teknisi'], $dashboardTeknisi['roles']);
        $this->assertTrue(
            $dashboardTeknisi['terkunci'] ?? false,
            '`teknisi.dashboard` harus `terkunci` -- halaman tujuan setelah login '
            .'tidak boleh bisa dicabut dari panel pusat.',
        );

        /*
         * "Input Tagihan" milik admin saja.
         *
         * Sebelumnya `roles` juga memuat `teknisi`, dan itu membuat
         * sidebar teknisi punya DUA menu input meter. Desain per-role
         * teknisi hanya punya "Input Pemakaian Air".
         */
        $this->assertSame(['admin'], $katalog['tagihan.input']['roles']);
    }

    #[Test]
    public function setiap_menu_punya_path_yang_ada_di_router(): void
    {
        /*
         * Katalog dan router frontend harus sinkron. Menu yang path-nya
         * tidak ada di router akan tampil di sidebar dan lead ke NotFound
         * -- dan tidak akan pernah ketahuan karena "menuju halaman kosong"
         * tidak terlihat bedanya dari bug lain.
         *
         * Daftar di bawah dibaca dari `frontend/src/router/index.js`.
         */
        $router = file_get_contents(base_path('../frontend/src/router/index.js'));

        $this->assertNotFalse($router, 'File router frontend tidak terbaca.');

        /*
         * Semua path yang hilang dikumpulkan dulu, baru dilaporkan SEKALI.
         *
         * Sebelumnya assertion di dalam loop, jadi test berhenti di menu
         * pertama yang bermasalah. Padahal katalognya bisa punya beberapa
         * menu mati sekaligus, dan semuanya butuh diperbaiki -- tapi yang
         * terlihat hanya satu per run. Setelah menu pertama dibenahi,
         * menu kedua baru muncul, dan itu chewed satu putaran penuh tanpa
         * ada yang realizes ada masalah kedua sejak awal.
         *
         * Mengumpulkan juga membuat pesannya bisa dibaca: satu daftar seluruh
         * menu yang perlu route-nya, bukan tebakan "sudah satu, coba lagi".
         */
        $hilang = [];

        foreach (app(MenuAccessService::class)->catalog() as $item) {
            $path = (string) $item['path'];

            // `/app` sendiri tidak diuji. Ia adalah akar layout, bukan
            // route anak: di router ia tertulis `path: '/app'` sebagai
            // `path: ''` yang di dalam children-nya. Dicocokkan sebagai
            // substring, kata `app` match hampir di mana saja di file --
            // termasuk pada nama komponen -- jadi hasilnya selalu benar
            // tanpa memeriksa apa pun. Keberadaannya sudah dijamin oleh
            // `setiap_menu_memiliki_path_dan_label` di test unit.
            if (rtrim($path, '/') === '/app') {
                continue;
            }

            // Route di router ditulis sebagai path RELATIF terhadap
            // layout `/app` (`path: 'transaksi/jurnal-umum'`), jadi
            // path penuh dari katalog harus dibuang `/app/`-nya dulu.
            //
            // Path dipakai utuh, bukan hanya segmen pertamanya:
            // `instalasi/register` dan `instalasi/status` sama-sama
            // diawali `instalasi`, jadi mencocokkan satu segmen akan
            // membiarkan kedua salahnya lolos.
            $needle = trim(str_replace('/app/', '', $path), '/');

            // Path ber-parameter (`/app/kelas-biaya/config/:id`) tidak
            // diuji di sini; yang diuji path menu yang tertera di sidebar.
            if ($needle === '' || str_contains($needle, ':')) {
                continue;
            }

            /*
             * Pencocokan bersifat case-SENSITIVE, dan itu memang yang
             * dimaksud. Vue Router membandingkan path dengan pitah kasar,
             * jadi `path: 'Pelaporan'` TIDAK melayani tautan ke
             * `/app/pelaporan` yang dituangkan sidebar dan `menu_access.php`
             * -- tautannya mendarat di route NotFound, dan halaman
             * Pelaporan tidak pernah terbuka.
             *
             * Justru karena itu test ini berguna: huruf besar yang
             * tidak disengaja di router tidak terlihat dari mana pun
             * selain dari sini.
             */
            if (! str_contains($router, "path: '{$needle}'")) {
                $hilang[] = sprintf('`%s` -> /%s (cari: path: \'%s\')', $item['key'], $needle, $needle);
            }
        }

        $this->assertSame(
            [],
            $hilang,
            "Menu berikut menunjuk path yang tidak ada di router:\n - ".implode("\n - ", $hilang),
        );
    }
}
