import { ref } from 'vue'

import { ambilBranding } from '@/services/branding.service'
import { WARNA_BAWAAN, normalisasiHex, warnaTeksDiAtas } from '@/utils/themeColor'

/**
 * Warna dan teks halaman login milik lokasi yang sedang dilayani.
 *
 * Store ini Global, bukan milik halaman login saja: warna lokasi dipakai
 * seluruh aplikasi (sidebar, tombol, tabel), dan objek ini adalah satu
 * tempat yang tahu warna itu apa.
 *
 * Sifat PENTING: yang disimpan di sini HANYA branding yang benar-benar
 * diterima server. Nilai bawaan TIDAK ditulis ke state -- kalau ditulis,
 * panel pusat yang membuka form branding akan melihat `judul: 'ASTALOKA'`,
 * menyimpannya, dan mengubah "kosong = pakai bawaan" menjadi "selalu punya
 * nilai". Yang dikembalikan justru nilai bakunya, sementara isinya tetap
 * `''`.
 */
const branding = ref(null)
const dimuat = ref(false)
const gagalMuat = ref(false)

/**
 * Muat branding sekali saja.
 *
 * `dijalankan` memakai flag sendiri, bukan `dimuat`: `dimuat` ikut true
 * setelah gagal, dan kalau flag itu yang dipakai sebagai penjaga, satu
 * kegagalan sementara di awal sesi akan membuat warna tidak pernah dimuat
 * lagi sampai seluruh halaman di-refresh.
 */
let dijalankan = false
let sedangBerjalan = null

export async function muatBranding() {
  if (dijalankan) return branding.value

  // Dua pemanggil yang datang bersamaan (mis. LoginView dan ShortcutLoginView)
  // harus mendapat request yang sama, bukan dua request. Tanpa ini, warna
  // bisa terpakai dua kali dalam jarak beberapa milidetik dan salah satunya
  // bisa pertama kali selesai -- untuk endpoint publik ini dampaknya kecil,
  // tapi tidak ada alasan membiarkan request ganda terjadi.
  if (sedangBerjalan) return sedangBerjalan

  sedangBerjalan = ambilBranding()
    .then((data) => {
      branding.value = data
      dimuat.value = true
      gagalMuat.value = false
      return data
    })
    .catch(() => {
      /*
       * Gagal memuat branding TIDAK boleh menggagalkan halaman.
       *
       * Halaman login tetap harus terbuka kalau endpoint ini mati: tanpa
       * branding, orang tidak bisa masuk sama sekali, dan penyebabnya
       * (backend sedang restart, satu endpoint yang belum termigrasi) bukan
       * sesuatu yang bisa mereka perbaiki dari sini.
       *
       * `branding` dibiarkan `null` -- BUKAN diisi dengan objek bawaan.
       * Mengisinya berarti state "sudah memuat" menyatu dengan state
       * "gagal memuat", dan warna bawaan akan menutupi kegagalan yang
       * sedang terjadi.
       */
      branding.value = null
      dimuat.value = true
      gagalMuat.value = true
      return null
    })
    .finally(() => {
      dijalankan = true
      sedangBerjalan = null
    })

  return sedangBerjalan
}

/**
 * Judul halaman login.
 *
 * Urutannya: nilai yang tersimpan, lalu bawaan. Tidak pernah `null`, karena
 * setiap pemanggil membacanya sebagai teks yang langsung dirender.
 *
 * Teks bakunya ADA DI SINI, bukan diambil dari `branding`. Endpoint publik
 * memang mengirim nilai final, tapi state ini juga diisi dari panel pusat
 * yang memakai bentuk `raw()` -- dan bentuk itu `null` berarti "kosong = pakai
 * bawaan". Tanpa lapsan ini, panel pusat yang memuat branding untuk form-nya
 * akan membuat halaman login ikut menampilkan teks kosong.
 *
 * Ketiganya WAJIB sama persis dengan `App\Support\Branding` di backend
 * (`DEFAULT_JUDUL`, `DEFAULT_SUB_JUDUL`, `DEFAULT_DESKRIPSI`). Duplikasi ini
 * disengaja dan hanya untuk kasus saat server tidak mengirim nilai final --
 * panel pusat yang memakai bentuk `raw()` dan backend versi lama. Kalau
 * ketiganya berbeda dari backend, gejalanya bukan error: panel pusat
 * menampilkan "Bawaan: ..." yang berbeda dari teks yang benar-benar muncul
 * di halaman login, dan orang menyimpulkan panelnya yang salah padahal
 * halamannya sudah benar. Yang harus jadi rujukan saat mengubah salah satunya
 * ada di kedua file.
 */
const JUDUL_BAWAAN = 'ASTALOKA'
const SUB_JUDUL_BAWAAN = 'Digital Platform'
const DESKRIPSI_BAWAAN =
  'Asta Layanan Operasional, Keuangan, dan Administrasi. Satu sistem untuk berbagai jenis usaha: air minum, internet, pembayaran sampah, jasa, dan layanan lainnya.'

export function judulLogin() {
  return branding.value?.judul || JUDUL_BAWAAN
}

export function subJudulLogin() {
  return branding.value?.sub_judul || SUB_JUDUL_BAWAAN
}

export function deskripsiLogin() {
  return branding.value?.deskripsi || DESKRIPSI_BAWAAN
}

/**
 * Nilai bawaan untuk form panel pusat.
 *
 * Dipisah dari fungsi di atas karena form butuh bedanya: di sana string kosong
 * berarti "hapus teks ini dan kembali ke bawaan", sementara di halaman login
 * string kosong berarti "tampilkan bawaan". Fungsi yang sama untuk dua hal
 * yang berbeda tidak akan pernah benar untuk keduanya.
 */
export function bawaanBranding() {
  return {
    judul: JUDUL_BAWAAN,
    sub_judul: SUB_JUDUL_BAWAAN,
    deskripsi: DESKRIPSI_BAWAAN,
  }
}

/**
 * Warna utama lokasi, sudah divalidasi.
 *
 * `normalisasiHex` di sini, bukan hanya di server: nilai ini pernah melewati
 * `JSON.parse` dan bisa datang dari cache peramban yang dibuat versi
 * aplikasi lama. CSS `var()` menerima string apa adanya, jadi hex rusak
 * tidak akan menghasilkan error -- hanya tombol yang diam-diam tidak punya
 * warna, dan itu sulit dilacak.
 */
export function warnaLokasi() {
  return normalisasiHex(branding.value?.warna) ?? WARNA_BAWAAN
}

/** Warna teks di atas warna utama. Lihat catatan panjangnya di `themeColor.js`. */
export function warnaTeksLokasi() {
  return warnaTeksDiAtas(warnaLokasi())
}

/**
 * Keadaan branding, untuk ditampilkan apa adanya.
 *
 * `memuat` dipakai LoginView supaya tidak perlu menampilkan teks hardcoded
 * selama request masih berjalan -- kalau tidak, judul bawaan akan terlihat
 * sedetik sebelum diganti judul lokasi, dan halaman berkedip.
 */
export function useBranding() {
  return {
    branding,
    dimuat,
    gagalMuat,
    muatBranding,
    judulLogin,
    subJudulLogin,
    deskripsiLogin,
    warnaLokasi,
    warnaTeksLokasi,
    bawaanBranding,
  }
}
