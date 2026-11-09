import { strict as assert } from 'node:assert'
import { after, before, describe, it } from 'node:test'

import { komponen, pasangDom, tutupDom } from './vueHarness.mjs'

/*
 * DESAIN KARTU "SISTEM TAGIHAN" DI HALAMAN SOP
 *
 * Dua aturan dengan satuan BERBEDA diletakkan berdampingan: satu tanggal
 * (1-31) dan satu jumlah bulan (0-24). Dulu keduanya ditumpuk vertikal
 * satu per satu, jadi layar yang dipakai hanya separuh -- padahal dua
 * angka yang harus dibandingkan admin ("tanggal ini" vs "berapa bulan
 * tunggakan yang boleh") jadi jauh lebih mudah kalau berdampingan.
 *
 * Test ini mengunci DUA hal:
 *
 *   1. Tata letak dua kolom. Yang diuji bukan asal ada grid, tapi
 *      URUTANNYA benar: aturan tanggal di kiri, aturan bulan di kanan.
 *      Urutan yang terbalik biasanya lolos dari "__testing__" -- tapi di
 *      layar, panel kiri adalah yang dibaca pertama, jadi ketidakcocokan
 *      urutan terasa sebagai "salah tempat".
 *
 *   2. Keterangan yang RINGKAS. Panel penjelasan punya dua blok yang
 *      tadinya butuh beberapa kalimat. Yang diuji di sini bukan panjang
 *      karakter (itu rapuh -- satu kata lagi menggagalkan test tanpa
 *      alasan), tapi hal yang benar-benar penting: dua input punya label
 *      yang JELAS, dan penjelasan tetap menyebut kedua aturan (dari
 *      "generate jurnal piutang" sampai status SUSPENDED).
 *
 * Kenapa tidak pakai snapshot? Snapshot mengunci class Tailwind persis,
 * dan setiap perubahan kecil pada spacing akan menggagalkan test tanpa
 * ada yang rusak. Yang diuji di sini perilaku yang dilihat pengguna:
 * dua kolom, urutan benar, dan keterangan tetap memberi tahu apa yang
 * dilakukan aturan itu.
 */

let mount
let flushPromises
let FontAwesomeIcon
let library
let fa
let SistemTagihan

before(async () => {
  await pasangDom()

  const testUtils = await import('@vue/test-utils')
  mount = testUtils.mount
  flushPromises = testUtils.flushPromises

  const core = await import('@fortawesome/fontawesome-svg-core')
  const vueFa = await import('@fortawesome/vue-fontawesome')
  fa = await import('@fortawesome/free-solid-svg-icons')

  library = core.library
  FontAwesomeIcon = vueFa.FontAwesomeIcon

  library.add(fa.faCalendar, fa.faCalendarDay, fa.faCalendarXmark, fa.faRobot, fa.faBolt, fa.faSave)

  SistemTagihan = await komponen('presentations/views/app/admin/sop/partials/sistemTagihan.vue')
})

after(async () => {
  await tutupDom()
})

/** Mount komponen dengan form dua angka. */
function mountSistemTagihan(form = {}) {
  return mount(SistemTagihan, {
    props: {
      modelValue: { toleransiTunggakan: 27, batasTagihan: 3, ...form },
      onSave: () => {},
    },
    global: {
      components: { 'font-awesome-icon': FontAwesomeIcon },
    },
  })
}

/**
 * Grid dua kolom.
 *
 * Class Tailwind di repo ini memakai modifier PENTING (`!`), jadi
 * `md:grid-cols-2!` -- bukan `md:grid-cols-2`. Yang dicari di sini bukan
 * ejaan persis class-nya, tapi class yang MEWAKILAI dua kolom. Kalau
 * ejaan class berubah (modifier penting ditambahkan atau dihapus),
 * test ini tidak ikut gagal.
 */
function gridDuaKolom(wrapper) {
  return wrapper
    .findAll('div')
    .filter((el) => (el.classes() || []).some((c) => c.startsWith('md:grid-cols-2')))
}

describe('kartu Sistem Tagihan di halaman SOP', () => {
  it('menampilkan dua aturan dalam dua kolom', () => {
    const wrapper = mountSistemTagihan()

    assert.ok(
      gridDuaKolom(wrapper).length > 0,
      'harus ada grid dua kolom, supaya kedua aturan tampil berdampingan',
    )
  })

  it('menaruh aturan tanggal di kiri dan aturan bulan di kanan', () => {
    const wrapper = mountSistemTagihan()

    const grid = gridDuaKolom(wrapper)[0]

    assert.ok(grid, 'grid dua kolom harus ada')

    const teks = grid.text()

    const posisiTanggal = teks.indexOf('Toleransi Menunggak')
    const posisiBulan = teks.indexOf('Batas Tagihan')

    assert.ok(posisiTanggal >= 0, 'kartu harus memuat "Toleransi Menunggak"')
    assert.ok(posisiBulan >= 0, 'kartu harus memuat "Batas Tagihan"')

    assert.ok(
      posisiTanggal < posisiBulan,
      'aturan tanggal harus tampil lebih dulu (kiri), baru aturan bulan (kanan)',
    )
  })

  it('tetap punya dua input angka dengan rentang yang benar', () => {
    const wrapper = mountSistemTagihan()

    const angka = wrapper.findAll('input[type="number"]')

    assert.equal(angka.length, 2, 'harus ada dua input angka: tanggal dan jumlah bulan')

    assert.equal(angka[0].attributes('min'), '1')
    assert.equal(angka[0].attributes('max'), '31')
    assert.equal(angka[1].attributes('min'), '0')
    assert.equal(angka[1].attributes('max'), '24')
  })

  it('menampilkan nilai form yang tersimpan', () => {
    const wrapper = mountSistemTagihan({ toleransiTunggakan: 21, batasTagihan: 6 })

    const angka = wrapper.findAll('input[type="number"]')

    assert.equal(angka[0].element.value, '21')
    assert.equal(angka[1].element.value, '6')
  })

  it('menjelaskan kedua aturan tanpa kalimat bertele-tele', async () => {
    const wrapper = mountSistemTagihan()

    await flushPromises()

    const teks = wrapper.text()

    // Dua aturan harus sama-sama dijelaskan, bukan hanya satu.
    assert.ok(teks.includes('Jurnal piutang'), 'penjelasan harus menyebut jurnal piutang')
    assert.ok(
      teks.includes('SUSPENDED'),
      'penjelasan harus menyebut status SUSPENDED untuk pemutusan sambung',
    )

    /*
     * Dulu ada kalimat panjang yang mengulang-ulang apa yang sudah
     * tertulis di label kartu ("Nilai Toleransi Menunggak di atas adalah
     * tanggal (1-31) di setiap bulan yang dijadwalkan sistem untuk ...").
     * Kalimat itu tidak menambah informasi -- label-nya sudah menyebutnya. Yang
     * diuji: penjelasan TIDAK lagi dimulai dengan mengulang label.
     */
    assert.ok(
      !teks.includes('di atas adalah'),
      'penjelasan tidak boleh mengulang label yang sudah tertulis di kartu',
    )
  })

  it('menyimpan lewat tombol Simpan Sistem Tagihan', async () => {
    const wrapper = mountSistemTagihan()

    let dipanggil = 0

    await wrapper.setProps({
      onSave: () => {
        dipanggil++
      },
    })

    const tombol = wrapper.findAll('button').find((b) => b.text().includes('Simpan'))

    assert.ok(tombol, 'tombol simpan harus ada')

    await tombol.trigger('click')

    assert.equal(dipanggil, 1, 'tombol harus memanggil onSave')
  })
})
