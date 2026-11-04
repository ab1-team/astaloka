/**
 * POPUP INPUT PEMAKAIAN: kamera TIDAK boleh menyala sendiri.
 *
 * Bug yang dikunci test ini sudah terjadi di repo: `inputMeterModal.vue`
 * memanggil `openScanCamera()` di dalam watcher `props.show`, jadi SETIAP
 * popup input meter langsung menyalakan kamera scan.
 *
 * Kenapa itu penting, dan kenapa tidak kelihatan sebagai bug
 * ---------------------------------------------------------
 *
 * Yang WAJIB diisi di popup itu cuma angka meter akhir. Foto meteran dan
 * hasil scan OCR sama-sama opsional -- dan popup ini dipakai teknisi yang
 * sering sudah mencatat angka meterannya secara manual di lapangan.
 *
 * Efeknya di lapangan: tiap pencatatan meter harus
 *
 *   1. menunggu popup selesai memuat kamera,
 *   2. membuang waktu mengarahkan kamera ke meteran, dan
 *   3. menutup kamera yang tidak pernah dipakai.
 *
 * Tidak ada error, tidak ada request gagal, tidak ada yang protes. Yang
 * hilang cuma waktu -- dan itu justru alasan why bug ini bisa bertahan lama.
 *
 * Yang diuji di sini
 * ------------------
 *
 *   1. Popup yang dibuka dengan setting "kamera otomatis" MATI tidak
 *      memanggil `getUserMedia` sama sekali.
 *   2. Popup yang dibuka dengan setting "kamera otomatis" NYALA DOES
 *      memanggilnya -- supaya tombol matinya tidak cuma berarti
 *      "tidak pernah buka kamera" (yang regresinya sama: fitur mati total).
 *   3. Tombol scan tetap bisa menyalakan kameranya sendiri, kapan saja.
 *   4. Kalau kamera gagal dinyalakan (izin ditolak), popup TIDAK diam-diam
 *      menjalankan scan dengan kamera mati -- hasilnya harus "gagal baca".
 *
 * CATATAN SOAL `navigator.mediaDevices`
 * -------------------------------------
 *
 * JSDOM TIDAK punya `navigator.mediaDevices`. Jadi kalau komponen memanggil
 * `navigator.mediaDevices.getUserMedia(...)` tanpa stub, yang terjadi bukan
 * "kamera gagal" -- yang terjadi adalah `TypeError: Cannot read properties of
 * undefined (reading 'getUserMedia')`, dan test gagal dengan pesan yang
 * sama sekali tidak menyebut kamera.
 *
 * Stub-nya dipasang di `beforeEach` dan DICATAT setiap pemanggilannya, jadi
 * test bisa membedakan "kamera tidak pernah diminta" dari "kamera diminta
 * lalu gagal".
 */

import { strict as assert } from 'node:assert'
import { after, before, beforeEach, describe, it } from 'node:test'

import { daftarkanPagarVirtual, komponen, pasangDom, tutupDom } from './vueHarness.mjs'

/** Lokasi berkas popup, relatif terhadap `src/`. */
const JALUR_MODAL = 'presentations/views/app/admin/tagihan/partials/inputMeterModal.vue'

/**
 * Berapa kali `getUserMedia` dipanggil, dan hasilnya sukses atau gagal.
 *
 * Dipasang di `globalThis` supaya stub bisa mengisinya tanpa harus
 * mengimpor apa pun dari modul test.
 */
let kamera

/** Pesan toast yang muncul selama test. */
let toast

/** Dialog SweetAlert yang muncul selama test. */
let dialog

let mount
let flushPromises
let createPinia
let setActivePinia
let InputMeterModal

/**
 * Stub `navigator.mediaDevices`.
 *
 * `getUserMedia` mengembalikan stream tiruan yang punya `getTracks()`, karena
 * `stopLiveCamera()` dan `stopScanCamera()` memanggilnya saat popup ditutup.
 *
 * `gagal` bisa berupa `true` (gagal generik) atau objek `Error` dengan `name`
 * tertentu -- supaya test bisa memastikan pesan yang muncul BEDA-BEDA sesuai
 * penyebabnya.
 */
function pasangKamera(gagal = false) {
  kamera = { dipanggil: 0, gagal }

  const stream = {
    getTracks: () => [
      {
        stop() {
          kamera.berhenti = (kamera.berhenti ?? 0) + 1
        },
      },
    ],
  }

  Object.defineProperty(globalThis.navigator, 'mediaDevices', {
    value: {
      async getUserMedia() {
        kamera.dipanggil += 1

        if (kamera.gagal) {
          const error =
            typeof kamera.gagal === 'object' ? kamera.gagal : new Error('Izin kamera ditolak')
          if (typeof error.name !== 'string') error.name = 'NotAllowedError'
          throw error
        }

        return stream
      },
    },
    configurable: true,
    writable: true,
  })
}

/** Hapus stub kamera, supaya test berikutnya tidak mewarisi yang di atas. */
function lepasKamera() {
  if (globalThis.navigator?.mediaDevices) {
    delete globalThis.navigator.mediaDevices
  }
}

before(async () => {
  await pasangDom()

  const testUtils = await import('@vue/test-utils')
  mount = testUtils.mount
  flushPromises = testUtils.flushPromises

  const pinia = await import('pinia')
  createPinia = pinia.createPinia
  setActivePinia = pinia.setActivePinia

  /*
   * Pagar untuk service dan dialog.
   *
   * `sopService` dipakai store `settings` untuk membaca pengaturan kamera
   * otomatis. Kalau tidak ditiru, store akan memanggil HTTP sungguhan --
   * dan di test Node itu berarti `fetch` ke host yang tidak ada, yang
   * menggagalkan test karena alasan yang salah.
   *
   * Pagar dipasang DUA ALAS: `@/services/sop.service` dan
   * `@/services/sop.service.js`. Store mengimpor yang BERAKHIR `.js`,
   * sementara service diimpor tanpa ekstensi di tempat lain, dan nama
   * berkas tiruan diturunkan dari specifier apa adanya.
   *
   * `MySwal` ditiru supaya dialog konfirmasi hasil OCR tidak muncul
   * sungguhan (SweetAlert2 butuh DOM yang jauh lebih lengkap dari yang
   * JSDOM sediakan di sini).
   */
  const tiruanService = `
    export const sopService = {
      async getAll() {
        return { data: { success: true, data: {} } }
      },
      async getPemakaianAir() {
        const nilai = globalThis.__autoOpenMeterCamera ?? false
        return { data: { success: true, data: { autoOpenMeterCamera: nilai } } }
      },
      async savePemakaianAir(payload) {
        globalThis.__simpanPemakaianAir = payload
        return { data: { success: true } }
      },
    }
    export default sopService
  `

  daftarkanPagarVirtual('@/services/sop.service', tiruanService)
  daftarkanPagarVirtual('@/services/sop.service.js', tiruanService)

  daftarkanPagarVirtual(
    '@/utils/swal',
    `
    export const MySwal = {
      fire: async (opsi) => {
        globalThis.__dialog.push(opsi)
        return { isConfirmed: globalThis.__dialogKonfirmasi ?? true }
      },
    }
    export const showErrorToast = (e) => globalThis.__toast.push(['error', e])
    export const showSuccessToast = (m) => globalThis.__toast.push(['success', m])
    export const showWarningToast = (m) => globalThis.__toast.push(['warning', m])
    export const showInfoToast = (m) => globalThis.__toast.push(['info', m])
  `,
  )

  InputMeterModal = await komponen(JALUR_MODAL)
})

after(async () => {
  lepasKamera()
  await tutupDom()
})

beforeEach(() => {
  toast = []
  dialog = []

  globalThis.__toast = toast
  globalThis.__dialog = dialog
  globalThis.__dialogKonfirmasi = true
  globalThis.__autoOpenMeterCamera = false
  globalThis.__simpanPemakaianAir = null

  lepasKamera()
  pasangKamera()
})

/**
 * Mount popup, lalu BUKA popup-nya.
 *
 * Popupnya sengaja dipasang dalam keadaan tertutup dulu, lalu dibuka lewat
 * `setProps`. Alasannya bukan sekadar cara: `watch(() => props.show)` tanpa
 * `immediate` TIDAK jalan saat mount -- jadi popup yang langsung dipasang
 * dengan `show: true` tidak pernah melewati watcher sama sekali.
 *
 * Yang dilewatkan watcher itu bukan hal kecil: meter akhir di-set dari
 * meter awal, state di-reset, dan kamera diputuskan berdasarkan
 * pengaturan. Popup yang dipasang terbuka akan terlihat "bekerja" --
 * kolomnya kosong, kameranya tidak pernah menyala -- dan test-nya lolos
 * tanpa menguji apa pun yang penting.
 */
async function mountPopup({ meterAwal = 187 } = {}) {
  const pinia = createPinia()
  setActivePinia(pinia)

  const wrapper = mount(InputMeterModal, {
    props: {
      show: false,
      customer: {
        noInduk: 'PDAM-0001',
        nama: 'Pelanggan Uji',
        dusun: 'Dusun 1',
        meterAwal,
      },
    },
    global: {
      plugins: [pinia],
      stubs: {
        teleport: true,
        'font-awesome-icon': { template: '<i />' },
      },
    },
  })

  await wrapper.setProps({ show: true })
  await flushPromises()

  return wrapper
}

/** Tombol "Scan Meteran Sekarang". */
function tombolScan(wrapper) {
  return wrapper.findAll('button').find((b) => b.text().includes('Scan Meteran'))
}

/** Teks "Kamera Belum Aktif" -- penanda kamera scan belum menyala. */
function placeholderKamera(wrapper) {
  return wrapper.text().includes('Kamera Belum Aktif')
}

describe('popup input pemakaian', () => {
  it('kamera scan TIDAK menyala sendiri saat popup dibuka', async () => {
    const wrapper = await mountPopup()

    assert.equal(
      kamera.dipanggil,
      0,
      'Kamera harus TIDAK diminta saat popup dibuka -- yang wajib diisi cuma meter akhir.',
    )
    assert.ok(
      placeholderKamera(wrapper),
      'Area kamera harus menampilkan "Kamera Belum Aktif", bukan kotak bidik di atas layar kosong.',
    )
  })

  it('kamera scan TIDAK menyala sendiri saat setting dibaca dari server sebagai mati', async () => {
    // Nilainya datang dari server, bukan dari default lokal. Ini yang terjadi
    // di produksi: admin belum pernah menyentuh pengaturan, jadi backend
    // mengirim `false`.
    globalThis.__autoOpenMeterCamera = false

    const wrapper = await mountPopup()

    assert.equal(kamera.dipanggil, 0)
    assert.ok(placeholderKamera(wrapper))
  })

  it('kamera scan MENYALA sendiri kalau admin menyalakannya', async () => {
    globalThis.__autoOpenMeterCamera = true

    const wrapper = await mountPopup()

    assert.equal(
      kamera.dipanggil,
      1,
      'Dengan setting "kamera otomatis" nyala, kamera harus langsung diminta.',
    )
    assert.equal(
      placeholderKamera(wrapper),
      false,
      'Kamera yang sudah menyala tidak boleh menampilkan placeholder "Kamera Belum Aktif".',
    )
  })

  it('tombol scan menyalakan kamera saat diklik', async () => {
    const wrapper = await mountPopup()

    assert.equal(kamera.dipanggil, 0, 'Kamera belum boleh menyala sebelum tombol ditekan.')

    const tombol = tombolScan(wrapper)
    assert.ok(tombol, 'Tombol "Scan Meteran Sekarang" harus ada.')
    assert.equal(
      tombol.attributes('disabled'),
      undefined,
      'Tombol scan tidak boleh disabled hanya karena kamera belum menyala.',
    )

    await tombol.trigger('click')
    await flushPromises()

    assert.equal(kamera.dipanggil, 1, 'Tombol scan harus menyalakan kamera yang belum menyala.')
    assert.equal(
      placeholderKamera(wrapper),
      false,
      'Setelah tombol ditekan, placeholder kamera harus hilang.',
    )
  })

  it('kamera yang gagal dinyalakan tidak membuat scan diam-diam dianggap berhasil', async () => {
    pasangKamera(true)

    const wrapper = await mountPopup()

    const tombol = tombolScan(wrapper)
    await tombol.trigger('click')
    await flushPromises()

    assert.equal(kamera.dipanggil, 1, 'Kamera harus dicoba satu kali.')
    assert.ok(
      dialog.length > 0,
      'Kamera yang gagal harus memberi tahu user lewat dialog, bukan diam-diam.',
    )
  })

  it('hasil scan tidak langsung ditulis ke meter akhir sebelum dikonfirmasi', async () => {
    const wrapper = await mountPopup()

    // Kamera menyala, tapi frame-nya tidak terbaca (video tidak punya
    // dimensi di JSDOM) -> OCR tidak menghasilkan angka.
    const tombol = tombolScan(wrapper)
    await tombol.trigger('click')
    await flushPromises()

    const input = wrapper.find('input[type="text"]')
    assert.equal(
      input.element.value,
      '187',
      'Meter akhir harus tetap sama seperti meter awal kalau scan gagal.',
    )
  })

  it('pesan gagal kamera menyebut penyebabnya, bukan cuma "kamera gagal"', async () => {
    /*
     * Dulu semua penyebab gagal kamera menghasilkan pesan yang SAMA:
     * "Pastikan browser memiliki izin untuk mengakses kamera (Webcam/HP)."
     *
     * Padahal lima penyebab yang berbeda punya cara memperbaiki yang
     * berbeda juga -- dan yang paling sering terjadi di lapangan (halaman
     * dibuka lewat HTTP di LAN, bukan HTTPS) sama sekali bukan soal izin.
     */
    const error = new Error('ditolak')
    error.name = 'NotAllowedError'
    pasangKamera(error)

    const wrapper = await mountPopup()

    const tombol = tombolScan(wrapper)
    await tombol.trigger('click')
    await flushPromises()

    assert.ok(dialog.length > 0, 'Kamera yang gagal harus memberi tahu user lewat dialog.')

    const pesan = dialog[dialog.length - 1]
    assert.match(pesan.title ?? '', /izin/i, 'Izin ditolak harus disebut jelas di judul dialog.')
    assert.ok(
      (pesan.text ?? '').length > 20,
      'Pesan harus menjelaskan cara memperbaiki, bukan cuma menyebut gejalanya.',
    )
  })

  it('halaman tanpa secure context disuruh pakai HTTPS, bukan disuruh ubah izin', async () => {
    /*
     * `beforeEach` memasang stub kamera, jadi stub itu harus dilepas
     * SESUDAH popup dibuka. Melepasnya sebelum mount tidak berguna --
     * `beforeEach` sudah memasang yang baru.
     */
    const wrapper = await mountPopup()

    /*
     * Di luar secure context `navigator.mediaDevices` TIDAK ADA sama
     * sekali -- objeknya `undefined`, bukan objek yang `getUserMedia`-nya
     * menolak.
     *
     * Ini kondisi yang paling sering terjadi di lapangan: teknisi membuka
     * aplikasi lewat IP lokal (`http://192.168.1.10:5173`), bukan HTTPS.
     *
     * Menunjuk user ke pengaturan izin browser untuk masalah ini tidak akan
     * pernah berhasil -- izinnya memang sudah benar.
     */
    lepasKamera()

    const tombol = tombolScan(wrapper)
    await tombol.trigger('click')
    await flushPromises()

    assert.ok(dialog.length > 0, 'Kamera yang gagal harus memberi tahu user lewat dialog.')

    const pesan = dialog[dialog.length - 1]
    assert.match(
      pesan.text ?? '',
      /HTTPS/i,
      'Masalah secure context harus disebut sebagai HTTPS, bukan izin kamera.',
    )
    assert.doesNotMatch(
      pesan.title ?? '',
      /izin/i,
      'Masalah ini bukan soal izin -- jangan sampai user mencari pengaturan izin.',
    )
  })
})
