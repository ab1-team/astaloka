import { strict as assert } from 'node:assert'
import { after, before, beforeEach, describe, it } from 'node:test'

import { daftarkanPagarVirtual, komponen, pasangDom, tutupDom } from './vueHarness.mjs'

/*
 * SUSUNAN SIDEBAR PER ROLE: harus sama persis dengan desain pamsides-v2.
 *
 * Yang dikunci di sini bukan "menu ini boleh tampil", tapi SUSUNAN-nya:
 * urutan, nama, dan -- yang paling sering salah -- apakah sebuah menu
 * berdiri sendiri atau berada di dalam sebuah group.
 *
 * Kenapa susunan, bukan hanya isi
 * ------------------------------
 *
 * Dua menu dengan isi yang sama bisa terlihat sangat berbeda tergantung
 * susunannya. "Input Pemakaian Air" sebagai link tingkat atas berbeda dari
 * "Input Pemakaian Air" di dalam group "Master Instalasi": yang pertama
 * satu klik, yang kedua dua klik dan menampilkan judul group yang berbeda.
 *
 * Yang paling sering dikira sepele: group dengan SATU anak. Sidebar di
 * sini mem-flatten group satu-anak menjadi link langsung -- lebih cepat,
 * tapi kalau desain per-role memang meminta group itu tetap terlihat,
 * flatten itu menghapus satu tingkat navigasi. Karena itu katalog punya
 * flag `group_tetap`.
 *
 * HARAPAN DIBAWAH TIDAK DIHITUNG DARI KATALOG YANG SEDANG DIUJI
 * -------------------------------------------------------------
 *
 * Daftar harapan di bawah ditulis DARI `SidebarView.vue` pamsides-v2
 * (referensi `menuItems`, baris 269-358). Hardcode-nya disengaja: kalau
 * daftar ini ikut dihitung dari katalog yang sedang diuji, test akan
 * selalu lulus -- termasuk kalau katalognya sendiri salah. Yang diuji
 * adalah HASIL, dan yang dibandingkan adalah referensi yang tidak ikut
 * berubah.
 *
 * Jalankan: `node --test` dari folder `frontend`.
 */

/**
 * Katalog tiruan, bentuknya sama dengan respons `GET /menu-access/catalog`.
 *
 * Meniru backend berarti katalog TIDAK diuji di sini -- ia diuji di
 * backend (`MenuAccessTest`). Yang diuji file ini adalah cara frontend
 * MENERJEMAHKAN katalog itu menjadi susunan sidebar.
 *
 * Yang ditirukan: entri yang punya `allowed: true`. Item yang tidak
 * diizinkan (`allowed: false`) sengaja TIDAK ikut, supaya test ini
 * benar-benar menguji penyaringan role, bukan sekadar pencetakan.
 */
const KATALOG = [
  // ---- Admin ----------------------------------------------------------
  {
    key: 'settings.sop',
    label: 'Personalisasi (SOP)',
    group: 'Pengaturan',
    icon: 'cog',
    path: '/app/settings/personalisasi-sop',
    roles: ['admin'],
    allowed: true,
  },
  {
    key: 'settings.coa',
    label: 'Chart of Account (CoA)',
    group: 'Pengaturan',
    icon: 'cog',
    path: '/app/settings/coa',
    roles: ['admin'],
    allowed: true,
  },
  {
    key: 'settings.package',
    label: 'Paket & Tarif Layanan',
    group: 'Pengaturan',
    icon: 'cog',
    path: '/app/kelas-biaya',
    roles: ['admin'],
    allowed: true,
  },
  {
    key: 'master.pelanggan',
    label: 'Pelanggan',
    group: 'Basis Data',
    icon: 'users',
    path: '/app/data-pelanggan',
    roles: ['admin'],
    allowed: true,
  },
  {
    key: 'master.desa',
    label: 'Desa',
    group: 'Basis Data',
    icon: 'map-marker-alt',
    path: '/app/data-desa',
    roles: ['admin'],
    allowed: true,
  },
  {
    key: 'master.instalasi',
    label: 'Daftar Instalasi',
    group: 'Basis Data',
    icon: 'building',
    path: '/app/dataInstalasi',
    roles: ['admin'],
    allowed: true,
  },
  {
    key: 'instalasi.register',
    label: 'Registrasi Instalasi',
    group: 'Master Instalasi',
    icon: 'chart-bar',
    path: '/app/instalasi/register',
    roles: ['admin'],
    allowed: true,
  },
  {
    key: 'instalasi.status',
    label: 'Status Instalasi',
    group: 'Master Instalasi',
    icon: 'chart-bar',
    path: '/app/instalasi/status',
    roles: ['admin'],
    allowed: true,
  },
  {
    key: 'instalasi.pemakaian',
    label: 'Input Pemakaian Air',
    group: 'Master Instalasi',
    icon: 'faucet',
    path: '/app/instalasi/teknisiPemakaianAir',
    roles: ['teknisi'],
    allowed: true,
    group_tetap: true,
  },
  {
    key: 'tagihan.input',
    label: 'Input Tagihan',
    group: 'Pencatatan Tagihan',
    icon: 'pen-to-square',
    path: '/app/instalasi/pemakaian',
    roles: ['admin'],
    allowed: true,
  },
  {
    key: 'tagihan.daftar',
    label: 'Daftar Tagihan',
    group: 'Pencatatan Tagihan',
    icon: 'file-invoice-dollar',
    path: '/app/instalasi/daftar-tagihan',
    roles: ['admin'],
    allowed: true,
  },
  {
    key: 'transaksi.jurnal-umum',
    label: 'Jurnal Umum',
    group: 'Transaksi',
    icon: 'money-bill-wave',
    path: '/app/transaksi/jurnal-umum',
    roles: ['admin'],
    allowed: true,
  },
  {
    key: 'transaksi.tutup-buku',
    label: 'Tutup Buku',
    group: 'Transaksi',
    icon: 'lock',
    path: '/app/transaksi/tutup-buku',
    roles: ['admin'],
    allowed: true,
  },
  {
    key: 'pelaporan',
    label: 'Pelaporan',
    group: 'Pelaporan',
    icon: 'file-alt',
    path: '/app/pelaporan',
    roles: ['admin'],
    allowed: true,
  },

  // ---- Surveyor --------------------------------------------------------
  {
    key: 'survey.daftar',
    label: 'Daftar Survey',
    group: 'Survey',
    icon: 'clipboard-check',
    path: '/app/surveyor',
    roles: ['surveyor'],
    allowed: true,
    tanpa_sidebar: true,
  },
  {
    key: 'survey.create',
    label: 'Buat Survey Baru',
    group: 'Survey',
    icon: 'plus-circle',
    path: '/app/survey/create',
    roles: ['surveyor'],
    allowed: true,
    top_level: true,
  },

  // ---- Teknisi ---------------------------------------------------------
  {
    key: 'teknisi.hasil-instalasi',
    label: 'Hasil Instalasi',
    group: 'Pusat Teknis',
    icon: 'screwdriver-wrench',
    path: '/app/teknisi/hasil-instalasi/:id',
    roles: ['teknisi'],
    allowed: true,
    hidden: true,
  },
  {
    key: 'teknisi.daftar-tagihan',
    label: 'Daftar Tagihan',
    group: 'Pusat Teknis',
    icon: 'file-invoice-dollar',
    path: '/app/teknisi/daftar-tagihan',
    roles: ['teknisi'],
    allowed: true,
    hidden: true,
  },
]

/**
 * Susunan sidebar per role, sesuai desain pamsides-v2.
 *
 * Bentuknya: `Group: anak1, anak2` untuk group, `Label` untuk link
 * tingkat atas (tanpa `:`).
 */
const HARAPAN = {
  admin: [
    'Beranda',
    'Pengaturan: Personalisasi (SOP), Chart of Account (CoA), Paket & Tarif Layanan',
    'Basis Data: Pelanggan (Tambah Pelanggan, Data Pelanggan), Desa (Tambah Desa, Data Desa), Daftar Instalasi',
    'Master Instalasi: Registrasi Instalasi, Status Instalasi',
    'Pencatatan Tagihan: Input Tagihan, Daftar Tagihan',
    'Transaksi: Jurnal Umum, Tutup Buku',
    'Pelaporan',
  ],
  teknisi: ['Beranda', 'Master Instalasi: Input Pemakaian Air'],
  surveyor: ['Beranda', 'Buat Survey Baru'],
}

/**
 * Item yang tidak boleh jadi tautan sidebar, per role.
 *
 * Semuanya `tanpa_sidebar` atau `hidden`: halamannya tetap milik role-nya
 * dan dibuka dari dashboard, tapi tidak boleh jadi entri menu. Bedanya,
 * `hidden` juga menghilangkannya dari daftar centang panel pusat.
 *
 * Dipejak PER ROLE, bukan per label global. "Daftar Tagihan" ada dua
 * yang berbeda: milik teknisi (tidak boleh muncul) dan milik admin
 * ("Pencatatan Tagihan" -> "Daftar Tagihan", memang SHOULD muncul).
 * Kalau dicek per label global, yang salah yang kalah.
 */
const TANPA_TAUTAN = {
  admin: ['Daftar Survey', 'Hasil Instalasi'],
  teknisi: ['Daftar Survey', 'Hasil Instalasi', 'Daftar Tagihan'],
  surveyor: ['Daftar Survey', 'Hasil Instalasi', 'Daftar Tagihan'],
}

let SidebarView
let mount
let flushPromises
let createPinia
let setActivePinia

before(async () => {
  /*
   * Katalog tiruan untuk store.
   *
   * Store dibaca lewat `useMenuAccessStore()` di dalam komponen, jadi yang
   * perlu ditirukan adalah `menuAccessService.getCatalog()` -- bukan store
   * -nya. `items` di store diisi dari hasil request itu, dan sisanya
   * (filter role, flatten group) dikerjakan di `SidebarView.vue` -- bagian
   * yang justru sedang diuji.
   */
  daftarkanPagarVirtual(
    '@/services/menuAccess.service',
    `
    export const menuAccessService = {
      async getCatalog() {
        // Bentuknya sama dengan hasil service asli: service mengembalikan
        // BADAN respons (axios sudah melepas \`response.data\`), jadi di
        // sini isinya \`{ success, data: { items, groups } }\`.
        //
        // Kalau dibungkus satu tingkat lagi, store membaca
        // \`res.data.data.items\` dan mendapat \`undefined\` -- gejalanya
        // sidebar hanya berisi "Beranda", tanpa satu pun menu.
        return { success: true, data: { items: globalThis.__katalog, groups: [] } }
      },
    }

    export default menuAccessService
  `,
  )

  // Identitas lembaga: tidak ada hubungannya dengan susunan menu, tapi
  // komponen memanggilnya di `onMounted`.
  daftarkanPagarVirtual(
    '@/services/sop.service',
    `
    export const sopService = {
      async getPublicIdentity() {
        return { data: { success: true, data: { nama: '', nama_lembaga: '' } } }
      },
    }

    export default sopService
  `,
  )

  await pasangDom()

  const testUtils = await import('@vue/test-utils')
  mount = testUtils.mount
  flushPromises = testUtils.flushPromises

  const pinia = await import('pinia')
  createPinia = pinia.createPinia
  setActivePinia = pinia.setActivePinia

  SidebarView = await komponen('presentations/layouts/app/SidebarView.vue')
})

after(async () => {
  await tutupDom()
})

beforeEach(() => {
  globalThis.__katalog = KATALOG.map((item) => ({ ...item }))
})

/**
 * Mount sidebar untuk sebuah role.
 *
 * Router sungguhan dipasang, bukan `$route` tiruan.
 *
 * `SidebarView` memanggil `useRoute()`, yang butuh injection router --
 * dan `route.path` dipakai untuk menandai menu yang aktif. Dengan
 * `$route` tiruan lewat `mocks`, injection-nya tidak ada, dan komponen
 * gagal di `watch(() => route.path)` dengan "Cannot read properties of
 * undefined (reading 'path')" -- yang tidak menyebut router sama sekali.
 *
 * Router yang dibuat di sini HANYA berisi satu route catch-all. Memuat
 * `router/index.js` asli akan menarik seluruh aplikasi (semua halaman,
 * semua service), dan itu membuat test ini rapuh: satu perubahan di
 * halaman mana pun bisa menggagalkan test sidebar.
 */
async function mountSidebar(role) {
  const { createRouter, createMemoryHistory } = await import('vue-router')

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }],
  })

  await router.push('/app')
  await router.isReady()

  const pinia = createPinia()
  setActivePinia(pinia)

  const { useUiStore } = await import('@/stores/uiStore')
  useUiStore().userRole = role

  const wrapper = mount(SidebarView, {
    global: {
      stubs: {
        /*
         * Stub ikon yang MEMBAWA nama ikannya.
         *
         * Stub biasa (`<i />`) membuang nama ikon, dan nama ikon itu justru
         * pembeda utama antara menu tingkat atas dan anak group:
         *
         *   - menu tingkat atas merender `<font-awesome-icon :icon>`.
         *   - anak group TIDAK merender ikon sama sekali -- hanya
         *     penanda bullet.
         *
         * Tanpa nama ikon, "Buat Survey Baru" yang berdiri sendiri dan
         * "Buat Survey Baru" yang jadi anak group satu-anak yang sudah
         * di-flatten menghasilkan markup yang sama persis. Dengan
         * `data-icon`, keduanya bisa dibedakan.
         */
        'font-awesome-icon': {
          props: ['icon'],
          template: '<i :data-icon="icon" />',
        },
        BaseButton: true,
        AbtAstaBrataModal: true,
      },
      plugins: [pinia, router],
    },
  })

  await flushPromises()
  await flushPromises()

  return wrapper
}

/**
 * Susunan sidebar untuk sebuah role, dalam bentuk teks yang mudah dibaca.
 *
 * `Group: anak1, anak2` untuk group, `Label` untuk link tingkat atas.
 *
 * URUTAN dibaca dari DOM, bukan dikumpulkan per jenis. Dua hal punya
 * bentuk yang sama di DOM tapi berbeda maknanya:
 *
 *   - menu `top_level` ("Buat Survey Baru") -- berdiri sendiri, dirender
 *     sebagai `<router-link>` langsung.
 *   - group satu anak yang di-flatten ("Pelaporan") -- juga `<router-link>`
 *     langsung, tapi ia adalah hasil flatten, dan posisinya mengikuti
 *     urutan group di katalog.
 *
 * Kalau keduanya dipisah, urutan aslinya hilang, dan test tidak bisa
 * membedakan "Pelaporan di tempat yang benar" dari "Pelaporan di tempat
 * yang salah tapi tetap terlihat".
 */
function bacaSusunan(wrapper) {
  const nav = wrapper.find('.sidebar-nav')

  const hasil = []

  for (const anak of nav.element.children) {
    // Group: judulnya ada di dalam `<span class="sidebar-nav-label">`.
    if (anak.classList.contains('sidebar-nav-group')) {
      const judul = anak.querySelector('.sidebar-nav-label')?.textContent?.trim() ?? ''

      const submenu = anak.querySelector('.sidebar-submenu')

      const daftarAnak = []

      /*
       * Anak group bisa berupa dua bentuk:
       *
       *   - `<a class="sidebar-submenu-item">` -- link biasa.
       *   - `<div class="sidebar-nav-group">` -- group BERSARANG
       *     ("Pelanggan"/"Desa"), yang di dalamnya ada link ber-kelas
       *     `is-parent` dan satu tingkat lebih dalam, ber-kelas
       *     `nested-item`.
       *
       * Dua bentuk itu tidak boleh disamakan: yang bersarang punya
       * TURUNAN sendiri, danrenders dua tingkat.
       */
      for (const item of submenu?.children ?? []) {
        if (item.classList.contains('sidebar-nav-group')) {
          const induk = item.querySelector('.sidebar-submenu-item.is-parent')

          const cucu = [...item.querySelectorAll('.sidebar-submenu-item.nested-item')].map(
            (el) => el.textContent?.trim() ?? '',
          )

          daftarAnak.push(`${induk?.textContent?.trim() ?? ''} (${cucu.join(', ')})`)

          continue
        }

        if (!item.classList.contains('sidebar-submenu-item')) continue

        daftarAnak.push(item.textContent?.trim() ?? '')
      }

      hasil.push(`${judul}: ${daftarAnak.join(', ')}`)

      continue
    }

    // Link tingkat atas.
    if (anak.classList.contains('sidebar-nav-item')) {
      const label = anak.querySelector('.sidebar-nav-label')?.textContent?.trim() ?? ''

      hasil.push(label)
    }
  }

  return hasil
}

describe('susunan sidebar per role', () => {
  it('teknisi: satu group "Master Instalasi" dengan satu anak', async () => {
    /*
     * Dua-duanya penting, dan hanya salah satu yang salah sudah cukup
     * merusak tampilan:
     *
     *   - groupnya HARUS ada (bukan link datar): kalau di-flatten, teknisi
     *     kehilangan satu tingkat navigasi dan desainnya tidak lagi sama
     *     dengan pamsides-v2.
     *   - satu kata pun: "Input Pemakaian Air", bukan "Input Pemakaian".
     */
    const wrapper = await mountSidebar('teknisi')

    assert.deepEqual(bacaSusunan(wrapper), HARAPAN.teknisi)

    wrapper.unmount()
  })

  it('surveyor: "Buat Survey Baru" berdiri sendiri, bukan di dalam group', async () => {
    /*
     * Kalau `top_level` diabaikan, "Buat Survey Baru" masuk ke group
     * "Survey" -- dan karena "Daftar Survey" di-`tanpa_sidebar`, group itu
     * punya satu anak saja dan akan di-flatten. Hasilnya sidebar-nya
     * kelihatan HAMPIR sama: group-nya hilang, tapi tautannya tetap ada
     * di posisi yang sama.
     *
     * Yang membedakan keduanya: menu tingkat atas merender IKONNYA,
     * sedangkan anak group hanya menampilkan penanda bullet. Maka
     * "Buat Survey Baru" harus punya ikon `plus-circle` -- kalau ia
     * anak group, ikonnya hilang.
     */
    const wrapper = await mountSidebar('surveyor')

    assert.deepEqual(bacaSusunan(wrapper), HARAPAN.surveyor)

    const ikonBuatSurvey = wrapper
      .findAll('.sidebar-nav .sidebar-nav-label')
      .filter((el) => el.text().trim() === 'Buat Survey Baru')
      .map((el) => {
        // Ikon-nya ada di induk yang sama (`<router-link>`).
        const induk = el.element.closest('a, div')

        return induk?.querySelector('i[data-icon]')?.getAttribute('data-icon') ?? null
      })

    assert.deepEqual(
      ikonBuatSurvey,
      ['plus-circle'],
      '"Buat Survey Baru" harus berdiri sendiri sebagai menu tingkat atas (punya ikon). ' +
        'Kalau ikonnya hilang, ia turun jadi anak group "Survey" -- hasil flatten yang ' +
        'terlihat sama tetapi hierarkinya salah.',
    )

    wrapper.unmount()
  })

  it('admin: group dan urutannya sama persis dengan desain per-role', async () => {
    const wrapper = await mountSidebar('admin')

    assert.deepEqual(bacaSusunan(wrapper), HARAPAN.admin)

    wrapper.unmount()
  })

  it('menu `tanpa_sidebar` / `hidden` tidak jadi tautan sidebar', async () => {
    /*
     * "Daftar Survey" (`tanpa_sidebar`) serta "Hasil Instalasi" dan
     * "Daftar Tagihan" (`hidden`, milik teknisi) tetap halaman milik
     * role-nya -- dibuka dari dashboard -- tapi tidak boleh jadi entri menu.
     */
    for (const role of ['admin', 'teknisi', 'surveyor']) {
      const wrapper = await mountSidebar(role)

      const gabung = bacaSusunan(wrapper).join(' | ')

      for (const label of TANPA_TAUTAN[role]) {
        assert.ok(
          !gabung.includes(label),
          `"${label}" tidak boleh jadi entri sidebar (role ${role}), tapi ada di: ${gabung}`,
        )
      }

      wrapper.unmount()
    }
  })

  it('menu `top_level` berdiri sendiri walau group-nya punya anak lain', async () => {
    /*
     * Test sebelumnya ("Buat Survey Baru berdiri sendiri") memakai group
     * yang anak visibelnya cuma satu -- dan itu membuat `top_level`
     * TIDAK TERBEDAKAN dari flatten, karena keduanya menghasilkan markup
     * yang sama persis.
     *
     * Di sini bedanya jadi nyata: group "Survey" punya dua anak yang
     * tampil ("Riwayat Survey" dan "Survey Export"; "Daftar Survey"
     * `tanpa_sidebar` jadi tidak ikut). Kalau `top_level` diabaikan,
     * "Buat Survey Baru" masuk ke dalam group itu -- dan sidebar
     * menampilkan "Survey" sebagai group dengan tiga anak, bukan
     * "Buat Survey Baru" sebagai link tingkat atas.
     *
     * Group dengan dua anak juga tidak bisa di-flatten, jadi ini
     * menguji `top_level` tanpa ikut menguji `group_tetap`.
     */
    globalThis.__katalog = [
      {
        key: 'survey.daftar',
        label: 'Daftar Survey',
        group: 'Survey',
        icon: 'clipboard-check',
        path: '/app/surveyor',
        roles: ['surveyor'],
        allowed: true,
        tanpa_sidebar: true,
      },
      {
        key: 'survey.riwayat',
        label: 'Riwayat Survey',
        group: 'Survey',
        icon: 'clock-rotate-left',
        path: '/app/surveyor/riwayat',
        roles: ['surveyor'],
        allowed: true,
      },
      {
        key: 'survey.export',
        label: 'Survey Export',
        group: 'Survey',
        icon: 'file-export',
        path: '/app/surveyor/export',
        roles: ['surveyor'],
        allowed: true,
      },
      {
        key: 'survey.create',
        label: 'Buat Survey Baru',
        group: 'Survey',
        icon: 'plus-circle',
        path: '/app/survey/create',
        roles: ['surveyor'],
        allowed: true,
        top_level: true,
      },
    ]

    const wrapper = await mountSidebar('surveyor')

    assert.deepEqual(bacaSusunan(wrapper), [
      'Beranda',
      'Buat Survey Baru',
      'Survey: Riwayat Survey, Survey Export',
    ])

    wrapper.unmount()
  })

  it('path ber-parameter tidak jadi tautan sidebar', async () => {
    /*
     * `/app/teknisi/hasil-instalasi/:id` yang dipakai apa adanya akan
     * mengarah ke tautan dengan `:id` LITERAL -- bukan id tiket sungguhan.
     * Route-nya tidak akan cocok, dan user mendarat di halaman kosong.
     */
    const wrapper = await mountSidebar('teknisi')

    const href = wrapper
      .findAll('.sidebar-nav a')
      .map((el) => el.attributes('href'))
      .filter((h) => h && h.includes(':'))

    assert.deepEqual(href, [], `ada tautan dengan parameter yang belum diganti: ${href.join(', ')}`)

    wrapper.unmount()
  })
})
