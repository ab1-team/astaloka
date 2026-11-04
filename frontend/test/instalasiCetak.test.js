import { strict as assert } from 'node:assert'
import { describe, it } from 'node:test'

await import('./vueHarness.mjs')

const { packRows, kePotongan } = await import('../src/utils/instalasiCetak.js')

/*
 * PEMECAHAN BARIS UNTUK CETAKAN STATUS INSTALASI
 * ==============================================
 *
 * Cetakan tabel status instalasi memakai kop surat dan judul di halaman
 * pertama, lalu dipecah jadi beberapa halaman A4 landscape.
 *
 * Yang paling penting di file ini:
 *
 *   SEMUA baris harus masuk cetakan. Kalau ada baris yang hilang
 *   diam-diam, laporan yang Looks lengkap sebenarnya tidak lengkap --
 *   dan tidak ada tanda apa pun di dokumennya.
 *
 *   Jumlah baris per halaman tidak bisa ditetapkan dengan angka tetap.
 *   Satu baris bisa satu baris teks atau dua, tergantung panjang nama
 *   dan alamat. Karena itu tinggi tiap baris diukur dari DOM, lalu
 *   dikemas dengan `packRows`.
 *
 * Jalankan: `node --test frontend/test/`
 */

describe('packRows', () => {
  it('semua baris masuk ke halaman, tidak ada yang hilang', () => {
    const tinggi = Array.from({ length: 137 }, (_, i) => (i % 3 === 0 ? 28 : 16))

    const hasil = packRows(tinggi, 200, 240)

    const semua = hasil.flat()

    assert.equal(semua.length, 137, 'jumlah baris di halaman harus sama dengan jumlah baris asal')
    assert.equal(semua[0], 0)
    assert.equal(semua[semua.length - 1], 136)

    // Tidak boleh ada baris yang muncul dua kali atau terlewat.
    assert.equal(new Set(semua).size, 137)
  })

  it('baris tidak pernah melebihi tinggi yang tersedia', () => {
    const tinggi = Array.from({ length: 60 }, (_, i) => (i % 2 === 0 ? 28 : 16))

    const tersediaPertama = 150
    const tersediaBerikutnya = 190

    const hasil = packRows(tinggi, tersediaPertama, tersediaBerikutnya)

    hasil.forEach((indeks, halaman) => {
      const total = indeks.reduce((sum, i) => sum + tinggi[i], 0)
      const batas = halaman === 0 ? tersediaPertama : tersediaBerikutnya

      assert.ok(total <= batas, `halaman ${halaman + 1} berisi ${total}px, batasnya ${batas}px`)
    })
  })

  it('halaman pertama memakai batasnya sendiri, halaman lanjutan memakai batas yang lain', () => {
    // 10 baris setinggi 20px.
    const tinggi = Array.from({ length: 10 }, () => 20)

    // Halaman pertama hanya muat 2 baris (40px), halaman lanjutan 5 baris (100px).
    const hasil = packRows(tinggi, 40, 100)

    assert.equal(hasil[0].length, 2)
    assert.equal(hasil[1].length, 5)
    assert.equal(hasil[2].length, 3)
  })

  it('baris yang lebih tinggi dari halaman tetap dapat dicetak sendiri', () => {
    // Satu baris setinggi 500px, sedangkan halaman hanya muat 100px.
    const tinggi = [500, 20, 20]

    const hasil = packRows(tinggi, 100, 100)

    assert.deepEqual(hasil[0], [0], 'baris pertama tidak boleh dipecah')
    assert.deepEqual(hasil[1], [1, 2])
  })

  it('baris tepat sama dengan sisa tinggi masih muat', () => {
    const tinggi = [50, 50, 50]

    const hasil = packRows(tinggi, 100, 100)

    assert.deepEqual(hasil[0], [0, 1])
    assert.deepEqual(hasil[1], [2])
  })

  it('daftar baris kosong menghasilkan tanpa halaman', () => {
    assert.deepEqual(packRows([], 100, 100), [])
  })
})

describe('kePotongan', () => {
  it('potongan membawa baris yang tepat dan nomor urut yang benar', () => {
    const rows = Array.from({ length: 5 }, (_, i) => ({ id: i + 1 }))

    const potongan = kePotongan(rows, [
      [0, 1],
      [2, 3, 4],
    ])

    assert.equal(potongan.length, 2)

    assert.equal(potongan[0].startIndex, 0)
    assert.deepEqual(
      potongan[0].items.map((r) => r.id),
      [1, 2],
    )

    assert.equal(potongan[1].startIndex, 2)
    assert.deepEqual(
      potongan[1].items.map((r) => r.id),
      [3, 4, 5],
    )
  })

  it('urutan nomor baris berlanjut antar halaman', () => {
    const rows = Array.from({ length: 7 }, (_, i) => i)

    const potongan = kePotongan(rows, [
      [0, 1, 2],
      [3, 4],
      [5, 6],
    ])

    // Nomor pada tabel = startIndex + posisi + 1, jadi harus berlanjut.
    const nomor = potongan.flatMap((p) => p.items.map((_, i) => p.startIndex + i + 1))

    assert.deepEqual(nomor, [1, 2, 3, 4, 5, 6, 7])
  })
})
