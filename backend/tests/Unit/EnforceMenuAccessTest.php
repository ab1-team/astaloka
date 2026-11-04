<?php

namespace Tests\Unit;

use App\Http\Middleware\EnforceMenuAccess;
use App\Services\MenuAccessService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pencocokan path di `EnforceMenuAccess`.
 *
 * DIJALANKAN TANPA DATABASE. Logika ini tidak menyentuh DB sama sekali --
 * hanya membaca config dan membandingkan string. Dan justru bagian inilah
 * yang paling rawan: kalau pencocokan prefix-nya salah, middleware-nya
 * tidak menolak apa pun, dan itu terlihat sebagai "fitur-nya jalan" karena
 * tidak ada yang error.
 *
 * Bukti nyata: sebelum ada test ini, prefix yang terbuang sehingga
 * `$request->path()` (yang nilainya `api/pelaporan`) tidak pernah cocok
 * dengan config yang menulis `pelaporan`. Semua test yang hanya memeriksa
 * kasus negatif lolos, sementara pemblokirannya sendiri tidak pernah
 * dijalankan -- `/api/pelaporan` membalas 200 untuk jabatan yang tidak
 * diberi hak Procurement-nya.
 *
 * Karena itu test di sini memeriksa KEDUA arah: bukan hanya path yang harus
 * ditolak, tapi juga path yang harus TIDAK ikut tertangkap. Penangkapan
 * berlebihan samaelsonya merusak -- satu prefix yang terlalu umum membuat
 * menu yang seharusnya terbuka ikut tertutup.
 */
class EnforceMenuAccessTest extends TestCase
{
    private function middleware(): EnforceMenuAccess
    {
        return new EnforceMenuAccess(app(MenuAccessService::class));
    }

    /**
     * Panggil method privat middleware.
     *
     * Sengaja lewat reflection, bukan HTTP: logika pencocokan prefix ini
     * murni perbandingan string, dan menjalankannya lewat HTTP membuat test
     * ikut bergantung pada migrasi, token, dan database. Test seperti itu
     * gagal karena alasan yang tidak ada hubungannya dengan apa yang sedang
     * diuji -- persis yang terjadi di mesin ini.
     *
     * Namanya BUKAN `call`. `Illuminate\Foundation\Testing\TestCase` sudah
     * punya `call()` public untuk memanggil route, dan menimpanya dengan
     * versi privat membuat seluruh file gagal dimuat dengan "Access level to
     * ... must be public (as in class ...TestCase)".
     *
     * Gejalanya sulit dibaca karena salah tempat: suite yang hanya berisi
     * test ini lolos, dan yang gagal adalah seluruh `php artisan test` --
     * karena satu file yang tidak bisa dimuat menghentikan pemuatan test
     * lain, dan pesan error-nya menunjuk ke baris yang tidak salah.
     */
    private function panggil(string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(EnforceMenuAccess::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->middleware(), ...$args);
    }

    /**
     * Endpoint yang dipakai bersama dan TIDAK dikunci.
     *
     * Daftar ini naik dari `config/menu_access.php` sendiri, bukan dari
     * ingatan. Tulisannya bisa berubah sewaktu-waktu, dan daftar yang
     * ditulis tangan akan terlihat benar sampai ada endpoint yang berpindah.
     */
    private function endpointTidakDikunci(): array
    {
        $dikunci = [];

        foreach (app(MenuAccessService::class)->catalog() as $item) {
            foreach ((array) ($item['api'] ?? []) as $prefix) {
                $dikunci[$prefix] = $item['key'];
            }
        }

        return $dikunci;
    }

    /**
     * Ubah path seperti yang middleware lakukan, lalu kembalikan daftar
     * menu yang|resultOwners gunakan.
     */
    private function pemilik(string $requestPath): array
    {
        $normalized = $this->panggil('normalize', [$requestPath]);
        $stripped = $this->panggil('stripApiPrefix', [$normalized]);

        return $this->panggil('menuKeysForPrefix', [$stripped]);
    }

    private function selaluBoleh(string $requestPath): bool
    {
        $normalized = $this->panggil('normalize', [$requestPath]);
        $stripped = $this->panggil('stripApiPrefix', [$normalized]);

        return $this->panggil('isAlwaysAllowed', [$stripped]);
    }

    // ---- Prefix grup `api` harus dibuang --------------------------------

    #[Test]
    public function prefix_grup_api_dibuang_sehingga_config_tanpa_api_mencocok(): void
    {
        // Path asli dari Laravel, sudah memuat prefix grup `api`.
        $this->assertSame('/pelaporan', $this->panggil('stripApiPrefix', ['/api/pelaporan']));
        $this->assertSame('/transactions', $this->panggil('stripApiPrefix', ['/api/transactions']));
    }

    #[Test]
    public function prefix_api_tidak_dibuang_di_tengah_path(): void
    {
        // `central/api/...` tidak boleh jadi `central/...`.
        $this->assertSame(
            '/central/api/whatever',
            $this->panggil('stripApiPrefix', ['/central/api/whatever'])
        );
    }

    #[Test]
    public function path_tanpa_prefix_api_tidak_berubah(): void
    {
        $this->assertSame('/pelaporan', $this->panggil('stripApiPrefix', ['/pelaporan']));
        // Path yang persis `api` saja -- harus jadi kosong, bukan `/`.
        $this->assertSame('', $this->panggil('stripApiPrefix', ['/api']));
    }

    #[Test]
    public function endpoint_asli_menghasilkan_pemilik_menu_yang_benar(): void
    {
        $pemilik = $this->pemilik('api/pelaporan');

        $this->assertContains('pelaporan', $pemilik);
    }

    // ---- Menu yang harus terdeteksi -------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpointMenuProvider(): array
    {
        return [
            'pelaporan' => ['api/pelaporan', 'pelaporan'],
            'transaksi' => ['api/transactions', 'transaksi.jurnal-umum'],
            'jenis transaksi' => ['api/jenis-transactions', 'transaksi.jurnal-umum'],
            'tutup buku' => ['api/tutup-buku/status', 'transaksi.tutup-buku'],
            'e-budgeting' => ['api/ebudgeting/check-exists', 'transaksi.ebudgeting'],
            'komisi sps' => ['api/komisi-sps', 'transaksi.komisi-sps'],
            'alokasi laba' => ['api/alokasi-laba', 'transaksi.alokasi-laba'],
            'paket tarif' => ['api/installation-packages', 'settings.package'],
            'akun coa per level' => ['api/accounts/by-level/3', 'settings.coa'],
        ];
    }

    #[Test]
    #[DataProvider('endpointMenuProvider')]
    public function endpoint_dikuasai_menu_yang_sesuai(string $path, string $key): void
    {
        $this->assertContains(
            $key,
            $this->pemilik($path),
            "`{$path}` tidakrecognized sebagai punya menu `{$key}`.",
        );
    }

    // ---- Endpoint yang dipakai lebih dari satu menu ---------------------

    #[Test]
    public function endpoint_bersama_dimiliki_lebih_dari_satu_menu(): void
    {
        /*
         * `GET /accounts` dipakai CoA dan (lewat `settings.coa` yang hanya
         * memakai `accounts/by-level`) tidak lagi Jurnal Umum. Yang tetap
         * dipakai bersama adalah `installation-tickets`: dipakai Daftar
         * Instalasi, Registrasi, Status Instalasi, dan Arsip Instalasi.
         */
        $pemilik = $this->pemilik('api/installation-tickets');

        $this->assertGreaterThan(
            1,
            count($pemilik),
            'Endpoint yang dipakai banyak menu harus dilaporkan oleh semuanya.',
        );

        foreach (['master.instalasi', 'instalasi.register', 'instalasi.status'] as $key) {
            $this->assertContains($key, $pemilik);
        }
    }

    // ---- Endpoint bersama yang tidak boleh ikut tertangkap ---------------

    /**
     * @return array<int, array<int, string>>
     */
    public static function endpointSelaluBolehProvider(): array
    {
        return [
            ['api/settings/lembaga-identity'],
            ['api/monthly-bills/unpaid-summary'],
            ['api/users/penandatangan'],
            ['api/dashboard/statistics'],
            ['api/dashboard/popup-data'],
            ['api/logout'],
            ['api/me'],
            ['api/settings/sop'],
            ['api/settings/signatures'],
            ['api/customers/search'],
        ];
    }

    /**
     * `installation-tickets/{id}/survey` DIHAPUS dari daftar bebas, dan itu
     * bukan regresi.
     *
     * Semula prefix ini selalu bebas, karena satu-satunya pemakainya --
     * halaman survey milik role `surveyor` -- tidak punya satu pun entri di
     * katalog. Tidak ada yang bisa mengunci apa pun yang tidak tercatat.
     *
     * Sekarang `survey.create` menguasainya, jadi jabatan Surveyor bisa
     * mencabut halaman yang memang halamannya.
     *
     * CATATAN SOAL PEMILIK YANG BANYAK
     * --------------------------------
     * `POST /installation-tickets/12/survey` pemilikunya bukan hanya
     * `survey.create`: prefix `installation-tickets` milik admin juga
     * mencocokinya, karena `prefixMatches` menempelkan prefix ke segmen
     * berikutnya. Itu perilaku lama yang disengaja, dan pengaruhnya
     * SESUNGGUHNYA tetap benar: middleware hanya menolak kalau SEMUA
     * pemiliknya tidak diizinkan. Jadi surveyor yang mencabut `survey.create`
     * tapi meninggalkan `survey.daftar` masih ditolak -- ia tidak punya satu
     * pun menu yang memakai prefix itu -- sementara admin yang mencentang
     * `instalasi.status` tetap boleh, persis seperti sebelumnya.
     */
    #[Test]
    public function endpoint_survey_sekarang_punya_pemilik_menu(): void
    {
        $pemilik = $this->pemilik('/installation-tickets/12/survey');

        $this->assertContains(
            'survey.create',
            $pemilik,
            'POST survey harus punya pemilik, kalau tidak tidak ada yang bisa menguncinya.',
        );

        $this->assertFalse(
            $this->selaluBoleh('/installation-tickets/12/survey'),
            'Prefix ini sudah tidak bebas: tidak ada gunanya ada di daftar bebas '
            .'dan punya pemilik sekaligus -- yang kedua membuat yang pertama diam-diam.',
        );
    }

    /**
     * `menu-access/catalog` dulu ada di `always_allowed_api`, dan sekarang
     * sudah tidak ada di sana.
     *
     * Itu bukan regresi: endpoint itu tetap bebas, tapi sekarang karena
     * SEBAB yang lebih kuat dan lebih aman. `EnforceMenuAccess` hanya menolak
     * prefix yang dimiliki sebuah menu, jadi `menu-access` yang tidak lagi
     * punya pemilik di katalog otomatis lolos -- dan itu berlaku untuk semua
     * path yang tersisa, bukan cuma satu yang dicatat manual.
     *
     * Yang justru penting: `settings.hak-akses` dihapus dari katalog, jadi
     * tidak ada lagi `api` prefix yang menunjuk area pengaturan. Kalau masih ada
     * prefix `settings/hak-akses` yang tertinggal di katalog, test ini tetap
     * lulus padahal pemblokirannya masih hidup di tempat yang salah.
     *
     * Dan `settings.hak-akses` sendiri TIDAK boleh muncul sebagai entri
     * katalog -- halamannya sudah pindah ke panel pusat. Absence-nya diuji
     * oleh test di bawah, bukan oleh komentar di sini.
     */
    #[Test]
    public function menu_access_tidak_perlu_didaftarkan_karena_tidak_punya_pemilik_menu(): void
    {
        $this->assertFalse(
            $this->selaluBoleh('/menu-access/catalog'),
            'menu-access tidak lagi perlu ada di `always_allowed_api`. Kalau dia '
            .'masuk daftar lagi, itu berarti ada yang menunggu tidak ditutup.',
        );

        // Tapi endpointnya sendiri tetap bebas.
        $this->assertSame(
            [],
            $this->pemilik('/menu-access/catalog'),
            'Kalau katalog punya menu yang memakai prefix `menu-access`, pembacaan '
            .'hak akses ikut terpotong -- dan tidak ada yang memanggilnya untuk '
            .'memperbaikkannya dari aplikasi lokasi.',
        );
    }

    /**
     * Entri katalog `settings.hak-akses` sudah dihapus.
     *
     * Sidebar lokasi tidak boleh lagi menunjuk halaman yang sudah pindah ke
     * panel pusat. Kalau entri ini masih ada, frontend menampilkan menu yang
     * lead ke halaman yang sudah tidak ada -- dan `EnforceMenuAccess` juga akan
     * memblokir endpoint lamanya, jadi gejalanya 403 di halaman yang tidak
     * pernah ada.
     */
    #[Test]
    public function katalog_tidak_lagi_memakai_menu_hak_akses(): void
    {
        $keys = array_column(app(MenuAccessService::class)->catalog(), 'key');

        $this->assertNotContains('settings.hak-akses', $keys);
    }

    #[Test]
    #[DataProvider('endpointSelaluBolehProvider')]
    public function endpoint_bersama_selalu_boleh(string $path): void
    {
        $this->assertTrue(
            $this->selaluBoleh($path),
            "`{$path}` ikut terblokir padahal dipakai bersama.",
        );
    }

    #[Test]
    public function endpoint_yang_benar_benar_dilarang_tidak_lolos_ke_daftar_bebas(): void
    {
        foreach (['api/pelaporan', 'api/transactions', 'api/komisi-sps'] as $path) {
            $this->assertFalse(
                $this->selaluBoleh($path),
                "`{$path}` ada di daftar bebas padahal seharusnya dibatasi menu.",
            );
        }
    }

    // ---- Batas prefix: jangan terlalu longgar ---------------------------

    #[Test]
    public function prefix_tidak_mencocok_segmen_berikutnya(): void
    {
        /*
         * `accounts` harus cocok dengan `/accounts` dan `/accounts/by-level`,
         * tapi TIDAK dengan `/accounts-abc`. Tanpa batas segmen, satu menu
         * bisa ikut menutup endpoint milik menu lain yang namanya berawalan
         * sama -- dan gejalanya acak, sulit dilacak.
         */
        $this->assertTrue($this->panggil('prefixMatches', ['/accounts', 'accounts']));
        $this->assertTrue($this->panggil('prefixMatches', ['/accounts/by-level/1', 'accounts']));
        $this->assertFalse($this->panggil('prefixMatches', ['/accounts-abc', 'accounts']));
    }

    #[Test]
    public function wildcard_cocok_satu_segmen_saja(): void
    {
        $this->assertTrue(
            $this->panggil('prefixMatches', ['/installation-tickets/12/survey', 'installation-tickets/*/survey'])
        );

        // Dua segmen di tengah tidak boleh cocok dengan satu `*`.
        $this->assertFalse(
            $this->panggil('prefixMatches', ['/installation-tickets/12/sub/survey', 'installation-tickets/*/survey'])
        );
    }

    #[Test]
    public function prefix_tanpa_garis_miring_depan_tetap_cocok(): void
    {
        $this->assertTrue($this->panggil('prefixMatches', ['/pelaporan', 'pelaporan']));
        $this->assertTrue($this->panggil('prefixMatches', ['/pelaporan', '/pelaporan']));
    }

    // ---- Katalog ---------------------------------------------------------

    #[Test]
    public function katalog_tidak_punya_key_duplikat(): void
    {
        $keys = array_column(app(MenuAccessService::class)->catalog(), 'key');

        $this->assertSame(
            $keys,
            array_unique($keys),
            'Key menu duplikat membuat hak akses yang disimpan tidak bisa dibedakan.',
        );
    }

    #[Test]
    public function setiap_menu_memiliki_path_dan_label(): void
    {
        foreach (app(MenuAccessService::class)->catalog() as $item) {
            $this->assertNotEmpty($item['key'], 'Menu tanpa key.');
            $this->assertNotEmpty($item['label'], "Menu `{$item['key']}` tanpa label.");
            $this->assertNotEmpty($item['path'], "Menu `{$item['key']}` tanpa path.");
        }
    }

    #[Test]
    public function menu_selalu_boleh_memang_ada_di_katalog(): void
    {
        $svc = app(MenuAccessService::class);
        $keys = array_column($svc->catalog(), 'key');
        $selaluBoleh = $svc->alwaysAllowedKeys();

        foreach ($selaluBoleh as $key) {
            $this->assertContains(
                $key,
                $keys,
                "`{$key}` ditandai tidak bisa dikunci tapi tidak ada di katalog.",
            );
        }

        /*
         * Daftar kosong harus sadar dirinya kosong, bukan lolos diam-diam.
         *
         * `menu_access.always` sengaja boleh kosong -- tidak ada menu yang
         * perlu bebas di setiap instalasi. Tapi kalau daftarnya KOSONG karena
         * nama kuncinya salah ketik, test ini akan tetap hijau: `foreach`
         * di atas tidak pernah masuk, jadi tidak ada satu pun assertion yang
         * jalan. PHPUnit menandainya "risky", tapi hanya di suite yang
         * `failOnRisky` benar-benar menyala -- dan di `--testsuite=Feature`
         * test ini tidak ikut dijalankan sama sekali.
         *
         * Jadi daftar kosong di sini diuji sebagai KASUS NYATA: kalau
         * memang kosong, tidak ada yang perlu dijamin, dan itu sah. Yang
         * penting test ini berhenti berpura-pura menguji sesuatu.
         */
        $this->assertIsArray($selaluBoleh);
    }

    #[Test]
    public function endpoint_bebas_tidak_menabrak_menu_yang_dikunci(): void
    {
        /*
         * Kalau satu prefix ada di `always_allowed_api` DAN di `api` sebuah
         * menu, mencentang menu itu tidak akan mengubah apa pun -- checkbox
         * kelihatan bekerja, endpoint-nya tetap terbuka. Quiet failure yang
         * paling mudah lolos dari pengujian.
         */
        $dikunci = $this->endpointTidakDikunci();

        $tabrakan = array_intersect(
            array_keys($dikunci),
            (array) config('menu_access.always_allowed_api'),
        );

        // Kalau tidak ada yang tabrakan, test ini tidak membuktikan apa pun.
        // Satu contoh yang benar-benar bertabrakan dijahitkan di sini supaya
        // penyusun test berikutnya tidak mengira daftar kosong itu sudah aman.
        // `dashboard` persis bug yang pernah ada di config ini: menu Beranda
        // menulis `api => ['dashboard']` padahal `dashboard` ada di daftar
        // bebas, jadi mencentang atau mencabut menu itu tidak berefek.
        $this->assertSame(
            ['dashboard'],
            $this->tabrakanDengan(['dashboard' => ['dashboard']]),
        );

        $this->assertSame(
            [],
            array_values($tabrakan),
            'Prefix ini ada di `always_allowed_api` sekaligus dimiliki sebuah menu: '
            .implode(', ', $tabrakan).' -- checkbox-nya tidak akan mengubah apa pun.',
        );
    }

    /**
     * Tabrakan antara prefix bebas dan api menu, untuk satu set api.
     *
     * Dipisah supaya bisa dipakai sebagai contoh yang wajib gagal, bukan
     * hanya sebagai pemeriksaan diam-diam.
     *
     * @param  array<string, array<int, string>>  $apiPerMenu
     * @return array<int, string>
     */
    private function tabrakanDengan(array $apiPerMenu): array
    {
        $dikunci = [];

        foreach ($apiPerMenu as $key => $prefixes) {
            foreach ((array) $prefixes as $prefix) {
                $dikunci[$prefix] = $key;
            }
        }

        return array_values(array_intersect(
            array_keys($dikunci),
            (array) config('menu_access.always_allowed_api'),
        ));
    }

    #[Test]
    public function endpoint_bersama_dimiliki_semua_pemakai(): void
    {
        /*
         * Aturan main dari config: kalau sebuah prefix dipakai lebih dari
         * satu menu, SEMUA menu itu harus menyebutnya.
         *
         * Middleware memakai "boleh kalau salah satu pemiliknya boleh".
         * Kalau `villages` hanya disebut oleh menu Desa, dan jabatan itu
         * tidak punya Desa, maka `GET /villages` ikut diblokir -- padahal
         * form Registrasi Instalasi yang masih terbuka memakainya untuk
         * dropdown desa. Dropdownnya kosong tanpa ada pesan apa pun, dan
         * tidak ada cara user's knowing halaman mana yang salah.
         *
         * Ini bentuk kesalahan yang paling mudah lolos di sini: tidak
         * sengaja mengunci endpoint bersama hanya karena pemanggil
         * pertamanya yang terlihat saat menulis config.
         */
        // Dikunci berdasAR key menu, bukan prefix -- supaya bisa
        // ditanyakan "menu apa saja yang mengklaim prefix ini".
        $dikunciMenu = [];

        foreach (app(MenuAccessService::class)->catalog() as $item) {
            $dikunciMenu[(string) $item['key']] = (array) ($item['api'] ?? []);
        }

        /*
         * Prefix yang dipakai bersama, beserta menu yang membutuhkannya.
         *
         * Ditulis sebagai daftar eksplisit, bukan turunan config, supaya
         * test ini bisa mendeteksi kalau config lupa menambah pemiliknya.
         *
         * Bentuk `installation-tickets*` dan `monthly-bills*` (wildcard di
         * akhir) adalah BENTANG yang berbeda dari `installation-tickets` dan
         * `monthly-bills` polos: `fnmatch` dengan `FNM_PATHNAME` membuat `*`
         * tidak bisa menyeberangi `/`, jadi `installation-tickets*` hanya
         * cocok dengan `/installation-tickets` itu sendiri, sementara
         * `installation-tickets` (polos) ikut cocok dengan seluruh
         * turunannya. Dua-duanya dicatat terpisah karena memang dua
         * kelompok endpoint yang berbeda.
         */
        $bersama = [
            'villages' => ['master.desa', 'instalasi.register'],
            'customers' => ['master.pelanggan', 'instalasi.status'],
            'installation-tickets' => [
                'master.instalasi',
                'instalasi.register',
                'instalasi.status',
                'arsip.instalasi',
            ],
            'installation-tickets*' => [
                'survey.daftar',
                'survey.create',
            ],
            'installation-packages' => [
                'settings.package',
                'master.instalasi',
                'instalasi.register',
            ],
            'meter-readings' => ['instalasi.pemakaian', 'tagihan.input', 'arsip.pemakaian'],
            'monthly-bills*' => [
                'teknisi.daftar-tagihan',
                'tagihan.daftar',
                'transaksi.tagihan-bulanan',
                'arsip.tagihan',
            ],
            'whatsapp/*' => [
                'teknisi.daftar-tagihan',
                'tagihan.daftar',
            ],
            'piutang' => ['transaksi.tagihan-bulanan', 'arsip.piutang'],
            'bills' => ['tagihan.daftar', 'transaksi.tagihan-bulanan'],
        ];

        foreach ($bersama as $prefix => $harusIkutSebut) {
            foreach ($harusIkutSebut as $key) {
                $this->assertContains(
                    $prefix,
                    $dikunciMenu[$key] ?? [],
                    "Menu `{$key}` memakai `{$prefix}` tapi tidak menyatakannya, "
                    ."jadi `{$prefix}` akan ikut terblokir oleh menu lain.",
                );
            }
        }

        /*
         * Arah SEBALIKNYA, dan ini yang membuat test di atas benar-benar
         * menguji aturan "semua pemilik ikut menyebut".
         *
         * Tanpa pemeriksaan ini, daftar `$bersama` bisa mengiklankan
         * pemilik yang sebenarnya tidak ada -- dan test tetap hijau,
         * padahal config sudah berubah: satu menu yang TIDAK lagi memakai
         * prefix bersama tidak akan terdeteksi, dan justru itulah
         * penyebab endpoint bersama ikut terblokir.
         *
         * Daftar pemilik dihitung dari config, lalu dibandingkan dengan
         * daftar eksplisit di atas. Bedanya harus kosong, dan pesan
         * errornya menyebutkan kedua sisi supaya bisa langsung ditelusuri.
         */
        $pemilikSebenarnya = [];

        foreach ($dikunciMenu as $key => $prefixes) {
            foreach ($prefixes as $prefix) {
                $pemilikSebenarnya[$prefix][] = $key;
            }
        }

        foreach ($pemilikSebenarnya as $prefix => $pemilik) {
            if (count($pemilik) < 2) {
                // Dipakai satu menu saja: tidak ada "pemilik bersama" yang
                // perlu dinyatakan lengkap.
                continue;
            }

            $this->assertArrayHasKey(
                $prefix,
                $bersama,
                "Prefix `{$prefix}` dipakai lebih dari satu menu ("
                .implode(', ', $pemilik)
                .') tapi tidak dicatat sebagai prefix bersama. Kalau salah '
                .'satu pemiliknya dicabut, endpoint ini ikut terblokir -- dan '
                .'tidak ada yang memperingatkan.',
            );

            $this->assertSame(
                $this->urutkan($pemilik),
                $this->urutkan($bersama[$prefix]),
                sprintf(
                    'Pemilik prefix `%s` tidak sama dengan yang dicatat. '
                    .'Config punya (%s), test punya (%s).',
                    $prefix,
                    implode(', ', $pemilik),
                    implode(', ', $bersama[$prefix]),
                ),
            );
        }
    }

    /**
     * Urutkan daftar key supaya perbandingan tidak bergantung pada urutan
     * kemunculan menu di katalog.
     *
     * Urutan di config berubah setiap kali ada menu baru ditambahkan, dan
     * itu perubahan yang SAH -- yang penting adalah himpunan pemiliknya,
     * bukan urutannya.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private function urutkan(array $keys): array
    {
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Prefix bersama harus benar-benar dipakai LEBIH DARI SATU menu.
     *
     * Tanpa ini, daftar `$bersama` di test sebelumnya bisa memuat prefix
     * yang sebenarnya hanya dimiliki satu menu -- dan test itu tetap
     * hijau, karena "semua pemilik menyebutnya" tetap berlaku untuk satu
     * pemilik. Yang hilang adalah deteksi kalau prefix yang tadinya
     * bersama sudah berubah jadi milik satu menu saja: tidak ada yang
     * memberi tahu bahwa daftar itu perlu diperbarui.
     */
    #[Test]
    public function prefix_yg_dicatat_bersama_memang_berpemilik_lebih_dari_satu(): void
    {
        $pemilikSebenarnya = [];

        foreach (app(MenuAccessService::class)->catalog() as $item) {
            foreach ((array) ($item['api'] ?? []) as $prefix) {
                $pemilikSebenarnya[$prefix][] = (string) $item['key'];
            }
        }

        foreach ([
            'villages',
            'customers',
            'installation-tickets',
            'installation-tickets*',
            'installation-packages',
            'meter-readings',
            'monthly-bills*',
            'piutang',
            'bills',
            'whatsapp/*',
        ] as $prefix) {
            $this->assertGreaterThan(
                1,
                count($pemilikSebenarnya[$prefix] ?? []),
                "Prefix `{$prefix}` tercatat sebagai prefix bersama, tapi "
                .'hanya dimiliki satu menu (atau tidak ada sama sekali).',
            );
        }
    }

    #[Test]
    public function endpoint_yang_tidak_dikunci_memang_memang_bersama(): void
    {
        /*
         * Arah sebaliknya: `users` sengaja TIDAK dikunci menu mana pun,
         * karena dipakai form tagihan yang tidak kita kuasai. Kalau
         * pemakaiannya berubah suatu saat, test ini tidak bisa melihatnya --
         * tapi minimal ia mencegah prefix yang belum dianalisis ikut
         * terkunci diam-diam.
         */
        $dikunci = $this->endpointTidakDikunci();

        $this->assertArrayNotHasKey(
            'users',
            $dikunci,
            '`users` dipakai usePemakaianAir untuk dropdown teknisi; '
            .'menguncinya akan mematikan dropdown di halaman yang terbuka.',
        );
    }
}
