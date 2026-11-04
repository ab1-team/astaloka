<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ikon di halaman depan.
 *
 * Dua-duanya adalah cacat yang TIDAK PERNAH muncul sebagai error di
 * console atau di log: halaman tetap render, markup tetap valid, tidak
 * ada exception. Yang rusak hanya tampilan -- dan orang yang sedang
 * merapikan bagian halaman lain tidak akan berhenti untuk melihat satu
 * kotak abu yang misterius.
 *
 * Karena itu test ini ada: dua-duanya cacat visual yang lolos dari
 * setiap mekanisme lain di proyek ini.
 *
 * 1. Slot ikon kartu "Laporan siap" (`.art-card`) tidak pernah punya isi.
 *    Bentuknya `::before` yang HANYA berisi kotak semi transparan --
 *    jadi yang tampil adalah kotak kosong di atas judul. Di layar
 *    terbaca sebagai kotak abu yang tidak punya makna, bukan sebagai
 *    ikon.
 *
 * 2. Lambang WhatsApp (`i-wa`) menyatu jadi gumpalan bulat di mode solid.
 *    Simbolnya punya DUA `<path>` terpisah: siluet gelembung dan handset
 *    di dalamnya. Di mode garis hasilnya benar, tapi di mode solid
 *    (`.i-solid`, dipakai di tombol "Minta Demo Gratis") keduanya dapat
 *    `fill: currentColor` yang sama -- handset tidak lagi jadi lubang,
 *    tapi menyatu dengan gelembung. Ikon yang tampil: titik hitam.
 *
 * Yang diuji di sini bukan gambar, tapi STRUKTUR yang membuat kedua
 * gambar itu benar: ada `<svg>` di dalam slot ikon, dan lambang
 * WhatsApp digambar sebagai satu path dengan `fill-rule="evenodd"`.
 *
 * Test visual (screenshot) tidak dipakai di sini, sama seperti
 * `LandingPenggunaTest`: yang dikunci adalah aturannya, supaya
 * perbaikan di masa depan tidak harus mengubah markup yang sama.
 */
class LandingIkonTest extends TestCase
{
    private function html(): string
    {
        return $this->get('/')->assertOk()->getContent();
    }

    /**
     * Slot ikon kartu "Laporan siap" harus berisi SVG, bukan kotak kosong.
     *
     * Yang dikunci: di dalam `.art-card` ada elemen `.art-card__ico`
     * yang memuat `<use>` ke sebuah simbol.
     *
     * Kenapa tidak cukup sekadar "ada `<svg>` di halaman": halaman ini
     * punya puluhan ikon, jadi assertion itu akan lulus bahkan kalau
     * slot ikon yang dimaksud tetap kosong.
     */
    #[Test]
    public function slot_ikon_kartu_laporan_memang_berisi_svg(): void
    {
        $this->assertMatchesRegularExpression(
            '/<div class="art-card__ico">\s*<svg\b[^>]*>\s*<use href="#([^"]+)"\s*\/>\s*<\/svg>\s*<\/div>/s',
            $this->html(),
            'Slot ikon kartu "Laporan siap" harus berisi <svg> yang menunjuk simbol di sprite.'
        );
    }

    /**
     * Kotak ikon kartu laporan harus punya aturan CSS-nya sendiri.
     *
     * `.art-card__ico` menggantikan `::before` yang kosong. Kalau aturan
     * CSS-nya hilang, ikon tetap ada di markup tapi tidak punya kotak --
     * jadi bentuknya berubah jadi ikon telanjang yang menempel di teks.
     *
     * Yang dikunci hanya keberadaan aturannya, bukan warnanya: warna itu
     * urusan desain dan bisa berubah tanpa membuat ikon hilang.
     */
    #[Test]
    public function kotak_ikon_kartu_laporan_punya_aturan_css(): void
    {
        $this->assertStringContainsString(
            '.art-card__ico',
            $this->html(),
            'Kotak ikon kartu laporan harus punya aturan CSS sendiri.'
        );
    }

    /**
     * Slot ikon kartu laporan tidak boleh pakai `::before` lagi.
     *
     * `::before` tidak bisa memuat `<svg>`: konten yang dihasilkannya
     * adalah teks. Kalau ada yang memindahkan kembali ikon ke `::before`,
     * ikon itu tidak akan hilang dari halaman -- dia akan hilang dari
     * tampilan, persis seperti bug yang ada sekarang.
     *
     * Jadi yang dikunci justru KEBALIKAN dari test pertama: `::before`
     * dengan `content: ""` di dalam `.art-card` adalah tanda slot ikon
     * kembali ke bentuk yang tidak bisa memuat ikon.
     */
    #[Test]
    public function slot_ikon_kartu_laporan_tidak_pakai_pseudo_element_kosong(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/\.art-card::before\s*\{[^}]*content:\s*""/s',
            $this->html(),
            'Slot ikon tidak boleh kembali ke ::before kosong -- ::before tidak bisa memuat SVG.'
        );
    }

    /**
     * Lambang WhatsApp harus satu path dengan `fill-rule="evenodd"`.
     *
     * Ini yang membuat handset jadi lubang di dalam gelembung, baik di
     * mode garis maupun di mode solid.
     *
     * Yang dikunci BUKAN `fill-rule` semata: tanpa itu pun bentuknya
     * mungkin kebetulan benar di satu mode. Yang dikunci adalah satu
     * path -- karena dua path adalah penyebab aslinya, dan dua path
     * bisa saja tetap dipakai tapi kebetulan tidak terlihat salah di
     * mode yang sedang diuji.
     */
    #[Test]
    public function lambang_whatsapp_adalah_satu_path_dengan_evenodd(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression(
            '/<symbol id="i-wa"[^>]*>\s*<path[^>]*fill-rule="evenodd"/s',
            $html,
            'Simbol i-wa harus satu path dengan fill-rule="evenodd" supaya handset jadi lubang.'
        );

        $this->assertSame(
            1,
            $this->jumlahPathDiSimbol($html, 'i-wa'),
            'Simbol i-wa tidak boleh punya dua path terpisah -- keduanya akan terisi warna yang sama di mode solid.'
        );
    }

    /**
     * Setiap `<use>` harus menunjuk simbol yang benar-benar ada.
     *
     * `<use>` ke simbol yang tidak ada tidak menghasilkan error --
     * browser hanya tidak menggambar apa pun. Jadi ikon hilang tanpa
     * jejak di console, dan satu-satunya cara tahu adalah dengan
     * membandingkan sendiri daftar pemakaian dengan daftar definisi.
     *
     * Ini test yang menangkap kelas bug yang paling sering menetap
     * lama di halaman seperti ini: simbol dihapus atau di-rename,
     * pemakaiannya tidak.
     */
    #[Test]
    public function setiap_use_menunjuk_simbol_yang_ada(): void
    {
        $html = $this->html();

        $definisi = [];
        if (preg_match_all('/<symbol\s+id="([^"]+)"/', $html, $m)) {
            $definisi = $m[1];
        }

        $pemakaian = [];
        if (preg_match_all('/<use\s+href="#([^"]+)"/', $html, $m)) {
            $pemakaian = array_values(array_unique($m[1]));
        }

        $this->assertNotEmpty($definisi, 'Sprite ikon harus ada di halaman.');

        $hilang = array_values(array_diff($pemakaian, $definisi));

        $this->assertSame(
            [],
            $hilang,
            'Ikon ini menunjuk simbol yang tidak ada, jadi tidak akan tergambar: '
                .implode(', ', $hilang)
        );
    }

    /**
     * Goresan ikon harus punya tebal yang cukup untuk digambar.
     *
     * Aturan mainnya sudah tertulis di halaman itu sendiri (lihat
     * catatan di `.mock__ico svg`): `stroke-width` ditulis untuk
     * viewBox 24px, lalu di-scale ke ukuran tampil. Jadi angka di CSS
     * bukan angka yang sampai ke layar.
     *
     * Di bawah ~1.5px efektif, Anti-Aliasing browser membagi satu
     * goresan ke dua piksel dan masing-masingnya diberi bobot ~0.5 --
     * hasilnya bukan "tipis" tapi "kabut abu" yang hampir tidak
     * kelihatan. Di layar Retina angkanya turun lagi ke setengah.
     *
     * Test ini menghitung angka efektif itu untuk setiap aturan ikon
     * yang punya `width` eksplisit, dan gagal kalau ada yang di bawah
     * batas. Tanpa ini, menurunkan `stroke-width` demi "lebih rapi"
     * akan lolos tanpa ada yang sadar ikonnya ikut menghilang.
     */
    #[Test]
    public function goresan_ikon_tebalnya_cukup(): void
    {
        $html = $this->html();

        // `assertMatchesRegularExpression` tidak mengisi array hasil
        // tangkapan, jadi `preg_match` dipakai langsung di sini.
        $this->assertSame(
            1,
            preg_match('/<style>(.*?)<\/style>/s', $html, $m),
            'Halaman harus punya satu blok <style>.'
        );

        $css = preg_replace('#/\*.*?\*/#s', '', $m[1]);

        // Setiap aturan yang menata `<svg>` dan punya `width` + `stroke-width`.
        preg_match_all('/([^{}]+)\{([^{}]*svg[^{}]*)\}/s', $css, $aturan, PREG_SET_ORDER);

        $terlemah = [];

        foreach ($aturan as [, $selektor, $badan]) {
            if (! preg_match('/width:\s*([\d.]+)px/', $badan, $w)) {
                continue;
            }

            if (! preg_match('/stroke-width:\s*([\d.]+)/', $badan, $s)) {
                continue;
            }

            $ukuran = (float) $w[1];
            $lebar = (float) $s[1];

            // viewBox semua simbol di halaman ini 24x24.
            $efektif = $lebar * ($ukuran / 24);

            if ($efektif < 1.5) {
                $terlemah[] = sprintf(
                    '%s (%dpx, stroke %s) -> %.2fpx efektif',
                    trim(preg_replace('/\s+/', ' ', $selektor)),
                    $ukuran,
                    $lebar,
                    $efektif
                );
            }
        }

        $this->assertSame(
            [],
            $terlemah,
            "Goresan ikon ini di bawah 1.5px efektif, jadi akan jadi kabut abu:\n- "
                .implode("\n- ", $terlemah)
        );
    }

    /**
     * Berapa `<path>` yang ada di dalam satu simbol.
     *
     * Dipisah sebagai helper karena dipakai dua kali: sekali untuk
     * memastikan `i-wa` cuma punya satu, dan sekali sebagai pembanding
     * kalau simbol lain nanti ikut dipecah jadi beberapa path (yang
     * berarti aturan `.i-solid`-nya harus ditinjau ulang).
     */
    private function jumlahPathDiSimbol(string $html, string $id): int
    {
        if (! preg_match('/<symbol\s+id="'.preg_quote($id, '/').'"[^>]*>(.*?)<\/symbol>/s', $html, $m)) {
            return 0;
        }

        preg_match_all('/<path\b/', $m[1], $path);

        return count($path[0]);
    }
}
