import { strict as assert } from 'node:assert'
import { after, before, beforeEach, describe, it } from 'node:test'

import { daftarkanPagarVirtual, komponen, pasangDom, tutupDom } from './vueHarness.mjs'

/*
 * ACCORDION HAK AKSES: dari klik baris lokasi sampai daftar jabatan muncul.
 *
 * Bug yang dikunci test ini benar-benar terjadi. `DataTable` tidak pernah
 * memberi tahu pemanggilnya saat sebuah baris di-expand. Halaman Hak Akses
 * memanggil pemuatannya dari dalam daftar jabatan yang belum terisi, jadi
 * pemicunya tidak pernah keluar. Hasilnya di layar: "Lokasi ini belum punya
 * jabatan" untuk lokasi yang datanya justru lengkap di server.
 *
 * Yang membuat bug itu lolos dari build dan lint adalah sifatnya: tidak ada
 * satu pun alat yang gagal. Kompilasi berhasil, bentuk respons tidak
 * berubah, backend mengembalikan jabatan dengan benar. Yang hilang hanya
 * pemicunya.
 *
 * Karena itu tiap test di sini memakai kontrol: keadaan sebelum klik dan
 * sesudah klik diuji berpasangan, supaya test gagal kalau tiruannya yang
 * salah -- bukan lolos karena memang tidak ada apa pun untuk ditampilkan.
 */

/** Jejak panggilan yang diisi oleh tiruan service. */
let jejak

/** Pesan toast yang muncul selama test. */
let toast

/** Lokasi seperti `locationService.getAll()` membalas di panel pusat. */
const DAFTAR_LOKASI = [
  {
    id: 1,
    slug: 'lokasi',
    nama: 'Lokasi',
    label: 'Lokasi',
    domain: 'demo.astaloka.test',
    is_active: true,
  },
  {
    id: 2,
    slug: 'lokasi-dua',
    nama: 'Lokasi Dua',
    label: 'Lokasi Dua',
    domain: 'dua.astaloka.test',
    is_active: true,
  },
]

/**
 * Bentuk jabatan persis seperti hasil `bacaJabatan()` di backend.
 *
 * `configured` sengaja dibedakan antara `true` dan `false`, dan `menu` selalu
 * punya isi: halaman membaca keduanya saat render, jadi tiruan kosong akan
 * menguji bentuk yang salah.
 *
 * `role` ikut ada karena backend mengirimnya, dan halaman memakainya untuk
 * dua hal: lencana peran di baris jabatan, dan catatan "daftar mana yang
 * dipakai jabatan ini". Tanpa `role`, lencana itu akan menulis "Tidak
 * diketahui" untuk jabatan yang sebenarnya jelas perannya.
 */
function jabatanFixtures() {
  return [
    {
      id: 1,
      nama: 'Direktur',
      configured: true,
      total_user: 2,
      role: 'admin',
      menu: [
        { key: 'dashboard', label: 'Dashboard', group: 'Utama', selected: true, locked: false },
        { key: 'laporan', label: 'Laporan', group: 'Pelaporan', selected: false, locked: false },
      ],
    },
    {
      id: 2,
      nama: 'Sekretaris',
      configured: false,
      total_user: 1,
      role: 'admin',
      menu: [
        { key: 'dashboard', label: 'Dashboard', group: 'Utama', selected: true, locked: false },
        { key: 'laporan', label: 'Laporan', group: 'Pelaporan', selected: true, locked: false },
      ],
    },
  ]
}

/** Respons backend yang normal: 200 dengan daftar jabatan. */
function balasJabatan() {
  return { data: { success: true, data: jabatanFixtures() } }
}

/** Respons backend saat database lokasi tidak bisa dibaca. */
function balas503() {
  const galat = new Error('Request failed with status code 503')

  galat.response = {
    status: 503,
    data: {
      success: false,
      message: 'Database lokasi `lokasi` tidak bisa dibaca. Periksa kredensialnya di panel pusat.',
    },
  }

  return Promise.reject(galat)
}

/** Reset jejak dan pasang penanganan bawaan sebelum tiap test. */
function pasangPagar() {
  jejak = { lokasi: [], menuAccess: [], update: [], reset: [] }
  toast = []

  globalThis.__jejak = jejak
  globalThis.__toast = toast
  globalThis.__balasJabatan = balasJabatan
}

let mount
let flushPromises
let createPinia
let setActivePinia
let DataTable
let CentralHakAksesIndex

before(async () => {
  pasangPagar()
  // pasangDom() memasang DOM ke globalThis sebagai efek sampingnya.
  // Nilai balikannya tidak perlu disimpan: yang dipakai component dan
  // @vue/test-utils adalah globalnya, bukan variabel lokal ini.
  await pasangDom()

  const testUtils = await import('@vue/test-utils')
  mount = testUtils.mount
  flushPromises = testUtils.flushPromises

  /*
   * Pagar harus dipasang sebelum Vite server hidup: plugin-nya membaca daftar
   * modul saat server dibuat, dan modul yang sudah ter-resolve tidak pernah lagi
   * membaca pagar-nya.
   */
  /*
   * Pagar dipasang SEBELUM komponen diimpor. Komponen yang sudah ter-resolve
   * tidak pernah membaca pagarnya lagi.
   */
  daftarkanPagarVirtual(
    '@/services/central.service',
    `
    export const locationService = {
      async getAll() {
        globalThis.__jejak.lokasi.push(1)
        return { data: { success: true, data: ${JSON.stringify(DAFTAR_LOKASI)} } }
      },
    }

    export const centralMenuAccessService = {
      async getAll(id) {
        globalThis.__jejak.menuAccess.push(id)
        return globalThis.__balasJabatan()
      },
      async update(id, jabatan, keys) {
        globalThis.__jejak.update.push({ id, jabatan, keys })
        return { data: { success: true, message: 'ok' } }
      },
      async reset(id, jabatan) {
        globalThis.__jejak.reset.push({ id, jabatan })
        return { data: { success: true, message: 'ok' } }
      },
    }
  `,
  )

  daftarkanPagarVirtual(
    '@/utils/swal',
    `
    export const MySwal = { fire: async () => ({ isConfirmed: true }) }
    export const showErrorToast = (e) => globalThis.__toast.push(['error', e])
    export const showSuccessToast = (m) => globalThis.__toast.push(['success', m])
    export const showWarningToast = (m) => globalThis.__toast.push(['warning', m])
  `,
  )

  /*
   * Pinia diambil lewat `import()` biasa.
   *
   * Harness memuat komponen lewat loader Node, yang meneruskan `import 'pinia'`
   * apa adanya ke resolver Node. `@vue/test-utils` juga memuat pinia dari
   * `node_modules` yang sama. Keduanya karena itu memakai satu salinan, dan
   * `setActivePinia` di sini terlihat oleh `useStore()` di dalam komponen.
   *
   * Dulu harness memakai module runner Vite, dan runner memuat build pinia
   * sendiri -- dua salinan, dua "pinia aktif" terpisah, dan pinia melempar
   * "there was no active Pinia" tepat di dalam setup().
   */
  const pinia = await import('pinia')
  createPinia = pinia.createPinia
  setActivePinia = pinia.setActivePinia

  DataTable = await komponen('presentations/components/ui/DataTable.vue')
  CentralHakAksesIndex = await komponen('presentations/views/central/CentralHakAksesIndex.vue')
})

after(async () => {
  await tutupDom()
})

/** Opsi global yang sama untuk kedua jenis komponen. */
function opsiGlobal() {
  return {
    mocks: {
      $route: { path: '/central/hak-akses', params: {}, query: {} },
      $router: { push: () => {}, replace: () => {} },
    },
    stubs: {
      'font-awesome-icon': { template: '<i />' },
    },
  }
}

/**
 * Mount halaman Hak Akses dengan pinia aktif.
 *
 * Pinia dipasang DUA kali, dan keduanya dibutuhkan. `setActivePinia()`
 * memberi tahu kode di luar komponen (mis. saat `mount()` membaca setup)
 * pinia mana yang aktif, tapi tidak cukup untuk `useStore()` di dalam
 * `setup()` -- untuk itu instance-nya harus diteruskan lewat plugin
 * `global.plugins`. Tanpa yang kedua, komponen melempar "there was no active
 * Pinia" tepat di `setup()`, jadi test gagal sebelum satu pun baris yang
 * diuji pernah dirender.
 */
function mountHalaman() {
  const pinia = createPinia()

  setActivePinia(pinia)

  return mount(CentralHakAksesIndex, {
    global: { ...opsiGlobal(), plugins: [pinia] },
  })
}

/**
 * Baris data saja, bukan baris hasil expand.
 *
 * `DataTable` merender baris yang terbuka sebagai `<tr>` tambahan dengan satu
 * `td` ber-`colspan`, jadi `tbody tr` mentah ikut mengembang sesaat accordion
 * dibuka dan indeks setelahnya bergeser.
 *
 * Baris expand-filter yang dipakai: `td`-nya punya `colspan`. Baris data punya
 * satu `td` per kolom, dan tidak pernah punya `colspan`.
 *
 * Jerinya BUKAN `td.length > 1` seperti yang dipakai sebelumnya, dan itu
 * penting untuk tabel tanpa `expandable`. Tanpa chevron, satu kolom artinya
 * satu `td`, sehingga baris data itu tersaring dan `barisData()` mengembalikan
 * array kosong. Gejalanya muncul jauh dari penyebabnya:
 *
 *     Cannot read properties of undefined (reading 'find')
 *
 * yang sama persis dengan gejala "tidak ada baris sama sekali" -- padahal
 * barisnya ada dan tampil. Test yang memanggilnya gagal bukan karena
 * komponennya salah, tapi karena tidak tahu harus mencari ke mana.
 *
 * Diperiksa dari `cek-tr.mjs`:
 *
 *   tanpa `expandable` : 1 baris, 1 `td` per baris
 *   dengan `expandable`: 1 baris, 2 `td` per baris
 */
function barisData(wrapper) {
  return wrapper.findAll('tbody > tr').filter((tr) => tr.findAll('td[colspan]').length === 0)
}

/** Tombol chevron pembuka accordion pada baris data ke-`n`. */
function chevron(wrapper, n = 0) {
  return barisData(wrapper)[n].find('button')
}

describe('DataTable memberi tahu pemanggil saat baris di-expand', () => {
  beforeEach(pasangPagar)

  it('melepas `expand` saat dibuka dan `collapse` saat ditutup', async () => {
    // Sumber bug-nya: tanpa event ini halaman tidak punya cara meminta data
    // saat accordion dibuka, dan tidak ada tempat lain yang memanggilnya.
    const wrapper = mount(DataTable, {
      props: {
        data: [{ id: 7, nama: 'Lokasi' }],
        columns: [{ key: 'nama', title: 'Lokasi' }],
        expandable: true,
      },
      slots: { 'expanded-row': '<div class="isi-accordion">isi</div>' },
      global: opsiGlobal(),
    })

    const tombol = chevron(wrapper)

    await tombol.trigger('click')

    assert.equal(wrapper.emitted('expand')?.length, 1, 'klik pertama harus melepas `expand`')
    assert.equal(wrapper.emitted('collapse'), undefined, 'belum ada yang ditutup')
    assert.equal(wrapper.find('.isi-accordion').exists(), true, 'isi tampil setelah dibuka')

    await tombol.trigger('click')

    assert.equal(wrapper.emitted('collapse')?.length, 1, 'klik kedua harus melepas `collapse`')
    assert.equal(wrapper.emitted('expand')?.length, 1, 'klik kedua tidak boleh melepas `expand`')
    assert.equal(wrapper.find('.isi-accordion').exists(), false, 'isi hilang setelah ditutup')
  })

  it('melepas `expand` dengan baris yang diklik sebagai isi', async () => {
    // Pemanggil butuh tahu baris mana yang harus dimuat. Event tanpa isi
    // memaksa setiap halaman mencocokkan `id` sendiri di dalam closure.
    const wrapper = mount(DataTable, {
      props: {
        data: [
          { id: 7, nama: 'Kiri' },
          { id: 9, nama: 'Kanan' },
        ],
        columns: [{ key: 'nama', title: 'Lokasi' }],
        expandable: true,
      },
      global: opsiGlobal(),
    })

    await chevron(wrapper, 1).trigger('click')

    assert.deepEqual(
      wrapper.emitted('expand')?.[0]?.[0],
      { id: 9, nama: 'Kanan' },
      'isi event harus baris yang diklik',
    )
  })

  it('tidak melepas event apa pun tanpa `expandable`', async () => {
    // Komponen ini dipakai puluhan halaman tanpa `expandable`. Menjalankan
    // logika expand pada tabel biasa mengubah klik di baris menjadi sesuatu
    // tanpa disengaja.
    const wrapper = mount(DataTable, {
      props: {
        data: [{ id: 7, nama: 'Lokasi' }],
        columns: [{ key: 'nama', title: 'Lokasi' }],
      },
      global: opsiGlobal(),
    })

    await barisData(wrapper)[0].find('td').trigger('click')

    assert.equal(wrapper.emitted('expand'), undefined)
    assert.equal(wrapper.emitted('collapse'), undefined)
  })
})

describe('halaman hak akses memuat jabatan saat baris lokasi dibuka', () => {
  beforeEach(pasangPagar)

  it('menampilkan daftar jabatan setelah chevron diklik', async () => {
    const wrapper = mountHalaman()

    await flushPromises()

    // Kontrol: baris lokasi ada dan belum ada yang diklik, jadi pada titik ini
    // "belum punya jabatan" memang BENAR dan boleh muncul.
    assert.match(wrapper.text(), /Lokasi/, 'baris lokasi harus tampil')
    assert.equal(jejak.menuAccess.length, 0, 'tidak boleh memuat sebelum ada yang diklik')

    await chevron(wrapper).trigger('click')
    await flushPromises()

    const teks = wrapper.text()

    assert.doesNotMatch(
      teks,
      /belum punya jabatan/,
      'BUG INI: "belum punya jabatan" tampil padahal backend mengirim dua jabatan',
    )
    assert.match(teks, /Direktur/, 'jabatan pertama harus muncul')
    assert.match(teks, /Sekretaris/, 'jabatan kedua harus muncul')
  })

  it('meminta endpoint dengan id lokasi, bukan slug', async () => {
    // Service menerima keduanya, tapi id yang salah akan mengembalikan 404 --
    // dan 404 itu muncul sebagai "belum punya jabatan", yaitu pesan yang
    // mengarah ke sebab yang keliru.
    const wrapper = mountHalaman()

    await flushPromises()
    await chevron(wrapper).trigger('click')
    await flushPromises()

    assert.deepEqual(jejak.menuAccess, [1], 'harus meminta lokasi id=1')
  })

  it('tidak meminta ulang lokasi yang sudah dimuat', async () => {
    // Tanpa penjaga `sudahDimuat`, tiap render ulang accordion menembak satu
    // sambungan ke database tenant. Daftar ini per lokasi, jadi satu halaman
    // hak akses bisa memicu puluhan sambungan hanya karena pengguna mengetik
    // di kotak pencarian.
    const wrapper = mountHalaman()

    await flushPromises()

    await chevron(wrapper).trigger('click')
    await flushPromises()

    assert.equal(jejak.menuAccess.length, 1)

    await chevron(wrapper).trigger('click')
    await chevron(wrapper).trigger('click')
    await flushPromises()

    assert.equal(jejak.menuAccess.length, 1, 'buka-tutup-buka tidak menambah permintaan')
  })

  it('memuat tiap lokasi terpisah dan tidak saling menimpa', async () => {
    // Dua lokasi punya jabatan sama-sama bernama "Direktur" dengan `id: 1`.
    // Kalau state centangan disimpan per jabatan saja, centangan lokasi kedua
    // ikut menentukan tampilan lokasi pertama -- sementara yang tersimpan di
    // server tetap milik lokasi masing-masing, jadi layar menampilkan sesuatu
    // yang tidak pernah disimpan.
    const wrapper = mountHalaman()

    await flushPromises()

    await chevron(wrapper, 0).trigger('click')
    await flushPromises()
    await chevron(wrapper, 1).trigger('click')
    await flushPromises()

    assert.deepEqual(jejak.menuAccess, [1, 2], 'kedua lokasi harus meminta datanya sendiri')
    assert.equal(
      (wrapper.text().match(/Direktur/g) || []).length,
      2,
      'kedua lokasi harus menampilkan jabatan mereka sendiri',
    )
  })

  it('menampilkan alasan dari backend, bukan "belum punya jabatan", saat 503', async () => {
    // Backend membalas 503 dengan alasannya sendiri. "Belum punya jabatan" untuk
    // respons itu berbohong: jobatannya ada, yang tidak bisa dibaca adalah
    // database-nya -- dan superadmin akan menyimpulkan tidak ada yang perlu
    // diatur.
    globalThis.__balasJabatan = balas503

    const wrapper = mountHalaman()

    await flushPromises()
    await chevron(wrapper).trigger('click')
    await flushPromises()

    const teks = wrapper.text()

    assert.doesNotMatch(
      teks,
      /belum punya jabatan/,
      'kegagalan baca tidak boleh ditampilkan sebagai "belum punya jabatan"',
    )
    assert.match(teks, /tidak bisa dibaca/, 'pesan 503 dari backend harus tampil apa adanya')
    assert.match(teks, /Coba lagi/, 'harus ada jalan mencoba ulang')
  })

  it('permintaan yang berhasil tidak memunculkan pesan apa pun', async () => {
    const wrapper = mountHalaman()

    await flushPromises()
    await chevron(wrapper).trigger('click')
    await flushPromises()

    assert.doesNotMatch(wrapper.text(), /Coba lagi/, 'request berhasil tidak menampilkan error')
    assert.equal(toast.length, 0, 'request berhasil tidak boleh memunculkan toast')
  })
})

/*
 * LENCANA PERAN: jabatan ini untuk siapa?
 *
 * Dua alasan kenapa ini diuji, dan keduanya soal superadmin yang salah paham:
 *
 *  1. Dia harus tahu sedang mengatur pekerjaan lapangan atau pekerjaan
 *     kantor. Tanpa lencana, daftar centang untuk "Surveyor" dan untuk
 *     "Direktur" kelihatan sama saja -- padahal isinya beda jauh.
 *
 *  2. Dia harus tahu bahwa "Cater" dan "Teknisi" itu SATU tempat. Kalau
 *     keduanya tampil dengan nama peran yang berbeda, superadmin akan
 *     mengira ada dua daftar yang harus disetel terpisah -- dan keduanya
 *     akan terlihat sama sampai salah satu berubah.
 */
describe('halaman hak akses menampilkan peran jabatan', () => {
  beforeEach(pasangPagar)

  /** Terapkan daftar jabatan custom pada respons tiruan. */
  function pakaiJabatan(daftar) {
    globalThis.__balasJabatan = () => ({ data: { success: true, data: daftar } })
  }

  /** Buka satu jabatan (accordion kedua level) dan kembalikan wrapper-nya. */
  async function bukaJabatan(wrapper, n = 0) {
    await flushPromises()
    await chevron(wrapper).trigger('click')
    await flushPromises()

    // Baris jabatan hidup di dalam baris expand, jadi bukan bagian dari
    // `barisData()` -- yang itu baris data lokasi.
    const kepala = wrapper.findAll('.hak-akses-jabatan__head')[n]
    await kepala.trigger('click')
    await flushPromises()

    return wrapper
  }

  it('menampilkan nama peran dari backend', async () => {
    pakaiJabatan([
      {
        id: 1,
        nama: 'Surveyor',
        configured: true,
        total_user: 1,
        role: 'surveyor',
        menu: [
          {
            key: 'survey.daftar',
            label: 'Daftar Survey',
            group: 'Survey',
            selected: true,
            locked: false,
          },
        ],
      },
    ])

    const wrapper = mountHalaman()

    await bukaJabatan(wrapper)

    assert.match(
      wrapper.text(),
      /Surveyor/,
      'lencana peran harus menampilkan nama peran yang dikirim backend',
    )
  })

  it('menyebut teknisi dan cater sebagai satu peran yang sama', async () => {
    pakaiJabatan([
      {
        id: 1,
        nama: 'Teknisi',
        configured: true,
        total_user: 1,
        role: 'teknisi',
        menu: [
          {
            key: 'teknisi.daftar-tagihan',
            label: 'Daftar Tagihan',
            group: 'Pusat Teknis',
            selected: true,
            locked: false,
          },
        ],
      },
      {
        id: 2,
        nama: 'Cater',
        configured: true,
        total_user: 1,
        role: 'teknisi',
        menu: [
          {
            key: 'teknisi.daftar-tagihan',
            label: 'Daftar Tagihan',
            group: 'Pusat Teknis',
            selected: true,
            locked: false,
          },
        ],
      },
    ])

    const wrapper = mountHalaman()

    await bukaJabatan(wrapper)

    const teks = wrapper.text()

    assert.match(teks, /Teknisi \/ Cater/, 'peran teknisi harus menyebut cater juga')
    assert.match(
      teks,
      /memakai daftar menu/,
      'catatan peran harus menjelaskan bahwa daftar ini milik peran itu',
    )
  })

  it('menu `hidden` tidak muncul di daftar centang teknisi', async () => {
    /*
     * "Daftar Tagihan" dan "Hasil Instalasi" dibuka dari KARTU di dashboard
     * teknisi, bukan dari entri sidebar. Backend mengirimnya `hidden`, dan
     * halaman ini harus menyaringnya -- kalau tidak, superadmin melihat
     * checkbox untuk menu yang tidak pernah tampil sebagai menu.
     *
     * Yang diuji sekaligus: group yang SELURUH isinya hidden ikut hilang
     * (tidak boleh tersisa kartu kosong), dan lencana "N menu diizinkan"
     * hanya menghitung menu yang benar-benar tampil.
     */
    pakaiJabatan([
      {
        id: 1,
        nama: 'Teknisi',
        configured: true,
        total_user: 1,
        role: 'teknisi',
        menu: [
          {
            key: 'instalasi.pemakaian',
            label: 'Input Pemakaian Air',
            group: 'Master Instalasi',
            selected: true,
            locked: false,
            hidden: false,
          },
          {
            key: 'teknisi.dashboard',
            label: 'Dashboard',
            group: 'Utama',
            selected: true,
            locked: true,
            hidden: false,
          },
          {
            key: 'teknisi.hasil-instalasi',
            label: 'Hasil Instalasi',
            group: 'Pusat Teknis',
            selected: true,
            locked: false,
            hidden: true,
          },
          {
            key: 'teknisi.daftar-tagihan',
            label: 'Daftar Tagihan',
            group: 'Pusat Teknis',
            selected: true,
            locked: true,
            hidden: true,
          },
        ],
      },
    ])

    const wrapper = mountHalaman()

    await bukaJabatan(wrapper)

    const teks = wrapper.text()

    assert.match(teks, /Input Pemakaian Air/, 'menu yang tampil harus tetap ada')
    assert.match(teks, /Dashboard/, 'Dashboard tetap tampil sebagai menu wajib terbuka')

    assert.doesNotMatch(
      teks,
      /Daftar Tagihan/,
      '"Daftar Tagihan" tidak boleh muncul di daftar centang -- halamannya dibuka dari dashboard.',
    )
    assert.doesNotMatch(
      teks,
      /Hasil Instalasi/,
      '"Hasil Instalasi" tidak boleh muncul di daftar centang.',
    )
    assert.doesNotMatch(
      teks,
      /Pusat Teknis/,
      'group yang seluruh isinya hidden tidak boleh tersisa sebagai kartu kosong.',
    )

    assert.match(
      teks,
      /2 menu diizinkan/,
      'lencana hanya boleh menghitung menu yang benar-benar tampil.',
    )
  })

  it('tidak menampilkan catatan peran untuk jabatan admin', async () => {
    pakaiJabatan([
      {
        id: 1,
        nama: 'Bendahara',
        configured: false,
        total_user: 0,
        role: 'admin',
        menu: [
          {
            key: 'master.pelanggan',
            label: 'Pelanggan',
            group: 'Basis Data',
            selected: true,
            locked: false,
          },
        ],
      },
    ])

    const wrapper = mountHalaman()

    await bukaJabatan(wrapper)

    assert.doesNotMatch(
      wrapper.text(),
      /memakai daftar menu/,
      'jabatan admin memakai daftar STANDAR -- tidak perlu catatan peran',
    )
  })
})
