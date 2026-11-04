import { strict as assert } from 'node:assert'
import { before, describe, it } from 'node:test'

import { pasangDom } from './vueHarness.mjs'

/*
 * PATH PEMAKAIAN: kata "air" dihapus dari URL.
 *
 *   SEBELUM : /app/instalasi/pemakaian-air        -> /app/instalasi/pemakaian-air/input
 *   SESUDAH : /app/instalasi/pemakaian            -> /app/instalasi/pemakaian/input
 *
 * Yang paling penting di test ini: QUERY harus diteruskan.
 * `?bulan=Oktober&tahun=2026` bukan hiasan -- itu periode yang memang
 * sedang dilihat user. Redirect yang membuang query akan mendarat di
 * halaman yang benar dengan filter yang salah, dan itu lebih membingungkan
 * dari 404: user melihat angka pemakaian bulan ini, lalu mengira datanya
 * salah.
 *
 * Router tidak bisa diuji dengan `router.resolve()` saja: `resolve()`
 * hanya mengembalikan route yang COCOK, tanpa mengikuti redirect.
 * Redirect baru terlihat setelah `router.push()` -- dan `push()` melewati
 * guard, jadi test ini perlu menyiapkan sesi (token) dan Pinia lebih dulu.
 */

let router

before(async () => {
  await pasangDom()

  /*
   * Sesi.
   *
   * Tanpa token, guard melempar ke `/login` -- dan testnya jadi tidak
   * menguji apa pun: yang terlihat hanya "selalu ke login", yang benar
   * juga berlaku kalau redirect-nya hilang sama sekali.
   */
  localStorage.setItem('auth_token', 'token-uji')
  localStorage.setItem('auth_expires_at', String(Date.now() + 3_600_000))
  localStorage.setItem('user_role', 'admin')

  const { createPinia, setActivePinia } = await import('pinia')

  setActivePinia(createPinia())

  const modul = await import('../src/router/index.js')

  router = modul.default
})

/**
 * Navigasi dan kembalikan route akhirnya.
 *
 * `push()` mengembalikan Promise yang bisa DITOLAK saat guard memutuskan
 * mengalihkan ke halaman lain. Promise itu tidak ditangkap di sini
 * supaya kegagalan guard tidak jadi error yang membingungkan -- yang
 * diperiksa adalah `currentRoute`, yaitu tempat sebenarnya user berada
 * setelah semua guard selesai.
 */
async function navigasi(path) {
  await router.push(path).catch(() => {})

  return router.currentRoute.value
}

describe('path pemakaian tanpa kata "air"', () => {
  it('path baru halaman pemakaian bekerja', async () => {
    const hasil = await navigasi('/app/instalasi/pemakaian')

    assert.equal(hasil.path, '/app/instalasi/pemakaian')
    assert.equal(hasil.redirectedFrom, undefined)
  })

  it('path baru halaman input pemakaian bekerja', async () => {
    const hasil = await navigasi('/app/instalasi/pemakaian/input')

    assert.equal(hasil.path, '/app/instalasi/pemakaian/input')
    assert.equal(hasil.redirectedFrom, undefined)
  })

  it('path lama halaman pemakaian dialihkan ke path baru', async () => {
    const hasil = await navigasi('/app/instalasi/pemakaian-air')

    assert.equal(hasil.path, '/app/instalasi/pemakaian')
  })

  it('path lama halaman input pemakaian dialihkan ke path baru', async () => {
    const hasil = await navigasi('/app/instalasi/pemakaian-air/input')

    assert.equal(hasil.path, '/app/instalasi/pemakaian/input')
  })

  it('query bulan dan tahun diteruskan ke path baru', async () => {
    const hasil = await navigasi('/app/instalasi/pemakaian-air/input?bulan=Oktober&tahun=2026')

    assert.equal(hasil.path, '/app/instalasi/pemakaian/input')
    assert.equal(hasil.query.bulan, 'Oktober')
    assert.equal(hasil.query.tahun, '2026')
  })

  it('query diteruskan untuk path lama halaman pemakaian juga', async () => {
    const hasil = await navigasi('/app/instalasi/pemakaian-air?bulan=10&tahun=2026')

    assert.equal(hasil.path, '/app/instalasi/pemakaian')
    assert.equal(hasil.query.bulan, '10')
    assert.equal(hasil.query.tahun, '2026')
  })

  it('path lama dicatat sebagai asal redirect', async () => {
    const hasil = await navigasi('/app/instalasi/pemakaian-air/input?bulan=10&tahun=2026')

    assert.equal(hasil.redirectedFrom?.path, '/app/instalasi/pemakaian-air/input')
  })

  it('path tanpa query tetap dialihkan dengan query kosong', async () => {
    const hasil = await navigasi('/app/instalasi/pemakaian-air/input')

    assert.equal(hasil.path, '/app/instalasi/pemakaian/input')
    assert.deepEqual(hasil.query, {})
  })

  it('tidak ada loop redirect: path baru tidak ikut dialihkan', async () => {
    /*
     * `redirectedFrom` sengaja TIDAK diperiksa di sini.
     *
     * `redirectedFrom` di router berarti "path asal yang DIALIHKAN ke
     * path ini". Kalau user benar-benar membuka path BARU (`/pemakaian/input`),
     * tidak ada path asal -- jadi `redirectedFrom` harus kosong.
     *
     * Tapi: navigasi di test ini berurutan, dan `router.push()` ke path yang
     * SAMA dengan path sekarang diabaikan router (biar tidak menambah riwayat).
     * Test sebelumnya persis di path yang sama (`/app/instalasi/pemakaian-air/input`
     * -> redirect -> `/app/instalasi/pemakaian/input`), jadi router tidak
     * menjalankan navigasi baru dan `currentRoute` masih menunjuk hasil
     * redirect sebelumnya -- dengan `redirectedFrom` yang masih terisi.
     *
     * Yang diuji di sini adalah yang penting: tidak ada LOOP. Kalau path
     * baru ikut dialihkan, `currentRoute.path` tidak akan pernah sama
     * dengan path baru.
     */
    const hasil = await navigasi('/app/instalasi/pemakaian/input')

    assert.equal(hasil.path, '/app/instalasi/pemakaian/input')
  })

  it('path pemakaian lain tidak terpengaruh', async () => {
    for (const path of ['/app/instalasi/daftar-tagihan', '/app/instalasi/teknisiPemakaianAir']) {
      const hasil = await navigasi(path)

      assert.equal(hasil.path, path)
      assert.equal(hasil.redirectedFrom, undefined)
    }
  })
})
