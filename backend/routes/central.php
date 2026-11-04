<?php

use App\Http\Controllers\Central\CentralAccountController;
use App\Http\Controllers\Central\CentralAuthController;
use App\Http\Controllers\Central\CentralMenuAccessController;
use App\Http\Controllers\Central\CentralMigrationController;
use App\Http\Controllers\Central\CoaController;
use App\Http\Controllers\Central\DashboardController;
use App\Http\Controllers\Central\InvoiceController;
use App\Http\Controllers\Central\LocationController;
use App\Http\Controllers\Central\PublicLocationController;
use App\Http\Controllers\Central\SuperadminController;
use App\Http\Controllers\Central\TenantDomainController;
use App\Http\Controllers\MyInvoiceController;
use App\Models\Central\AuditLog;
use App\Models\Central\Tenant;
use App\Models\Central\TenantInvoice;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenancy & Panel Pusat (ASTA BRATA)
|--------------------------------------------------------------------------
|
| Dipisah ke file sendiri karena domainnya berbeda: ini bekerja pada
| DATABASE PUSAT (registry lokasi, user superadmin, invoice langganan), bukan
| pada data operasional lokasi. Middleware `superadmin` mengembalikan koneksi
| default ke database pusat dan membatasi akses hanya untuk role superadmin.
|
| Route `tenant` di sini menandai endpoint yang HANYA untuk domain pusat.
| Di subdomain lokasi, route yang sama tidak boleh aktif -- itu sebabnya
| middleware superadmin menolak role non-superadmin, dan halaman depan
| (web.php) yang mengarahkan subdomain ke login langsung.
|
*/

// --- Login superadmin -----------------------------------------------------
Route::prefix('central')->group(function () {
    Route::post('/login', [CentralAuthController::class, 'login'])
        ->middleware(['throttle:login', 'throttle:login-ip']);

    /*
     | Daftar lokasi untuk dropdown di landing page.
     |
     | Publik, tapi hanya mengembalikan nama + slug. Endpoint di dalam grup
     | `auth:sanctum` di bawah tetap menyimpan data lengkap (email, alamat,
     | tagihan) -- jangan menggabungkan keduanya hanya agar landing page
     | lebih sederhana.
     |
     | Throttle-nya longgar (30/menit) karena setiap pengunjung halaman
     | depan memanggil ini sekali, tapi tidak dari peramban bot yang
     | memuat ulang terus.
     */
    Route::get('/lokasi', [PublicLocationController::class, 'index'])
        ->middleware('throttle:30,1');

    Route::middleware(['auth:sanctum', 'superadmin'])->group(function () {
        Route::post('/logout', [CentralAuthController::class, 'logout']);

        // Registry lokasi
        Route::apiResource('locations', LocationController::class);

        /*
         | Mengaktifkan kembali lokasi yang sudah dinonaktifkan.
         |
         | Endpoint TERPISAH dari `DELETE /locations/{location}` yang
         | hanya bisa menonaktifkan. DELETE di sini berarti "matikan", dan
         | endpoint yang sama tidak boleh dipakai untuk membalikkannya --
         | kalau boleh, DELETE bisa mengembalikan lokasi, dan satu skrip
         | yang salah bisa membangunkan lokasi yang sengaja dimatikan.
         |
         | `PATCH`, bukan `PUT`: yang diaktifkan adalah SATU bagian dari
         | lokasi -- statusnya -- bukan seluruh isi lokasi. PUT di sini
         | akan dibaca sebagai "ganti seluruh data lokasi ini".
         */
        Route::patch('locations/{location}/activate', [LocationController::class, 'activate']);

        /*
         | Hapus lokasi PERMANEN, beserta database dan seluruh datanya.
         |
         | Berbeda dari `DELETE /locations/{location}` yang hanya mematikan
         | lokasi tanpa menyentuh database. Yang di sini menghapus semuanya
         | dan tidak bisa dibatalkan -- jadi body request WAJIB berisi slug
         | lokasi yang tepat (lihat `forceDestroy()`).
         |
         | Path-nya `/locations/{location}/force-delete`, bukan
         | `/locations/{location}/hapus`: penamaan "force delete" sudah
         | dipakai di Laravel untuk operasi yang tidak bisa dibatalkan,
         | dan itu persis sifat yang dimiliki endpoint ini.
         */
        Route::delete('locations/{location}/force-delete', [LocationController::class, 'forceDestroy']);

        /*
         | Daftar sektor yang boleh dipilih untuk sebuah tenant.
         |
         | Terpisah dari `apiResource` dan diletakkan SEBELUMnya dengan path
         | eksplisit, bukan `/locations/sektor` di dalam resource -- kalau
         | begitu, `sektor` akan dibaca sebagai `show({location})` dan Form
         | Request akan menolak string "sektor" yang bukan angka.
         */
        Route::get('sektor', [LocationController::class, 'sektor']);

        /*
         | Warna branding yang boleh dipilih untuk sebuah lokasi.
         |
         | Sama seperti `sektor` di atas: daftar milik FORM, dan form
         | harus menawarkan persis apa yang backend terima. Warna di sini
         | menentukan identitas seluruh aplikasi lokasi (sidebar, tombol,
         | tabel), jadi warna yang lolos tanpa sengaja lewat payload akan
         | jadi warna aplikasi tanpa pernah ditinjau -- dan backend menolak
         | dengan 422 yang tidak menyebut warna mana yang salah.
         |
         | Path eksplisit di luar `apiResource`, dengan alasan yang sama
         | seperti sektor: `/locations/warna` akan dibaca sebagai
         | `show({location})`.
         */
        Route::get('warna', [LocationController::class, 'warnaBranding']);

        /*
         | Domain custom tiap lokasi.
         |
         | Dua bentuk URL untuk operasi yang sama:
         |
         |   /domains, /domains/{domain}      -> panel pusat, lintas lokasi
         |   /locations/{location}/domains    -> ikut di halaman detail lokasi
         |
         | Keduanya memanggil method yang sama. Endpoint per-lokasi bukan
         | cuma alias: `LocationController::show()` memuat lokasi beserta
         | invoice-nya, sementara daftar domain untuk form edit butuh bentuk
         | sendiri -- dan mencampurkannya ke sana akan membuat setiap pembukaan
         | halaman lokasi ikut menarik seluruh domain, walaupun sebagian besar
         | hanya satu yang ditampilkan.
         |
         | {domain} di-bind ke model lewat implicit binding, jadi param harus
         | berupa id angka.
         */
        Route::get('domains', [TenantDomainController::class, 'index']);
        Route::post('domains', [TenantDomainController::class, 'store']);
        Route::patch('domains/{domain}', [TenantDomainController::class, 'update']);
        Route::delete('domains/{domain}', [TenantDomainController::class, 'destroy']);

        Route::get('locations/{location}/domains', [TenantDomainController::class, 'indexForTenant']);
        Route::post('locations/{location}/domains', [TenantDomainController::class, 'store']);

        /*
         | Hak akses menu per jabatan, untuk SATU lokasi.
         |
         | Dipindah dari aplikasi lokasi ke sini karena menyetelnya dari
         | sana memaksa logout-login berulang: tiap lokasi punya sesinya
         | sendiri, jadi menyetel lima lokasi berarti lima kali masuk ke
         | domain yang berbeda. Dari panel pusat, satu sesi cukup untuk
         | semuanya.
         |
         | Tabel `jabatan_menu_access` tetap milik database LOKASI, jadi
         | controller di dalamnya memakai `TenantManager::runFor()` per
         | permintaan. Tidak ada tabel baru di pusat, dan tidak ada lokasi
         | lama yang kehilangan pengaturannya karena pindah.
         |
         | Path eksplisit di luar `apiResource`, dengan alasan yang sama
         | seperti `sektor` dan `warna` di atas: `/locations/{location}`
         | akan dibaca sebagai `show({location})`.
         |
         | `{jabatan}` dikunci `whereNumber`. Tanpa itu, `/menu-access/abc`
         | lolos ke controller dan membuat PHP melemparkan TypeError saat
         | mengisi parameter bertipe `int` -- jadi 500 dengan pesan yang tidak
         | menyinggung apa pun. 404 jauh lebih jujur untuk path yang memang
         | salah.
         |
         | `POST .../menu-access` membuat jabatan BARU di lokasi itu. Berada
         | di route yang sama, bukan di `/jabatans` terpisah, karena yang
         | dikelola di halaman ini sebenarnya satu hal: daftar jabatan
         | beserta hak aksesnya. Jabatan tidak bisa dibuat di aplikasi lokasi
         | -- `runFor()` dari panel pusat adalah satu-satunya jalur yang
         | menulis ke tabel `jabatans` saat ini -- jadi kalau disembunyikan ke
         | menu lain, superadmin akan mencari-cari tempat yang tidak ada.
         */
        Route::get('locations/{location}/menu-access', [CentralMenuAccessController::class, 'index']);
        Route::post('locations/{location}/menu-access', [CentralMenuAccessController::class, 'store']);
        Route::put('locations/{location}/menu-access/{jabatan}', [CentralMenuAccessController::class, 'update'])
            ->whereNumber('jabatan');
        Route::post('locations/{location}/menu-access/{jabatan}/reset', [CentralMenuAccessController::class, 'reset'])
            ->whereNumber('jabatan');

        /*
         | User pusat (superadmin).
         |
         | TIDAK memakai `apiResource`. Endpoint create dan update memang ada,
         | tapi destroy tidak ada: menghapus baris user pusat membuat jejak
         | audit lama menunjuk id yang sudah tidak ada artinya, dan bisa
         | menghilangkan superadmin terakhir tanpa peringatan. Menonaktifkan
         | mencapai tujuan yang sama tanpa salah satu dari dua akibat itu --
         | dan karena token ikut dicabut, hasilnya secepat menghapus.
         |
         | {user} di-bind ke string, bukan ke model `User`: model itu memakai
         | `database.default` yang belum tentu menunjuk database pusat, dan
         | query builder di controller sudah dijaga koneksinya eksplisit.
         */
        Route::get('superadmins', [SuperadminController::class, 'index']);
        Route::post('superadmins', [SuperadminController::class, 'store']);
        Route::patch('superadmins/{user}', [SuperadminController::class, 'update'])
            ->whereNumber('user');

        // Tagihan langganan
        Route::apiResource('invoices', InvoiceController::class)->except(['update', 'destroy']);
        Route::post('invoices/{invoice}/paid', [InvoiceController::class, 'markPaid']);
        Route::post('invoices/{invoice}/unpaid', [InvoiceController::class, 'markUnpaid']);

        /*
         * Rekening penampung pembayaran tagihan.
         *
         * Terpisah dari `invoices` dan dari `coa` karena ketiganya
         * menjawab pertanyaan yang berbeda: `invoices` berapa yang
         * ditagihkan, `coa` kerangka akun milik semua lokasi, dan ini
         * rekening milik pusat yang benar-benar dipakai untuk
         * menerima uang.
         *
         * TIDAK ada `destroy`. Baris yang pernah dipakai pembayaran
         * menunjuk ke sini, jadi menghapusnya membuat riwayat
         * pembayaran menunjuk rekening yang tidak ada. `PATCH` dengan
         * `is_active: false` mencapai tujuan yang sama tanpa akibat itu.
         *
         * `{account}` di-bind ke model, dan model itu mengunci
         * `$connection = 'central'`, jadi route ini tidak pernah bisa
         * membaca tabel lokasi lewat nama tabel yang mirip.
         */
        Route::get('rekening', [CentralAccountController::class, 'index']);
        Route::post('rekening', [CentralAccountController::class, 'store']);
        Route::patch('rekening/{account}', [CentralAccountController::class, 'update']);
        Route::get('rekening/{account}/mutasi', [CentralAccountController::class, 'payments']);

        /*
         * Cetak invoice.
         *
         * Path eksplisit, bukan method terpisah pada resource, karena
         * `apiResource` di sini sudah memakai `show` untuk mengembalikan
         * JSON yang dipakai panel pusat.
         *
         * Tidak bentrok dengan `invoices/{invoice}` meski dideklarasikan
         * sesudahnya: pola itu dua segmen, sedangkan URL PDF tiga segmen
         * (`invoices/5/pdf`), jadi tidak pernah cocok. `whereNumber` tetap
         * ditambahkan sebagai pengaman -- tanpa itu `/invoices/abc/pdf`
         * lolos ke model binding dan gagal dengan pesan yang tidak
         * menjelaskan apa pun.
         */
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])
            ->whereNumber('invoice');

        /*
         * Agregat untuk dashboard pusat.
         *
         * Endpoint ini menggantikan hitungan manual dari `/locations` +
         * `/invoices` yang dulu dipakai dashboard. Yang membedakannya bukan
         * bentuk JSON, tapi sumber angkanya: `invoices` dipaginasi 50 baris,
         * sehingga menjumlahkannya dari sana menghasilkan total yang hanya
         * menghitung 50 tagihan terbaru -- dan lokasi yang tagihannya di
         * luar itu terlihat lunas tanpa ada error yang mengatakannya.
         *
         * `/overview` di bawah sengaja dibiarkan apa adanya: isinya masih
         * dipakai, dan mengubah bentuk responsnya akan mengubah sekalian
         * semua yang memakainya.
         */
        Route::get('dashboard', [DashboardController::class, 'index']);

        // Ringkasan untuk dashboard pusat
        Route::get('overview', function () {
            $lokasi = Tenant::count();
            $aktif = Tenant::where('is_active', true)->count();
            $belumLunas = TenantInvoice::where('status', 'UNPAID')->count();
            $nilaiTunggak = (float) TenantInvoice::where('status', 'UNPAID')->sum('jumlah');

            $terlambat = TenantInvoice::query()
                ->where('status', 'UNPAID')
                ->get()
                ->filter(fn ($i) => $i->isOverdue())
                ->count();

            $auditHariIni = AuditLog::whereDate('created_at', today())->count();
            $auditGagal = AuditLog::whereDate('created_at', today())
                ->where('action', 'like', '%failed')
                ->count();

            return response()->json([
                'success' => true,
                'data' => compact(
                    'lokasi', 'aktif', 'belumLunas', 'nilaiTunggak', 'terlambat',
                    'auditHariIni', 'auditGagal'
                ),
            ]);
        });

        /*
         | Struktur akun level 1-3 (chart of accounts).
         |
         | Level 1-3 disimpan di database pusat dan berlaku untuk SEMUA lokasi.
         | Endpoint di sini satu-satunya jalan untuk menyuntingnya.
         |
         | Level datang dari PATH, bukan body request. Kalau level boleh dikirim
         | klien, satu request bisa menulis ke tabel yang salah tanpa jejak.
         |
         | Store dan destroy ADA, dengan dua batasan yang diurus di
         | `CoaController` dan {@see \App\Services\CoaCascadeService}:
         |
         |   1. Penghapusan berjenjang. Menghapus level 1/2 ikut menghapus
         |      turunannya di pusat; menghapus level 3 ikut menghapus akun
         |      level 4 yang menunjuknya di SETIAP lokasi. Daun dulu, batang
         |      kemudian.
         |   2. Akun level 4 yang sudah punya data keuangan (amount, ebudgeting,
         |      transactions) MENAHAN penghapusan dan dijawab 409 -- supaya
         |      yang hilang adalah struktur yang tidak dipakai, bukan pembukuan
         |      yang sudah terjadi.
         |
         | `impact` terpisah dari `destroy` karena frontend harus tahu dampaknya
         | SEBELUM menekan tombol. Kalau dialognya menghitung dari pemindaian
         | yang berbeda dengan yang dipakai untuk menghapus, jumlah yang
         | ditampilkan bisa berbeda dari jumlah yang benar-benar hilang.
         |
         | `id` dan `parent_id` tetap tidak bisa disunting -- keduanya adalah
         | acuan `accounts.parent_id` di setiap database lokasi dan tidak punya
         | foreign key yang menegakkannya. Lihat {@see CoaController}.
         */
        Route::get('coa', [CoaController::class, 'index']);
        Route::get('coa/level/{level}/{id}', [CoaController::class, 'show'])
            ->whereNumber('level')->whereNumber('id');
        Route::post('coa/level/{level}', [CoaController::class, 'store'])
            ->whereNumber('level');
        Route::get('coa/level/{level}/{id}/impact', [CoaController::class, 'impact'])
            ->whereNumber('level')->whereNumber('id');
        Route::patch('coa/level/{level}/{id}', [CoaController::class, 'update'])
            ->whereNumber('level')->whereNumber('id');

        /*
         | `confirm` -- kode akun yang wajib diketik ulang superadmin -- dikirim
         | lewat BODY, bukan query string, supaya isinya ada di payload request
         | yang sudah dicatat middleware, bukan tercampur ke URL yang juga masuk
         | access log.
         */
        Route::delete('coa/level/{level}/{id}', [CoaController::class, 'destroy'])
            ->whereNumber('level')->whereNumber('id');

        /*
         | Migrasi data ke database SATU lokasi.
         |
         | Setiap endpoint membawa lokasi dan jenis data di PATH, bukan di
         | body. Alasannya bukan gaya URL: karena satu lokasi = satu
         | database, "data ini masuk ke mana" harus bisa dibaca dari log
         | akses tanpa melihat isi request. Kalau lokasi ada di body,
         | POST /migrasi-data/commit tidak bisa dijawab dari log --
         | jadi tidak punya jawaban yang bisa dicari.
         |
         | `{location}` boleh berupa slug ATAU id, ditangani di controller
         | seperti pada controller central lain.
         |
         | TIDAK memakai `apiResource`. Endpoint-nya memang berbentuk CRUD
         | mengembalikan daftar jenis migrasi yang bukan baris database,
         | `show` tidak ada, dan `store` punya dua tahap yang harus dipanggil
         | berurutan dengan `hash` dari tahap pertama. `apiResource` akan
         | membangkitkan URL yang tidak ada artinya di sini.
         |
         | whereNumber('id') pada endpoint unduh log: tanpa itu,
         | `/log/nope` lolos ke controller dan gagal dengan TypeError yang
         | tidak menyinggung apa pun.
         |
         | Limiter `uploads` di endpoint yang menerima file. Limiter itu sudah
         | ada di `AppServiceProvider::configureRateLimiters()` tapi belum
         | dipakai route mana pun -- jadi ini yang pertama yang benar-benar
         | menerapkannya.
         */
        Route::get('migrasi-data', [CentralMigrationController::class, 'index']);

        Route::get('migrasi-data/{location}/{type}/template', [CentralMigrationController::class, 'template']);
        Route::post('migrasi-data/{location}/{type}/validasi', [CentralMigrationController::class, 'validate'])
            ->middleware('throttle:uploads');
        Route::post('migrasi-data/{location}/{type}/commit', [CentralMigrationController::class, 'commit'])
            ->middleware('throttle:uploads');
        Route::post('migrasi-data/{location}/{type}/log-gagal', [CentralMigrationController::class, 'logGagal']);
    });
});

/*
|--------------------------------------------------------------------------
| Tagihan Langganan -- Pandangan User Lokasi
|--------------------------------------------------------------------------
|
| Route ini dipakai user lokasi, bukan superadmin, dan TIDAK butuh tenant
| context di URL: lokasi ditentukan dari subdomain oleh ResolveTenant.
|
| Harus masuk daftar `tenancy.invoice_exempt_paths` supaya tetap bisa diakses
| ketika tagihan terlambat.
|
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('tagihan-saya', [MyInvoiceController::class, 'index']);
    Route::get('tagihan-saya/summary', [MyInvoiceController::class, 'summary']);
});
