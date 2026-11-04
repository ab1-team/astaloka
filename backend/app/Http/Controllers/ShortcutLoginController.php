<?php

namespace App\Http\Controllers;

use App\Models\Central\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InvoiceGate;
use App\Services\ReceivableGenerator;
use App\Services\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Login pintasan: daftar user LOKASI INI, dan masuk ke salah satunya tanpa
 * mengetik email + password.
 *
 * TUJUAN
 * ------
 * Halaman ini ada untuk keperluan pengembangan dan demo: buka
 * `/login/user`, lihat user yang ada, klik satu, langsung masuk. Tidak ada
 * input yang perlu diisi.
 *
 * APA YANG TIDAK BUKAN TUJUANNYA
 * -------------------------------
 * Ini bukan halaman publik. Endpoint-nya tidak butuh `auth:sanctum` --
 * justru itulah inti fiturnya -- sehingga siapa pun yang tahu URL-nya dapat
 * menerbitkan token untuk user mana pun di lokasi yang sedang dilayani,
 * termasuk role `admin`. Kalau aplikasinya pernah dipublikasikan, halaman
 * ini harus dimatikan lewat `SHORTCUT_LOGIN_ENABLED=false`; lihat
 * `config/shortcut_login.php`.
 *
 * MENGAPA HANYA SATU LOKASI, BUKAN SELURUH LOKASI
 * --------------------------------------------------
 * Versi sebelumnya mengambil seluruh baris `tenants` dan membuka satu koneksi
 * database PER LOKASI, lalu mengirimkan daftar itu ke browser: nama, email,
 * role, dan password demo milik lokasi orang lain, semuanya dalam satu respons.
 *
 * Tiga hal rusak sekaligus karena itu:
 *
 *   1. Isolasi. Data satu lokasi bocor ke browser orang lain, dan
 *      `TenantManager::runFor()` membuat `database.default` berganti-ganti di
 *      dalam satu request -- jadi tidak ada lagi batas database yang bisa
 *      diandalkan selama request itu berjalan.
 *   2. Skala. Satu request membuka N koneksi dan menjalankan `Hash::check()`
 *      untuk setiap user di setiap lokasi. Lima puluh lokasi berarti puluhan
 *      ribu pemeriksaan bcrypt untuk satu halaman.
 *   3. Penerbitan token. `tenant_id` + `user_id` berarti token bisa
 *      diarahkan ke lokasi manapun yang tidak sedang dilayani, dari domain
 *      mana pun.
 *
 * Sekarang identitas lokasi hidup di SATU tempat: `TenantManager::current()`,
 * hasil middleware `ResolveTenant` yang sama dengan seluruh request lain.
 * Tidak ada daftar tenant yang dipindai, tidak ada `runFor()` di halaman ini,
 * dan tidak ada parameter `tenant_id` -- kalau masih dikirim, isinya
 * diabaikan dan tidak ada efek lain.
 *
 * MENGAPA PASSWORD BISA DITAMPILKAN
 * --------------------------------
 * Tidak bisa -- dan itu sebabnya halaman ini jujur soal itu. Kolom
 * `users.password` berisi hash bcrypt, yang satu arah: tidak ada kode,
 * termasuk kode di berkas ini, yang bisa mengubahnya kembali menjadi teks.
 *
 * Yang ditampilkan hanya password yang COCOK dengan salah satu password demo
 * di config (`password`, `rahasia123`, `legacy`) -- nilai yang memang sudah
 * tertulis di README dan dipakai seeder. Dicocokkan lewat `Hash::check()`,
 * jadi tidak ada hash yang bocor ke respons. User yang sudah mengganti
 * password-nya tampil `••••••`.
 */
class ShortcutLoginController extends Controller
{
    public function __construct(
        private TenantManager $manager,
        private InvoiceGate $gate,
        private AuditLogger $audit,
        private ReceivableGenerator $piutang,
    ) {}

    /**
     * Daftar user lokasi yang SEDANG MELAYANI request ini.
     *
     * Endpoint publik (tidak ada `auth:sanctum`) -- itulah tujuannya. Dibatasi
     * oleh `throttle` yang longgar di route, dan oleh `per_location_limit`
     * di config supaya tidak ikut jadi Denial-of-Service untuk server sendiri.
     *
     * Bentuk respons TETAP seperti sebelumnya: satu grup lokasi yang berisi
     * daftar user. Yang berubah hanya isinya -- hanya satu grup, dan
     * grup itu selalu milik lokasi yang sedang dilayani. Bentuknya sengaja
     * tidak diratakan jadi array user polos supaya frontend bisa membaca
     * keduanya tanpa penyesuaian yang menyesatkan.
     */
    public function index(): JsonResponse
    {
        if (! config('shortcut_login.enabled')) {
            return $this->disabled();
        }

        $tenant = $this->manager->current();

        if (! $tenant instanceof Tenant) {
            return $this->tanpaLokasi();
        }

        /*
         * Query diambil langsung lewat `DB::table()` dengan koneksi `tenant`,
         * bukan lewat `User::query()`.
         *
         * Alasannya bukan sekadar selera: `User` tidak punya
         * `$connection`, jadi ia mengikuti `database.default` -- dan bentuk itu
         * praktis, karena middleware `ResolveTenant` sudah mengarahkan
         * default ke database lokasi ini. Tapi ada satu kondisi di mana itu
         * tidak berlaku: pada request yang TIDAK melewati middleware (test
         * yang memanggil controller langsung, atau perintah yang memakai
         * `runFor()`), default bisa masih menunjuk database pusat, dan
         * `User::query()` diam-diam membaca tabel `users` yang tidak ada di
         * sana.
         *
         * Menyebutkan `DB::connection(TenantManager::CONNECTION)` secara
         * eksplisit membuat endpoint ini tidak bergantung pada state global
         * apa pun: ia SELALU membaca dari koneksi yang memang ditulis untuk
         * lokasi aktif, dan `per_location_limit` yang membatasi hasilnya.
         */
        try {
            $users = DB::connection(TenantManager::CONNECTION)
                ->table('users')
                ->orderBy('name')
                ->limit($this->limit())
                ->get(['id', 'name', 'email', 'role', 'password']);
        } catch (Throwable $e) {
            /*
             * Database lokasi tidak bisa dibaca.
             *
             * Ditangani dengan pesan yang menyebut penyebabnya. Tanpa itu,
             * user akan mengira pintasannya yang rusak -- padahal yang rusak
             * database lokasi. Status 503, bukan 500, karena masalahnya ada di
             * server dan mungkin hilang sendiri.
             */
            report($e);

            return $this->databaseTidakBisaDibuka($tenant);
        }

        return response()->json([
            'success' => true,
            'data' => [[
                'tenant_id' => $tenant->id,
                'slug' => $tenant->slug,
                'nama' => $tenant->nama,
                'label' => $tenant->label(),
                'users' => $this->mapUsers($users),
                /*
                 * Tidak lagi ada `error` per lokasi, karena hanya ada satu
                 * lokasi dan kondisi database-tidak-bisa-dibaca sudah
                 * dibalas sebagai 503 di atas. Field-nya dihapus, bukan
                 * diganti `false`: kolom yang selalu `null`/`false` hanya
                 * membuat frontend tetap menyimpan logika untuk kondisi yang
                 * sudah mustahil terjadi.
                 */
            ]],
        ]);
    }

    /**
     * Terbitkan token untuk satu user LOKASI INI, tanpa memeriksa password.
     *
     * HANYA `user_id` yang diterima. Parameter `tenant_id` tidak lagi ada --
     * dan sengaja tidak dipakai meski masih dikirim frontend versi lama:
     * mengabaikannya membuat permintaan versi lama tetap saja dilayani untuk
     * lokasi yang sedang aktif, sementara tidak ada jalur yang bisa
     * mengarahkan token ke lokasi lain.
     */
    public function login(Request $request): JsonResponse
    {
        if (! config('shortcut_login.enabled')) {
            return $this->disabled();
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $tenant = $this->manager->current();

        if (! $tenant instanceof Tenant) {
            return $this->tanpaLokasi();
        }

        try {
            $user = $this->cariUser($data['user_id']);
        } catch (Throwable $e) {
            /*
             * Database lokasi tidak bisa dibaca saat mencari user. Pesannya
             * sama dengan yang dipakai `index()` untuk kondisi yang sama,
             * dan statusnya 503 -- masalah server, bukan salah parameter.
             */
            report($e);

            return $this->databaseTidakBisaDibuka($tenant);
        }

        /*
         * `null` = user tidak ada di database lokasi ini. Possibility-nya
         * nyata (user dihapus setelah halaman dibuka, atau `user_id` milik
         * lokasi lain yang diketik tangan) dan pesannya ditulis eksplisit
         * supaya tidak tertukar dengan "kredensial salah".
         */
        if ($user === null) {
            return $this->gagal('User tidak ditemukan di lokasi ini.');
        }

        /*
         * Gerbang tagihan tetap berlaku di pintasan ini.
         *
         * Tanpa ini, `/login/user` jadi jalur melewati penagihan: akun yang
         * `POST /login` tolak dengan 402 bisa tetap masuk lewat sini. Yang
         * diminta adalah "jalan pintas login", bukan "jalan pintas melewati
         * tagihan" -- dan membiarkan keduanya berbeda akan membuat popup
         * tagihan di halaman login berbohong.
         *
         * Status 402 (bukan 401) dengan bentuk payload yang sama persis
         * dengan `AuthController::login`, karena frontend sudah tahu cara
         * membacanya.
         */
        $blocking = $this->gate->blocking($tenant);

        if ($blocking->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => $this->gate->message($blocking),
                'code' => 'INVOICE_UNPAID',
                'data' => $this->gate->payload($tenant),
            ], 402);
        }

        try {
            $token = $this->terbitkan($user);
        } catch (Throwable $e) {
            /*
             * Token tidak bisa ditulis -- database lokasi tidak terbaca, atau
             * tabel `personal_access_tokens` belum ada (migrasi belum
             * dijalankan di lokasi itu).
             *
             * 503, bukan 500: masalahnya ada di server dan mungkin hilang
             * sendiri, sedangkan 500 membuat orang mengira aplikasinya rusak.
             */
            report($e);

            return $this->databaseTidakBisaDibuka($tenant);
        }

        $this->audit->record(
            'auth.login_shortcut',
            tenant: $tenant,
            subjectType: User::class,
            subjectId: $user->id,
            description: "{$user->name} ({$user->email}) masuk lewat pintasan `/login/user`.",
            properties: [
                'email' => $user->email,
                'role' => $user->role,
                'token_name' => 'auth_token',
            ],
            actor: $user,
        );

        /*
         * Generate jurnal piutang tunggakan, sama seperti di `POST /api/login`.
         *
         * Kenapa pintasan juga harus memanggilnya
         * ---------------------------------------
         *
         * Halaman `/login/user` adalah JALUR LOGIN yang sah -- bukan hanya
         * alat bantu development. Gejalanya kalau generate hanya ada di
         * `AuthController`: orang yang masuk lewat pintasan tidak pernah
         * menjalankan generate piutang, jadi:
         *
         *   - Tagihan menunggak tidak pernah dijurnalkan untuk lokasi itu.
         *   - `monthly_bills` tetap `unpaid`, tapi akun Piutang Usaha
         *     `1.1.03.01` tidak pernah bertambah -- Neraca dan daftar tagihan
         *     jadi dua sumber kebenaran yang berbeda.
         *   - Tidak ada error, tidak ada log, tidak ada tanda di mana pun.
         *
         * Dan gejalanya dari sisi user justru membingungkan: "toleransi
         * sudah diset 28, tapi kok tidak ada popup generate piutangnya?" --
         * padahal popunya memang tidak pernah muncul untuk login pintasan.
         *
         * Aturan yang dipakai di sini sama persis dengan login biasa:
         * generate jalan di SETIAP login PADA tanggal generate itu saja,
         * dan idempoten (tagihan yang sudah punya jurnal dilewati). Jadi
         * pintasan tidak membuat generate dobel -- ia cuma memastikan pintasan
         * tidak menjadi jalan yang MENGHILANGKAN generate.
         *
         * Kegagalan generate TIDAK membatalkan login: password sudah benar,
         * akun sudah aktif, dan tagihan langganan sudah lunas. Sisa pekerjaan
         * akuntansi internal tidak boleh menjadi alasan orang tidak bisa masuk.
         */
        $piutang = null;

        try {
            $piutang = $this->piutang->runIfDue();
        } catch (Throwable $e) {
            // Sabuk pengaman: `runIfDue()` sudah menangkap Throwable miliknya
            // sendiri. Ini hanya untuk jaga-jaga bila ada kegagalan di luar
            // sana (mis. koneksi cache mati) -- login tetap harus berhasil.
            report($e);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'token' => $token,
                /*
                 * Slug lokasi yang sedang dilayani.
                 *
                 * Dikirim di sini karena halaman ini selesai tanpa menghitung ulang
                 * nilai ini di frontend: `ShortcutLoginView.vue` menyimpannya
                 * lewat `simpanTenantSlug()`, sama seperti `LoginView.vue`
                 * melakukan setelah `POST /login`.
                 *
                 * Tanpa ini, `localStorage.tenant_slug` yang sudah ada dari
                 * sesi sebelumnya masih berisi lokasi LAMA -- dan setiap
                 * request sesudah login (termasuk `GET /api/branding`) akan
                 * membawa slug itu sebagai `X-Tenant-Slug`. Gejalanya
                 * dashboard terbuka dengan benar tapi branding milik lokasi
                 * lain, dan tidak ada satu pun error yang menyebutnya.
                 *
                 * `null` kalau tidak ada tenant aktif (install satu lokasi
                 * tanpa baris registry). Frontend membedakan `null` dari
                 * string kosong dan tidak mengirim header apa pun.
                 */
                'tenant_slug' => $tenant->slug,

                /*
                 * Ringkasan generate piutang tunggakan yang barusan jalan.
                 *
                 * Bentuknya PERSIS sama dengan yang dikirim `POST /api/login`,
                 * supaya frontend bisa menampilkan popup yang sama di kedua
                 * jalur login tanpa cabang khusus.
                 *
                 * `null` kalau generate tidak dijalankan: belum tanggal
                 * generate, atau prosesnya gagal. Keduanya kondisi normal
                 * yang tidak perlu popup apa pun.
                 */
                'piutang_tunggakan' => $piutang,
            ],
        ]);
    }

    /**
     * Ambil user dari database lokasi aktif.
     *
     * `DB::connection('tenant')` yang dipakai, bukan `User::query()`: lihat
     * catatan panjang di `index()` soal kenapa nama koneksi ditulis eksplisit.
     *
     * Kolom `password` ikut diambil hanya karena harus dibandingkan dengan
     * `Hash::check()`. Atribut `#[Hidden]` pada model hanya memengaruhi
     * serialisasi -- tidak menahan kolom yang dipilih eksplisit -- jadi di sini
     * yang dipakai `stdClass` mentah dan pemetaannya ditulis tangan di
     * {@see mapUsers()}.
     */
    private function cariUser(int $id): ?User
    {
        $baris = DB::connection(TenantManager::CONNECTION)
            ->table('users')
            ->where('id', $id)
            ->first(['id', 'name', 'email', 'role', 'password']);

        if ($baris === null) {
            return null;
        }

        /*
         * Model `User` dipakai di sini bukan untuk query, tapi karena
         * `createToken()` (Sanctum) dan `AuditLogger` butuh objek yang punya
         * relasi + atribut itu -- keduanya membaca `database.default`, yang
         * middleware sudah arahkan ke lokasi ini.
         *
         * `newFromBuilder()` bukan `User::find()`: ia tidak menjalankan query
         * kedua, dan tidak ada jalur di mana ID-nya bisa berarti user dari
         * database lain.
         */
        $user = (new User)->newFromBuilder((array) $baris);

        return $user->exists ? $user : null;
    }

    /**
     * Terbitkan token Sanctum untuk user yang sudah dipastikan ada.
     *
     * `createToken()` menulis ke `personal_access_tokens` lewat
     * `database.default`, yang untuk request ini adalah database lokasi aktif.
     *
     * Exception TIDAK ditangkap di sini. `login()` yang membingkusnya dalam
     * satu `try/catch` yang juga menutupi pembacaan user di atas, jadi
     * kegagalan menulis token dan kegagalan membaca user ditangani dengan
     * pesan yang sama -- dan memang seharusnya begitu: keduanya berarti
     * database lokasi yang tidak bisa dibuka, yang gejalanya bukan salah
     * parameter dan bukan kredensial.
     */
    private function terbitkan(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    /**
     * Bentuk respons tiap user, beserta password demo-nya kalau bisa dipastikan.
     *
     * Dipetakan dari `stdClass` hasil query mentah, bukan dari model, supaya
     * kolom `password` yang terpilih tidak ikut ter-serialize. Bentuk nilainya
     * dua jenis, bukan boolean:
     *
     *   - string  -> password demo, boleh diketik manual
     *   - null    -> tidak diketahui, tampil `••••••`
     *
     * Yang dikirim ke browser adalah teks password untuk password yang
     * benar-benar lemah dan sudah terdokumentasi -- bukan kebocoran. Hash-nya
     * sendiri tidak pernah dikirim.
     */
    private function mapUsers(Collection $users): Collection
    {
        $reveal = (bool) config('shortcut_login.reveal_password', true);

        return $users
            ->map(function (object $user) use ($reveal) {
                return [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                    'role' => (string) $user->role,
                    'password' => $reveal ? $this->passwordDemoFor($user) : null,
                ];
            })
            ->values();
    }

    /**
     * Password demo yang cocok, atau null kalau tidak ada yang cocok.
     *
     * `Hash::check()` satu kali per kandidat. Harusnya murah pada daftar ini
     * (biasanya <= 3 nilai), tapi inilah alasan `per_location_limit` ada:
     * tanpa batas, jumlah user x jumlah kandidat x biaya bcrypt bisa membuat
     * satu request berjalan sangat lama.
     *
     * Tidak ada cache: `Hash::check()` tidak bisa di-invert, jadi hasil yang
     * disimpan untuk dipakai lagi harus disimpan per-user anyway -- dan
     * daripada menambah state, endpoint ini cukup dibaca sekali per muat
     * halaman.
     */
    private function passwordDemoFor(object $user): ?string
    {
        foreach ((array) config('shortcut_login.demo_passwords', []) as $candidate) {
            if ($candidate !== '' && Hash::check($candidate, (string) $user->password)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Berapa banyak user yang boleh ditampilkan.
     *
     * Satu lokasi saja, jadi `max_tenants` yang dulu membatasi jumlah lokasi
     * per request tidak lagi punya objek untuk dibatasi: yang tersisa hanyalah
     * jumlah user, dan itulah yang dikembalikan.
     *
     * Kuncinya `per_location_limit`, bukan `per_tenant_limit` yang lama. Nama
     * yang lama berbau "per tenant" padahal yang diBATAS hanya user di satu
     * lokasi -- jadi memakai ulang kunci itu akan membuat operator salah
     * paham maksudnya. Kunci lama tidak ikut dibaca sebagai fallback:
     * diam-diam memakai nilai lama setelah konfigurasi diubah adalah bentuk
     * perubahan yang tidak terlihat.
     */
    private function limit(): int
    {
        return max(1, (int) config('shortcut_login.per_location_limit', 100));
    }

    /**
     * 503 untuk database lokasi yang tidak bisa dibaca.
     *
     * Dipakai di tiga tempat (membaca daftar, mencari user, menerbitkan
     * token) karena ketiganya berarti hal yang sama dan pesannya harus sama:
     * yang rusak adalah database lokasi, bukan input, dan bukan kredensial.
     *
     * Menyebutkan slug lokasi di dalam pesan penting karena inilah satu-satunya
     * tempat pengguna diberi tahu LOKASI MANA yang bermasalah -- endpoint ini
     * tidak lagi menerima `tenant_id` maupun menampilkan daftar lokasi, jadi
     * tanpa nama di sini orang hanya melihat "database tidak bisa dibuka"
     * tanpa tahu harus datang ke mana.
     */
    private function databaseTidakBisaDibuka(Tenant $tenant): JsonResponse
    {
        return $this->gagal(
            'Database lokasi tidak bisa dibuka. Periksa lokasi `'
            .$tenant->slug.'` di panel pusat.',
            503
        );
    }

    /** 404 yang sama untuk halaman dan endpoint yang dimatikan lewat config. */
    private function disabled(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Login pintasan dimatikan. Set SHORTCUT_LOGIN_ENABLED=true untuk mengaktifkannya.',
        ], 404);
    }

    /**
     * 404 untuk request yang tidak punya lokasi yang sedang dilayani.
     *
     * BUKAN 503 dan BUKAN daftar kosong. Dua-duanya menyesatkan: 503 membuat
     * frontend mencoba lagi (padahal request-nya akan selalu sama -- tidak ada
     * host maupun header yang menunjuk lokasi), sedangkan daftar kosong
     * terlihat seperti lokasi yang memang belum punya akun, dan itu sendiri
     * adalah kesimpulan yang salah.
     *
     * Pesannya menyebut cara memperbaikinya: buka lewat domain lokasi, atau
     * -- di development dengan `TENANCY_LOOKUP=slug` -- kirim header
     * `X-Tenant-Slug`. Tanpa kedua petunjuk itu, gejalanya "halaman selalu
     * kosong" yang tidak pernah menyuruh siapa pun melihat `.env`.
     */
    private function tanpaLokasi(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Tidak ada lokasi yang melayani request ini. '
                .'Buka lewat domain lokasi, atau kirim header X-Tenant-Slug dengan slug lokasi '
                .'(hanya aktif di development, lihat TENANCY_LOOKUP).',
            'code' => 'NO_TENANT',
        ], 404);
    }

    /**
     * Respons gagal yang eksplisit.
     *
     * Status default 422 (parameter salah / user tidak ada). Database lokasi
     * yang tidak bisa dibuka memakai 503 -- itu masalah server, bukan salah
     * input, dan membedakannya penting: 422 membuat frontend mencoba mengirim
     * ulang dengan data berbeda, sedangkan 503 berarti "coba lagi nanti".
     */
    private function gagal(string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
