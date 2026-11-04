import { strict as assert } from 'node:assert'
import { before, describe, it } from 'node:test'

import { pasangDom } from './vueHarness.mjs'

/*
 * REDIRECT PATH LAMA ARSIP: `/app/arsip/tunggakan` -> `/app/arsip/piutang`.
 *
 * Feature arsip diubah namanya dari "Tunggakan" jadi "Piutang", jadi path
 * `/app/arsip/tunggakan` tidak lagi ada. Bookmark dan tautan yang sudah
 * tersebar (chat WhatsApp, shortcut lama) akan menabrak catch-all
 * `/:pathMatch(.*)*` dan mendarat di dashboard tanpa penjelasan --
 * gejalanya seperti "halaman arsipnya hilang", padahal cuma pindah nama.
 *
 * Yang paling penting di test ini: QUERY harus diteruskan.
 * `?bulan=10&tahun=2026` bukan hiasan -- itu periode yang memang sedang
 * dilihat user. Redirect yang membuang query akan mendarat di halaman
 * yang benar dengan filter yang salah, dan itu lebih membingungkan dari
 * 404: user melihat angka piutang bulan ini, lalu mengira datanya salah.
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

describe('redirect path lama arsip piutang', () => {
  it('path lama dialihkan ke path baru', async () => {
    const hasil = await navigasi('/app/arsip/tunggakan')

    assert.equal(hasil.path, '/app/arsip/piutang')
  })

  it('query bulan dan tahun diteruskan ke path baru', async () => {
    const hasil = await navigasi('/app/arsip/tunggakan?bulan=10&tahun=2026')

    assert.equal(hasil.path, '/app/arsip/piutang')
    assert.equal(hasil.query.bulan, '10')
    assert.equal(hasil.query.tahun, '2026')
  })

  it('path tanpa query tetap dialihkan dengan query kosong', async () => {
    const hasil = await navigasi('/app/arsip/tunggakan')

    assert.equal(hasil.path, '/app/arsip/piutang')
    assert.deepEqual(hasil.query, {})
  })

  it('path lama dicatat sebagai asal redirect', async () => {
    const hasil = await navigasi('/app/arsip/tunggakan?bulan=10&tahun=2026')

    assert.equal(hasil.redirectedFrom?.path, '/app/arsip/tunggakan')
  })

  it('path baru tidak ikut dialihkan (tidak ada loop)', async () => {
    const hasil = await navigasi('/app/arsip/piutang')

    assert.equal(hasil.path, '/app/arsip/piutang')
    assert.equal(hasil.redirectedFrom, undefined)
  })

  it('path arsip lain tidak terpengaruh', async () => {
    for (const path of ['/app/arsip/instalasi', '/app/arsip/pemakaian', '/app/arsip/tagihan']) {
      const hasil = await navigasi(path)

      assert.equal(hasil.path, path)
      assert.equal(hasil.redirectedFrom, undefined)
    }
  })
})
