/**
 * Jumlah baris per halaman untuk cetakan form input & daftar tagihan.
 *
 * angkanya DIHITUNG dari pengukuran nyata, bukan ditebak.
 *
 * Area cetak A4 = 297mm - padding atas/bawah 12mm = 273mm.
 * `tools/ukur-cetak-form.mjs` memindai kapasitas tiap halaman di Chrome
 * headless (dengan menambah baris satu per satu sampai keluar dari area
 * cetak) dan menghasilkan:
 *
 *   Cetak Form Input     lanjutan 51 baris (268.9mm)   pertama 49 (272.6mm)
 *   Cetak Daftar Tagihan lanjutan 55 baris (272.3mm)   pertama 50 (270.0mm)
 *
 * Yang dipakai di sini adalah nilai TERKECIL di antara keduanya, dikurangi
 * 1-2 baris sebagai cadangan.
 *
 * Kenapa cadangan itu penting:
 * pengukuran memakai baris contoh BERTEKS PENDEK. Nama pelanggan yang
 * panjang membungkus baris jadi dua baris, dan satu baris seperti itu
 * saja sudah cukup untuk mendorong baris terakhir keluar dari kertas.
 *
 * Gejalanya kalau meluber: baris terakhir HILANG dari PDF. Tidak ada
 * error, tidak ada tanda, tidak ada peringatan -- cetakan hanya terlihat
 * kurang satu baris.
 *
 * Jangan diubah tanpa menjalankan ulang:
 *   node tools/ukur-cetak-form.mjs
 */
export const PER_PAGE_ROWS = 50
export const FIRST_PAGE_ROWS = 48
