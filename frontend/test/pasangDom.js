/**
 * DOM dipasang di modul TERPISAH, yang diimpor lebih dulu.
 *
 * Akar masalahnya terbukti di `cek-dasar.mjs`:
 *
 *   tanpa import tambahan  -> mount OK
 *   dengan `vite`          -> mount OK
 *   dengan `@vitejs/plugin-vue` -> doc: null
 *   dengan `@vue/test-utils`    -> doc: null
 *
 * `@vitejs/plugin-vue` dan `@vue/test-utils` sama-sama mengimpor `vue`
 * sebagai IMPORT STATIS. Di ESM, import statis dievaluasi sebelum body
 * modul -- jadi `@vue/runtime-dom` ikut dievaluasi lebih dulu, dan baris
 *
 *     const doc = typeof document !== "undefined" ? document : null
 *
 * terikat ke `null`. Nilai itu mengikat SELURUH proses.
 *
 * Menaruh pemasangan DOM di body `vueHarness.mjs` tidak menolong, karena
 * body itu sendiri berjalan SETELAH semua import statisnya -- termasuk
 * `import vue from '@vitejs/plugin-vue'`.
 *
 * Yang benar: DOM dipasang di modul tanpa import apa pun yang menarik
 * Vue, dan modul itu diimpor paling awal. Urutan impor ESM dievaluasi
 * sesuai urutan deklarasi, jadi `import './pasangDom.js'` di baris
 * PERTAMA `vueHarness.mjs` dijamin selesai sebelum import Vue.
 */

import { JSDOM } from 'jsdom'

const dom = new JSDOM('<!doctype html><html><body></body></html>', {
  url: 'http://localhost/',
  pretendToBeVisual: true,
})

const { window } = dom

globalThis.window = window
globalThis.document = window.document

/*
 * `navigator` harus lewat `defineProperty`. Di Node 22 `globalThis.navigator`
 * sudah ada dan hanya bisa dibaca, jadi assignment biasa diam-diam gagal
 * dalam mode strict -- dan `navigator.userAgent` yang dibaca PrimeVue akan
 * tetap milik Node, bukan JSDOM.
 */
Object.defineProperty(globalThis, 'navigator', {
  value: window.navigator,
  configurable: true,
  writable: true,
})

globalThis.requestAnimationFrame = (cb) => setTimeout(() => cb(Date.now()), 0)
globalThis.cancelAnimationFrame = (id) => clearTimeout(id)

/*
 * `history`.
 *
 * `vue-router` v5 membaca `history.state` sebagai identifier global --
 * bukan `window.history.state`. Di JSDOM `history` hanya ada di dalam
 * `window`, dan `pasangDom.js` di sini tidak pernah memintanya ke global.
 *
 * Gejalanya muncul saat router dinavigasi, jauh dari penyebabnya:
 *
 *     ReferenceError: history is not defined
 *       at finalizeNavigation (vue-router/dist/vue-router.js:1386:35)
 *
 * Router memindahkan halaman ke `currentRoute` dengan variabel yang nilainya
 * tidak pernah ada -- `const state = !isBrowser ? {} : history.state`.
 * `isBrowser`-nya benar (JSDOM menyamar jadi browser), lalu baris yang sama
 * memanggil `history.state` dan meledak. Halaman yang diuji tidak pernah
 * sampai dirender, jadi test gagal dengan pesan yang sama sekali tidak
 * menyebut router.
 *
 * Yang dipasang adalah objek ASLI milik JSDOM, bukan tiruan. `history`
 * bawaan browser menyimpan state navigasi; kalau diganti, `replaceState`
 * milik vue-router akan menulis ke tempat yang salah dan test berikutnya
 * bisa membaca state milik test sebelumnya.
 */
if (globalThis.history === undefined) {
  Object.defineProperty(globalThis, 'history', {
    value: window.history,
    configurable: true,
    writable: true,
  })
}

globalThis.HTMLElement = window.HTMLElement
globalThis.Element = window.Element
globalThis.Node = window.Node
globalThis.Event = window.Event
globalThis.CustomEvent = window.CustomEvent

/*
 * `location`.
 *
 * `vue-router` v5 memakai `createWebHistory()` yang membaca `location`
 * sebagai identifier GLOBAL -- bukan `window.location`. Tanpa ini, memuat
 * `src/router/index.js` gagal dengan:
 *
 *     ReferenceError: location is not defined
 *       at createWebHistory (vue-router/dist/vue-router.js:...)
 *
 * Yang membuat ini penting: router itu yang Holds definisi SEMUA route,
 * termasuk redirect path lama `/app/arsip/tunggakan`. Tanpa `location`,
 * tabel route tidak bisa diuji sama sekali di Node.
 *
 * `history` (yang sudah dipasang di atas) menunjuk ke `window.history`
 * yang sama, jadi keduanya tetap konsisten -- `history.state` dan
 * `location.pathname` menggambarkan halaman yang sama.
 */
if (globalThis.location === undefined) {
  Object.defineProperty(globalThis, 'location', {
    value: window.location,
    configurable: true,
    writable: true,
  })
}

/*
 * Vue memanggil `app.mount()` yang mengecek `instanceof SVGElement` untuk
 * menentukan namespace sebuah elemen. Tanpa ini, mount komponen yang elemen
 * utamanya adalah SVG (ikon di sini) melempar ReferenceError sebelum badan
 * test berjalan -- jadi kegagalannya terlihat tidak berhubungan dengan yang
 * sedang diuji.
 *
 * Disalin lewat loop, bukan disebut satu per satu: setiap interface DOM yang
 * mungkin dipakai Vue di kemudian hari sudah tercakup, dan yang kurang hanya
 * akan muncul sebagai `ReferenceError` di dalam kode vendor.
 */
for (const nama of [
  'SVGElement',
  'MathMLElement',
  'HTMLInputElement',
  'HTMLTextAreaElement',
  'HTMLSelectElement',
  'HTMLButtonElement',
  'HTMLAnchorElement',
  'HTMLFormElement',
  'DocumentFragment',
  'Text',
  'Comment',
  'MouseEvent',
  'KeyboardEvent',
  'FocusEvent',
  'InputEvent',
  'DOMParser',
  'HTMLTemplateElement',
  'HTMLSlotElement',
  /*
   * `ShadowRoot` masuk daftar ini karena `@primeuix/utils` memakainya saat
   * memeriksa apakah sebuah node berada di dalam shadow tree:
   *
   *     function y(t) {
   *       if (t instanceof ShadowRoot && t.host) t = t.host
   *       return t
   *     }
   *
   * `primevue/editor` memanggil utilitas itu saat memuat Quill, jadi tanpa
   * stub ini test apa pun yang menyentuh Editor gagal dengan
   * `ReferenceError: ShadowRoot is not defined` -- error yang menyebut
   * kelas browser, bukan komponen yang sedang diuji.
   */
  'ShadowRoot',
  /*
   * `XMLSerializer` dan `Range` ikut karena PrimeVue memakainya.
   *
   * `primevue/select` yang dipakai `BaseSelect` memanggil
   * `new XMLSerializer()` saat memuat gaya komponennya. JSDOM menyediakannya
   * di `window`, tapi `pasangDom.js` tidak pernah memintanya ke global --
   * dan PrimeVue memanggilnya sebagai identifier global, bukan `window.X`.
   *
   * Gejalanya:
   *
   *     ReferenceError: XMLSerializer is not defined
   *
   * yang menyebut kelas browser, bukan filter PrimeVue mana pun di repo
   * ini -- padahal tidak ada yang salah pada filternya.
   *
   * `Range` masuk daftar yang sama karena alasan yang sama: JSDOM
   * menyediakannya, dan ia belum pernah diminta ke global.
   */
  'XMLSerializer',
  'Range',
  'NodeFilter',
  'MutationObserver',
]) {
  if (globalThis[nama] === undefined && window[nama] !== undefined) {
    globalThis[nama] = window[nama]
  }
}

globalThis.getComputedStyle = window.getComputedStyle.bind(window)

/*
 * `matchMedia`.
 *
 * JSDOM tidak menyediakan `window.matchMedia` sama sekali, dan
 * PrimeVue memanggilnya di `mounted()` komponen `Select`:
 *
 *     ReferenceError: matchMedia is not defined
 *       at bindMatchMediaOrientationListener (primevue/select/index.mjs:773:21)
 *
 * Yang dipanggilnya cuma untuk memastikan dropdown yang terbuka mengikuti
 * orientasi layar -- sesuatu yang tidak ada artinya di JSDOM.
 *
 * Di-stub di sini, bukan di file test, karena ini kekurangan JSDOM yang
 * berlaku untuk SEMUA test yang menyentuh PrimeVue -- dan `pasangDom.js`
 * memang tempat kekurangan DOM dikumpulkan.
 */
if (typeof globalThis.matchMedia !== 'function') {
  const matchMediaPalsu = (query) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener() {},
    removeListener() {},
    addEventListener() {},
    removeEventListener() {},
    dispatchEvent() {
      return false
    },
  })

  globalThis.matchMedia = matchMediaPalsu
  window.matchMedia = matchMediaPalsu
}

/*
 * `localStorage` dan `sessionStorage`.
 *
 * Disalin dari window JSDOM yang sama, bukan dibuat sendiri. Store di
 * aplikasi ini membacanya di dalam `setup()` -- useUiStore salah satunya --
 * jadi begitu komponen di-mount, bagian ini sudah terpanggil. JSDOM sudah
 * menyediakan keduanya; yang belum adalah pentasnya ke globalThis.
 */
globalThis.localStorage = window.localStorage
globalThis.sessionStorage = window.sessionStorage

/*
 * `WebSocket` dibiarkan mati.
 *
 * Node 22 sudah menyediakan `WebSocket` global (lewat undici), dan itulah
 * yang dipakai runtime HMR Vite. Runtime itu langsung membuatnya dengan
 * `undefined` karena di middleware mode tidak ada host HMR, lalu meledak
 * dengan:
 *
 *     DOMException [SyntaxError]: TypeError: Invalid URL
 *       at new WebSocket (node:internal/deps/undici/undici:12800:17)
 *
 * Yang membuatnya sulit dibaca: seluruh frame di stack milik Node dan
 * undici. Tidak ada satu pun yang menyebut Vite, HMR, atau file mana pun di
 * repo ini -- kelihatan seperti bug di dalam Node.
 *
 * Test harness memang tidak punya server HMR, jadi tidak ada yang perlu
 * disambungkan. Koneksi yang tetap terjadi dijawab sebagai berhasil supaya
 * kode yang memanggilnya tidak melihat error.
 */
class WebSocketDiam {
  static CONNECTING = 0
  static OPEN = 1
  static CLOSING = 2
  static CLOSED = 3

  CONNECTING = 0
  OPEN = 1
  CLOSING = 2
  CLOSED = 3

  onopen = null
  onclose = null
  onerror = null
  onmessage = null

  /**
   * Pendengar event, supaya `addEventListener` yang dipakai runtime HMR
   * tidak melempar "socket.addEventListener is not a function".
   *
   * Kode Vite memakai keduanya: `socket.onmessage = ...` di satu tempat dan
   * `socket.addEventListener("message", ...)` di tempat lain. Karena itu
   * kedua bentuk harus hidup berdampingan.
   */
  #pendengar = new Map()

  addEventListener(jenis, fn) {
    if (!this.#pendengar.has(jenis)) this.#pendengar.set(jenis, new Set())

    this.#pendengar.get(jenis).add(fn)
  }

  removeEventListener(jenis, fn) {
    this.#pendengar.get(jenis)?.delete(fn)
  }

  dispatchEvent(acara) {
    for (const fn of this.#pendengar.get(acara.type) ?? []) fn(acara)

    return true
  }

  constructor() {
    queueMicrotask(() => {
      this.onopen?.({ type: 'open' })
      this.dispatchEvent({ type: 'open' })
    })
  }

  send() {}
  close() {
    queueMicrotask(() => {
      this.onclose?.({ type: 'close', code: 1000 })
      this.dispatchEvent({ type: 'close', code: 1000 })
    })
  }
}

globalThis.WebSocket = WebSocketDiam
window.WebSocket = WebSocketDiam

export default window
