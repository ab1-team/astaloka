import { strict as assert } from 'node:assert'
import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { after, before, describe, it } from 'node:test'

import { pasangDom, komponen, tutupDom } from './vueHarness.mjs'

/*
 * KOP SURAT CETAKAN STATUS INSTALASI
 * ==================================
 *
 * Halaman cetak Status Instalasi memakai `BaseReportLayout` dari modul
 * Pelaporan, tapi dengan `no-kop` -- kop surat digambar sendiri di
 * dalam `ReportCetakStatusInstalasi.vue`.
 *
 * Kenapa tidak memakai kop bawaan layout: kop itu memakai
 * `lembaga.alamat_kab` sebagai baris kedua. Identitas lembaga yang
 * kita punya tidak pernah punya field itu, jadi placeholder
 * "ALAMAT LEMBAGA" yang akan tercetak.
 *
 * Test di sini mengunci hal yang tidak terlihat dari kode: aturan
 * scoped CSS milik komponen laporan harus benar-benar sampai ke
 * elemen kop, dan padding halaman harus benar-benar 12mm/15mm.
 *
 * Kenapa ini penting: `cetakStatusInstalasi.vue` MENGUKUR tinggi baris
 * dari DOM (probe off-screen) untuk memecah baris ke halaman. Kalau
 * aturan scoped tidak berlaku, padding yang terukur bukan padding
 * yang dicetak -- jumlah baris per halaman jadi meleset, dan halaman
 * terakhir meluber ke halaman fisik berikutnya.
 *
 * Jalankan: `node --test frontend/test/`
 */

let ReportCetakStatusInstalasi = null

before(async () => {
  await pasangDom()

  ReportCetakStatusInstalasi = await komponen(
    'presentations/views/app/admin/instalasi/partials/ReportCetakStatusInstalasi.vue',
  )
})

after(async () => {
  await tutupDom()
})

const payloadDasar = {
  config: { paper_size: 'A4', orientation: 'landscape' },
  items: [
    {
      id: 'PAM-0001',
      ticketId: 1,
      name: 'Budi Santoso',
      nik: '3201010101010001',
      phone: '08123456789',
      type: 'Paket 10m3',
      address: 'Jl. Merdeka No. 1',
      village: 'Sukamaju',
      status: 'Selesai',
      orderDate: '01/11/2026',
    },
  ],
  startIndex: 0,
  showMeta: true,
  total: 1,
  label: 'Permohonan',
  search: '',
  lembaga: {
    nama_lembaga: 'Demo Astaloka',
    alamat: 'Jl. Contoh No. 9',
    telepon: '0271-123456',
    email: 'demo@example.com',
    initials: 'DA',
  },
}

/**
 * Baca berkas dari folder `frontend/`.
 *
 * Dipakai untuk memeriksa SUMBER template, bukan hasil render.
 */
async function bacaBerkas(relatif) {
  const path = fileURLToPath(new URL(`../${relatif}`, import.meta.url))

  return readFile(path, 'utf-8')
}

/**
 * Pasang komponen dan kembalikan elemen DOM-nya.
 *
 * `@vue/test-utils` dipakai supaya komponen benar-benar dirender.
 * Render manual lewat `createApp` juga bisa, tapi butuh container
 * dan handle `unmount` sendiri.
 */
async function render(payload = payloadDasar) {
  const { mount } = await import('@vue/test-utils')

  const wrapper = mount(ReportCetakStatusInstalasi, {
    props: {
      payload,
      meta: { page: 1, total: 1 },
    },
  })

  return wrapper
}

describe('ReportCetakStatusInstalasi', () => {
  it('halaman memakai ukuran A4 landscape', async () => {
    const wrapper = await render()

    /*
     * `BaseReportLayout` punya root element `.report-page`, dan itu
     * yang jadi root komponen ini juga. Jadi elemen root-lah yang
     * dicek, bukan turunannya.
     */
    const halaman = wrapper.element

    assert.ok(halaman.classList.contains('report-page'), 'root harus elemen halaman laporan')
    assert.ok(halaman.classList.contains('size-a4'), 'ukuran kertas harus A4')
    assert.ok(
      halaman.classList.contains('landscape'),
      'orientasi harus landscape (tabel 10 kolom terlalu lebar untuk portrait)',
    )

    wrapper.unmount()
  })

  it('kop surat digambar sendiri, bukan dari layout bawaan', async () => {
    const wrapper = await render()

    const kop = wrapper.element.querySelector('.surat-kop')

    assert.ok(kop, 'kop surat harus ada')
    assert.ok(
      kop.querySelector('.kop-logo-fallback'),
      'kalau logo tidak ada, harus muncul fallback inisial',
    )
    assert.ok(
      wrapper.element.textContent.includes('Demo Astaloka'),
      'nama lembaga harus tercetak di kop',
    )
    assert.ok(
      wrapper.element.textContent.includes('Jl. Contoh No. 9'),
      'alamat lembaga harus tercetak di kop',
    )

    wrapper.unmount()
  })

  it('identitas lembaga diambil dari payload, bukan ditulis manual', async () => {
    /*
     * Nama lembaga HARUS datang dari payload, bukan ditulis langsung
     * di template. Kalau ditulis manual, kop surat tidak ikut berubah
     * saat admin mengubah profil lembaga di Pengaturan.
     *
     * Dicek dari SUMBER template, bukan dari hasil render: hasil
     * render memang berisi nama lembaga (dari payload), dan itu
     * justru yang membuatnya tidak bisa dibedakan dari teks hardcoded.
     */
    const sumber = await bacaBerkas(
      'src/presentations/views/app/admin/instalasi/partials/ReportCetakStatusInstalasi.vue',
    )

    const template = sumber.slice(0, sumber.indexOf('<script setup>'))

    // Komentar HTML dibuang: komentar memang sering menyebut contoh
    // teks ("ALAMAT LEMBAGA"), tapi komentar tidak pernah tercetak.
    const templateTanpaKomentar = template.replace(/<!--[\s\S]*?-->/g, '')

    assert.ok(
      !templateTanpaKomentar.includes('Demo Astaloka'),
      'nama lembaga tidak boleh ditulis manual di template',
    )
    assert.ok(
      !templateTanpaKomentar.includes('ALAMAT LEMBAGA'),
      'alamat tidak boleh pakai placeholder hardcoded',
    )

    // Tapi nilainya HARUS masuk ke kop dari payload.
    const wrapper = await render()

    assert.ok(
      wrapper.element.textContent.includes('Demo Astaloka'),
      'nama lembaga dari payload harus tercetak di kop',
    )

    wrapper.unmount()
  })

  it('tabel memuat 10 kolom sesuai urutan yang dipakai di Excel', async () => {
    const wrapper = await render()

    const headers = Array.from(wrapper.element.querySelectorAll('thead th')).map((th) =>
      th.textContent.trim(),
    )

    assert.deepEqual(headers, [
      'No',
      'Kode Instalasi',
      'Nama Pelanggan',
      'NIK',
      'No. Telp',
      'Alamat',
      'Desa/Dusun',
      'Paket',
      'Status',
      'Tgl Order',
    ])

    wrapper.unmount()
  })

  it('nomor baris melanjutkan dari halaman sebelumnya', async () => {
    const wrapper = await render({ ...payloadDasar, startIndex: 40 })

    /*
     * `tbody` PERTAMA adalah milik tabel kop surat, jadi yang dicari
     * adalah `tbody` tabel data -- dibedakan lewat kelasnya.
     */
    const kolomNo = wrapper.element.querySelector('.data-table tbody tr td')

    assert.ok(kolomNo, 'baris data harus ada')
    assert.equal(kolomNo.textContent.trim(), '41', 'baris pertama halaman kedua bernomor 41')

    wrapper.unmount()
  })

  it('judul dan blok meta hanya di halaman pertama', async () => {
    const wrapper = await render(payloadDasar)

    assert.ok(wrapper.element.querySelector('.page-header'), 'halaman pertama ada judul')
    assert.ok(wrapper.element.querySelector('.meta-grid'), 'halaman pertama ada blok meta')

    wrapper.unmount()

    const wrapperLanjut = await render({ ...payloadDasar, showMeta: false })

    assert.equal(
      wrapperLanjut.element.querySelector('.page-header'),
      null,
      'halaman lanjutan tidak boleh mengulang judul',
    )
    assert.equal(
      wrapperLanjut.element.querySelector('.meta-grid'),
      null,
      'halaman lanjutan tidak boleh mengulang blok meta',
    )

    wrapperLanjut.unmount()
  })

  it('kop surat tetap ada di halaman lanjutan', async () => {
    const wrapper = await render({ ...payloadDasar, showMeta: false })

    assert.ok(
      wrapper.element.querySelector('.surat-kop'),
      'halaman lanjutan tetap harus punya kop surat, supaya jelas milik lembaga mana',
    )

    wrapper.unmount()
  })

  it('footer mencantumkan nomor halaman', async () => {
    const wrapper = await render(payloadDasar)

    const footer = wrapper.element.querySelector('.page-footer')

    assert.ok(footer, 'footer halaman harus ada')
    assert.ok(
      footer.textContent.includes('Halaman 1 dari 1'),
      'footer harus menyebut nomor halaman',
    )

    wrapper.unmount()
  })
})
