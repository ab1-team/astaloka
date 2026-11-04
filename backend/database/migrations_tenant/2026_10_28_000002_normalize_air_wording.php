<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalisasi teks yang menyebut "air" sebagai produk di dua tempat.
 *
 * Latar belakang
 * --------------
 *
 * ASTALOKA dipakai lintas jenis usaha (air minum, internet, sampah, dan
 * lainnya), tapi dua tempat masih menyebut "air" sebagai produk yang selalu
 * ada:
 *
 *   1. `trouble_reports.trouble_type` -- pilihan "Jenis Gangguan" di form
 *      pelanggan masih memakai istilah air mentah: "Air Mati Total",
 *      "Debit Air Kecil", "Air Keruh/Berbau". Pelanggan dari lokasi yang
 *      bukan air minum tidak punya opsi yang cocok -- semua pilihan memakai
 *      kosakata air.
 *
 *   2. `settings.pesan_tagihan` dan `settings.pesan_pembayaran` -- pesan
 *      bawaan yang dikirim ke pelanggan (WhatsApp / notifikasi) menyebut
 *      "tagihan air".
 *
 * Kenapa dua-duanya ditangani di SATU migration
 * ---------------------------------------------
 *
 * Keduanya berasal dari perubahan yang sama -- kosakata produk yang
 * mengunci ASTALOKA ke satu jenis usaha -- dan keduanya harus selesai
 * bersamaan. Kalau salah satu tertinggal, form sudah generik tapi pesan
 * tagihan masih berbunyi "tagihan air": hasilnya setengah jadi dan terlihat
 * tidak konsisten.
 *
 * Yang TIDAK diubah
 * -----------------
 *
 * `trouble_type` untuk nilai yang sudah benar (`meter_rusak`) tidak
 * disentuh. Nilai baru pada form sengaja memakai bentuk generik --
 * "Layanan Mati Total", "Volume Rendah / Debit Kurang", "Kualitas
 * Bermasalah" -- karena harus cocok untuk SEMUA jenis usaha. Untuk lokasi
 * air, gejalanya sama persis dengan yang lama; untuk lokasi internet atau
 * sampah juga masuk akal.
 *
 * `settings.pesan_tagihan` dan `settings.pesan_pembayaran` tidak ditulis
 * ulang kalau admin sudah mengubahnya sendiri. Yang diganti hanya frasa
 * "air" di dalamnya -- jadi hasil edit admin tetap utuh, kecuali bagian
 * yang memang ikut menyebut air.
 */
return new class extends Migration
{
    /**
     * Nilai `trouble_type` lama -> nilai baru.
     *
     * `pipa_bocor` -> `instalasi_bocor` ikut dipetakan karena "pipa"
     * tidak berlaku di lokasi yang layanannya bukan air -- internet,
     * sampah, atau jasa. Yang dipetakan adalah NILAI-nya, jadi laporan
     * lama dan baru memakai kosakata yang sama.
     *
     * `meter_rusak` TIDAK dipetakan: "meteran" masih dipakai untuk semua
     * jenis layanan yang butuh pencatatan consumption, jadi nilainya
     * sudah benar dan tidak perlu disentuh.
     */
    private const PETA_GANGGUAN = [
        'air_mati' => 'layanan_mati_total',
        'debit_kecil' => 'debit_rendah',
        'air_keruh' => 'kualitas_buruk',
        'pipa_bocor' => 'instalasi_bocor',
    ];

    /**
     * Frasa lama -> frasa baru, untuk pesan tagihan & pembayaran.
     *
     * Cuma frasa yang menyebut "air" yang diganti, bukan seluruh kalimat.
     * Kalau seluruh kalimat diganti, hasil edit admin ikut hilang.
     */
    private const PETA_PESAN = [
        'tagihan air bulan ini sebesar' => 'tagihan layanan bulan ini sebesar',
        'pembayaran tagihan air sebesar' => 'pembayaran tagihan sebesar',
    ];

    public function up(): void
    {
        $this->normalisasiGangguan();
        $this->normalisasiPesan();
    }

    public function down(): void
    {
        if (Schema::hasTable('trouble_reports')) {
            foreach (array_flip(self::PETA_GANGGUAN) as $baru => $lama) {
                DB::table('trouble_reports')
                    ->where('trouble_type', $baru)
                    ->update(['trouble_type' => $lama]);
            }
        }

        if (Schema::hasTable('settings')) {
            foreach (array_flip(self::PETA_PESAN) as $baru => $lama) {
                $this->gantiTeksPesan($baru, $lama);
            }
        }
    }

    /**
     * Terjemahkan nilai `trouble_type` yang sudah tersimpan.
     */
    private function normalisasiGangguan(): void
    {
        if (! Schema::hasTable('trouble_reports')) {
            return;
        }

        foreach (self::PETA_GANGGUAN as $lama => $baru) {
            DB::table('trouble_reports')
                ->where('trouble_type', $lama)
                ->update(['trouble_type' => $baru]);
        }
    }

    /**
     * Ganti frasa "air" pada pesan bawaan.
     */
    private function normalisasiPesan(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (self::PETA_PESAN as $lama => $baru) {
            $this->gantiTeksPesan($lama, $baru);
        }
    }

    /**
     * Ganti satu frasa di kolom pesan.
     *
     * Pengerjaan dilakukan DI PHP, bukan lewat `REPLACE()` SQL, dan itu
     * bukan soal performa -- tabel `settings` isinya satu baris per
     * lokasi. Alasannya adalah aturan case yang BERBEDA antara `LIKE` dan
     * `REPLACE()`, dan perbedaan itu menghasilkan migrasi yang SELESAI
     * dengan sukses tapi tidak mengubah apa pun.
     *
     * Bawaan pesan tagihan diawali huruf besar:
     *
     *     "Yth. {customer}, Pembayaran tagihan air sebesar {tagihan} ..."
     *
     * sementara frasa yang dicari di sini ditulis huruf kecil semua:
     *
     *     "pembayaran tagihan air sebesar"
     *
     * `WHERE ... LIKE` memakai collation yang case-INSENSITIVE, jadi baris
     * itu TERPILIH. Tapi `REPLACE()` membandingkan dengan aturan case
     * yang SENSITIF -- frasa tidak cocok, dan baris itu terpilih tapi TIDAK
     * berubah. Hasilnya: migration hijau, dan pesan yang masih
     * berbunyi "tagihan air" tetap ada di database -- persis yang
     * seharusnya diubah.
     *
     * Kalau `COLLATE utf8mb4_unicode_ci` ditambahkan ke dalam
     * `REPLACE()`, barisnya berubah, tapi huruf besarnya hilang: hasil
     * akhirnya "pembayaran tagihan sebesar" di tengah kalimat yang
     * diawali huruf besar. Teksnya jadi terlihat miring.
     *
     * Karena itu penggantian dilakukan di PHP dengan `stripos()`, yang
     * case-insensitive tanpa mengubah huruf aslinya -- huruf besar di
     * kalimat admin tetap huruf besar.
     *
     * `WHERE ... LIKE` tetap dipakai sebagai penyaring BARIS,
     * supaya hanya baris yang benar-benar memuat frasa itu yang dibaca
     * dan ditulis ulang. Baris lain -- termasuk yang admin sudah ubah
     * menjadi kalimat sendiri -- tetap utuh.
     */
    private function gantiTeksPesan(string $lama, string $baru): void
    {
        foreach (['pesan_tagihan', 'pesan_pembayaran'] as $kolom) {
            if (! Schema::hasColumn('settings', $kolom)) {
                continue;
            }

            $baris = DB::table('settings')
                ->select('id', $kolom)
                ->whereNotNull($kolom)
                ->where($kolom, 'like', "%{$lama}%")
                ->get();

            foreach ($baris as $b) {
                $teks = (string) $b->{$kolom};

                $teksBaru = $this->gantiFrasa($teks, $lama, $baru);

                if ($teksBaru === $teks) {
                    continue;
                }

                DB::table('settings')
                    ->where('id', $b->id)
                    ->update([$kolom => $teksBaru]);
            }
        }
    }

    /**
     * Ganti semua kemunculan frasa, tanpa mengubah huruf aslinya.
     *
     * `stripos()` mencocokkan tanpa membedakan huruf besar-kecil, tapi
     * huruf yang ada di teks ASLI yang dipakai saat membangun hasil --
     * jadi "Pembayaran tagihan air" menjadi "Pembayaran tagihan", bukan
     * "pembayaran tagihan".
     *
     * Setiap kemunculan diproses satu per satu, karena frasa bisa muncul
     * lebih dari sekali dalam satu pesan.
     */
    private function gantiFrasa(string $teks, string $lama, string $baru): string
    {
        $hasil = '';
        $offset = 0;
        $panjangLama = strlen($lama);

        while (($pos = stripos($teks, $lama, $offset)) !== false) {
            $hasil .= substr($teks, $offset, $pos - $offset);

            $hasil .= $this->samakanHuruf(
                $baru,
                substr($teks, $pos, $panjangLama)
            );

            $offset = $pos + $panjangLama;
        }

        return $hasil.substr($teks, $offset);
    }

    /**
     * Samakan huruf replacement dengan huruf frasa yang digantikan.
     *
     * Tiga pola yang sering muncul di teks berbahasa Indonesia:
     *
     *   - huruf kecil semua   -> "tagihan air"      -> "tagihan layanan"
     *   - huruf awal besar    -> "Tagihan air"      -> "Tagihan layanan"
     *   - huruf besar semua    -> "TAGIHAN AIR"      -> "TAGIHAN LAYANAN"
     *
     * Tanpa ini, "Tagihan air" berubah jadi "tagihan layanan" -- huruf
     * besarnya hilang di tengah kalimat, dan teksnya terlihat seperti
     * salah ketik.
     */
    private function samakanHuruf(string $baru, string $cocok): string
    {
        if ($cocok === mb_strtoupper($cocok) && mb_strtolower($cocok) !== $cocok) {
            return mb_strtoupper($baru);
        }

        if (mb_strtoupper(mb_substr($cocok, 0, 1)).mb_strtolower(mb_substr($cocok, 1)) === $cocok) {
            return mb_strtoupper(mb_substr($baru, 0, 1)).mb_substr($baru, 1);
        }

        return mb_strtolower($baru);
    }
};
