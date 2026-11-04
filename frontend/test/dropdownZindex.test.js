import { strict as assert } from 'node:assert'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, it } from 'node:test'

/**
 * Dropdown yang terbuka harus MENUTUPI input "Cari..." milik tabel.
 *
 * Gejalanya
 * ---------
 *
 * Di halaman "Pemakaian Air", dropdown "Pilih Teknisi" dibuka, tapi input
 * "Cari..." milik tabel di bawahnya tetap kelihatan -- bahkan menimpa
 * dropdown, padahal dropdown itu z-index-nya 9999.
 *
 * Sebabnya
 * --------
 *
 * `ContentCard` yang `hoverable` mendapat `transform: translateY(-2px)`
 * ketika kursor lewat di atasnya. `transform` otomatis membuat STACKING
 * CONTEXT, dan sejak saat itu z-index dropdown hanya dibandingkan dengan
 * isi card itu sendiri -- bukan dengan halaman. Card (z-index auto) lalu
 * bersaing dengan `DataTable` di bawahnya di level yang sama, dan di level
 * yang sama yang menang adalah URUTAN DOM. Tabel ditulis SESUDAH card
 * filter di template, jadi tabel menimpa dropdown.
 *
 * Yang dikunci di sini
 * --------------------
 *
 * Aturan `.content-card:has(.select-dropdown)` harus tetap ada, dengan
 * `position: relative` dan z-index yang:
 *  - lebih besar dari z-index isi halaman biasa (tabel, kartu lain), dan
 *  - lebih kecil dari topnav (50), sub-header (40), dan overlay
 *    PrimeVue (10000) -- dropdown tidak boleh menutupi navigasi.
 *
 * Selektornya harus `:has(.select-dropdown)`, BUKAN `:has(.custom-select-search)`.
 * Alasannya: `.select-dropdown` hanya ADA di DOM ketika dropdown-nya benar-
 * benar terbuka (`v-if="isOpen"` di `SelectSearch.vue`). Kalau yang diperiksa
 * adalah `.custom-select-search` -- yang selalu ada -- card filter akan
 * terus meninggi dan ikut menutupi isi lain di halaman yang tidak ada
 * hubungannya dengan dropdown.
 *
 * Jalankan: `node --test frontend/test/`
 */

const AKAR = fileURLToPath(new URL('../src/', import.meta.url))

const UI_BASE = readFileSync(join(AKAR, 'assets/css/ui-base.css'), 'utf8')
const MAIN_CSS = readFileSync(join(AKAR, 'assets/css/main.css'), 'utf8')

/**
 * Ambil isi blok aturan CSS berdasarkan selektor.
 *
 * Komentar dibuang dulu supaya blok CSS yang sengaja ditulis sebagai
 * contoh di dalam penjelasan dokumentasi tidak ikut terhitung.
 */
function isiBlok(selektor) {
  const tanpaKomentar = UI_BASE.replace(/\/\*[\s\S]*?\*\//g, '')
  const pola = selektor.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const rx = new RegExp(`(?:^|\\})\\s*${pola}\\s*\\{([^{}]*)\\}`, 'm')
  const cocok = tanpaKomentar.match(rx)
  return cocok ? cocok[1] : null
}

/**
 * Buang komentar dari isi berkas .vue.
 *
 * Komentar HTML dan komentar JS sengaja boleh menyebut emoji --
 * penjelasan sering mengutip hal yang baru saja diganti. Yang penting
 * adalah kode yang benar-benar dirender.
 */
function tanpaKomentar(isi) {
  return isi
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '')
}

/** Ambil nilai numerik z-index dari deklarasi CSS. */
function zIndexDari(isi) {
  const cocok = isi.match(/z-index:\s*(-?\d+)/)
  return cocok ? Number(cocok[1]) : null
}

/**
 * Ambil nilai z-index yang tertulis di `main.css` untuk sebuah selektor.
 *
 * Dipakai untuk membandingkan angka z-index card dengan lapisan navigasi
 * yang sudah ada di aplikasi.
 */
function zIndexLapis(selektor) {
  const pola = selektor.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const rx = new RegExp(`${pola}\\s*\\{[^{}]*?z-index:\\s*(-?\\d+)`, 'm')
  const cocok = MAIN_CSS.match(rx)
  return cocok ? Number(cocok[1]) : null
}

describe('dropdown di atas input cari tabel', () => {
  it('aturan card yang membuka dropdown masih ada di ui-base.css', () => {
    const isi = isiBlok('.content-card:has(.select-dropdown)')

    assert.ok(
      isi !== null,
      'aturan `.content-card:has(.select-dropdown)` hilang dari ui-base.css -- ' +
        'dropdown akan kembali tertutup input "Cari..." milik tabel',
    )

    assert.match(
      isi,
      /position:\s*relative/,
      'card yang berisi dropdown harus `position: relative` supaya z-index-nya berlaku',
    )

    const z = zIndexDari(isi)
    assert.ok(z !== null, 'blok itu tidak punya z-index')
    assert.ok(z > 1, `z-index card (${z}) harus lebih besar dari z-index isi halaman (1)`)
  })

  it('selektornya memakai .select-dropdown, bukan .custom-select-search', () => {
    // `.custom-select-search` selalu ada di DOM, jadi memakainya sebagai
    // pemicu akan membuat card meninggi TERUS -- termasuk saat tidak ada
    // dropdown yang terbuka. `.select-dropdown` baru muncul saat terbuka.
    assert.match(
      UI_BASE,
      /\.content-card:has\(\.select-dropdown\)/,
      'selektor pemicu harus `.content-card:has(.select-dropdown)`',
    )

    const isi = isiBlok('.content-card:has(.custom-select-search)')
    assert.equal(
      isi,
      null,
      'ada aturan `.content-card:has(.custom-select-search)` -- card akan meninggi terus ' +
        'walaupun tidak ada dropdown yang terbuka',
    )
  })

  it('card tidak menutupi navigasi dan overlay', () => {
    const isi = isiBlok('.content-card:has(.select-dropdown)')
    const z = zIndexDari(isi)

    const topnav = zIndexLapis('.topnav')
    const subHeader = zIndexLapis('.sub-header')

    if (topnav !== null) {
      assert.ok(
        z < topnav,
        `z-index card (${z}) harus di bawah topnav (${topnav}) -- dropdown tidak boleh menutupi navigasi`,
      )
    }

    if (subHeader !== null) {
      assert.ok(z < subHeader, `z-index card (${z}) harus di bawah sub-header (${subHeader})`)
    }

    // Overlay PrimeVue (DatePicker, dialog) berada di angka 10000-an.
    // Kalau card ikut naik ke angka itu, dropdown bisa menutupi panel
    // tanggal yang sedang terbuka.
    assert.ok(
      z < 1000,
      `z-index card (${z}) harus di bawah BaseModal (1000) dan overlay PrimeVue (10000)`,
    )
  })

  it('SelectSearch hanya merender dropdown saat isOpen', () => {
    // Dasar dari aturan `:has(.select-dropdown)`: kalau dropdown selalu ada
    // di DOM, `:has()` tidak bisa membedakan "terbuka" dan "tertutup".
    const selectSearch = readFileSync(
      join(AKAR, 'presentations/components/SelectSearch.vue'),
      'utf8',
    )

    assert.match(
      selectSearch,
      /v-if="isOpen"[^>]*class="select-dropdown"|class="select-dropdown"[^>]*v-if="isOpen"/,
      'dropdown SelectSearch harus dirender dengan `v-if="isOpen"`',
    )
  })
})

describe('ikon search memakai FontAwesome, bukan emoji', () => {
  /**
   * Emoji `🔍` digambar oleh sistem operasi dengan gayanya sendiri: di
   * Windows dia berwarna-warni (kaca pembesar biru-merah) dan bentuknya
   * jauh lebih besar daripada ikon garis yang dipakai komponen lain.
   * Akibatnya ikon search di header tabel terlihat "beda" dari ikon
   * search di dalam dropdown -- keduanya sebenarnya ikon yang sama untuk
   * aksi yang sama.
   *
   * Yang dikunci: tidak ada emoji search yang masih tertinggal di
   * komponen UI. Emoji lain (📊, ✅, ❌) bukan urusan test ini.
   */
  const BERKAS = [
    'presentations/components/ui/DataTable.vue',
    'presentations/components/transaksi/DaftarAkunSaldo.vue',
    'presentations/views/app/admin/instalasi/InstalasiStatus.vue',
  ]

  for (const berkas of BERKAS) {
    it(`${berkas} tidak memakai emoji search`, () => {
      const isi = readFileSync(join(AKAR, berkas), 'utf8')
      const kode = tanpaKomentar(isi)

      assert.ok(
        !/\u{1F50D}/u.test(kode),
        `${berkas} masih memakai emoji 🔍 -- pakai <font-awesome-icon icon="search" />`,
      )
    })
  }

  it('DataTable memakai font-awesome-icon untuk ikon search', () => {
    const isi = readFileSync(join(AKAR, 'presentations/components/ui/DataTable.vue'), 'utf8')
    assert.match(
      isi,
      /<font-awesome-icon\s+icon="search"/,
      'DataTable harus memakai <font-awesome-icon icon="search" />',
    )
  })

  it('font-awesome-icon terdaftar global dan faSearch tersedia', () => {
    // Kalau registrasi globalnya hilang, `<font-awesome-icon>` tidak
    // dirender sama sekali -- ikon jadi kosong tanpa error.
    const main = readFileSync(join(AKAR, 'main.js'), 'utf8')

    assert.match(main, /font-awesome-icon/, 'font-awesome-icon tidak terdaftar di main.js')
    assert.match(main, /faSearch\b/, 'faSearch tidak ada di daftar ikon yang ditambahkan')
  })
})
