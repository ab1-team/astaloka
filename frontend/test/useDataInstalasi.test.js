/**
 * Halaman Data Instalasi (`/app/dataInstalasi`) harus menampilkan SEMUA
 * instalasi berstatus `completed`.
 *
 * BUG YANG DIKUNCI
 * =================
 *
 * Composable `useDataInstalasi` dulu meminta `?per_page=200`. Jalur itu
 * dibatasi server dengan `min($perPage, 200)`, jadi hanya 200 baris PERTAMA
 * yang sampai -- sisanya hilang tanpa jejak.
 *
 * Gejalanya persis seperti yang dilaporkan: di database ada lebih dari 100
 * instalasi `completed`, tapi di layar tidak sampai semua.
 *
 * Test ini mengunci KONTRAK frontend: halaman ini meminta `all=1` (tanpa
 * batas baris) dan hanya status `completed`.
 *
 * Jalankan: `node --test frontend/test/`
 */

import { strict as assert } from 'node:assert'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, it } from 'node:test'

/** Sumber composable, relatif terhadap `src/`. */
const JALUR = 'composables/useDataInstalasi.js'

/** Sumber berkas composable di dalam `src/`. */
function sumber() {
  return readFileSync(fileURLToPath(new URL(`../src/${JALUR}`, import.meta.url)), 'utf-8')
}

/**
 * Buang komentar dari sumber.
 *
 * Dipakai karena komentar di dalam BLOK PERMINTAAN menjelaskan kenapa
 * `all=1` dipakai -- dan penjelasan itu memang menyebut `per_page`.
 * Tanpa membuang komentar dulu, pemeriksaan "tidak boleh ada per_page di
 * parameter" akan salah membaca kalimat penjelasan itu sebagai parameter.
 *
 * Yang dibuang:
 *   - komentar blok (slit-star ... star-slit)
 *   - komentar baris (dua garis miring)
 *
 * String literal tidak disentuh -- `status: 'completed'` tidak punya
 * slash, dan kalau nanti ada URL berisi `//`, URL-nya ikut terhapus.
 * Itu tidak berpengaruh pada apa pun yang diperiksa di sini.
 */
function tanpaKomentar(isi) {
  return isi.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
}

describe('useDataInstalasi', () => {
  describe('permintaan ke server', () => {
    it('meminta all=1, bukan per_page yang dibatasi server', () => {
      const isi = sumber()

      assert.ok(
        /all:\s*1/.test(isi),
        'halaman ini harus meminta all=1 supaya tidak ada baris yang terpotong',
      )

      /*
       * `per_page` TIDAK boleh ada di ARGUMEN permintaan.
       *
       * Jalur `per_page` dibatasi server di 200 baris. Meminta `per_page:
       * 200` hanya menjamin 200 baris pertama -- sisanya hilang, dan
       * `total` ikut terpotong sehingga antarmuka menampilkan "of 200
       * entries" padahal datanya lebih banyak. Itu bug yang dikunci di sini.
       *
       * Yang diperiksa adalah OBJEK PARAMETER-nya, bukan seluruh isi
       * berkas: `per_page` memang boleh disebut di komentar yang
       * menjelaskan kenapa `all=1` dipakai -- dan komentar itulah yang
       * mencegah bug ini dikembalikan nanti. Karena itu komentar dibuang
       * dulu sebelum diperiksa.
       */
      const bersih = tanpaKomentar(isi)

      const mulai = bersih.indexOf('getTickets({')
      assert.ok(mulai !== -1, 'permintaan ke server harus bisa ditemukan')

      const akhir = bersih.indexOf('})', mulai)
      assert.ok(akhir !== -1, 'permintaan ke server harus punya penutup')

      const argumen = bersih.slice(mulai, akhir)

      assert.ok(
        !/per_page/.test(argumen),
        `argumen permintaan tidak boleh memakai per_page, tapi: ${argumen}`,
      )
    })

    it('hanya meminta status completed', () => {
      const isi = sumber()

      /*
       * Daftar status yang dikirim server harus HANYA `completed`.
       *
       * Halaman ini adalah daftar instalasi yang sudah selesai pasang.
       * `suspended` (Blokir) dan `terminated` (Cabut) punya tempat masing-masing
       * di `/app/instalasi/status`, dan tiket yang masih berjalan belum
       * jadi pelanggan aktif.
       */
      const cocok = isi.match(/const\s+ACTIVE_CONNECTION_STATUSES\s*=\s*\[([^\]]*)\]/)

      assert.ok(cocok, 'harus ada konstanta daftar status yang dipakai halaman ini')

      const status = cocok[1]
        .split(',')
        .map((s) => s.trim().replace(/^['"]|['"]$/g, ''))
        .filter((s) => s !== '')

      assert.deepEqual(
        status,
        ['completed'],
        `hanya status completed yang boleh tampil, tapi daftarnya: ${JSON.stringify(status)}`,
      )
    })
  })

  describe('pemetaan baris ke tabel', () => {
    it('memetakan kode instalasi, nama, alamat, paket, dan status', () => {
      const isi = sumber()

      for (const kunci of ['kodeInstalasi', 'nama', 'alamat', 'paket', 'status', 'rawStatus']) {
        assert.ok(isi.includes(`${kunci}:`), `baris tabel harus punya kolom "${kunci}"`)
      }
    })

    it('membaca kode pelanggan dari relasi customer', () => {
      const isi = sumber()

      assert.ok(
        /customer_code/.test(isi),
        'kode instalasi diambil dari customer_code, bukan dari id tiket',
      )
    })
  })

  describe('bentuk balasan server', () => {
    it('menerima array langsung maupun bentuk paginasi', () => {
      const isi = sumber()

      /*
       * Dua jalur server membalas dengan bentuk berbeda:
       *   - `?all=1`  -> `data` adalah array langsung
       *   - `?page=..` -> `data.data` adalah array
       *
       * Composable harusnya membaca keduanya, supaya tidak rapuh kalau
       * jalurnya diganti someday.
       */
      assert.ok(
        /Array\.isArray\(res\?\.data\)/.test(isi),
        'harus membaca balasan bentuk array langsung',
      )
      assert.ok(
        /Array\.isArray\(res\?\.data\?\.data\)/.test(isi),
        'harus membaca balasan bentuk paginasi',
      )
    })
  })

  describe('pencarian', () => {
    it('memfilter berdasarkan nama', () => {
      const isi = sumber()

      assert.ok(
        /r\.nama\.toLowerCase\(\)\.includes\(q\)/.test(isi),
        'pencarian harus memfilter berdasarkan nama pelanggan',
      )
    })
  })

  describe('pencetakan', () => {
    it('mencetak SELURUH hasil, bukan hanya halaman yang tampil', () => {
      const isi = sumber()

      /*
       * Cetak harus memakai `filteredData` -- seluruh hasil yang sudah
       * difilter pencarian -- bukan `paginatedData` (isi halaman yang
       * sedang tampil). Kalau yang dicetak hanya halaman yang terlihat,
       * dokumen yang keluar tidak lengkap padahal tabelnya lengkap.
       */
      assert.ok(/filteredData\.value/.test(isi), 'pencetak harus memakai filteredData')
    })
  })
})
