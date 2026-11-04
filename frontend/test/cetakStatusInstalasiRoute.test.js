import { strict as assert } from 'node:assert'
import { before, describe, it } from 'node:test'

import { pasangDom } from './vueHarness.mjs'

/*
 * ROUTE CETAK STATUS INSTALASI
 * ============================
 *
 * Halaman cetak status instalasi terdaftar sebagai route TOP-LEVEL
 * (`/app/instalasi/status/cetak`), bukan anak dari `/app`.
 *
 * Kenapa top-level:
 *
 *   Halaman cetak memakai kerangka preview sendiri -- tanpa sidebar
 *   aplikasi, dengan toolbar zoom dan thumbnail. Kalau route-nya jadi
 *   anak dari `/app`, halaman dirender di dalam MainView, dan sidebar
 *   ikut tercetak ke PDF.
 *
 * Kenapa tetap AMAN:
 *
 *   Prefix admin di `ROLE_GUARDS` dicocokkan dengan
 *   `path.startsWith(prefix + '/')`. Jadi `/app/instalasi/status/cetak`
 *   tetap tertangkap prefix `/app/instalasi/status` dan role non-admin
 *   tetap dialihkan ke dashboard-nya.
 */

let router

before(async () => {
  await pasangDom()

  localStorage.setItem('auth_token', 'token-uji')
  localStorage.setItem('auth_expires_at', String(Date.now() + 3_600_000))
  localStorage.setItem('user_role', 'admin')

  const { createPinia, setActivePinia } = await import('pinia')

  setActivePinia(createPinia())

  const modul = await import('../src/router/index.js')

  router = modul.default
})

async function navigasi(path) {
  await router.push(path).catch(() => {})

  return router.currentRoute.value
}

describe('route cetak status instalasi', () => {
  it('route-nya terdaftar sebagai top-level', () => {
    const hasil = router.resolve({ path: '/app/instalasi/status/cetak' })

    assert.equal(hasil.name, 'Cetak Status Instalasi')
    assert.equal(
      hasil.matched.length,
      1,
      'route harus top-level: kalau lebih dari satu, halaman ikut dibungkus MainView (sidebar ikut tercetak)',
    )
  })

  it('query filter dan q diteruskan', () => {
    const hasil = router.resolve({
      path: '/app/instalasi/status/cetak',
      query: { filter: 'aktif', q: 'Siti' },
    })

    assert.equal(hasil.path, '/app/instalasi/status/cetak')
    assert.equal(hasil.query.filter, 'aktif')
    assert.equal(hasil.query.q, 'Siti')
  })

  it('admin boleh membuka', async () => {
    const hasil = await navigasi('/app/instalasi/status/cetak?filter=permohonan&q=Siti')

    assert.equal(hasil.path, '/app/instalasi/status/cetak')
    assert.equal(hasil.query.filter, 'permohonan')
  })

  it('role teknisi dialihkan ke dashboardnya', async () => {
    localStorage.setItem('user_role', 'teknisi')

    const hasil = await navigasi('/app/instalasi/status/cetak?filter=permohonan')

    assert.notEqual(
      hasil.path,
      '/app/instalasi/status/cetak',
      'halaman cetak status instalasi hanya boleh untuk admin',
    )

    localStorage.setItem('user_role', 'admin')
  })

  it('halaman status instalasi yang biasa tidak tertimpa', async () => {
    const hasil = await navigasi('/app/instalasi/status')

    assert.equal(hasil.path, '/app/instalasi/status')
  })
})
