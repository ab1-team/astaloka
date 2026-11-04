<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Panel pusat: kelola user pusat (superadmin).
 *
 * User pusat TIDAK punya lokasi. Ia hidup di database pusat dengan role
 * `superadmin`, dan bekerja pada registry lokasi -- bukan pada data
 * operasional lokasi mana pun. Karena itu setiap query di sini dibatasi
 * `role = 'superadmin'` walaupun tabelnya bernama `users`, sama dengan yang
 * dipakai di tiap database lokasi. Tanpa batas itu, user lokasi bisa muncul
 * di daftar ini dan diubah dari panel -- padahal panel tidak punya hak atas
 * database lokasi.
 *
 * TIGA HAL YANG TIDAK ADA DI CRUD USER BIASA
 *
 * 1. Akun sendiri tidak bisa dinonaktifkan.
 *
 *    Sesi panel pusat bertahan 8 jam. Menonaktifkan akun sendiri berarti
 *    8 jam pekerjaan yang sedang berjalan berhenti, dan tidak ada jalan
 *    keluar dari panel -- karena tidak ada lagi yang bisa masuk ke panel
 *    untuk membatalkannya.
 *
 * 2. Superadmin aktif terakhir tidak bisa dinonaktifkan.
 *
 *    Konsekuensinya bukan "orang berikutnya tidak bisa login", tapi "tidak
 *    ada yang bisa membuat akun baru" -- karena itu harus lewat panel ini.
 *    Satu-satunya jalan keluar adalah akses langsung ke database.
 *
 * 3. Token ikut dicabut saat akun dinonaktifkan atau password diganti.
 *
 *    Menonaktifkan di tabel saja tidak menyentuh token yang sudah terbit.
 *    Pemegang token itu tetap punya akses sampai kedaluwarsa. Menonaktifkan
 *    berarti memutus akses yang sudah terbuka, bukan sekadar menutup pintu
 *    untuk login berikutnya.
 *
 * TIDAK ADA delete di sini, hanya nonaktifkan. Alasannya ada di migration
 * `2026_10_14_000003_add_is_active_to_central_users_table`: menghapus baris
 * membuat jejak audit lama menunjuk id yang tidak ada artinya lagi, dan bisa
 * menghilangkan superadmin terakhir tanpa peringatan.
 */
class SuperadminController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Daftar user pusat.
     *
     * Tanpa filter status dari sisi klien. Daftar ini adalah seluruh akun
     * yang ada -- satu per admin pusat, jarang lebih dari segelintir baris --
     * dan tiap barinya sudah membawa badge Aktif/Nonaktif. Menyaring yang
     * mati tidak menambah informasi apa pun; ia cuma membuat akun yang
     * aksesnya sudah dicabut lebih sulit ditemukan lagi.
     *
     * Query `status` masih diterima supaya klien lama tidak error, tapi
     * hasilnya sama dengan `all`.
     *
     * @queryParam search     Cari di nama atau email
     */
    public function index(Request $request): JsonResponse
    {
        $q = $this->table()->where('role', 'superadmin');

        if ($search = trim((string) $request->query('search', ''))) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        match ($request->query('status', 'all')) {
            'active' => $q->where('is_active', true),
            'inactive' => $q->where('is_active', false),
            default => null,
        };

        /*
         * Statistik dihitung dari LUAR filter yang sedang aktif.
         *
         * Kalau ikut filter, angka "aktif: 1" di header ikut berubah begitu
         * admin memilih "Nonaktif", dan keduanya terlihat benar -- padahal
         * yang dijawab bukan lagi "ada berapa user pusat". Angka yang
         * selalu konstan itulah yang membuat admin bisa membedakan
         * "memang cuma ada satu" dari "saya sedang melihat subset".
         */
        $seluruhnya = $this->table()->where('role', 'superadmin');

        return response()->json([
            'success' => true,
            'data' => $q->get()->map(fn ($u) => $this->present($u))->values(),
            'meta' => [
                'total' => (clone $seluruhnya)->count(),
                'aktif' => (clone $seluruhnya)->where('is_active', true)->count(),
                /*
                 * Id akun yang sedang dipakai. Dikirim supaya frontend bisa
                 * menonaktifkan tombolnya sendiri, alih-alih membiarkan
                 * superadmin menekan tombol lalu ditolak server dengan 422
                 * setelah formnya terlanjur terisi.
                 */
                'self' => (int) $request->user()->id,
            ],
        ]);
    }

    /**
     * Tambah user pusat.
     *
     * Password wajib diisi. Akun tanpa password tidak bisa login, dan itu
     * baru ketahuan saat orang itu mencoba -- bukan saat admin menekan
     * tombol simpan.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', $this->emailUnowned()],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $id = $this->table()->insertGetId([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'superadmin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            'central.user_create',
            subjectType: User::class,
            subjectId: $id,
            description: "User pusat `{$data['name']}` ({$data['email']}) dibuat.",
            properties: ['email' => $data['email']],
        );

        return response()->json([
            'success' => true,
            'message' => "User pusat `{$data['name']}` dibuat.",
            'data' => $this->present($this->table()->find($id)),
        ], 201);
    }

    /**
     * Ubah nama, email, status aktif, atau password.
     *
     * Password dikosongkan berarti "jangan sentuh password". Ini yang membuat
     * halaman ini bisa dipakai untuk menonaktifkan akun tanpa memaksa admin
     * mengingat password lama orang itu.
     *
     * Password kosong juga TIDAK berarti "setel jadi string kosong": password
     * yang kosong adalah password yang semua orang tahu. `filled()` di bawah
     * yang menjaga itu.
     */
    public function update(Request $request, string $user): JsonResponse
    {
        $db = $this->table();

        $row = $db->where('role', 'superadmin')->find($user);

        if (! $row) {
            return response()->json([
                'success' => false,
                'message' => 'User pusat tidak ditemukan.',
            ], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'email' => ['sometimes', 'required', 'email', 'max:191', $this->emailUnowned((int) $row->id)],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $sebelum = [
            'name' => $row->name,
            'email' => $row->email,
            'is_active' => (bool) $row->is_active,
        ];

        /*
         * Ganti password dicatat sebagai aksi sendiri, bukan sebagai salah
         * satu perubahan di diff.
         *
         * `_password_` adalah satu-satunya perubahan yang tidak pernah muncul
         * di diff -- nilai barunya adalah hash yang berbeda setiap kali,
         * jadi membandingkan string sebelum-sesudah tidak menghasilkan
         * apa pun yang berguna. Kalau tidak dicatat terpisah, perubahan
         * password hilang dari jejak audit sepenuhnya, padahal ini
         * perubahan paling penting dari empat perubahan yang mungkin terjadi.
         */
        if (filled($data['password'] ?? null)) {
            $this->revokeTokens((int) $row->id);

            $this->audit->record(
                'central.user_password_reset',
                subjectType: User::class,
                subjectId: $row->id,
                description: "Password user pusat `{$row->name}` ({$row->email}) diganti. Aksesnya dicabut.",
                properties: ['email' => $row->email],
            );

            $data['password'] = Hash::make($data['password']);
        } else {
            /*
             * Password kosong (atau tidak dikirim sama sekali) berarti "jangan
             * sentuh password" -- bukan "setel jadi string kosong".
             *
             * Nilainya harus dibuang dari payload, kalau tidak string kosong
             * itu ikut tersimpan di database dan siapa pun bisa masuk dengan
             * password kosong.
             */
            unset($data['password']);
        }

        /*
         * `password_confirmation` TIDAK pernah jadi kolom di database --
         * fungsinya hanya mengonfirmasi bahwa admin mengetik password yang
         * sama dua kali. Kalau ikut tersimpan, query UPDATE-nya gagal karena
         * kolom itu tidak ada.
         *
         * Yang TIDAK boleh dibuang di sini adalah `password` yang sudah
         * di-hash di atas. Membuangnya membuat penggantian password tidak
         * terjadi sama sekali, padahal server membalas "berhasil", access
         * log mencatat password sudah diganti, dan semua token dicabut --
         * sementara password lama tetap bisa dipakai. Form yang-isinya
         * "menang" atas keadaan sebenarnya.
         */
        unset($data['password_confirmation']);

        $menonaktifkan = array_key_exists('is_active', $data)
            && ! $data['is_active']
            && (bool) $row->is_active;

        if ($menonaktifkan) {
            $this->guardNotSelf((int) $request->user()->id, (int) $row->id, (string) $row->name);

            $this->guardNotLastActive((int) $row->id, (string) $row->name);

            /*
             * Token dicabut SEBELUM update, supaya jeda antara "akun dinyatakan
             * mati" dan "akses yang sudah terbit tidak berlaku lagi"
             * sesingkat mungkin.
             */
            $this->revokeTokens((int) $row->id);
        }

        if ($data !== []) {
            $data['updated_at'] = now();

            $db->where('id', $row->id)->update($data);
        }

        $sesudah = $db->find($row->id);

        $perubahan = AuditLogger::diff($sebelum, [
            'name' => $sesudah->name,
            'email' => $sesudah->email,
            'is_active' => (bool) $sesudah->is_active,
        ]);

        if ($perubahan !== []) {
            $this->audit->record(
                'central.user_update',
                subjectType: User::class,
                subjectId: $row->id,
                description: $menonaktifkan
                    ? "User pusat `{$sesudah->name}` dinonaktifkan. Aksesnya langsung dicabut."
                    : "User pusat `{$sesudah->name}` diperbarui ("
                        .implode(', ', array_keys($perubahan)).').',
                properties: ['email' => $sesudah->email, 'changes' => $perubahan],
            );
        }

        return response()->json([
            'success' => true,
            'message' => $menonaktifkan
                ? "User pusat `{$sesudah->name}` dinonaktifkan dan aksesnya dicabut."
                : 'User pusat diperbarui.',
            'data' => $this->present($sesudah),
        ]);
    }

    /* ============================================================
     | PEKERJAAN INTERNAL
    |============================================================
     */

    /**
     * Email harus unik di antara user pusat.
     *
     * Tabel `users` di database pusat hanya berisi superadmin, jadi `role`
     * di klausa unique sebenarnya tidak menambah apa pun saat ini. Tapi
     * pemeriksaan dilakukan di database dan bukan "hapus semua email yang
     * sama" seperti di app lokasi: user pusat BUKAN data yang boleh ikut
     * terhapus bersama, jadi bentrok harus ditolak, bukan diselesaikan
     * diam-diam.
     */
    private function emailUnowned(?int $ignoreId = null): Unique
    {
        return Rule::unique('users', 'email')
            ->where('role', 'superadmin')
            ->ignore($ignoreId);
    }

    /**
     * Cabut semua token milik satu user.
     *
     * Token di perangkat lain ikut hilang, bukan hanya yang sedang dipakai.
     * Untuk akun yang dinonaktifkan itu memang yang diinginkan: kalau
     * akunnya sudah dinyatakan mati, tidak ada alasan menyisakan akses dari
     * mana pun. Sama berlakunya untuk ganti password -- orang yang punya
     * token lama harus keluar, bukan hanya tidak bisa masuk lagi.
     *
     * `tokenable_type` menyimpan MORPH ALIAS (`user`), bukan nama class
     * lengkap. Menulis `User::class` di sini terlihat benar dan lolos
     * review, tapi tidak pernah cocok dengan satu baris pun -- hasilnya nol
     * baris terhapus dan "sesi dicabut" hanya jadi janji. Yang dipakai di
     * sini justru nilai yang benar-benar ditulis Sanctum.
     */
    private function revokeTokens(int $userId): void
    {
        $user = new User;
        $user->setConnection($this->central());

        DB::connection($this->central())
            ->table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $userId)
            ->delete();
    }

    /**
     * Menolak menonaktifkan akun yang sedang dipakai.
     *
     * Pesannya menyebut akibatnya, bukan hanya melarangnya. Admin yang
     * membaca "akun Anda sendiri tidak bisa dinonaktifkan" akan mengira
     * itu aturan tejada; yang sebenarnya adalah keputusan ini akan
     * memutus sesi dia sendiri dalam hitungan detik, dan tidak ada tombol
     * batal.
     */
    private function guardNotSelf(int $aktorId, int $targetId, string $nama): void
    {
        if ($aktorId !== $targetId) {
            return;
        }

        throw new HttpException(422, "Akun Anda sendiri (`{$nama}`) tidak bisa dinonaktifkan dari sini. "
            .'Memakai akun lain, atau biarkan aktif kalau memang masih dipakai.');
    }

    /**
     * Menolak menonaktifkan superadmin aktif terakhir.
     *
     * Dihitung ulang dari database, bukan dari data yang dikirim klien: dua
     * admin bisa menekan tombol hampir bersamaan, dan keduanya akan melihat
     * angka yang sama lalu sama-sama menolak. Itu hasil yang benar -- salah
     * satunya gagal dan membaca pesan yang sama.
     */
    private function guardNotLastActive(int $targetId, string $nama): void
    {
        $sisa = $this->table()
            ->where('role', 'superadmin')
            ->where('is_active', true)
            ->where('id', '!=', $targetId)
            ->count();

        if ($sisa > 0) {
            return;
        }

        throw new HttpException(422, "`{$nama}` adalah user pusat aktif terakhir. Menonaktifkannya "
            .'berarti tidak ada lagi yang bisa masuk ke panel untuk membuat akun baru. '
            .'Buat dulu akun untuk orang lain, baru nonaktifkan yang ini.');
    }

    private function central(): string
    {
        return (string) config('tenancy.central_connection', 'central');
    }

    /**
     * Query builder yang SELALU menunjuk database pusat.
     *
     * `EnsureSuperadmin` sudah mengembalikan `database.default` ke pusat, tapi
     * menulis koneksi secara eksplisit di sini membuat controller ini tetap
     * benar kalau someday dipanggil dari konteks lain -- dan biaya-nya
     * nol.
     */
    private function table(): QueryBuilder
    {
        return DB::connection($this->central())->table('users');
    }

    private function present(object $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'is_active' => (bool) $u->is_active,
            'email_verified_at' => $u->email_verified_at,
            'created_at' => $u->created_at,
            'updated_at' => $u->updated_at,
        ];
    }
}
