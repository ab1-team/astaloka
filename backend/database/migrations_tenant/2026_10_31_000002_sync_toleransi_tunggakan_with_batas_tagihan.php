<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Samakan `toleransi_tunggakan` dengan `batas_tagihan`.
 *
 * LATAR BELAKANG
 * --------------
 *
 * Halaman SOP punya dua input tanggal yang DUPLIKAT: "Tanggal Jatuh
 * Tempo" (`settings.batas_tagihan`) dan "Toleransi Menunggak"
 * (`settings.toleransi_tunggakan`). Keduanya TANGGAL dalam bulan, jadi
 * satuan dan maknanya sama -- yang satu jadi jatuh tempo tagihan, yang
 * satu jadi tanggal generate piutang. Admin dipaksa menjaga dua angka
 * yang tidak harus dijaga.
 *
 * Kedua kolom itu juga bisa berbeda isinya. Di lokasi demo:
 * `batas_tagihan` = 27, `toleransi_tunggakan` = 30.
 *
 * Akibatnya ada dua masalah nyata:
 *
 *   1. Tanggal jatuh tempo tagihan (27) TIDAK sama dengan tanggal generate
 *      piutang (30). Admin menyimpan tanggal 27, tapi jurnal piutang baru
 *      muncul tanggal 30.
 *
 *   2. `advanceDayOfMonth()` membaca `toleransi_tunggakan` (30) sementara
 *      `due_date` dihitung dari `batas_tagihan` (27). Jadi pembayaran
 *      pada tanggal 28, 29, atau 30 dianggap ADVANCE -- padahal
 *      tagihannya belum jatuh tempo. Bug ini hilang sendiri begitu
 *      keduanya jadi satu angka.
 *
 * YANG DIUBAH
 * -----------
 *
 * `toleransi_tunggakan` disamakan dengan `batas_tagihan`.
 *
 * Arahnya penting: yang mengikuti `batas_tagihan`, bukan sebaliknya.
 * `batas_tagihan` adalah angka yang sudah TERMATERIALISASI di
 * `monthly_bills.due_date` -- semua tagihan yang sudah ada punya jatuh
 * tempo tanggal 27 di lokasi demo. Kalau arahnya dibalik (toleransi
 * 30 yang jadi acuan), tagihan yang sudah ada tetap tanggal 27
 * sementara tagihan BARU jadi tanggal 30: satu bulan tagihan yang sama
 * punya dua jatuh tempo berbeda. Itu jauh lebih merusak daripada
 * generate piutang bergeser beberapa hari.
 *
 * `batas_tagihan` sendiri TIDAK disentuh sama sekali, jadi tidak ada
 * tagihan lama maupun baru yang jatuh temponya bergeser.
 *
 * BARIS YANG BELUM DIISI
 * ----------------------
 *
 * `batas_tagihan` = 0 berarti "SOP belum pernah diisi" -- dan angka 0
 * hanya bisa muncul kalau ada yang menuliskannya eksplisit, karena
 * default kolomnya 10.
 *
 * Baris seperti itu TIDAK diubah. Alasannya: `batas_tagihan` = 0 berarti
 * tagihan baru jatuh tempo di tanggal 1 (hari pertama bulan tagihan --
 * bukan tanggal bisnis yang pernah dipilih siapa pun). Kalau
 * migration promotes angka `toleransi_tunggakan` ke sana, jatuh tempo
 * tagihan BARU di lokasi itu ikut bergeser -- perubahan yang tidak
 * diminta siapa pun dan tidak terlihat.
 *
 * Baris itu tetap punya generate piutang yang jalan seperti biasa,
 * karena `BillingTolerance::tanggal()` memakai `toleransi_tunggakan`
 * sebagai cadangan kalau `batas_tagihan` tidak berisi tanggal yang sah.
 *
 * IDEMPOTENT
 * ----------
 *
 * Hanya baris yang kedua kolomnya berbeda yang diubah, dan migration
 * aman dijalankan berulang: setelah sekali jalan, kedua kolomnya sudah
 * sama, jadi jalan lagi tidak mengubah apa pun.
 */
return new class extends Migration
{
    /**
     * Nilai `toleransi_tunggakan` sebelum dua kolom digabung.
     *
     * Dipakai `down()` untuk mengembalikan kolom salinan ke angka warisan.
     */
    private const NILAI_LAMA = 30;

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        if (! Schema::hasColumn('settings', 'batas_tagihan')
            || ! Schema::hasColumn('settings', 'toleransi_tunggakan')) {
            return;
        }

        DB::table('settings')
            ->whereBetween('batas_tagihan', [1, 31])
            ->whereColumn('batas_tagihan', '!=', 'toleransi_tunggakan')
            ->update([
                'toleransi_tunggakan' => DB::raw('batas_tagihan'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        if (! Schema::hasColumn('settings', 'batas_tagihan')
            || ! Schema::hasColumn('settings', 'toleransi_tunggakan')) {
            return;
        }

        /*
         * Dikembalikan ke angka warisan 30.
         *
         * `up()` menyamakan kolomnya dengan `batas_tagihan`, jadi baris yang
         * berubah di `up()` adalah baris yang sekarangkedua kolomnya sama.
         * Baris itu dikembalikan ke 30 -- angka yang dipakai kedua kolom
         * sebelum migration ini, dan angka yang selalu ada di semua lokasi.
         *
         * `batas_tagihan` dibiarkan apa adanya: nilai itu sudah
         * TERMATERIALISASI di `monthly_bills.due_date`, jadi mengembalikannya
         * ke 0 justru akan menggeser jatuh tempo tagihan baru.
         */
        DB::table('settings')
            ->whereBetween('batas_tagihan', [1, 31])
            ->whereBetween('toleransi_tunggakan', [1, 31])
            ->whereColumn('toleransi_tunggakan', '=', 'batas_tagihan')
            ->update([
                'toleransi_tunggakan' => self::NILAI_LAMA,
                'updated_at' => now(),
            ]);
    }
};
