import { strict as assert } from 'node:assert'
import { describe, it } from 'node:test'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

/*
 * KONTRAK EXPORT & CETAK STATUS INSTALASI
 * ========================================
 *
 * Dua tombol di halaman `/app/instalasi/status`:
 *
 *   1. "Export Excel"  -> unduh file `.xlsx`
 *   2. "Cetak Table"   -> buka halaman cetak ber-kop surat
 *
 * KEDUA-DUANYA sebelumnya tidak benar-benar bekerja:
 *
 *   - "Export Excel" cuma stub: `exportData` tidak melakukan apa-apa.
 *   - "Cetak Table" menyusun HTML sendiri lewat `document.write()`:
 *     tanpa kop surat, tanpa kop institutions, dan -- yang paling buruk --
 *     HTML-nya dirakit dari nilai data mentah, jadi nama pelanggan yang
 *     mengandung `<` bisa merusak markup cetakannya.
 *
 * Test ini mengunci KONTRAK frontend-nya:
 *
 *   - ekspor memakai kategori + pencarian yang SEDANG AKTIF di layar,
 *     supaya file dan layarnever berbeda isi;
 *   - ekspor requesting binary (blob), bukan JSON;
 *   - cetakan memakai SELURUH baris yang lolos filter (`filteredData`),
 *     bukan hanya halaman tabel yang sedang terlihat;
 *   - kop surat diambil dari `useIdentitasLembaga` (sumber identitas
 *     tunggal) dan dirender lewat `BaseReportLayout` yang sama dengan
 *     modul Pelaporan -- supaya keduanya tidak melenceng dari kop.
 *
 * Jalankan: `node --test frontend/test/`
 */

/** Akar folder `src/`. */
const AKAR = fileURLToPath(new URL('../src/', import.meta.url))

/** Isi sebuah berkas, dibaca sebagai teks UTF-8. */
function isi(berkas) {
  return readFileSync(join(AKAR, berkas), 'utf8')
}

/**
 * Buang komentar dari sumber.
 *
 * Dipakai supaya penjelasan di dalam komentar tidak terbaca sebagai
 * kode. String literal tidak disentuh.
 */
function tanpaKomentar(isi) {
  return isi.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
}

const COMPOSABLE = 'composables/useInstalasiStatus.js'
const SERVICE = 'services/ticket.service.js'
const HALAMAN = 'presentations/views/app/admin/instalasi/InstalasiStatus.vue'
const CETAK = 'presentations/views/app/admin/instalasi/cetakStatusInstalasi.vue'
const LAPORAN = 'presentations/views/app/admin/instalasi/partials/ReportCetakStatusInstalasi.vue'

describe('export Excel status instalasi', () => {
  it('halaman memanggil service ekspor yang mengembalikan file binary', () => {
    const sumber = isi(COMPOSABLE)

    assert.ok(
      /ticketService\.exportStatusExcel\(/.test(sumber),
      'composable harus memanggil service ekspor',
    )

    const svc = isi(SERVICE)

    assert.ok(/exportStatusExcel/.test(svc), 'service harus punya method exportStatusExcel')

    assert.ok(
      /responseType:\s*'blob'/.test(svc),
      'response ekspor wajib blob: tanpa itu axios mem-parsing body sebagai JSON dan file unduhnya rusak',
    )
  })

  it('kategori dan kata kunci pencarian yang sedang aktif ikut dikirim', () => {
    const sumber = tanpaKomentar(isi(COMPOSABLE))

    assert.ok(
      /kategori:\s*store\.activeStatus/.test(sumber),
      'kategori tab yang aktif harus dikirim supaya file sama dengan layar',
    )

    assert.ok(
      /params\.search\s*=\s*pencarian/.test(sumber),
      'kata kunci pencarian yang sedang diketik harus ikut dikirim',
    )
  })

  it('error dari backend dibaca ulang dari blob', () => {
    const sumber = isi(COMPOSABLE)

    /*
     * Dengan `responseType: 'blob'`, balasan error (422 / 500) sampai
     * di browser sebagai Blob berisi JSON. Kalau tidak dibaca ulang
     * sebagai teks, `error.response.data.message` selalu `undefined` dan
     * user hanya melihat pesan generik yang tidak berguna.
     */
    assert.ok(/pesanDariBlob/.test(sumber), 'pesan error harus diambil dari body blob')

    assert.ok(
      /\.text\(\)/.test(sumber),
      'body blob harus dibaca ulang sebagai teks sebelum di-parse',
    )
  })

  it('tombol export punya status loading supaya tidak bisa diklik dua kali', () => {
    const halaman = isi(HALAMAN)

    assert.ok(
      /:loading="isExporting"/.test(halaman),
      'tombol Export Excel harus menampilkan spinner saat request berjalan',
    )

    const sumber = isi(COMPOSABLE)

    assert.ok(/isExporting/.test(sumber), 'state isExporting harus dibuat di composable')
  })
})

describe('cetak table status instalasi', () => {
  it('tombol cetak membuka halaman cetak, bukan window.write', () => {
    const halaman = isi(HALAMAN)

    assert.ok(/@click="printData"/.test(halaman), 'tombol Cetak Table harus memanggil printData')

    assert.ok(
      !/handlePrintData/.test(halaman),
      'pencetak lama (document.write) harus dihapus: tanpa kop surat dan rawan merusak markup',
    )

    assert.ok(!/document\.write/.test(halaman), 'halaman tidak boleh merakit HTML cetakan sendiri')
  })

  it('filter dan pencarian diteruskan ke halaman cetak lewat query string', () => {
    const sumber = tanpaKomentar(isi(COMPOSABLE))

    assert.ok(
      /filter:\s*store\.activeStatus/.test(sumber),
      'kategori tab harus diteruskan ke halaman cetak',
    )

    assert.ok(
      /q:\s*store\.searchQuery/.test(sumber),
      'kata kunci pencarian harus diteruskan ke halaman cetak',
    )

    const cetak = isi(CETAK)

    assert.ok(
      /route\.query\.filter/.test(cetak),
      'halaman cetak harus membaca kategori dari query string',
    )

    assert.ok(
      /route\.query\.q/.test(cetak),
      'halaman cetak harus membaca pencarian dari query string',
    )
  })

  it('cetak memakai filteredData, bukan paginatedData', () => {
    const cetak = tanpaKomentar(isi(CETAK))

    /*
     * Ini yang paling penting dari sisi hasil.
     *
     * `paginatedData` = isi halaman tabel yang SEDANG TERLIHAT.
     * `filteredData`  = seluruh baris yang lolos filter pencarian.
     *
     * Kalau yang dicetak `paginatedData`, user yang sedang di halaman 2
     * dari 5 hanya mendapat satu halaman isinya -- dokumen keluar terlihat
     * "lengkap" tapi isinya cuma separuh.
     */
    assert.ok(/filteredData\.value/.test(cetak), 'halaman cetak harus memakai filteredData')

    assert.ok(!/paginatedData/.test(cetak), 'halaman cetak tidak boleh memakai paginatedData')
  })

  it('kategori dari query string divalidasi sebelum dipakai', () => {
    const cetak = isi(CETAK)

    /*
     * Query string bisa disunting bebas oleh user. Kategori yang tidak
     * dikenal TIDAK boleh diteruskan ke store -- di sana `activeStatus`
     * menentukan data mana yang dimuat, dan nilai asing akan membuat
     * halaman kosong tanpa penjelasan.
     */
    assert.ok(
      /INSTALASI_MENU_LIST\.some\(\(m\)\s*=>\s*m\.key\s*===\s*filter\)/.test(cetak),
      'kategori dari query string harus dicek terhadap daftar kategori yang sah',
    )
  })

  it('kop surat memakai identitas lembaga, bukan teks yang ditulis manual', () => {
    const cetak = isi(CETAK)
    const laporan = isi(LAPORAN)

    assert.ok(
      /useIdentitasLembaga/.test(cetak),
      'halaman cetak harus memakai useIdentitasLembaga sebagai sumber identitas',
    )

    assert.ok(
      /ensureIdentitas/.test(cetak),
      'identitas harus dipastikan sudah termuat sebelum halaman dirender',
    )

    assert.ok(
      /BaseReportLayout/.test(laporan),
      'laporan harus memakai BaseReportLayout yang sama dengan modul Pelaporan',
    )

    /*
     * Nama lembaga yang ditulis manual di template = kop surat yang
     * tidak pernah ikut berubah begitu admin mengubah profil lembaga.
     */
    assert.ok(
      !/BUMDes Bangun Kencana/.test(cetak) && !/BUMDes Bangun Kencana/.test(laporan),
      'nama lembaga tidak boleh ditulis manual di halaman cetak',
    )
  })

  it('logo memakai storageLogoUrl supaya URL-nya absolut', () => {
    const laporan = isi(LAPORAN)

    assert.ok(
      /storageLogoUrl/.test(laporan),
      'URL logo harus lewat storageLogoUrl: path relatif /storage/... tidak resolve saat dicetak',
    )
  })

  it('seluruh baris dibagi ke beberapa halaman, bukan satu halaman panjang', () => {
    const cetak = tanpaKomentar(isi(CETAK))

    assert.ok(/filteredData\.value/.test(cetak), 'halaman cetak harus memakai filteredData')

    assert.ok(/packRows\(/.test(cetak), 'baris harus dikemas per halaman, bukan ditumpuk semuanya')

    assert.ok(
      /ukurBaris/.test(cetak),
      'jumlah baris per halaman harus diukur dari DOM, bukan ditebak',
    )
  })

  it('jumlah baris per halaman diukur, bukan ditetapkan dengan angka tetap', () => {
    const cetak = tanpaKomentar(isi(CETAK))

    /*
     * Satu baris tabel bisa satu baris teks atau dua, tergantung
     * panjang nama pelanggan dan alamatnya. Kalau jumlahnya ditetapkan
     * dengan angka tetap, halaman pasti meluber (baris terakhir
     * terpisah, kop surat hilang, nomor halaman tidak sinkron) atau
     * halaman terlalu jarang terisi.
     *
     * Karena itu tinggi tiap baris diukur dari DOM (probe off-screen)
     * lalu dipakai untuk mengemas baris ke halaman.
     */
    assert.ok(/getBoundingClientRect\(\)\.height/.test(cetak), 'tinggi baris harus diukur dari DOM')

    assert.ok(
      /probeEl/.test(cetak) && /status-probe/.test(cetak),
      'pengukuran harus memakai probe off-screen dengan lebar A4 landscape',
    )

    const util = isi('utils/instalasiCetak.js')

    assert.ok(/export const packRows/.test(util), 'helper pemecah baris harus ada di utils')
  })
})

describe('pemetaan kategori status instalasi', () => {
  it('kategori dan status mentah konsisten antara halaman dan ekspor', () => {
    const util = isi('utils/instalasiStatus.js')

    /*
     * Aturan kategori -> status mentah dipakai backend
     * (`StatusInstalasiExcelExport::KATEGORI_STATUS`) dan frontend
     * (`utils/instalasiStatus.js`). Kalau satu sisi berubah dan sisi lain
     * tidak, ekspor dan layar menampilkan data berbeda -- dan user mengira
     * ekspornya salah.
     *
     * `draft` sengaja TIDAK ada di kedua sisi: tiket draf tidak pernah
     * muncul di tab mana pun.
     */
    const dipmuseum = util.match(/INSTALASI_STATUSES_BY_CATEGORY\s*=\s*\{([\s\S]*?)\n\}/)

    assert.ok(dipmuseum, 'daftar status per kategori harus ada di util frontend')

    const isiKategori = dipmuseum[1]

    assert.ok(/permohonan:\s*\['pending'\]/.test(isiKategori))
    assert.ok(/pasang_baru:\s*\['surveyed',\s*'unpaid',\s*'processing'\]/.test(isiKategori))
    assert.ok(/aktif:\s*\['completed'\]/.test(isiKategori))
    assert.ok(/blokir:\s*\['suspended'\]/.test(isiKategori))
    assert.ok(/cabut:\s*\['terminated'\]/.test(isiKategori))

    assert.ok(!/draft/.test(isiKategori), 'status draft tidak boleh masuk kategori mana pun')
  })

  it('pencarian memakai tiga field yang sama dengan backend', () => {
    const util = isi('utils/instalasiStatus.js')

    assert.ok(/filterRowsBySearch/.test(util), 'helper pencarian bersama harus ada')

    const helper = util.match(/export const filterRowsBySearch[\s\S]*?\n\}/)

    assert.ok(helper, 'filterRowsBySearch harus ada')

    for (const field of ['name', 'id', 'address']) {
      assert.ok(
        helper[0].includes(`item.${field}`),
        `pencarian backend memakai ${field}; frontend harus sama`,
      )
    }
  })
})
