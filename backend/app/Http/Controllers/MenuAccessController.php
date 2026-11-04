<?php

namespace App\Http\Controllers;

use App\Models\Jabatan;
use App\Services\MenuAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Baca hak akses menu per jabatan di aplikasi lokasi.
 *
 * SUDAH READ-ONLY. Menyimpan hak akses tidak lagi dilakukan dari sini.
 *
 * DIPINDAH KE PANEL PUSAT. Alasannya praktis, bukan arsitektur: menyetelnya
 * dari aplikasi lokasi memaksa logout-login berulang. Tiap lokasi punya
 * sesinya sendiri, jadi menyetel lima lokasi berarti lima kali masuk ke
 * domain yang berbeda -- padahal yang diubah hanya kotak centang di satu
 * halaman. Dari panel pusat, satu sesi cukup untuk semuanya.
 *
 * Yang TIDAK dipindahkan: tabel dan aturannya. `jabatan_menu_access` tetap
 * ditulis di database lokasi (lewat `CentralMenuAccessController`), dan
 * `MenuAccessService` tetap pembaca yang sama. Aplikasi lokasi tidak tahu
 * siapa yang menulis tabel itu -- dan memang tidak perlu tahu.
 *
 * Kenapa `index()` sendiri TIDAK ikut dihapus: berguna supaya admin lokasi
 * bisa melihat hak akses jabatan apa yang berlaku di tempatnya tanpa harus
 * masuk ke panel pusat. Yang hilang hanya kemampuan MENYIMPAN, karena itulah
 * yang berakar pada masalah logout-login.
 *
 * `catalog()` tetap hidup dan dipakai SEMUA role -- sidebar memakainya,
 * dan menghapusnya akan membuat sidebar kosong untuk setiap user.
 *
 * Catatan soal "user": pengaturannya per JABATAN, bukan per user. Kalau
 * butuh satu orang berbeda dari sisa jabatannya, itu perilaku yang berbeda
 * dan perlu tabel sendiri -- bukan penyimpangan dari yang di sini.
 */
class MenuAccessController extends Controller
{
    public function __construct(private MenuAccessService $menu) {}

    /**
     * Daftar jabatan + menu yang boleh untuk masing-masing.
     *
     * Semua jabatan dibaca, termasuk yang belum pernah dikonfigurasi --
     * itulah yang membuat panel pusat bisa mengatur dari awal.
     */
    public function index()
    {
        $jabatan = Jabatan::query()
            ->orderBy('id')
            ->get(['id', 'nama_jabatan']);

        $penggunaPerJabatan = DB::table('users')
            ->select('jabatan_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('jabatan_id')
            ->groupBy('jabatan_id')
            ->pluck('total', 'jabatan_id');

        /*
         * Role apa saja yang memakai tiap jabatan.
         *
         * Berbeda dengan kolom `role` di bawah, yang berasal dari NAMA
         * jabatan: yang ini dibaca dari `users`, jadi menjawab "user siapa
         * yang memakai jabatan ini". Panel pusat memakainya untuk memberi
         * tahu superadmin jabatan mana yang CENTANGANNYA belum tentu berlaku,
         * karena `enforce_roles` hanya berisi role aplikasi.
         *
         * Kolom ini harus identik dengan yang dikirim
         * `CentralMenuAccessController::bacaJabatan()`. Bentuk respons kedua
         * sisi dikunci oleh test
         * `bentuk_respons_sama_dengan_aplikasi_lokasi`, jadi menambah kolom
         * di sini tanpa menambahkannya di sana akan menggagalkan test itu.
         */
        $rolePerJabatan = DB::table('users')
            ->select('jabatan_id', 'role')
            ->whereNotNull('jabatan_id')
            ->whereNotNull('role')
            ->distinct()
            ->get(['jabatan_id', 'role']);

        $daftarRole = [];

        foreach ($rolePerJabatan as $r) {
            $daftarRole[(int) $r->jabatan_id][] = (string) $r->role;
        }

        $data = [];

        foreach ($jabatan as $j) {
            $tersimpan = $this->menu->storedKeysForJabatan((int) $j->id);

            $data[] = [
                'id' => $j->id,
                'nama' => $j->nama_jabatan,
                // `null` = belum pernah dikonfigurasi (semua menu tampil).
                // `[]` = sudah dikonfigurasi tapi tidak boleh apa-apa.
                // Frontend perlu membedakan keduanya untuk menanyakan
                // "jadikan semua" atau tidak.
                'configured' => $tersimpan !== null,
                'total_user' => (int) ($penggunaPerJabatan[$j->id] ?? 0),
                'roles' => $daftarRole[(int) $j->id] ?? [],
                // Role dari NAMA jabatan, bukan dari user yang memakainya.
                // Wajib sama persis dengan yang dikirim
                // `CentralMenuAccessController::bacaJabatan()` -- bentuk
                // respons kedua sisi dikunci oleh test
                // `bentuk_respons_sama_dengan_aplikasi_lokasi`.
                'role' => $this->menu->roleForJabatan($j->nama_jabatan),
                'menu' => $this->menu->menuForJabatan((int) $j->id, $j->nama_jabatan),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Katalog menu lengkap.
     *
     * Dipakai frontend untuk menyusun sidebar, supaya daftar menu di
     * `SidebarView.vue` tidak perlu ditulis ulang di sana. Label, ikon,
     * group, dan path semuanya datang dari `config/menu_access.php` --
     * satu sumber yang sama dengan yang dipakai middleware pemblokiran.
     *
     * Endpoint ini terbuka untuk semua role login (bukan `role:admin`),
     * karena surveyor/teknisi/pelanggan juga butuh sidebar-nya. Yang
     * dikembalikan sudah terpotong sesuai hak akses masing-masing: user
     * tidak pernah melihat key menu yang tidak boleh dia buka, sehingga
     * label dan jalurnya pun tidak bocor.
     */
    public function catalog(Request $request)
    {
        $user = $request->user();
        $allowed = $this->menu->allowedKeysFor($user);

        $items = [];

        foreach ($this->menu->catalog() as $item) {
            $key = (string) $item['key'];

            // Menu yang tidak boleh disembunyikan dari daftar ini: frontend
            // butuh melihat SEMUA key supaya bisa membedakan "menu ini
            // tidak ada untuk saya" dari "menu ini disembunyikan admin".
            // Yang dikembalikan hanya key-nya, bukan label/path-nya, jadi
            // tidak ada informasi yang bocor.
            if (! in_array($key, $allowed, true)) {
                $items[] = ['key' => $key, 'allowed' => false];

                continue;
            }

            $items[] = [
                'key' => $key,
                'allowed' => true,
                'label' => (string) $item['label'],
                'group' => (string) ($item['group'] ?? ''),
                'icon' => (string) ($item['icon'] ?? ''),
                'path' => (string) ($item['path'] ?? ''),
                'hidden' => (bool) ($item['hidden'] ?? false),
                // `tanpa_sidebar`: halaman tetap ada dan tetap bisa
                // dicentang di panel pusat Hak Akses, tapi tidak jadi
                // entri sidebar. Dipakai untuk halaman yang desain
                // per-role-nya dibuka dari dashboard, bukan dari menu.
                //
                // BEDA dari `hidden`: `hidden` juga disembunyikan dari
                // daftar centang di panel pusat, jadi tidak bisa
                // dicabut. Yang ini TETAP bisa dicabut -- hanya
                // menunya yang tidak dirender.
                'tanpa_sidebar' => (bool) ($item['tanpa_sidebar'] ?? false),
                // Sidebar mem-flatten group yang cuma punya satu anak
                // menjadi link langsung. `group_tetap` membatalkan itu
                // untuk group tertentu, karena susunan menu itu bagian dari
                // desain per-role (teknisi membuka "Master Instalasi",
                // bukan langsung "Input Pemakaian Air").
                //
                // `group_tetap` TIDAK dikirim untuk item yang tidak
                // diizinkan: item itu tidak punya label/path yang boleh
                // bocor, jadi tidak boleh punya atribut lain juga.
                'group_tetap' => (bool) ($item['group_tetap'] ?? false),
                // Menu `top_level` berdiri sendiri, di luar group-nya.
                // "Buat Survey Baru" milik surveyor begitu: di desain
                // per-role ia link tingkat atas, bukan anak group "Survey".
                //
                // `group`-nya tetap ada supaya panel pusat Hak Akses masih
                // mengelompokkannya bersama menu sekelompoknya.
                'top_level' => (bool) ($item['top_level'] ?? false),
                // Sidebar menyaring per ROLE, karena `allowed` hanya bicara
                // soal jabatan. Satu user bisa punya role `teknisi` dan
                // jabatan yang mencentangnya tidak memuat `survey.daftar` --
                // menunya tetap tidak muncul, dan `roles` inilah yang
                // menyaringnya.
                //
                // Yang ketiga adalah `role_mismatch` di halaman Hak Akses,
                // dan itu urusan yang berbeda: sana yang ditanyakan adalah
                // "menu apa yang pekerjaannya", bukan "apa yang boleh".
                //
                // `roles` dikirim hanya untuk item yang sudah diizinkan.
                // Untuk item yang tidak, tidak ada yang perlu bocor: label,
                // path, maupun daftar role-nya sama sekali tidak dikirim.
                'roles' => array_values((array) ($item['roles'] ?? [])),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'groups' => $this->groupOrder(),
            ],
        ]);
    }

    /**
     * Urutan group untuk ditampilkan.
     *
     * Diambil dari kemunculan pertama tiap group di katalog, supaya
     * menambah entry baru tidak perlu menyentuh frontend.
     *
     * @return array<int, string>
     */
    private function groupOrder(): array
    {
        $groups = [];

        foreach ($this->menu->catalog() as $item) {
            $group = (string) ($item['group'] ?? '');

            if ($group !== '' && ! in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }
}
