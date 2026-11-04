<?php

namespace App\Services;

use App\Models\JabatanMenuAccess;
use App\Models\User;

/**
 * Menentukan menu apa yang boleh dilihat user, berdasarkan jabatannya.
 *
 * MENGAPA BUKAN BERDASARKAN ROLE
 *
 * `users.role` sudah bekerja: `CheckRole` menjaga setiap endpoint, dan
 * sidebar menyaring menu per role. Yang tidak bisa dilakukan begitu adalah
 * MEMBAGIKAN antar-admin. Kalau bendahara punya role `admin` supaya bisa
 * memakai Jurnal Umum, dia ikut mendapat seluruh menu admin -- Pengaturan,
 * Pelaporan, Basis Data. Membatasi itu per-role berarti membuat role baru,
 * lalu setiap endpoint yang boleh dipakai role itu harus ikut diubah, satu
 * per satu, supaya tidak 403.
 *
 * Jabatan menutup celah itu tanpa menyentuh routing: user tetap `admin`
 * (semua endpoint tetap boleh), tapi menu yang tampil bisa dibatasi. Ini
 *-alat untuk MEMBAGIKAN, bukan untuk memberi akses -- menambah jabatan
 * tidak pernah membuat teknisi bisa membuka halaman admin.
 *
 * ATURAN YANG DIPAKAI, berurutan
 *
 *  1. Role di luar `enforce_roles` TIDAK pernah dibatasi. Default-nya kini
 *     keempat role aplikasi, jadi daftar ini praktis hanya untuk role baru
 *     yang belum punya halaman sendiri.
 *  2. `always` (default: kosong) tidak bisa dicabut.
 *  3. User tanpa jabatan -> semua menu boleh. Jabatan itu atribut
 *     penandatangan laporan DAN penugasan kerja (lihat `User::findByJabatan`),
 *     jadi sebagian besar user memang tidak punya. Membatasi mereka
 *     berdasarkan "jabatan kosong" akan mengunci semuanya tanpa sebab.
 *  4. Jabatan tanpa baris di `jabatan_menu_access` -> semua menu boleh
 *     (belum pernah dikonfigurasi).
 *  5. Key yang tercatat TAPI bukan milik role user -> dibuang.
 *  6. Selain itu -> hanya key yang tertulis.
 *
 * Perhatikan aturan 3 dan 4 sama-sama berarti "semua menu". Itu bukan
 * duplikasi: keduanya menutup jalan masuk yang berbeda -- user tanpa
 * jabatan, dan jabatan yang belum pernah disetel.
 *
 * DUA HAL YANG SEBAIKNYA TIDAK BINGKAK TAPI MELETAKSI PERTIMBANGAN
 *
 * "Menu milik role lain tidak bisa diaktifkan lewat jabatan" bukan
 * lifecycle concern, dan itu disengaja. Mencentang menu `survey.create`
 * untuk jabatan Bendahara tidak akan membuat admin bisa membuka halaman
 * surveyor -- `CheckRole` sudah menutupnya -- jadi yang tersisa hanyalah
 * key yang tidak pernah berlaku, dan diam-diam. Aturan 5 ada supaya daftar
 * itu tidak bisa sampai ke `EnforceMenuAccess`.
 */
class MenuAccessService
{
    /** Cache per-request supaya satu request tidak query berulang. */
    private array $allowedCache = [];

    /** @return array<string, mixed> */
    public function catalog(): array
    {
        return (array) config('menu_access.items', []);
    }

    /** @return array<int, string> */
    public function alwaysAllowedKeys(): array
    {
        return (array) config('menu_access.always', []);
    }

    /**
     * Key menu yang TIDAK PERNAH bisa dicabut, untuk satu role.
     *
     * Bedanya dengan `always`: `always` bersifat GLOBAL -- semua role
     * mendapatkannya. Yang di sini bersifat PER ROLE, dan ditandai di
     * katalog dengan flag `terkunci`.
     *
     * Kenapa perlu bentuk per-role: "Dashboard" milik teknisi adalah halaman
     * tujuan setelah login, sama seperti "Beranda". Kalau dimasukkan lewat
     * `always`, ia akan ikut diizinkan untuk admin dan pelanggan --
     * padahal entri itu milik role `teknisi` saja, dan
     * `catalogForRole()` tidak akan pernah menampilkannya ke mereka.
     * Efeknya berdua: daftar allowed memuat key yang tidak ada di katalog
     * role itu, dan `hasFullAccess()` (yang membandingkan jumlah key dengan
     * jumlah katalog) tidak bisa lagi dipakai sebagai penentu akses penuh.
     *
     * Menu terkunci juga yang membuat situations berikut mustahil:
     * `allowsPath()` menolak `/app/teknisi`, router guard mengarahkan ke
     * `getDashboardRoute('teknisi')` yang juga `/app/teknisi`, dan hasilnya
     * redirect ke dirinya sendiri.
     *
     * @return array<int, string>
     */
    public function lockedKeysForRole(?string $role): array
    {
        $keys = [];

        foreach ($this->catalogForRole($role) as $item) {
            if ((bool) ($item['terkunci'] ?? false)) {
                $keys[] = (string) $item['key'];
            }
        }

        return $keys;
    }

    /** @return array<int, string> */
    public function enforceRoles(): array
    {
        return (array) config('menu_access.enforce_roles', ['admin']);
    }

    /**
     * Role yang dipakai sebuah jabatan, ditentukan dari NAMANYA.
     *
     * Ini yang membuat satu jabatan punya "wajah" sendiri di halaman Hak
     * Akses: `Cater` dan `Teknisi` sama-sama jatuh ke `teknisi`, jadi keduanya
     * melihat daftar centang yang PERSIS SAMA; `Bendahara` dan `Sekretaris`
     * jatuh ke `admin`, jadi keduanya melihat menu admin. Jabatan yang tidak
     * disebut di config memakai `jabatan_role_fallback`.
     *
     * PENCOCOKAN Case-insensitive dan dipangkas spasi, supaya "Caters",
     * "cater", dan " CATER " tidak menjadi tiga jabatan berbeda.
     *
     * @return string|null Null kalau nama jabatan kosong -- pemanggil harus
     *                     memperlakukannya sebagai "tidak diketahui".
     */
    public function roleForJabatan(?string $namaJabatan): ?string
    {
        $nama = trim((string) $namaJabatan);

        if ($nama === '') {
            return null;
        }

        $peta = array_change_key_case((array) config('menu_access.jabatan_roles', []), CASE_LOWER);

        $role = $peta[mb_strtolower($nama)] ?? config('menu_access.jabatan_role_fallback', 'admin');

        return is_string($role) && trim($role) !== '' ? trim($role) : null;
    }

    /**
     * Katalog menu, DITAMPING untuk satu role.
     *
     * `roles` di setiap entri adalah batas atas. Jabatan role `admin` tidak
     * boleh melihat -- apalagi mencentang -- menu Teknisi: mencabutnya tidak
     * akan mengubah apa pun, dan administrator yang salah klik lalu
     * menyimpannya akan mendapat pengaturan yang tidak pernah berlaku.
     *
     * Dipisah dari `catalog()` yang tetap mentah, karena pemanggil internal
     * (mengecek prefix endpoint) harus melihat SELURUH katalog: `pelanggan`
     * hanya boleh dibaca role `pelanggan`, tapi `EnforceMenuAccess` tetap
     * perlu tahu prefix itu dikuasai menu mana supaya bisa menolak.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalogForRole(?string $role): array
    {
        if ($role === null || trim($role) === '') {
            return [];
        }

        $role = trim($role);

        return array_values(array_filter(
            $this->catalog(),
            fn (array $item) => in_array($role, (array) ($item['roles'] ?? []), true),
        ));
    }

    /**
     * Key menu yang boleh dibuka user ini.
     *
     * Mengembalikan daftar key yang SEDANG DIPILIH -- bukan key yang
     * terlarang. Frontend memakai ini untuk menyembunyikan menu, backend
     * untuk menolak endpoint.
     *
     * @return array<int, string>
     */
    public function allowedKeysFor(?User $user): array
    {
        $allKeys = array_column($this->catalog(), 'key');

        // Aturan 1: role yang tidak ikut aturan ini.
        if (! $user) {
            return $allKeys;
        }

        if (! in_array(trim((string) $user->role), $this->enforceRoles(), true)) {
            return $allKeys;
        }

        // Aturan 2: menu yang tidak bisa dikunci.
        $selalu = $this->alwaysAllowedKeys();

        /*
         * Menu terkunci milik role ini (flag `terkunci` di katalog).
         *
         * Ditambahkan di sini, BUKAN hanya di `menuForJabatanWithFlags()`,
         * karena yang menentukan boleh/tidaknya sebuah path adalah
         * `allowedKeysFor()` -- kalau terkuncinya tidak ikut di daftar itu,
         * `allowsPath('/app/teknisi')` akan menolak, dan router guard akan
         * mengarahkan teknisi ke `/app/teknisi` juga.
         *
         * Bentuknya per-role, jadi tidak bocor ke role lain.
         */
        $terkunci = $this->lockedKeysForRole((string) $user->role);

        // Aturan 3: user tanpa jabatan -> semua.
        //
        // Berlaku untuk SEMUA role sekarang, bukan hanya admin: user
        // surveyor/teknisi/pelanggan yang belum ditugaskan ke jabatan
        // mana pun harus tetap bisa memakai aplikasi -- jabatan adalah
        // atribut penandatangan laporan dan penugasan kerja, bukan syarat
        // bisa login.
        if (! $user->jabatan_id) {
            return $allKeys;
        }

        // Aturan 4: jabatan tanpa konfigurasi -> semua.
        $tersimpan = $this->storedKeysForJabatan((int) $user->jabatan_id);
        if ($tersimpan === null) {
            return $allKeys;
        }

        /*
         * Aturan 5: menu milik role lain TIDAK PERNAH bisa diaktifkan lewat
         * jabatan.
         *
         * `saveForJabatan()` sudah menolak menyimpan key yang tidak dikenal
         * katalog, tapi "dikenal" tidak berarti "milik role ini": tanpa
         * penyaringan ini, jabatan Bendahara (role `admin`) bisa menyimpan
         * `survey.create` kalau key-nya ikut terkirim -- entri yang tidak
         * pernah bisa dia pakai, karena `CheckRole` dan `roles` di sidebar
         * sudah menutupnya.
         *
         * Disaring di sini, di tempat yang HANYA satu yang dibaca
         * `EnforceMenuAccess`, supaya kebocoran seperti itu tidak pernah
         * sampai ke endpoint. Tidak perlu penyaringan sama sekali di
         * `saveForJabatan()`: daftar yang sampai ke user tetap utuh, dan
         * key yang sudah terlanjur tersimpan tidak ikut hilang.
         */
        $bolehMilik = array_column($this->catalogForRole((string) $user->role), 'key');

        $tersimpan = array_values(array_intersect($tersimpan, $bolehMilik));

        return array_values(array_unique(array_merge($selalu, $terkunci, $tersimpan)));
    }

    /**
     * Key yang tercatat di tabel untuk sebuah jabatan, atau null kalau
     * jabatan itu belum pernah dikonfigurasi.
     *
     * `null` dan `[]` berarti hal yang SANGAT berbeda dan tidak boleh
     * dicampur: `[]` berarti "sudah disetel, tidak boleh apa-apa",
     * `null` berarti "belum pernah disetel, semua boleh".
     *
     * Key kosong (baris penanda "Hapus semua", lihat `saveForJabatan()`)
     * disaring di sini, bukan di tempat pemanggil, supaya SELURUH kode
     * yang bekerja dengan daftar ini -- `allowedKeysFor()`,
     * `menuForJabatanWithFlags()`, sampai tampilan di halaman Hak Akses --
     * tidak pernah melihatnya, dan tidak perlu tahu bahwa ia ada.
     *
     * Yang membedakan ada di `exists()` di bawah: baris penanda tetap
     * membuat jabatan terbaca "sudah dikonfigurasi" walau tidak ada menu
     * yang boleh. Itu justru tujuannya.
     *
     * @return array<int, string>|null
     */
    public function storedKeysForJabatan(int $jabatanId): ?array
    {
        if (array_key_exists($jabatanId, $this->allowedCache)) {
            return $this->allowedCache[$jabatanId];
        }

        $exists = JabatanMenuAccess::query()->where('jabatan_id', $jabatanId)->exists();

        $keys = $exists
            ? JabatanMenuAccess::query()
                ->where('jabatan_id', $jabatanId)
                ->pluck('menu_key')
                ->reject(fn ($key) => trim((string) $key) === '')
                ->values()
                ->all()
            : null;

        return $this->allowedCache[$jabatanId] = $keys;
    }

    /**
     * Apakah user boleh membuka halaman dengan path ini?
     *
     * Dipakai router guard di frontend dan tidak sendiri di backend --
     * backend memeriksa endpoint, bukan path halaman.
     */
    public function allowsPath(?User $user, string $path): bool
    {
        $path = '/'.ltrim($path, '/');

        foreach ($this->catalog() as $item) {
            $itemPath = '/'.ltrim((string) ($item['path'] ?? ''), '/');

            if ($itemPath === '' || $path !== $itemPath) {
                continue;
            }

            return $this->allowsKey($user, (string) $item['key']);
        }

        // Path yang tidak dikenal katalognya: biarkan lewat. Ini bukan
        // celah yang bisa ditusuk -- daftar route frontend sudah terpisah
        // dan role guard di sana yang menangani -- tapi menolak path tak
        // dikenal akan membuat halaman tak berbijak (NotFound, cetak)
        // ikut terblokir.
        return true;
    }

    public function allowsKey(?User $user, string $key): bool
    {
        return in_array($key, $this->allowedKeysFor($user), true);
    }

    /**
     * Menu milik seorang jabatan, lengkap dengan status centangnya.
     *
     * Ada dua bentuk pemanggilan, dan bedanya penting:
     *
     *  - Pakai NAMA jabatan (`menuForJabatan($id, $nama)`): role diturunkan
     *    dari nama itu. Ini yang dipakai kedua controller, dan yang membuat
     *    Cater dan Teknisi benar-benar berbagi satu tampilan.
     *  - Pakai ROLE langsung (`menuForJabatanWithFlags()`): hanya untuk
     *    pemanggil yang sudah tahu role-nya, dan untuk membandingkan hasil
     *    dengan bentuk lama.
     *
     * Kenapa nama jabatan yang dipakai, bukan role milik PEMANGGIL: role
     * pemanggil di panel pusat adalah `superadmin`, yang bukan punya menu
     * apa pun. Kalau itu yang diteruskan, daftar centangnya akan kosong
     * untuk semua jabatan.
     *
     * @return array<int, array<string, mixed>>
     */
    public function menuForJabatan(int $jabatanId, ?string $namaJabatan): array
    {
        return $this->menuForJabatanWithFlags($jabatanId, $this->roleForJabatan($namaJabatan));
    }

    /**
     * Menu milik seorang jabatan, lengkap dengan status centangnya.
     *
     * Dipakai halaman Hak Akses. Daftar yang dikembalikan sudah DITAMPING ke
     * `catalogForRole($role)`: jabatan Bendahara (role `admin`) tidak pernah
     * melihat menu Teknisi, dan jabatan Teknisi tidak pernah melihat menu
     * admin. Yang ditanyakan bukan "menu mana yang belum dicentang" tapi
     * "menu apa saja yang memang pekerjaannya", jadi menampilkan yang lain
     * hanya menambah centang yang tidak akan pernah berlaku.
     *
     * `$role` sudah SELALU diisi di kedua pemanggilnya, dan keduanya
     * menurunkannya dari nama jabatan (`roleForJabatan()`), bukan dari
     * identitas pemanggil. Itu yang membuat `Cater` dan `Teknisi` benar-benar
     * berbagi satu tampilan, dan bukan kebetulan punya daftar yang sama.
     *
     * @return array<int, array<string, mixed>>
     */
    public function menuForJabatanWithFlags(int $jabatanId, ?string $role): array
    {
        $tersimpan = $this->storedKeysForJabatan($jabatanId);
        $terpilih = $tersimpan === null ? null : array_flip($tersimpan);
        $selalu = $this->alwaysAllowedKeys();
        $terkunci = $this->lockedKeysForRole($role);

        $out = [];

        foreach ($this->catalogForRole($role) as $item) {
            $key = (string) $item['key'];

            /*
             * Menu terkunci TIDAK PERNAH bisa dicabut, jadi centangannya
             * selalu benar -- baik Jabatan itu belum pernah disetel, maupun
             * sudah disetel tanpa memuat key ini.
             *
             * Tanpa ini, `saveForJabatan()` (yang menimpa daftar secara utuh)
             * akan menghapus key terkunci begitu superadmin menekan Simpan,
             * dan checkbox-nya yang mati akan terlihat seperti hilang.
             */
            $terkunciIni = in_array($key, $terkunci, true);

            $out[] = [
                'key' => $key,
                'label' => (string) $item['label'],
                'group' => (string) ($item['group'] ?? ''),
                'icon' => (string) ($item['icon'] ?? ''),
                'path' => (string) ($item['path'] ?? ''),
                'hidden' => (bool) ($item['hidden'] ?? false),
                /*
                 * `tanpa_sidebar`: halaman tetap boleh dibuka dan tetap
                 * bisa dicentang di sini, tapi tidak jadi entri sidebar.
                 *
                 * Berbeda dari `hidden`, yang juga disembunyikan dari daftar
                 * centang ini -- jadi menu `hidden` tidak bisa dicabut dari
                 * panel pusat sama sekali. Yang ini tetap bisa.
                 *
                 * Dan `top_level`: menunya berdiri sendiri, bukan anak
                 * group-nya. Yang di panel pusat ini cuma informasi group
                 * untuk pengelompokan tampilan.
                 */
                'tanpa_sidebar' => (bool) ($item['tanpa_sidebar'] ?? false),
                'top_level' => (bool) ($item['top_level'] ?? false),
                'roles' => (array) ($item['roles'] ?? []),
                /*
                 * Tidak pernah menyala lagi di katalog yang sudah ditamping,
                 * tapi tetap dikirim: frontend membaca kolom ini saat
                 * menyalin daftar, dan mengubah bentuk respons akan membuat
                 * `bentuk_respons_sama_dengan_aplikasi_lokasi` gagal tanpa
                 * alasan yang sebenarnya. Nilai `true` berarti "menu ini bukan
                 * untuk jabatan ini", dan sekarang itu tidak pernah terjadi.
                 */
                'role_mismatch' => false,
                /*
                 * Default = semua tercentang (jabatan belum disetel).
                 *
                 * Setelah dikonfigurasi, centangnya wajib mengikuti daftar
                 * yang TERSIMPAN, bukan daftar `always`. Memakai `$selalu`
                 * di sini membuat setiap jabatan yang sudah dikonfigurasi
                 * membuka halaman dengan hanya "Beranda" tercentang,
                 * seolah-olah simpan tidak pernah terjadi -- dan karena itu
                 * juga membuat `adaPerubahan` di frontend selalu `true`,
                 * sehingga tombol Simpan tidak pernah bisa dimatikan.
                 *
                 * `$selalu` tidak perlu digabung di sini: `saveForJabatan()`
                 * sudah menulisnya ke tabel, jadi `$terpilih` memuatnya.
                 */
                'selected' => $terkunciIni || $terpilih === null ? true : isset($terpilih[$key]),
                /*
                 * `locked` = menu ini tidak bisa dicabut dari panel pusat.
                 *
                 * Dua sumbernya: daftar `always` (global) dan flag `terkunci`
                 * di katalog (per role). Keduanya menghasilkan hal yang sama
                 * di layar: checkbox-nya mati dan keterangannya "wajib
                 * terbuka".
                 */
                'locked' => in_array($key, $selalu, true) || $terkunciIni,
            ];
        }

        return $out;
    }

    /**
     * Simpan daftar menu yang diizinkan untuk sebuah jabatan.
     *
     * SELALU menulis ulang (delete + insert) alih-alih
     * `sync`-per-diff, karena yang disimpan adalah DAFTAR PENUH pilihan
     * admin -- bukan tambahan. Diff membiarkan key yang sudah tidak ada
     * di config ikut tertinggal di database dan terus dianggap
     * diizinkan padahal tidak lagi dikenal.
     *
     * @param  array<int, string>  $keys
     */
    public function saveForJabatan(int $jabatanId, array $keys): void
    {
        $known = array_column($this->catalog(), 'key');
        $selalu = $this->alwaysAllowedKeys();

        $rows = [];
        $diabaikan = [];

        foreach ($keys as $key) {
            $key = trim((string) $key);

            // Key yang tidak dikenal katalog diabaikan, bukan disimpan.
            // Kalau disimpan, ia akan diam-diam menolak setiap halaman
            // ("menunya tidak ada") tanpa pernah bisa dibersihkan dari
            // UI, karena UI hanya menampilkan key yang dikenal.
            if ($key === '' || ! in_array($key, $known, true)) {
                if ($key !== '') {
                    $diabaikan[] = $key;
                }

                continue;
            }

            $rows[$key] = [
                'jabatan_id' => $jabatanId,
                'menu_key' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        /*
         * Key `always` ditulis apa pun yang dikirim admin.
         *
         * Ini bukan untuk memberi akses -- `allowedKeysFor()` sudah
         * mengizinkannya di luar preferensi -- tapi untuk memastikan
         * jabatan yang sudah dikonfigurasi tidak pernah punya NOL BARIS.
         *
         * Nol baris dibaca `storedKeysForJabatan()` sebagai "belum pernah
         * dikonfigurasi", yang berarti semua menu terbuka. Tanpa ini,
         * menekan "Hapus semua" di halaman Hak Akses akan menyimpan
         *KEADAAN YANG PERSIS kebalikan dari yang diminta: seluruh menu
         * terbuka kembali, dan tidak ada yang mengatakannya karena
         * permintaan itu kelihatan berhasil.
         *
         * Menyimpan key `always` menutup celah itu tanpa kolom tambahan,
         * tanpa baris penanda, dan tanpa mengubah akses apa pun.
         */
        foreach ($selalu as $key) {
            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'jabatan_id' => $jabatanId,
                    'menu_key' => $key,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        /*
         * `always` sekarang kosong -- tidak ada menu yang dipaksa terbuka --
         * jadi loop di atas tidak pernah menulis apa pun. Tanpa baris
         * tambahan di sini, "Hapus semua" akan menyimpan NOL BARIS, dan
         * nol baris dibaca `storedKeysForJabatan()` sebagai "belum pernah
         * dikonfigurasi" yang berarti SEMUA menu terbuka.
         *
         * Itu keadaan yang persis kebalikan dari yang diminta admin, dan
         * yang lebih buruk: permintaan kelihatan berhasil sementara
         * hasilnya memberi akses lebih banyak, bukan lebih sedikit.
         *
         * Penandanya satu baris dengan `menu_key` kosong. Murah karena
         * tabel ini tidak punya kolom boolean dan menambahkannya berarti
         * satu migrasi untuk satu kebutuhan yang bisa dijawab tanpa itu.
         *
         * Baris ini TIDAK memberi akses apa pun: `allowedKeysFor()`
         * mengembalikan daftar dari database apa adanya, dan `catalog()`
         * tidak punya key kosong untuk dicocokkan -- jadi dia tidak pernah
         * muncul di UI dan tidak pernah mengunci endpoint.
         */
        if ($rows === []) {
            $rows[''] = [
                'jabatan_id' => $jabatanId,
                'menu_key' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Daftar yang isinya tidak dikenal seluruhnya hampir pasti
        // permintaan yang gagal (misalnya frontend sudah tidak sinkron
        // dengan katalog), bukan pilihan admin yang disengaja. Menyimpannya
        // berarti menghapus semua hak akses yang ada lalu membalas
        // `success: true`. Penolaknya dilakukan di controller sebagai 422
        // supaya Kegagalan itu kelihatan; di sini cukup dijaga agar
        // keadaan tidak berubah diam-diam.
        if ($rows === [] && $diabaikan !== []) {
            return;
        }

        // Buang dulu supaya constraint unik tidak bentrok saat menyimpan
        // ulang key yang sama.
        JabatanMenuAccess::query()->where('jabatan_id', $jabatanId)->delete();

        if ($rows !== []) {
            JabatanMenuAccess::query()->insert(array_values($rows));
        }

        unset($this->allowedCache[$jabatanId]);
    }
}
