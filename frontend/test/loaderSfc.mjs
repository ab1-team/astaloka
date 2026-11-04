/**
 * Loader Node untuk SFC Vue, dipasang lewat `module.register()`.
 *
 * Loader ini MEWAKILI pemuatan modul di dalam proses test. Berkas `.vue`
 * dikompilasi di sini, lalu hasilnya dievaluasi oleh Node sendiri -- bukan
 * oleh `AsyncFunction` module runner milik Vite.
 *
 * Kenapa tidak lewat module runner Vite
 * ------------------------------------
 *
 * Module runner Vite mengevaluasi modul dengan `new AsyncFunction(code)`.
 * Di sana `import` di level atas tidak diizinkan dan `require()` tidak ada,
 * dan itu memaksa dua konsekuensi yang tidak bisa dihindari:
 *
 *  1. `vue` harus diproses Vite, karena `external` hanya dibaca kalau
 *     `options.externalize && options.isBuild && consumer === 'server'` --
 *     tiga syarat yang semuanya salah di mode dev. Vite lalu memilih
 *     `dist/vue.runtime.esm-bundler.js`.
 *
 *  2. `@vue/test-utils` memuat `dist/vue.cjs.js`. Dua build berbeda, dua
 *     salinan reactivity, dan `renderSlot()` milik komponen membaca
 *     `currentRenderingInstance` yang dipegang salinan lain:
 *
 *         TypeError: Cannot read properties of null (reading 'ce')
 *           at renderSlot (@vue/runtime-core/...esm-bundler.js:3233)
 *
 * Loader Node tidak punya batasan itu. `import 'vue'` diteruskan apa
 * adanya ke resolver Node, jadi komponen dan `@vue/test-utils` dijamin
 * memakai berkas yang sama persis.
 */

import { existsSync, readFileSync } from 'node:fs'
import { fileURLToPath, pathToFileURL } from 'node:url'

import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc'

import env from './envTest.mjs'

/** Alias yang sama dengan `vite.config.js`. */
const AKAR = new URL('../src/', import.meta.url)

/**
 * Folder modul tiruan.
 *
 * `daftarkanPagarVirtual()` di `vueHarness.mjs` menulis berkas `.js` ke sini,
 * dan hook di bawah mengarahkan specifier yang cocok ke sana.
 *
 * Cara ini dipakai, bukan state yang dibagi lewat `register({ data })`, karena
 * `data` di-SNAPSHOT ketika `register()` dipanggil -- sedangkan pagarnya baru
 * diisi di dalam `before()` test. Snapshot-nya selalu kosong, dan gejalanya
 * adalah "mock-nya tidak terpakai" tanpa petunjuk apa pun penyebabnya.
 *
 * Berkas juga menghindari masalah thread: hook ini berjalan di thread
 * terpisah, jadi `Map` di modul harness tidak akan pernah terlihat di sini.
 */
const MOCK = new URL('./__mock__/', import.meta.url)

/**
 * Nama berkas test yang sedang berjalan.
 *
 * Diisi oleh `initialize()` dari `register(..., { data })`. Thread loader
 * TIDAK mewarisi `process.argv`, jadi `argv[1]` di sini selalu
 * `undefined` -- nama test harus dikirim lewat `data`.
 *
 * Nilai mula-mulanya `'umum'`, sama dengan fallback di `initialize()`:
 * tanpa nama test, yang dicari hanyalah `umum_<nama-specifier>.js`.
 */
let namaTestAktif = 'umum'

/**
 * Berkas tiruan untuk sebuah specifier alias, atau `null`.
 *
 * Pencocokan dilakukan PERSIS: nama berkasnya harus sama dengan
 * `<nama-test>_<nama-specifier>.js`.
 *
 * Versi lama mencari dari daftar berkas dan memakai yang namanya paling
 * panjang. Itu salah: dua berkas test yang meniru modul yang sama
 * menghasilkan akhiran yang sama, jadi tiruan milik test lain ikut cocok --
 * dan yang terpilih adalah milik file yang namanya lebih panjang, bukan
 * milik test yang sedang berjalan.
 *
 * Gejalanya: `does not provide an export named 'invoiceService'`, yang
 * menyebut modul tiruan, bukan dua test yang berebut satu berkas.
 *
 * Nama test sampai ke sini lewat `register(..., { data })`, BUKAN lewat
 * `process.argv`. Thread loader tidak mewarisi `process.argv`, jadi
 * `argv[1]` di sana selalu `undefined` -- dan tanpa nama test, satu-satunya
 * cara menebak adalah membaca daftar berkas, yang seperti di atas salah.
 */
function cariMock(specifier) {
  const nama = `${namaTestAktif}_${specifier.replace(/[^\w.-]/g, '_')}.js`
  const kandidat = new URL(nama, MOCK)

  return existsSync(fileURLToPath(kandidat)) ? kandidat.href : null
}

export async function initialize(data) {
  namaTestAktif = data?.namaTest || 'umum'
}

/**
 * Kompilasi satu SFC menjadi modul ESM.
 *
 * `inlineTemplate: true` dipakai supaya template ikut masuk ke modul yang
 * sama. Tanpa itu SFC menghasilkan modul terpisah `?vue&type=template`, dan
 * loader ini tidak punya cara mengaitkannya -- hasilnya komponen tanpa
 * `render`, persis gejala "Component is missing template or render function".
 *
 * `templateOptions` wajib diisi. Tanpanya `compileScript` memangkas hasil
 * kompilasi dan modul yang dihasilkan tidak bisa dipakai.
 *
 * `<style>` tidak ikut di sini: JSDOM tidak menerapkan CSS, dan menariknya
 * berarti menarik `@vite/client` yang tidak ada di loader ini.
 */
function kompilasiSfc(kode, namaFile) {
  const { descriptor, errors } = parse(kode, { filename: namaFile })

  if (errors.length) {
    throw new Error(`SFC ${namaFile} gagal di-parse: ${errors[0].message}`)
  }

  /*
   * `id` harus berupa heksadesimal pendek. `compileScript` memakainya untuk
   * menulis makro ke template; tanpa itu `defineProps` tidak pernah sampai
   * ke render, dan hampir semua komponen di repo ini memakainya.
   */
  const id = Buffer.from(namaFile).toString('hex').slice(0, 8)

  /*
   * SFC dengan `<script setup>` KOSONG.
   *
   * `compileScript` menolak berkasnya dengan:
   *
   *     [@vue/compiler-sfc] SFC contains no <script> tags.
   *
   * padahal berkasnya ADA `<script setup></script>` -- cuma isinya nol
   * byte, jadi parser menganggapnya tidak ada.
   *
   * Contoh di repo ini: `FooterView.vue` -- komponen paling sepele yang
   * ada, cuma `<footer>` dan teks. Karena itu komponen ini TIDAK PERNAH
   * bisa diimpor di Node, dan semua yang mengimpornya ikut gagal --
   * termasuk `MainView.vue` (layout admin) dan karena itu `router/index.js`
   * juga gagal, karena router mengimpor layout itu.
   *
   * Efeknya ke test: tabel route sama sekali tidak bisa diperiksa, dan
   * route yang salah -- seperti redirect path lama yang hilang -- lolos
   * tanpa ada yang menangkapnya.
   *
   * Di sini template-nya dikompilasi sendiri dan digabung sebagai modul
   * biasa, tanpa blok script sama sekali. Hasilnya tetap komponen yang
   * bisa dirender, persis seperti SFC dengan script kosong.
   */
  if (!descriptor.script && !descriptor.scriptSetup) {
    const template = compileTemplate({
      source: descriptor.template?.content ?? '',
      filename: namaFile,
      id,
    })

    if (template.errors.length) {
      throw new Error(
        `SFC ${namaFile} gagal dikompilasi: ${template.errors[0].message ?? template.errors[0]}`,
      )
    }

    /*
     * `compileTemplate` mengembalikan KODE template, bukan modul -- belum
     * ada `export default`. Tanpa itu, whoever mengimpor SFC ini gagal
     * dengan:
     *
     *     The requested module './FooterView.vue' does not provide an
     *     export named 'default'
     *
     * yang menyebut file turuan, bukan masalah aslinya.
     *
     * `compileTemplate` mengexpor fungsi render-nya sebagai `render`
     * bernama (bukan `_sfc_main` seperti yang dilakukan `compileScript`),
     * jadi modul turuannya yang/Reference `render` itu.
     */
    return `${template.code}\nexport default { render }\n`
  }

  return compileScript(descriptor, {
    id,
    inlineTemplate: true,
    templateOptions: {
      filename: namaFile,
      id,
      compilerOptions: {},
    },
  }).content
}

/**
 * Hook `load` dari Node.
 *
 * `format: 'module'` memberi tahu Node untuk mengurai hasilnya sebagai ESM --
 * yang justru membuat `import` di level atas boleh dipakai, sesuatu yang
 * mustahil di dalam `AsyncFunction` module runner Vite.
 */
export async function load(specifier, context, nextLoad) {
  const url = specifier.startsWith('file:') ? specifier : pathToFileURL(specifier).href

  if (!url.startsWith(AKAR.href)) {
    return nextLoad(specifier, context)
  }

  const namaFile = fileURLToPath(url)

  if (url.endsWith('.vue')) {
    return {
      format: 'module',
      source: sisipEnv(kompilasiSfc(readFileSync(namaFile, 'utf-8'), namaFile)),
      shortCircuit: true,
    }
  }

  /*
   * CSS jadi modul kosong, bukan diteruskan ke `nextLoad`.
   *
   * Beberapa SFC mengimpor CSS di level teratas (`central-form.css` di
   * `CentralTagihanIndex.vue`, misalnya). Node tidak punya `require.extensions`
   * untuk CSS, jadi meneruskannya berakhir dengan:
   *
   *     TypeError: Unknown file extension ".css" for ...central-form.css
   *
   * Node menolak di HOOK `load`, jadi modul itu tidak pernah sampai ke
   * komponen -- seluruh file test gagal memuat komponennya, dan gejalanya
   * menunjuk ke loader, bukan ke SFC mana pun.
   *
   * Isinya dikembalikan kosong karena JSDOM tidak menerapkan CSS sama sekali:
   * mengosongkannya tidak mengubah apa pun yang bisa diamati test.
   */
  if (url.endsWith('.css')) {
    return {
      format: 'module',
      source: sisipEnv('export default {}'),
      shortCircuit: true,
    }
  }

  if (!url.endsWith('.js')) {
    return nextLoad(specifier, context)
  }

  /*
   * Berkas `.js` milik `src/` juga perlu `import.meta.env`.
   *
   * `centralAxios.js` membacanya di level teratas, jadi modul ini GAGAL DIMUAT
   * -- dan karena itu modul lain yang mengimpornya ikut gagal, termasuk
   * komponen yang tidak pernah melakukan HTTP. Gejalanya muncul di tempat yang
   * tidak menyangka ada masalah: `TypeError: Cannot read properties of
   * undefined (reading 'VITE_API_BASE_URL')` yang tidak menyebut axios,
   * tidak menyebut env, dan tidak menyebut berkas mana pun yang membaca.
   */
  const asal = await nextLoad(specifier, context)

  if (asal.format !== 'module' || asal.source === undefined) {
    return asal
  }

  return { ...asal, source: sisipEnv(Buffer.from(asal.source).toString('utf-8')) }
}

/**
 * Tambahkan `import.meta.env` ke kode modul.
 *
 * `import.meta.env` tidak bisa dipasang lewat global: `import.meta` milik
 * setiap modul ESM, dan `env` hanya ada kalau Vite yang menyisipkannya. Yang
 * bisa dilakukan adalah menyisipkan deklarasi di awal modul, yang mengisi
 * `env` dengan objek yang benar.
 *
 * Hanya berlaku untuk modul milik `src/`. Paket di `node_modules` dimuat apa
 * adanya oleh Node dan tidak boleh disentuh.
 */
function sisipEnv(kode) {
  return `import.meta.env = ${JSON.stringify(env)};\n${kode}`
}

/**
 * Ekstensi yang dicoba untuk import tanpa ekstensi.
 *
 * Urutannya penting dan sama dengan urutan yang dipakai bundler: `.js` lebih
 * dulu karena itulah bentuk berkas di repo ini.
 */
const EKSTENSI = ['.js', '.mjs', '/index.js', '/index.mjs']

/**
 * Temukan berkas sebenarnya untuk sebuah URL, dengan dan tanpa ekstensi.
 *
 * Node Unlike Vite, TIDAK menebak ekstensi. `import '@/stores/uiStore'` di
 * `vite.config.js` berhasil karena bundler mencarinya di disk; lewat loader Node
 * hasilnya langsung:
 *
 *     ENOENT: no such file or directory, open
 *       'C:\laragon\www\astaloka\frontend\src\stores\uiStore'
 *
 * yang hilang hanya `.js`. Berkasnya ada, dan isinya benar -- tapi Node tidak
 * pernah diberi kesempatan untuk mencarinya.
 *
 * `DataTable.vue` menyebut ini sebagai "component is missing template", karena
 * Kegagalan datang dari modul store yang diimpor komponen, bukan dari
 * komponennya. Akar masalahnya ada di baris import.
 */
function denganEkstensi(url) {
  if (existsSync(fileURLToPath(url))) {
    return url.href
  }

  for (const ekstensi of EKSTENSI) {
    const kandidat = new URL(url.href + ekstensi)

    if (existsSync(fileURLToPath(kandidat))) {
      return kandidat.href
    }
  }

  return null
}

/**
/**
 * Hook `resolve` dari Node.
 *
 * Tiga hal yang ditulis ulang, berurutan dari yang paling spesifik:
 *
 *  1. Specifier alias yang punya tiruan -> berkas di `test/__mock__/`
 *  2. Alias `@/...`                    -> `src/...`, lalu ekstensi dicoba
 *  3. Berkas `src/` yang punya tiruan   -> berkas di `test/__mock__/`
 *  4. Selain itu                       -> resolver Node
 *
 * Poin 3 inilah yang dulu hilang, dan gejalanya sangat menyesatkan.
 *
 * `CentralHakAksesIndex.vue` mengimpor `@/services/central.service`. Alias itu
 * ditangani poin 2, jadi tiruan HARUS kepakai. Tapi Node mengunci URL yang
 * sudah ter-resolve di cache modulnya, dan impor kedua yang diminta SFC
 *ositories sudah sampai sebagai path absolut di `src/`, bukan lagi sebagai
 * `@/...`. Poin 2 tidak menyentuhnya, dan modul aslinya yang termuat.
 *
 * Buktinya ada di `cek-siapa-mock.mjs`: penghitung di dalam tiruan naik satu
 * kali saat diimpor langsung dari file test, dan TIDAK naik lagi saat
 * komponen SFC diimpor -- padahal keduanya meminta modul yang sama.
 *
 * Yang paling menyebalkan: tidak ada error. Komponen tetap dirender, datanya
 * tetap muncul, tapi berasal dari HTTP sungguhan. Test yang mengharapkan
 * "kredensial" dari backend gagal dengan pesan yang sama persis dengan yang
 * akan muncul di produksi -- jadi penyebabnya tersembunyi di balik gejala yang
 * terlihat benar.
 */
export async function resolve(specifier, context, nextResolve) {
  if (specifier.startsWith('@/')) {
    const tiruan = cariMock(specifier)

    if (tiruan) {
      return { url: tiruan, shortCircuit: true }
    }

    const asli = new URL(specifier.slice(2), AKAR)
    const ketemu = denganEkstensi(asli)

    return { url: ketemu ?? asli.href, shortCircuit: true }
  }

  /*
   * Impor RELATIF tanpa ekstensi, dari dalam `src/`.
   *
   * Contoh nyata: `src/router/index.js` mengimpor `./dashboardRoutes`,
   * sementara berkasnya `dashboardRoutes.js`. Vite menyelesaikannya
   * otomatis; Node ESM tidak -- dan hasilnya:
   *
   *     Error [ERR_MODULE_NOT_FOUND]: Cannot find module
   *     '...\src\router\dashboardRoutes'
   *
   * Yang membuat ini penting: file router itu yang Holds definisi
   * SEMUA route -- termasuk redirect path lama arsip. Tanpa ia, tabel
   * route tidak bisa diuji sama sekali di Node, dan yang bisa diuji
   * hanyalah "// belum ada test untuk ini" yang/toh tidak memblokir apa
   * pun.
   *
   * Ekstensi dicoba di sini dengan cara yang sama seperti alias `@/...`
   * di atas, jadi perilakunya konsisten: berkas yang ADA dipakai, dan
   * kalau tidak ada, biarlah Node yang melaporkannya -- jangan diam-diam
   * menunjuk ke URL yang salah.
   *
   * `nextResolve` melempar (bukan mengembalikan) kalau berkasnya tidak
   * ada, jadi check-nya dilakukan lebih dulu: kalau hasil tebakan
   * eksplisit ada, itu yang dipakai; kalau tidak, error asli Node
   * dibiarkan apa adanya supaya pesannya tetap menyebut file yang
   * sebenarnya bermasalah.
   */
  if (
    (specifier.startsWith('./') || specifier.startsWith('../')) &&
    context.parentURL?.startsWith(AKAR.href)
  ) {
    const asal = new URL(specifier, context.parentURL)
    const ketemu = denganEkstensi(asal)

    if (ketemu) {
      const tiruan = mockUntuk(ketemu)

      return { url: tiruan ?? ketemu, shortCircuit: true }
    }
  }

  const hasil = await nextResolve(specifier, context)

  if (hasil.url?.startsWith(AKAR.href) && hasil.url.endsWith('.js')) {
    const tiruan = mockUntuk(hasil.url)

    if (tiruan) {
      return { ...hasil, url: tiruan, shortCircuit: true }
    }
  }

  return hasil
}

/**
 * Berkas tiruan untuk sebuah URL di dalam `src/`, atau `null`.
 *
 * Dicocokkan dari nama berkasnya, bukan dari specifier aslinya -- karena
 * `@/services/central.service` dan `.../src/services/central.service.js` harus
 * menemukan berkas yang sama. Yang membedakan keduanya hanya nama path-nya
 * relatif terhadap `src/`.
 */
function mockUntuk(url) {
  const relatif = url.slice(AKAR.href.length).replace(/\.js$/, '')

  return cariMock('@/' + relatif)
}
