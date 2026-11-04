<?php

namespace App\Support;

use App\Models\Central\Tenant;

/**
 * Sumber tunggal untuk isi dan warna halaman login sebuah lokasi.
 *
 * Kenapa kelas ini ada, bukan sekadar membaca kolom apa adanya
 * =============================================================
 *
 * Tiga tempat butuh isi yang sama, dan ketiganya tidak boleh punya aturan
 * fallback sendiri:
 *
 *   1. `PublicBrandingController` -- yang dilihat pengunjung di halaman login
 *   2. `LocationController::present()` -- panel pusat, supaya admin melihat
 *      persis apa yang akan tampil
 *   3. Form panel pusat, yang butuh nilai bawaan sebagai placeholder
 *
 * Kalau ketiganya punya daftar bawaan sendiri, perubahan satu baris di salah
 * satunya akan terlihat sebagai perubahan data: panel pusat menampilkan teks
 * bawaan untuk tenant yang halamannya sebenarnya masih menampilkan teks
 * bawaan yang lebih lawas.
 *
 * Yang dikembalikan di sini sudah berupa nilai final -- tidak ada `null`
 * yang berarti "pakai bawaan" yang harus ditafsirkan lagi di frontend. Form
 * panel pusat butuh nilai yang berbeda (kosong berarti "hapus dan kembali ke
 * bawaan"), jadi teks bakunya diekspos terpisah lewat `defaultJudul()`,
 * bukan dengan mengirim `null`.
 *
 * MengasmUSCtaukan "kosong" di satu tempat juga penting karena string kosong dan
 * spasi saja sama-sama berarti hal yang sama secara visual: admin yang mengetik
 * spasi lalu menyimpan tidak sedang meminta judul kosong -- dia sedang mengetik
 * judul yang tidak terlihat ada isinya.
 */
class Branding
{
    /** Judul bawaan: baris pertama headline. */
    public const DEFAULT_JUDUL = 'ASTALOKA';

    /** Sub judul bawaan: baris kedua headline, yang diberi warna/gradien. */
    public const DEFAULT_SUB_JUDUL = 'Digital Platform';

    /**
     * Deskripsi bawaan: paragraf di bawah headline.
     *
     * Sengaja dipakai kalimat yang sama dengan hero landing page
     * (`resources/views/landing.blade.php`) -- "Asta Layanan Operasional,
     * Keuangan, dan Administrasi" adalah expandan nama ASTALOKA yang sudah
     * dipakai di `<title>`, di hero, dan di footer sana.
     *
     * Bolak-balik antara "air bersih" dan "internet" yang baru saja terjadi
     * berakar pada bawaan yang lama: "Kelola kebutuhan air bersih dengan
     * lebih transparan, mudah, dan terintegrasi dalam satu sentuhan
     * digital." Kalimat itu berasal dari era PAMSIDES, ketika aplikasi ini
     * memang hanya untuk air. Setelah nama produk diganti menjadi ASTALOKA
     * -- yang melayani air minum, internet, sampah, dan usaha apa pun
     * (lihat section "fitur" di landing page) -- kalimat lama itu jadi
     * MENYESATKAN di halaman login: orang yang masuk lewat subdomain
     * internet atau sampah akan melihat janji air bersih.
     *
     * Karena itu bawaan di sini SAMA untuk semua lokasi dan hanya menyebut
     * cakupan yang benar. Lokasi yang punya kalimat sendiri mengisinya lewat
     * kolomnya, bukan dengan mengubah konstanta ini.
     */
    public const DEFAULT_DESKRIPSI = 'Asta Layanan Operasional, Keuangan, dan Administrasi. Satu sistem untuk berbagai jenis usaha: air minum, internet, pembayaran sampah, jasa, dan layanan lainnya.';

    /**
     * Warna bawaan.
     *
     * `sky-500` (#0ea5e9), bukan biru Tailwind. Dipilih supaya warna bawaan
     * aplikasi ini persis sama dengan token `--color-primary` di `main.css`,
     * yang dipakai seluruh halaman lokasi -- kalau bawaannya berbeda, maka
     * "pakai warna bawaan" akan terlihat seperti perubahan tampilan, padahal
     * tidak ada yang berubah.
     */
    public const DEFAULT_WARNA = '#0ea5e9';

    /**
     * Warna yang boleh dipilih, dan labelnya.
     *
     * Daftar tertutup, bukan warna bebas, karena warna ini jadi identitas
     * seluruh aplikasi lokasi -- sidebar, tombol, tabel, semua ikut. Warna
     * bebas membuat lokasi bisa memilih merah yang clash dengan warna
     * "danger" aplikasi, dan yang lebih buruk: yang clash adalah tombol
     * "Hapus" (merah) dan tombol utama (yang jadi merah juga), sehingga dua
     * aksi yang berlawanan terlihat sama.
     *
     * Nilai diambil dari palet emerald/green/etc di Aura (PrimeVue) supaya
     * "hijau" yang dipilih di sini menghasilkan komponen PrimeVue yang serasi
     * satu sama lain -- bukan sekadar hijau di CSS kustom dan hijau berbeda di
     * tombol `ToggleSwitch`.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const WARNA_TERSEDIA = [
        'biru' => ['#0ea5e9', 'Biru Muda (Sky)'],
        'biru-tua' => ['#2563eb', 'Biru (Blue)'],
        'hijau' => ['#10b981', 'Hijau (Emerald)'],
        'hijau-tua' => ['#059669', 'Hijau Tua (Emerald)'],
        'teal' => ['#14b8a6', 'Teal'],
        'ungu' => ['#8b5cf6', 'Ungu (Violet)'],
        'jingga' => ['#f59e0b', 'Jingga (Amber)'],
        'merah' => ['#ef4444', 'Merah (Red)'],
    ];

    /**
     * Pola warna hex yang valid.
     *
     * WAJIB sepanjang 7 karakter (`#` lalu 6 digit heksadesimal), dan huruf
     * besar ikut diterima lalu disimpan apa adanya.
     *
     * Enam karakter tanpa `#` ditolak, bukan diterima dan diterjemahkan: nilai
     * warna ini dipakai apa adanya di `color-mix()` dan di komputasi lightness
     * frontend, jadi bentuk yang berbeda akan menghasilkan warna yang
     * berbeda dari yang dipilih admin -- tanpa error apa pun yang menyinggungnya.
     */
    public const POLA_WARNA = '/^#[0-9a-fA-F]{6}$/';

    /**
     * Warna hex untuk sebuah tenant, sudah turun ke bawaan bila kosong atau
     * tidak valid.
     */
    public static function warna(Tenant $tenant): string
    {
        $hex = is_string($tenant->warna_utama) ? trim($tenant->warna_utama) : '';

        if ($hex === '' || ! preg_match(self::POLA_WARNA, $hex)) {
            return self::DEFAULT_WARNA;
        }

        return strtolower($hex);
    }

    /**
     * Isi halaman login sebuah tenant, sudah final.
     *
     * Semua nilai sudah terisi: tidak ada `null` yang berarti "pakai bawaan".
     *
     * @return array{judul: string, sub_judul: string, deskripsi: string, warna: string}
     */
    public static function for(Tenant $tenant): array
    {
        return [
            'judul' => self::teks($tenant->judul_login, self::DEFAULT_JUDUL),
            'sub_judul' => self::teks($tenant->sub_judul_login, self::DEFAULT_SUB_JUDUL),
            'deskripsi' => self::teks($tenant->deskripsi_login, self::DEFAULT_DESKRIPSI),
            'warna' => self::warna($tenant),
        ];
    }

    /**
     * Branding bawaan, tanpa perlu sebuah tenant.
     *
     * Dipakai endpoint publik saat tidak ada lokasi yang melayani request
     * (domain pusat, atau subdomain yang tidak terdaftar). Bentuknya sama
     * persis dengan `for()`, jadi frontend tidak perlu memeriksa "ini tenant
     * atau bukan" sebelum memakainya.
     *
     * @return array{judul: string, sub_judul: string, deskripsi: string, warna: string}
     */
    public static function default(): array
    {
        return [
            'judul' => self::DEFAULT_JUDUL,
            'sub_judul' => self::DEFAULT_SUB_JUDUL,
            'deskripsi' => self::DEFAULT_DESKRIPSI,
            'warna' => self::DEFAULT_WARNA,
        ];
    }

    /**
     * Isi yang benar-benar tersimpan, TANPA turun ke bawaan.
     *
     * Dipakai `LocationController::present()` supaya panel pusat menampilkan
     * keadaan sebenarnya: tenant yang kolomnya kosong tampil kosong, bukan
     * menampilkan teks bawaan seolah admin yang mengetiknya. Kalau panel
     * menampilkan bawaan, orang akan mengira isian itu sudah sengaja diisi
     * dan tidak akan pernah menyentuhnya.
     *
     * `warna` tetap diturunkan ke bawaan di sini, dan itu disengaja: panel
     * pusat memakai nilai warna itu untuk menggambar contoh warna, dan contoh
     * itu tidak bisa menggambar "tidak ada warna" -- yang akan tampil sebagai
     * kotak kosong dan dibaca sebagai bug, bukan sebagai "pakai warna bawaan".
     *
     * @return array{judul: ?string, sub_judul: ?string, deskripsi: ?string, warna: string}
     */
    public static function raw(Tenant $tenant): array
    {
        return [
            'judul' => self::teksMentah($tenant->judul_login),
            'sub_judul' => self::teksMentah($tenant->sub_judul_login),
            'deskripsi' => self::teksMentah($tenant->deskripsi_login),
            'warna' => self::warna($tenant),
        ];
    }

    /** Judul bawaan, untuk placeholder form panel pusat. */
    public static function defaultJudul(): string
    {
        return self::DEFAULT_JUDUL;
    }

    /** Sub judul bawaan, untuk placeholder form panel pusat. */
    public static function defaultSubJudul(): string
    {
        return self::DEFAULT_SUB_JUDUL;
    }

    /** Deskripsi bawaan, untuk placeholder form panel pusat. */
    public static function defaultDeskripsi(): string
    {
        return self::DEFAULT_DESKRIPSI;
    }

    /** Warna bawaan, untuk label form panel pusat. */
    public static function defaultWarna(): string
    {
        return self::DEFAULT_WARNA;
    }

    /**
     * String kosong (atau spasi saja) berarti "tidak diisi", jadi turun ke
     * bawaan.
     */
    private static function teks(?string $nilai, string $bawaan): string
    {
        return self::teksMentah($nilai) ?? $bawaan;
    }

    /** `null` untuk kosong -- bukan string kosong, supaya bedanya jelas. */
    private static function teksMentah(mixed $nilai): ?string
    {
        if (! is_string($nilai)) {
            return null;
        }

        $hasil = trim($nilai);

        return $hasil === '' ? null : $hasil;
    }
}
