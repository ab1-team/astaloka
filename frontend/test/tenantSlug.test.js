import { strict as assert } from 'node:assert'
import { beforeEach, describe, it } from 'node:test'

import {
  TENANT_SLUG_KEY,
  hapusTenantSlug,
  simpanTenantSlug,
  tenantSlugAktif,
} from '../src/utils/tenantSlug.js'

/**
 * `tenantSlugAktif()` adalah satu-satunya sumber header `X-Tenant-Slug`.
 *
 * Nilai yang tertinggal di localStorage setelah logout lokasi membuat
 * request berikutnya membawa identitas lokasi lama -- termasuk ke panel
 * pusat. Itu bukan bug tampilan: resolver mempercayai header ini di luar
 * produksi, jadi request bisa mendarat di database lokasi yang salah.
 */

const store = new Map()

globalThis.localStorage = {
  getItem: (kunci) => (store.has(kunci) ? store.get(kunci) : null),
  setItem: (kunci, nilai) => store.set(kunci, String(nilai)),
  removeItem: (kunci) => store.delete(kunci),
}

describe('tenantSlug', () => {
  beforeEach(() => {
    store.clear()
  })

  it('menyimpan slug dalam bentuk huruf kecil tanpa spasi tepi', () => {
    simpanTenantSlug(' Demo.Test ')

    assert.equal(store.get(TENANT_SLUG_KEY), 'demo.test')
    assert.equal(tenantSlugAktif(), 'demo.test')
  })

  it('null melepas slug lama, bukan mempertahankannya', () => {
    simpanTenantSlug('demo.test')
    simpanTenantSlug(null)

    assert.equal(store.has(TENANT_SLUG_KEY), false)
    assert.equal(tenantSlugAktif(), null)
  })

  it('string kosong juga melepas slug lama', () => {
    simpanTenantSlug('demo.test')
    simpanTenantSlug('   ')

    assert.equal(store.has(TENANT_SLUG_KEY), false)
    assert.equal(tenantSlugAktif(), null)
  })

  it('hapusTenantSlug menghapus nilai yang tersimpan', () => {
    simpanTenantSlug('demo.test')
    hapusTenantSlug()

    assert.equal(store.has(TENANT_SLUG_KEY), false)
  })
})
