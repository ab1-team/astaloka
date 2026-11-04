import { strict as assert } from 'node:assert'
import { describe, it } from 'node:test'

import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

/*
 * KONTEN HALAMAN PER ROLE: harus sama persis dengan pamsides-v2.
 *
 * Kenapa file ini ada, terpisah dari `sidebarSusunanPerRole.test.js`
 * ------------------------------------------------------------------
 *
 * Sidebar yang sama persis belum tentu isinya sama persis. Dua halaman
 * bisa punya menu, label, dan urutan yang identik -- lalu beda di judul
 * h1, warna judul, warna tombol, atau satu kata di dalam paragraf.
 *
 * Dan itulah yang terjadi. Setelah susunan sidebar dicocokkan, halaman
 * teknisi masih menulis "Input Pemakaian" sementara menunya menulis
 * "Input Pemakaian Air"; judul "Daftar Tagihan" masih hitam (`slate-900`)
 * padahal desainnya cyan; tombol "SIMPAN HASIL INSTALASI" masih abu
 * padahal desainnya gradien. Semuanya terlihat kalau orang membandingkan
 * kedua aplikasi berdampingan.
 *
 * Kenapa kata "Air" dikembalikan di sini
 * --------------------------------------
 *
 * Commit `ac89e94` menghapus kata "air" dari SELURUH teks yang dilihat
 * pengguna, dengan alasan yang benar untuk alasan-nya: ASTALOKA dipakai
 * lintas jenis usaha, jadi produknya tidak boleh selalu bicara tentang
 * air bersih.
 *
 * Tapi dua halaman per-role punya desain yang memang dibuat khusus untuk
 * role itu, dan desain itu membawa kata "air" di dalamnya -- "Input
 * Pemakaian Air", "VOLUME AIR", "Instalasi Air Bersih". Menghapus kata
 * itu dari halaman-halaman tersebut bukan membuat desainnya netral, tapi
 * membuat desainnya jadi rancangan yang tidak pernah ada: judul menu
 * "Input Pemakaian Air" membuka halaman yang berjudul "Input Pemakaian".
 *
 * File ini mengunci TEKS DAN WARNA yang dipakai role teknisi & surveyor.
 * Yang di luar cakupan itu -- branding produk ("ASTALOKA" vs "PAMSIMAS"),
 * dan halaman milik admin/pelanggan -- TIDAK disentuh di sini.
 *
 * Jalankan: `node --test` dari folder `frontend`.
 */

/** Akar folder `src/`. */
const AKAR = fileURLToPath(new URL('../src/', import.meta.url))

/** Isi sebuah berkas, dibaca sebagai teks UTF-8. */
function isi(berkas) {
  return readFileSync(join(AKAR, berkas), 'utf8')
}

/**
 * Daftar periksa: berkas -> teks yang HARUS ada.
 *
 * Dipakai untuk mengunci kata dan label yang jadi tanda desain per-role.
 * "Harus ada", bukan "harus tidak ada": kalau suatu saat teks itu diganti
 * lagi, test ini yang akan menyuruh orang itu meng consciously memutuskan
 * -- bukan diam-diam Hilang.
 */
const HARUS_ADA = [
  // ---- Halaman teknisi ------------------------------------------------
  {
    berkas: 'presentations/views/app/teknisi/PemakaianAir.vue',
    teks: [
      'Input Pemakaian Air',
      'Belum ada data pemakaian air untuk periode ini di wilayah Anda.',
    ],
  },
  {
    berkas: 'presentations/views/app/teknisi/DaftarTagihan.vue',
    teks: [
      'text-cyan-600!',
      'Menampilkan seluruh tagihan air pelanggan yang belum dilunasi.',
      'VOLUME AIR',
      'from-cyan-500! to-blue-600!',
    ],
  },
  {
    berkas: 'presentations/views/app/teknisi/InstallationResult.vue',
    teks: [
      'Upload foto dan data hasil pemasangan meter air pelanggan.',
      'variant="primary-gradient"',
      'shadow-cyan-200!',
      "confirmButtonColor: '#06b6d4'",
    ],
  },
  {
    berkas: 'presentations/views/app/teknisi/DashboardMain.vue',
    teks: ['Pencatatan pemakaian air per bulan'],
  },

  // ---- Halaman surveyor -----------------------------------------------
  {
    berkas: 'presentations/views/app/surveyor/createSurvey.vue',
    teks: ['variant="primary-gradient"'],
  },

  // ---- Partial yang dibuka dari halaman role --------------------------
  {
    berkas: 'presentations/views/app/admin/tagihan/partials/hasilInputModal.vue',
    teks: ['Hasil Input Pemakaian Air', 'Tagihan Air'],
  },
  {
    berkas: 'presentations/views/app/admin/tagihan/partials/inputMeterModal.vue',
    teks: ['Instalasi Air Bersih', 'from-cyan-500! to-blue-600!'],
  },
  {
    berkas: 'presentations/views/app/admin/tagihan/partials/detaiDaftarTagihan.vue',
    teks: ['Rincian Rekening Air Bulanan', 'Biaya Penggunaan Air', 'INVOICE TAGIHAN AIR'],
  },
  {
    berkas: 'presentations/views/app/admin/tagihan/partials/detailPemakaianAir.vue',
    teks: ['Input Pemakaian Air'],
  },
]

/**
 * Nama route yang harus ada.
 *
 * Route name bukan hal tampilan, tapi ia masuk ke `document.title` lewat
 * `humanize(to.name)` di `router/index.js` -- jadi nama route menentukan
 * judul tab browser. Mengubahnya jadi "Input Pemakaian" tanpa "Air" bukan
 * hanya soal kode: ia mengubah apa yang tertulis di tab.
 */
const ROUTE_NAMES = [
  { berkas: 'router/index.js', nama: "name: 'Input Pemakaian Air'," },
  { berkas: 'router/index.js', nama: "name: 'Input Pemakaian Air Teknisi'," },
]

describe('konten halaman per role', () => {
  it('semua berkas yang diperiksa benar-benar ada', () => {
    // Kalau nama berkasnya salah, `readFileSync` melempar dan test gagal
    // dengan pesan yang tidak menyebut apa pun yang berguna. Dicek
    // eksplisit supaya pesannya jelas.
    for (const { berkas } of [...HARUS_ADA, ...ROUTE_NAMES]) {
      assert.doesNotThrow(() => isi(berkas), `berkas tidak ditemukan: ${berkas}`)
    }
  })

  for (const { berkas, teks } of HARUS_ADA) {
    it(`"${teks[0]}" ada di ${berkas.split('/').pop()}`, () => {
      const isiBerkas = isi(berkas)

      for (const potongan of teks) {
        assert.ok(
          isiBerkas.includes(potongan),
          `${berkas}: "${potongan}" tidak ditemukan.\n` +
            `Halaman role ini harus sama persis dengan desain pamsides-v2.`,
        )
      }
    })
  }

  for (const { berkas, nama } of ROUTE_NAMES) {
    it(`route ${nama} terdaftar di ${berkas}`, () => {
      assert.ok(
        isi(berkas).includes(nama),
        `${berkas}: ${nama} tidak ditemukan -- judul tab browser ikut berubah.`,
      )
    })
  }

  it('tidak ada push ke route "Input Pemakaian" tanpa kata "Air"', () => {
    /*
     * Nama route yang salah tidak langsung merusak halaman: `router.push`
     * dengan nama yang tidak ada akan DITOLAK diam-diam (Vue Router
     * hanyaBecatat warning di console), jadi user menekan tombol dan
     * tidak terjadi apa-apa.
     *
     * Jadi yang dikunci di sini bukan hanya definisinya, tapi juga
     * PEMAKAIANNYA.
     */
    const pemakai = [
      'presentations/views/app/teknisi/PemakaianAir.vue',
      'presentations/views/app/admin/tagihan/pemakaianAir.vue',
    ]

    for (const berkas of pemakai) {
      const isiBerkas = isi(berkas)

      assert.ok(
        !/name:\s*'Input Pemakaian'/s.test(isiBerkas),
        `${berkas}: masih push ke route 'Input Pemakaian' -- route itu sudah dinamai 'Input Pemakaian Air'.`,
      )
    }
  })
})
