import { strict as assert } from 'node:assert'
import { after, before, beforeEach, describe, it } from 'node:test'

import { daftarkanPagarVirtual, komponen, pasangDom, tutupDom } from './vueHarness.mjs'

/*
 * TOAST LOGOUT DI PANEL PUSAT: "Logout Berhasil" beserta ikon centangnya.
 *
 * Bug yang dikunci test ini benar-benar terjadi. Tombol keluar di panel pusat
 * mengarahkan user ke `/central/login?logout=1`, tapi halaman login itu
 * HANYA membaca parameter `expired`. Parameter `logout` tidak pernah dibaca
 * siapa pun, jadi tidak ada apa-apa yang muncul setelah user keluar.
 *
 * Yang hilang bukan cuma ikon centang: seluruh toast-nya hilang. Di layar
 * itu sama persis dengan "halaman loginnya tidak merespons".
 *
 * Yang membuat bug itu lolos dari build dan lint adalah sifatnya: tidak ada
 * satu pun alat yang gagal. Kompilasi berhasil, rute-nya valid, `logout=1`
 * benar-benar ada di URL, dan `expired=1` -- yang strukturnya IDENTIK --
 * bekerja. Yang hilang hanya satu Percobaan membaca parameter.
 *
 * Karena itu test di sini tidak memeriksa "apakah ada parameter `logout`",
 * tapi APAKAH TOASTNYA benar-benar muncul: ikon centang, judul "Logout
 * Berhasil", dan sapaan yang menyebut namanya.
 */

let mount
let flushPromises
let createRouter
let createMemoryHistory
let CentralLoginView

/**
 * Toast yang ditembakkan selama test.
 *
 * Isinya bentuk `{ icon, title, text }` -- bukan objek SweetAlert2 utuh,
 * karena yang perlu diperiksa di sini HANYA isi toast-nya. Bentuk aslinya
 * tidak penting untuk membuktikan toast itu muncul.
 */
let toast

before(async () => {
  await pasangDom()

  const testUtils = await import('@vue/test-utils')
  mount = testUtils.mount
  flushPromises = testUtils.flushPromises

  const routerModul = await import('vue-router')
  createRouter = routerModul.createRouter
  createMemoryHistory = routerModul.createMemoryHistory

  /*
   * `MySwal` ditiru supaya toast yang muncul bisa diperiksa.
   *
   * SweetAlert2 sungguhan merender ke DOM sendiri dan membiarkan
   * `onMounted` yang async selesai tanpa bisa diperiksa. Yang diperiksa di
   * sini bukan tampilan toast-nya, tapi APAKAH toast itu ditembakkan --
   * itu yang hilang di produksi.
   */
  daftarkanPagarVirtual(
    '@/utils/swal',
    `
    export const MySwal = {
      fire: async (opsi) => {
        globalThis.__toast.push({
          icon: opsi?.icon ?? null,
          title: opsi?.title ?? null,
          text: opsi?.text ?? null,
        })
        return { isConfirmed: true }
      },
    }
    export const showErrorToast = () => {}
    export const showSuccessToast = () => {}
    export const showWarningToast = () => {}
  `,
  )

  /*
   * `centralAuthService` dan `centralAxios` ditiru supaya halaman ini tidak
   * menyentuh jaringan maupun localStorage sesi yang sebenarnya.
   *
   * Yang paling penting: `isCentralSessionValid()` harus `false`. Kalau ia
   * `true`, halaman langsung dialihkan ke `/central` dan `onMounted` berhenti
   * sebelum sempat membaca parameter -- toast-nya tidak akan pernah muncul,
   * dan test ini akan lulus karena alasan yang salah.
   */
  daftarkanPagarVirtual(
    '@/utils/centralAxios',
    `
    export const CENTRAL_TOKEN_KEY = 'central_token'
    export const CENTRAL_EXPIRES_KEY = 'central_expires_at'
    export const CENTRAL_USER_KEY = 'central_user'
    export function isCentralSessionValid() { return false }
    export function clearCentralSession() {}
    export function getCentralUser() { return null }
  `,
  )

  daftarkanPagarVirtual(
    '@/services/central.service',
    `
    export const centralAuthService = {
      async login() { return { success: true, data: { user: { name: 'Admin' } } } },
      async logout() { return { success: true } },
    }
    export const invoiceService = {}
    export const locationService = {}
    export const rekeningService = {}
  `,
  )

  CentralLoginView = await komponen('presentations/views/central/CentralLoginView.vue')
})

after(async () => {
  await tutupDom()
})

beforeEach(() => {
  toast = []
  globalThis.__toast = toast
})

/**
 * Terapkan URL halaman login.
 *
 * `window.location.search` dibaca langsung oleh halaman, jadi test harus
 * menyetelnya sendiri -- bukan lewat router. `history.replaceState` dipakai
 * karena JSDOM tidak mengizinkan `location.search` langsung ditulis.
 */
function setUrl(search) {
  window.history.replaceState({}, '', `/central/login${search}`)
}

/**
 * Mount halaman login dan tunggu `onMounted`-nya selesai.
 *
 * Router sungguhan dipasang karena halaman ini memakai `useRouter()` di
 * dalam `setup()`. `global.mocks.$router` hanya memasang properti pada
 * instance -- tidak pernah menyuntik ke `inject()`, jadi `useRouter()` akan
 * mengembalikan `undefined` dan halaman ini akan gagal saat mount.
 */
async function mountLogin(search) {
  setUrl(search)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/central/login', component: CentralLoginView }],
  })

  await router.push('/central/login')
  await router.isReady()

  const wrapper = mount(CentralLoginView, {
    global: {
      plugins: [router],
      stubs: { 'font-awesome-icon': { template: '<i />' } },
    },
  })

  await flushPromises()

  return wrapper
}

describe('toast logout di panel pusat', () => {
  it('muncul dengan ikon centang setelah logout berhasil', async () => {
    await mountLogin('?logout=1')

    assert.equal(toast.length, 1, 'harus ada tepat satu toast setelah logout')

    assert.equal(
      toast[0].icon,
      'success',
      'toast harus memakai ikon centang (success), bukan ikon lain atau tanpa ikon',
    )

    assert.equal(
      toast[0].title,
      'Logout Berhasil',
      'judul toast harus menyatakan bahwa logout BERHASIL',
    )
  })

  it('menyebut nama orang yang baru saja keluar', async () => {
    await mountLogin('?logout=1&name=Admin%20Pusat')

    assert.equal(toast.length, 1, 'harus ada tepat satu toast setelah logout')

    assert.match(
      toast[0].text ?? '',
      /Admin Pusat/,
      'toast harus menyapa orang yang logout lewat namanya',
    )
  })

  it('tetap memberi kabar saat namanya tidak ada', async () => {
    await mountLogin('?logout=1')

    assert.equal(toast.length, 1, 'harus ada tepat satu toast setelah logout')
    assert.ok(toast[0].text, 'toast tetap harus punya teks meskipun nama tidak ada')
  })

  it('membuang parameter logout dari URL supaya tidak muncul lagi saat dimuat ulang', async () => {
    await mountLogin('?logout=1&name=Admin%20Pusat')

    assert.equal(
      window.location.search,
      '',
      'parameter logout dan name harus dibuang dari URL setelah toast muncul',
    )
  })

  it('tidak memunculkan toast logout saat halaman dibuka tanpa parameter itu', async () => {
    await mountLogin('')

    assert.equal(toast.length, 0, 'halaman login biasa TIDAK boleh menampilkan toast logout')
  })

  it('tetap menampilkan peringatan sesi berakhir untuk parameter expired', async () => {
    await mountLogin('?expired=1')

    assert.equal(toast.length, 1, 'parameter expired harus tetap memunculkan satu toast')
    assert.equal(toast[0].icon, 'warning', 'sesi berakhir harus memakai ikon warning')
    assert.equal(toast[0].title, 'Sesi Berakhir')
  })
})
