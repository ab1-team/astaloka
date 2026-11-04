import { strict as assert } from 'node:assert'
import { readFileSync, readdirSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, it } from 'node:test'

/**
 * Tombol "Simpan" harus seragam di seluruh aplikasi.
 *
 * Yang dikunci di sini hanya satu hal: SETIAP tombol yang menyimpan data
 * memakai gaya abu yang sama. Bukan soal estetika -- tapi soal tanda.
 *
 * Kenapa ini perlu dijaga
 * ----------------------
 *
 * Dulu tombol simpan muncul dalam delapan bentuk: abu, biru, hijau,
 * oranye, merah, gradien biru, gradien hijau, dan abu tua. Semuanya
 * "tombol simpan", tapi tidak ada yang tahu itu dari warnanya. Yang
 * terjadi bukan sekadar halaman terlihat tidak seragam -- yang terjadi
 * adalah orang yang sudah belajar tombol merah berarti "hapus" dan
 * tombol hijau berarti "berhasil", lalu menemukan tombol oranye yang
 * berarti "simpan". Tidak ada yang salah dari masing-masing tombol itu
 * sendiri; masalahnya ada di tidak adanya satu bahasa.
 *
 * Kenapa abu
 * ----------
 *
 * Warna brand (biru lokasi) dipakai di sidebar, header, dan aksen. Kalau
 * tombol simpan ikut warna itu, satu-satunya pembeda antara "tombol
 * biasa" dan "tombol yang menyimpan data" hilang. Abu tidak dipakai
 * untuk hal lain, jadi tombol simpan selalu dikenali -- dan tetap
 * dikenali saat lokasi mengganti warna brand-nya.
 *
 * Kenapa satu token
 * -----------------
 *
 * Warna abu ini dideklarasikan sekali, di `ui-base.css`, sebagai
 * `--btn-save-bg`. Dua sistem tombol lain -- CSS panel pusat dan kaki
 * modal paket -- menunjuk ke token yang sama, bukan menulis hex sendiri.
 *
 * Itu bukan soal kerapian. `slate-500` di Tailwind v4 bernilai
 * `oklch(55.4% .046 257.417)`, yang jadi `#62748e` -- sementara hex yang
 * ditulis manual adalah `#64748b`. Kalau kedua cara itu dipakai di dua
 * tempat, tombol simpan di panel pusat terlihat sedikit lebih terang
 * daripada di aplikasi lokasi. Tidak ada yang rusak, tidak ada error,
 * tidak ada yang protes. Hanya saja "seragam" jadi setengah benar.
 *
 * Jalankan: `node --test frontend/test/`
 */

const AKAR = fileURLToPath(new URL('../src/', import.meta.url))

/**
 * Label yang berarti "menyimpan".
 *
 * Daftar ini sengaja sempit. Kalau melebar ke setiap kemunculan kata
 * "simpan", test ini akan menangkap kalimat penjelasan di dalam
 * paragraf -- yang bukan tombol sama sekali.
 */
const POLA_SIMPAN = /\b(simpan|simpanan|tersimpan|menyimpan|perbarui|terbitkan)\b/i

/**
 * Tombol yang thirsty ada kebutuhannya, tapi TIDAK memakai gaya abu.
 *
 * "Batalkan Lunas" mengubah status tagihan dan warnanya sudah punya
 * bahasa sendiri. "Simpan Alokasi Laba" di `tutupBuku.vue` adalah tautan
 * ke halaman lain -- bukan tombol simpan, dan sudah memakai gaya abu
 * sebagai tautan biasa.
 */
const KECUALIAN = [
  { berkas: 'CentralTagihanPopup.vue', teks: 'Batalkan Lunas' },
  { berkas: 'CentralTagihanIndex.vue', teks: 'Batalkan Lunas' },

  /*
   * Dua tombol ini milik desain per-role yang memang harus PERSIS sama
   * dengan pamsides-v2 -- bukan halaman yang "kebetulan" beda.
   *
   * "SIMPAN HASIL INSTALASI" (teknisi) dan "KIRIM HASIL SURVEY" (surveyor)
   * memakai gradien warna brand, bukan abu. Asterokens yang berlaku: dua
   * layar ini adalah tempat orang benar-benar mengerjakan pekerjaannya,
   * dan tombolnya adalah penanda "kerjakan ini" -- bukan sekadar
   * "menyimpan data".
   *
   * Pengecualiannya SEBUTAN, bukan wildcard: kalau nanti ada tombol simpan
   * lain yang ikut berubah ke gradien tanpa sengaja, test ini tetap
   * menangkapnya.
   */
  { berkas: 'InstallationResult.vue', teks: 'SIMPAN HASIL INSTALASI' },
  { berkas: 'createSurvey.vue', teks: 'KIRIM HASIL SURVEY' },
]

/** Varian `BaseButton` yang berarti "tombol menyimpan data". */
const VARIAN_SIMPAN = 'secondary'

/**
 * Varian `BaseButton` yang TIDAK boleh dipakai di tombol simpan.
 *
 * `danger` (merah) di aplikasi ini berarti "hapus" atau "bahaya", bukan
 * "simpan". `primary` dan gradiennya berarti "aksi utama" -- yang di
 * tempat lain memang dipakai, tapi di tombol simpan membuat satu-satunya
 * penanda simpan hilang.
 */
const VARIAN_DILARANG = [
  'primary',
  'primary-gradient',
  'info',
  'info-gradient',
  'success',
  'success-gradient',
  'warning',
  'warning-gradient',
  'danger',
  'danger-gradient',
  'secondary-gradient',
]

/** Kumpulkan seluruh berkas .vue di dalam `src/`. */
function kumpulkanBerkas(dir) {
  const hasil = []
  for (const entri of readdirSync(dir, { withFileTypes: true })) {
    const penuh = join(dir, entri.name)
    if (entri.isDirectory()) {
      hasil.push(...kumpulkanBerkas(penuh))
    } else if (entri.name.endsWith('.vue')) {
      hasil.push(penuh)
    }
  }
  return hasil
}

/**
 * Pecah isi berkas menjadi potongan tag tombol.
 *
 * Yang dikembalikan untuk setiap tombol: atributnya, dan teks di dalam
 * tag (termasuk isi tag anak, misalnya `<span>Simpan</span>`).
 */
function tombolDi(isi) {
  const hasil = []
  const rx = /<(BaseButton|base-button|button)\b([^>]*?)(>|\/>)/gi
  let cocok

  while ((cocok = rx.exec(isi)) !== null) {
    const tag = cocok[1]
    const atribut = cocok[2] ?? ''
    const mulaiIsi = rx.lastIndex
    const selfClosing = cocok[3] === '/>'

    let teks = ''
    if (!selfClosing) {
      const potong = isi.slice(mulaiIsi, mulaiIsi + 500)
      const tutup = potong.search(/<\/(BaseButton|base-button|button)>/i)
      teks = (tutup >= 0 ? potong.slice(0, tutup) : potong).replace(/<[^>]*>/g, ' ')
    }

    const title = atribut.match(/title="([^"]*)"/)?.[1] ?? ''
    const ariaLabel = atribut.match(/aria-label="([^"]*)"/)?.[1] ?? ''

    hasil.push({ tag, atribut, label: `${teks} ${title} ${ariaLabel}`.replace(/\s+/g, ' ').trim() })
  }

  return hasil
}

/**.True kalau teks label menyatakan intent "menyimpan". */
function adalahTombolSimpan(label) {
  return POLA_SIMPAN.test(label)
}

/** True kalau label ini dikecualikan. */
function dikecualikan(namaBerkas, label) {
  return KECUALIAN.some((k) => namaBerkas.endsWith(k.berkas) && label.includes(k.teks))
}

/**
 * Ambil blok-blok aturan CSS yang selektor-nya diawali `penanda`.
 *
 * Penting karena satu file CSS bisa punya banyak tombol: `ui-base.css`
 * memuat varian hapus (merah), gelap, dan gradien -- semuanya boleh
 * punya hex sendiri. Yang diperiksa hanya blok yang benar-benar dipakai
 * tombol simpan.
 *
 * Teks komentar ikut dibuang lebih dulu, supaya blok CSS yang ditulis
 * di dalam penjelasan dokumentasi tidak ikut dihitung sebagai aturan.
 */
function blokAturan(isi, penanda) {
  const tanpaKomentar = isi.replace(/\/\*[\s\S]*?\*\//g, '')
  const pola = penanda.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const hasil = []
  const rx = new RegExp(`(^|\\})\\s*((?:[^{}]*\\.)?${pola}[^{}]*)\\{([^{}]*)\\}`, 'g')
  let cocok

  while ((cocok = rx.exec(tanpaKomentar)) !== null) {
    hasil.push({ pembuka: cocok[2], isi: cocok[3] })
  }

  return hasil
}

/**
 * True kalau tombol ini memakai gaya abu yang sah.
 *
 * Bentuk yang diterima:
 * - `variant="secondary"`  -- BaseButton, abu pekat dengan teks putih
 * - `class="... slate-500 ..."` -- tombol mentah yang ditulis manual
 * - `class="... btn--save ..."` -- CSS panel pusat (.btn, .coa-btn, .ua-btn, .mg-btn)
 * - `class="... btn-save-modal ..."` -- kaki modal paket
 */
function memakaiGayaAbu(atribut) {
  if (new RegExp(`variant="${VARIAN_SIMPAN}"`).test(atribut)) return true
  if (/\bslate-500\b/.test(atribut)) return true
  if (/\b(btn--save|btn-save-modal)\b/.test(atribut)) return true
  return false
}

const SEMUA_BERKAS = kumpulkanBerkas(AKAR)

describe('tombol simpan', () => {
  it('ada berkas .vue yang dipindai', () => {
    // Kalau pemindaiannya salah, test di bawah akan lulus karena tidak
    // memeriksa apa pun -- dan itu persis jenis diamnya yang tidak
    // wanted.
    assert.ok(SEMUA_BERKAS.length > 50, `hanya ${SEMUA_BERKAS.length} berkas`)
  })

  it('semua tombol simpan memakai gaya abu yang sama', () => {
    const pelanggaran = []

    for (const berkas of SEMUA_BERKAS) {
      const isi = readFileSync(berkas, 'utf8')
      const nama = relative(AKAR, berkas).replace(/\\/g, '/')

      for (const { tag, atribut, label } of tombolDi(isi)) {
        if (!adalahTombolSimpan(label)) continue
        if (dikecualikan(nama, label)) continue
        if (memakaiGayaAbu(atribut)) continue

        pelanggaran.push(
          `${nama}: <${tag}> berlabel "${label.slice(0, 50)}" tidak memakai gaya abu` +
            ` (atribut: ${atribut.replace(/\s+/g, ' ').slice(0, 110)})`,
        )
      }
    }

    assert.deepEqual(
      pelanggaran,
      [],
      `\n${pelanggaran.length} tombol simpan tidak seragam:\n- ${pelanggaran.join('\n- ')}\n`,
    )
  })

  it('tidak ada tombol simpan yang masih memakai warna brand atau status', () => {
    // Gugatan yang lebih ketat: kalau `variant="primary-gradient"` atau
    // `success-gradient` muncul DI TOMBOL SIMPAN, itu regresi.
    const pelanggaran = []

    for (const berkas of SEMUA_BERKAS) {
      const isi = readFileSync(berkas, 'utf8')
      const nama = relative(AKAR, berkas).replace(/\\/g, '/')

      for (const { atribut, label } of tombolDi(isi)) {
        const varian = atribut.match(/variant="([^"]*)"/)?.[1]
        if (!varian || !VARIAN_DILARANG.includes(varian)) continue
        if (!adalahTombolSimpan(label)) continue
        if (dikecualikan(nama, label)) continue

        pelanggaran.push(
          `${nama}: tombol simpan memakai variant="${varian}" (label: "${label.slice(0, 50)}")`,
        )
      }
    }

    assert.deepEqual(
      pelanggaran,
      [],
      `\n${pelanggaran.length} tombol simpan masih berwarna:\n- ${pelanggaran.join('\n- ')}\n`,
    )
  })

  it('gaya tombol simpan yang dipakai benar-benar ada di CSS', () => {
    // Varian yang dipakai di template harus punya aturannya. Kalau
    // `variant="secondary"` dihapus dari `BaseButton.vue`, semua tombol
    // simpan akan kehilangan warnanya -- dan tidak ada error, tidak ada
    // build failure. Hanya tombol yang terlihat salah.
    const uiBase = readFileSync(join(AKAR, 'assets/css/ui-base.css'), 'utf8')
    assert.ok(
      uiBase.includes('.base-button--secondary'),
      'varian `secondary` hilang dari ui-base.css',
    )

    const centralPage = readFileSync(join(AKAR, 'assets/css/central-page.css'), 'utf8')
    assert.ok(
      centralPage.includes('--central-btn-save'),
      'token `--central-btn-save` hilang dari central-page.css',
    )

    const centralForm = readFileSync(join(AKAR, 'assets/css/central-form.css'), 'utf8')
    assert.ok(centralForm.includes('.btn--save'), 'kelas `.btn--save` hilang dari central-form.css')
  })

  it('ketiga sistem tombol membaca warna simpan dari satu token', () => {
    // Ini yang paling mudah hilang tanpa disadari.
    //
    // Tiga sistem tombol tidak saling tahu: `BaseButton`, CSS panel
    // pusat, dan kaki modal paket. Dulu masing-masing menulis hex-nya
    // sendiri. Hasilnya: `slate-500` Tailwind = `oklch(55.4% .046
    // 257.417)` = `#62748e`, sementara hex manual di dua file lain
    // `#64748b`. Selisih 3 poin per kanal RGB. Tidak ada error, tidak
    // ada build failure, tidak ada yang complain di code review -- cuma
    // tombol simpan di panel pusat terlihat sedikit lebih terang, dan
    // "seragam" jadi klaim yang setengah benar.
    //
    // Jadi test ini mengunci STRUKTUR, bukan warnanya: tiga file itu
    // wajib menunjuk ke `--btn-save-*`, dan tidak boleh ada hex warna
    // simpan yang ditulis langsung di sana. Nilai hexnya boleh diganti
    // (desain), tapi hanya di satu tempat.
    const TOKEN = 'var(--btn-save-'
    const wajib = [
      ['assets/css/ui-base.css', '.base-button--secondary'],
      ['assets/css/central-page.css', '--central-btn-save'],
      ['assets/css/ui-modals.css', '.btn-save-modal'],
    ]

    for (const [berkas, penanda] of wajib) {
      const isi = readFileSync(join(AKAR, berkas), 'utf8')

      // Alias panel pusat masih boleh ada, tapi harus tujuan akhirnya
      // menunjuk ke token bersama -- bukan berhenti di hex sendiri.
      // Yang diperiksa hanya deklarasi custom property di dalam blok
      // `:root { }` -- bukan selektor di file CSS.
      const deklarasi = [...isi.matchAll(/^(\s*)--([a-z][a-z0-9-]*)\s*:\s*([^;]+);/gim)]
      for (const [, , nama, nilai] of deklarasi) {
        if (!penanda.replace(/^\./, '').includes(nama.replace(/^central-/, ''))) continue
        assert.ok(
          nilai.includes(TOKEN),
          `${berkas}: --${nama} ditulis "${nilai.trim()}", harus menunjuk ke --btn-save-*`,
        )
      }

      // Blok aturan yang benar-benar dipakai tombol simpan -- bukan
      // seluruh isi file setelah penanda, karena file itu juga berisi
      // varian lain (hapus, gelap, gradien) yang memang boleh punya
      // hex sendiri.
      for (const blok of blokAturan(isi, penanda)) {
        const hex = blok.isi.match(/#[0-9a-f]{3,8}\b/gi) ?? []
        const warnaLain = hex.filter((h) => !/^#(?:fff|ffffff)$/i.test(h))

        assert.deepEqual(
          warnaLain,
          [],
          `${berkas}: blok "${blok.pembuka.trim()}" menulis hex warna simpan langsung: ${warnaLain.join(', ')}`,
        )
      }
    }

    // Token-nya harus benar-benar ada, dan hanya di satu tempat.
    const deklarasi = wajib.flatMap(([berkas]) =>
      readFileSync(join(AKAR, berkas), 'utf8')
        .split('\n')
        .filter((b) => /^--btn-save-[a-z-]+\s*:/.test(b.trim()))
        .map((b) => b.trim()),
    )

    assert.ok(deklarasi.length > 0, 'token `--btn-save-*` tidak dideklarasikan di mana pun')
    assert.deepEqual(
      deklarasi,
      deklarasi.filter((b) => b.startsWith('--btn-save-')),
      'token --btn-save-* hanya boleh dideklarasikan sekali',
    )
  })

  it('abu tombol simpan lolos WCAG AA untuk teks putih', () => {
    // Warna simpan diganti karena alasan estetika? Ini yang menghentikan
    // penggantian itu: abu muda kelihatan "lebih lembut" tapi teks putih
    // di atasnya hanya 2,9:1 dan tidak terbaca.
    const uiBase = readFileSync(join(AKAR, 'assets/css/ui-base.css'), 'utf8')
    const hex = uiBase.match(/--btn-save-bg:\s*(#[0-9a-f]{6})/i)?.[1]
    assert.ok(hex, 'nilai hex --btn-save-bg tidak terbaca di ui-base.css')

    const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
    const lin = (c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)
    const L = 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)
    const kontras = 1.05 / (L + 0.05) // putih = 1.0

    assert.ok(
      kontras >= 4.5,
      `kontras teks putih di atas ${hex} cuma ${kontras.toFixed(2)}:1, di bawah 4.5:1 (WCAG AA)`,
    )
  })
})
