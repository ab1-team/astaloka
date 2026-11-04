import { strict as assert } from 'node:assert'
import { describe, it } from 'node:test'

import { daftarDomainLokasi, daftarDomainTerurut } from '../src/utils/daftarDomain.js'

/**
 * Daftar domain yang ditampilkan di panel pusat.
 *
 * Yang diuji di sini adalah penggabungan dua sumber: daftar dari
 * `tenant_domains` dan dua kolom legacy di `tenants`.
 *
 * Bug yang memicu utility ini: panel hanya menampilkan SATU domain untuk
 * lokasi yang jelas punya dua -- keduanya diisi di form yang sama, keduanya
 * dilayani resolver. Akar masalahnya di backend (baris utama ikut terhapus
 * setiap kali slot alternatif diisi), tapi tampilan punya dua sumber yang
 * bisa berbeda isinya, dan menunjuk satu saja berarti domain yang hidup
 * jadi tidak terlihat.
 *
 * Jalankan: `node --test frontend/test/`
 */
describe('daftarDomainLokasi', () => {
  describe('daftar dari tenant_domains', () => {
    it('menampilkan semua domain milik lokasi', () => {
      // Dua domain: keduanya harus terlihat. Ini inti keluhan yang
      // diperbaiki -- hanya satu yang muncul.
      const hasil = daftarDomainLokasi({
        domains: [
          { id: 1, host: 'utama.test', is_primary: true, is_wildcard: false, is_active: true },
          {
            id: 2,
            host: 'alternatif.test',
            is_primary: false,
            is_wildcard: false,
            is_active: true,
          },
        ],
      })

      assert.deepEqual(
        hasil.map((d) => d.host),
        ['utama.test', 'alternatif.test'],
      )
    })

    it('mempertahankan wildcard dan status aktif', () => {
      const hasil = daftarDomainLokasi({
        domains: [
          { id: 1, host: '*.astaloka.test', is_primary: false, is_wildcard: true, is_active: true },
          { id: 2, host: 'mati.test', is_primary: false, is_wildcard: false, is_active: false },
        ],
      })

      assert.equal(hasil[0].is_wildcard, true)
      assert.equal(hasil[1].is_active, false)
    })

    it('membuang domain tanpa host', () => {
      const hasil = daftarDomainLokasi({
        domains: [
          { id: 1, host: 'ada.test' },
          { id: 2, host: '' },
          { id: 3, host: null },
        ],
      })

      assert.equal(hasil.length, 1)
      assert.equal(hasil[0].host, 'ada.test')
    })

    it('memberi kunci unik untuk setiap baris, termasuk entri legacy', () => {
      // Template Vue memakai `:key`, dan entri dari kolom legacy tidak
      // punya id baris database. Tanpa kunci yang selalu ada, dua entri
      // legacy akan berbagi `undefined` dan Vue menampilkan peringatan
      // "duplicate keys" -- lalu bisa merender baris yang salah.
      const hasil = daftarDomainLokasi({
        domains: [
          { id: 7, host: 'utama.test', is_primary: true, is_active: true },
          { id: 8, host: 'alternatif.test', is_primary: false, is_active: true },
        ],
        domain: 'legacy-utama.test',
        domain_alternatif: 'legacy-alternatif.test',
      })

      assert.equal(hasil.length, 4)

      const kunci = hasil.map((d) => d.key)

      assert.ok(kunci.every((k) => k !== undefined && k !== null))
      assert.equal(new Set(kunci).size, kunci.length, 'Kunci harus unik.')
    })
  })

  describe('penggabungan dengan kolom legacy', () => {
    it('menambah host legacy yang belum ada di daftar baru', () => {
      // Keadaan yang menimpa bug lama: baris utama hilang dari
      // `tenant_domains`, tapi kolom `domain` masih menunjuk host itu --
      // dan host itu masih dilayani resolver.
      const hasil = daftarDomainLokasi({
        domains: [{ id: 2, host: 'alternatif.test', is_primary: false, is_active: true }],
        domain: 'utama.test',
        domain_alternatif: 'alternatif.test',
      })

      assert.deepEqual(hasil.map((d) => d.host).sort(), ['alternatif.test', 'utama.test'])
    })

    it('tidak menggandakan host yang sudah ada di daftar baru', () => {
      const hasil = daftarDomainLokasi({
        domains: [{ id: 1, host: 'utama.test', is_primary: true, is_active: true }],
        domain: 'utama.test',
        domain_alternatif: 'alternatif.test',
      })

      const host = hasil.map((d) => d.host)

      assert.equal(host.filter((h) => h === 'utama.test').length, 1)
      assert.ok(host.includes('alternatif.test'))
    })

    it('membuang kolom legacy yang kosong', () => {
      const hasil = daftarDomainLokasi({
        domains: [{ id: 1, host: 'utama.test', is_primary: true, is_active: true }],
        domain: 'utama.test',
        domain_alternatif: null,
      })

      assert.equal(hasil.length, 1)
    })

    it('tetap menampilkan kolom legacy walau daftar barunya kosong', () => {
      // Kolom legacy tidak pernah dihapus dan masih dibaca resolver sebagai
      // cadangan -- jadi host di sana masih dilayani, dan panel HARUS
      // menampilkannya. Kalau tidak, admin tidak akan tahu kenapa domain
      // lamanya masih jalan padahal tidak ada di daftar.
      const hasil = daftarDomainLokasi({
        domains: [],
        domain: 'lama.test',
        domain_alternatif: 'lama-alternatif.test',
      })

      assert.deepEqual(hasil.map((d) => d.host).sort(), ['lama-alternatif.test', 'lama.test'])
    })
  })

  describe('fallback penuh ke kolom legacy', () => {
    it('dipakai saat backend tidak mengirim daftar sama sekali', () => {
      // Backend versi lama tidak mengirim `domains` sama sekali.
      const hasil = daftarDomainLokasi({
        domain: 'utama.test',
        domain_alternatif: 'alternatif.test',
      })

      assert.deepEqual(
        hasil.map((d) => d.host),
        ['utama.test', 'alternatif.test'],
      )
      assert.equal(hasil[0].is_primary, true)
      assert.equal(hasil[1].is_primary, false)
    })

    it('tidak melempar pada baris tanpa data sama sekali', () => {
      assert.deepEqual(daftarDomainLokasi(null), [])
      assert.deepEqual(daftarDomainLokasi(undefined), [])
      assert.deepEqual(daftarDomainLokasi({}), [])
    })
  })
})

describe('daftarDomainTerurut', () => {
  it('menaruh domain utama di atas', () => {
    // Domain utama adalah satu-satunya yang dipakai `settings.domain` di
    // database lokasi dan satu-satunya yang muncul di kop laporan.
    const hasil = daftarDomainTerurut({
      domains: [
        { id: 1, host: 'zzz.test', is_primary: false, is_active: true },
        { id: 2, host: 'aaa.test', is_primary: true, is_active: true },
      ],
    })

    assert.equal(hasil[0].host, 'aaa.test')
  })

  it('menaruh domain aktif mendahului domain nonaktif', () => {
    const hasil = daftarDomainTerurut({
      domains: [
        { id: 1, host: 'mati.test', is_primary: false, is_active: false },
        { id: 2, host: 'aktif.test', is_primary: false, is_active: true },
      ],
    })

    assert.equal(hasil[0].host, 'aktif.test')
  })

  it('mengurutkan sisanya menurut host', () => {
    const hasil = daftarDomainTerurut({
      domains: [
        { id: 1, host: 'c.test', is_primary: false, is_active: true },
        { id: 2, host: 'a.test', is_primary: false, is_active: true },
        { id: 3, host: 'b.test', is_primary: false, is_active: true },
      ],
    })

    assert.deepEqual(
      hasil.map((d) => d.host),
      ['a.test', 'b.test', 'c.test'],
    )
  })
})
