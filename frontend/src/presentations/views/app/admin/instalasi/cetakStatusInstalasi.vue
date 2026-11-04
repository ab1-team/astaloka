<template>
  <div class="preview-shell" :class="orientationClass" :style="{ '--page-size': pageSizeValue }">
    <div class="preview-toolbar no-print">
      <div class="toolbar-left">
        <button
          class="toolbar-menu-btn no-print"
          @click="showSidebar = !showSidebar"
          title="Tampilkan / sembunyikan thumbnail"
        >
          <span></span><span></span><span></span>
        </button>
        <h3 class="title">Cetak Status Instalasi - {{ labelKategori }}</h3>
      </div>

      <div class="toolbar-right">
        <div v-if="pages.length > 0" class="page-indicator">
          <span>Halaman {{ activePage + 1 }} / {{ pages.length }}</span>
        </div>

        <button class="print-btn" @click="cetak" title="Cetak dokumen ini (printer atau PDF)">
          <font-awesome-icon icon="print" />
          <span>Cetak</span>
        </button>

        <div v-if="pages.length > 0" class="zoom-controls no-print">
          <button
            class="zoom-btn"
            @click="zoomOut"
            :disabled="zoomLevel <= minZoom"
            title="Zoom Out"
          >
            <span>-</span>
          </button>
          <span class="zoom-percent active" @click="resetZoom" title="Reset zoom">
            {{ zoomPercent }}%
          </span>
          <button class="zoom-btn" @click="zoomIn" :disabled="zoomLevel >= maxZoom" title="Zoom In">
            <span>+</span>
          </button>
        </div>
      </div>
    </div>

    <div v-if="errorMsg" class="alert-error no-print">{{ errorMsg }}</div>

    <!--
      PROBE OFF-SCREEN: untuk mengukur tinggi baris tabel yang SEBENARNYA.

      Jumlah baris per halaman tidak bisa ditebak. Satu baris bisa satu
      baris teks atau dua, tergantung panjang nama pelanggan dan
      alamatnya -- dan itu berubah-ubah dari satu data ke data lain.

      Kalau jumlahnya ditebak dan meleset, akibatnya bukan "berjarak
      sedikit": halaman meluber ke halaman fisik berikutnya, jadi
      kop surat hilang di halaman sisanya dan nomor halaman jadi
      tidak sinkron dengan isi.

      Jadi tabel dirender sekali di luar layar (lebarnya sama persis
      dengan halaman A4 landscape, supaya pemenggalan teks sama),
      tinggi tiap barisnya diukur, lalu baris dikemas ke halaman
      berdasarkan tinggi yang benar-benar tersedia.

      Ada DUA probe, karena dua bentuk halaman punya ruang berbeda:

        - Probe pertama  : halaman PERTAMA (ada judul + blok meta).
        - Probe lanjutan : halaman LANJUTAN (hanya kop + kepala tabel).

      Ruang kosong di atas tabel diukur langsung untuk keduanya, jadi
      tidak perlu menebak berapa tinggi kop surat atau kepala tabel.

      `visibility: hidden` + `position: absolute` supaya tidak terlihat
      user, tapi DOM dan layout tetap aktif -- tanpa itu, `offsetHeight`
      selalu 0.
    -->
    <div v-if="probeData || probeDataLanjut" class="status-probe no-print" aria-hidden="true">
      <div v-if="probeData" ref="probeEl" class="status-probe-inner">
        <component :is="ReportView" :payload="probeData" :meta="{ page: 1, total: 1 }" />
      </div>

      <div v-if="probeDataLanjut" ref="probeElLanjut" class="status-probe-inner">
        <component :is="ReportView" :payload="probeDataLanjut" :meta="{ page: 2, total: 2 }" />
      </div>
    </div>

    <div class="workspace-container">
      <div v-show="showSidebar" class="thumbnail-sidebar no-print">
        <div
          v-for="(page, i) in pages"
          :key="'thumb-' + i"
          class="thumb-wrapper"
          :class="[orientationClass, { active: activePage === i }]"
          @click="scrollToPage(i)"
        >
          <div class="thumb-paper">
            <div class="thumb-scale-container">
              <component
                v-if="shouldRenderThumb(i)"
                :is="ReportView"
                :payload="page.payload"
                :meta="page.meta"
                class="thumb-real-component"
              />
              <div v-else class="thumb-placeholder">
                <span>{{ i + 1 }}</span>
              </div>
            </div>
            <div class="thumb-overlay"></div>
          </div>
          <span class="thumb-number">{{ i + 1 }}</span>
        </div>
        <div v-if="pages.length === 0 && !isLoading" class="thumb-empty">
          Belum ada data status instalasi
        </div>
      </div>

      <div ref="stageEl" class="preview-stage" @scroll.passive="onStageScroll">
        <div ref="reportRoot" class="report-root">
          <div
            v-for="(page, i) in pages"
            :key="i"
            class="report-page-wrap"
            :id="'report-page-' + i"
            :style="{
              width: pageScaledWidth(PAGE_CONFIG) + 'px',
              height: pageScaledHeight(PAGE_CONFIG) + 'px',
              '--page-scale': pageScale(PAGE_CONFIG),
            }"
          >
            <component
              :is="ReportView"
              :id="'page-' + i"
              :payload="page.payload"
              :meta="page.meta"
            />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import ReportView from '@/presentations/views/app/admin/instalasi/partials/ReportCetakStatusInstalasi.vue'
import { useInstalasiStatus } from '@/composables/useInstalasiStatus'
import { usePdfPreview, THUMB_RENDER_BUFFER } from '@/composables/usePdfPreview'
import { useIdentitasLembaga, ensureIdentitas } from '@/composables/useIdentitasLembaga'
import { INSTALASI_MENU_LIST } from '@/types/instalasiStatus'
import { packRows, kePotongan } from '@/utils/instalasiCetak'

/*
 * HALAMAN CETAK STATUS INSTALASI
 * ===============================
 *
 * Halaman ini adalah hasil tombol "Cetak Table" di
 * `/app/instalasi/status`.
 *
 * Kenapa halaman tersendiri, bukan `window.print()` langsung:
 *
 *   1. Format cetakannya harus sama dengan laporan modul Pelaporan --
 *      kop surat, judul, tabel ber-border, nomor halaman. HTML yang
 *      dirakit dengan `document.write()` (cara lama) tidak punya satu pun
 *      dari itu.
 *   2. Isi cetakan harus SELURUH baris yang lolos filter, bukan hanya
 *      halaman tabel yang sedang terlihat di layar.
 *
 * Data dimuat ulang di sini (bukan diambil dari state halaman asal),
 * karena halaman ini dibuka di TAB BARU: state Pinia halaman asal tidak
 * ikut ke tab baru. Query string `filter` dan `q` membawa apa yang
 * sedang aktif di layar.
 */

const route = useRoute()

const PAGE_CONFIG = { paper_size: 'A4', orientation: 'landscape' }

/**
 * 1mm berapa px pada 96 DPI.
 *
 * Dipakai untuk mengubah ukuran kertas (dalam mm) menjadi px, supaya
 * bisa dibandingkan dengan tinggi elemen yang diukur dari DOM.
 */
const PX_PER_MM = 96 / 25.4

/**
 * Tinggi kertas A4 landscape dalam px.
 *
 * A4 landscape: 297mm x 210mm. Yang dipakai adalah sisi TINGGI (210mm).
 */
const TINGGI_KERTAS_PX = 210 * PX_PER_MM

/**
 * Cadangan jumlah baris per halaman.
 *
 * Dipakai HANYA kalau pengukuran gagal (mis. probe tidak sempat ter-mount,
 * atau `offsetHeight` mengembalikan 0 di lingkungan tanpa layout engine).
 *
 * Nilainya sengaja konservatif: asumsikan SETIAP baris membungkus jadi
 * dua baris teks. Kalau meleset, halaman meluber ke halaman fisik
 * berikutnya -- isi tidak hilang, tapi kop surat halaman lanjutan hilang
 * dan nomor halaman jadi tidak sinkron. Itu jauh lebih buruk daripada
 * cetakan yang sedikit berjarak.
 */
const CADANGAN_BARIS_HALAMAN_PERTAMA = 19
const CADANGAN_BARIS_HALAMAN_NEXT = 21

/**
 * Ruang aman (px) yang dikurangi dari tinggi yang tersedia.
 *
 * Pembulatan antar-browser dan perbedaan subpixel bikin pengukuran bisa
 * meleset beberapa piksel. Tanpa pengurangan ini, satu baris bisa saja
 * meluber -- dan itu persis yang mau dihindari.
 */
const RUANG_AMAN_PX = 6

const isLoading = ref(true)
const errorMsg = ref('')
const pages = ref([])
const reportRoot = ref(null)
const stageEl = ref(null)
const activePage = ref(0)
const showSidebar = ref(true)

/* Probe off-screen untuk mengukur tinggi baris tabel. */
const probeEl = ref(null)
const probeData = ref(null)
const probeElLanjut = ref(null)
const probeDataLanjut = ref(null)

const { activeStatus, searchQuery, activeLabel, filteredData, fetchData, fetchError } =
  useInstalasiStatus()

const { namaLembaga, alamat, telepon, email, skKemenkumham, logoUrl, initials } =
  useIdentitasLembaga()

const shouldRenderThumb = (i) => Math.abs(i - activePage.value) <= THUMB_RENDER_BUFFER

const {
  zoomLevel,
  zoomPercent,
  minZoom,
  maxZoom,
  zoomIn,
  zoomOut,
  resetZoom,
  pageScale,
  pageScaledWidth,
  pageScaledHeight,
} = usePdfPreview(stageEl, PAGE_CONFIG)

const orientationClass = computed(() => PAGE_CONFIG.orientation)

/**
 * Ukuran kertas untuk aturan `@page` saat print.
 *
 * `@page` tidak bisa memakai selector kelas, jadi nilainya diteruskan
 * lewat custom property yang di-set di elemen root. Browser membacanya
 * saat mencetak.
 *
 * Kenapa perlu ada, dan bukan `@page { size: A4 landscape }` biasa:
 * aturan `@page` bersifat GLOBAL untuk seluruh dokumen. Modul Pelaporan
 * juga punya aturan `@page` miliknya sendiri (yang memakai
 * `var(--page-size)`), dan aturan yang dimuat TERAKHIR menang. Tanpa
 * custom property di sini, cetakan bisa ikut jadi A4 portrait -- tabel
 * sepuluh kolom lalu terpotong.
 */
const pageSizeValue = computed(() => {
  const isLandscape = PAGE_CONFIG.orientation === 'landscape'
  return isLandscape ? 'A4 landscape' : 'A4 portrait'
})

const labelKategori = computed(() => activeLabel.value || 'Semua Status')

/**
 * Identitas lembaga untuk kop surat.
 *
 * Diambil dari `useIdentitasLembaga` -- sumber yang sama dengan modul
 * Pelaporan. Identitas TIDAK boleh ditulis manual di sini: kop surat
 * harus ikut berubah begitu admin mengubah profil lembaga di Pengaturan.
 */
const lembaga = computed(() => ({
  nama: namaLembaga.value,
  nama_lembaga: namaLembaga.value,
  alamat: alamat.value,
  telepon: telepon.value,
  email: email.value,
  sk_kemenkumham: skKemenkumham.value,
  logo: logoUrl.value,
  initials: initials.value,
}))

/**
 * Pecah baris menjadi halaman.
 *
 * Yang dipotong adalah `filteredData` -- SELURUH baris yang lolos filter
 * pencarian, bukan `paginatedData` (isi halaman tabel yang sedang
 * terlihat). Kalau yang dicetak hanya halaman yang terlihat, dokumennya
 * tidak lengkap padahal tabelnya terlihat utuh.
 *
 * `batas` (hasil pengukuran probe) berisi tinggi tiap baris dan sisa
 * tinggi untuk halaman pertama / halaman lanjutan. Kalau `null`, dipakai
 * jumlah baris cadangan yang konservatif.
 */
const buildPages = (batas = null) => {
  const rows = filteredData.value || []

  if (rows.length === 0) {
    pages.value = []
    return
  }

  const chunks = []

  if (batas && batas.tinggiBaris.length === rows.length) {
    chunks.push(...kemaskBaris(batas))
  } else {
    const firstEnd = Math.min(CADANGAN_BARIS_HALAMAN_PERTAMA, rows.length)

    chunks.push({ items: rows.slice(0, firstEnd), startIndex: 0 })

    let cursor = firstEnd

    while (cursor < rows.length) {
      const end = Math.min(cursor + CADANGAN_BARIS_HALAMAN_NEXT, rows.length)
      chunks.push({ items: rows.slice(cursor, end), startIndex: cursor })
      cursor = end
    }
  }

  const total = rows.length

  pages.value = chunks.map((chunk, i) => ({
    payload: {
      config: PAGE_CONFIG,
      items: chunk.items,
      startIndex: chunk.startIndex,
      showMeta: i === 0,
      total,
      label: labelKategori.value,
      search: (searchQuery.value || '').trim(),
      lembaga: lembaga.value,
    },
    meta: {
      page: i + 1,
      total: chunks.length,
    },
  }))
}

/**
 * Kemas baris ke halaman berdasarkan tinggi yang terukur.
 *
 * Greedy: baris ditarik satu per satu selama masih muat di halaman
 * yang sedang dibangun. Kalau baris berikutnya tidak muat, halaman
 * ditutup dan baris itu membuka halaman baru.
 *
 * Tinggi baris IKUT diukur per baris, bukan pakai rata-rata. Baris
 * satu baris teks (16px) dan baris dua baris teks (28px) bedanya jauh
 * -- memakai rata-rata akan membuat halaman yang berisi baris pendek
 * meluber.
 *
 * Satu baris yang sendirian lebih tinggi dari halaman yang tersedia tetap
 * diletakkan di halamannya sendiri. Memotong satu baris jadi dua
 * halaman justru lebih buruk: barisnya jadi tidak terbaca.
 */
const kemaskBaris = (batas) => {
  const kelompok = packRows(batas.tinggiBaris, batas.tersediaPertama, batas.tersediaBerikutnya)

  return kePotongan(filteredData.value || [], kelompok)
}

/**
 * Ukur tinggi baris tabel dan sisa tinggi yang tersedia per halaman.
 *
 * Cara kerjanya:
 *
 *   1. Render tabel dengan SEMUA baris di dalam probe off-screen --
 *      dua kali: sekali dengan blok judul+meta (bentuk halaman PERTAMA)
 *      dan sekali tanpanya (bentuk halaman LANJUTAN).
 *   2. Ukur tinggi tiap `<tr>` di `tbody`.
 *   3. Ukur ruang kosong di atas tabel: dari tepi atas area konten
 *      sampai baris pertama tabel. Di situ sudah terhitung kop surat,
 *      judul, meta, margin atas tabel, dan kepala tabel.
 *   4. Sisa tinggi kertas dikurangi ruang kosong itu dan tinggi footer
 *      -- sisa itulah yang boleh dipakai baris data.
 *
 * Kenapa dua probe: halaman pertama memuat judul dan blok meta, halaman
 * lanjutan tidak. Kalau ruang kosong di atas tabel hanya dihitung
 * dari komponen-komponennya (kop + margin + kepala tabel), hasilnya
 * meleset begitu ada elemen lain yang ikut berubah -- dan itu yang
 * membuat halaman meluber.
 *
 * Padding halaman dibaca dari style yang benar-benar dipakai, bukan
 * ditebak: kalau aturan scoped ternyata tidak berlaku, padding-nya
 * berbeda dan pengukurannya harus ikut berbeda.
 *
 * @returns {Promise<{tinggiBaris: number[], tersediaPertama: number, tersediaBerikutnya: number}|null>}
 */
const ukurBaris = async () => {
  const rows = filteredData.value || []

  if (rows.length === 0) return null

  const payloadDasar = {
    config: PAGE_CONFIG,
    items: rows,
    startIndex: 0,
    total: rows.length,
    label: labelKategori.value,
    search: (searchQuery.value || '').trim(),
    lembaga: lembaga.value,
  }

  probeData.value = { ...payloadDasar, showMeta: true }

  /*
   * Probe lanjutan hanya butuh SATU baris.
   *
   * Yang diukur darinya cuma ruang kosong di atas tabel (kop surat +
   * kepala tabel) dan tinggi footer. Tinggi baris data diambil dari
   * probe pertama, jadi bringing semua baris ke sini cuma menambah
   * pekerjaan browser tanpa menambah ketepatan.
   */
  probeDataLanjut.value = { ...payloadDasar, items: rows.slice(0, 1), showMeta: false }

  await nextTick()

  // Beri waktu browser menyelesaikan layout (gambar logo, font) sebelum
  // diukur. Tanpa jeda, `offsetHeight` masih memakai tinggi sebelum
  // logo selesai dimuat -- dan baris bisa terukur terlalu pendek.
  await new Promise((resolve) => setTimeout(resolve, 150))

  const root = probeEl.value
  const rootLanjut = probeElLanjut.value

  const halaman = root ? root.querySelector('.report-page') : null
  const halamanLanjut = rootLanjut ? rootLanjut.querySelector('.report-page') : null
  const tbody = root ? root.querySelector('tbody') : null
  const tbodyLanjut = rootLanjut ? rootLanjut.querySelector('tbody') : null
  const footer = root ? root.querySelector('.page-footer') : null

  const tinggiBaris = tbody
    ? Array.from(tbody.querySelectorAll('tr')).map((tr) => tr.getBoundingClientRect().height)
    : []

  // Probe sudah selesai dipakai. Dilepas SEBELUM keluar dari fungsi ini
  // supaya DOM-nya tidak ikut terukur saat halaman disusun.
  probeData.value = null
  probeDataLanjut.value = null

  if (
    !halaman ||
    !halamanLanjut ||
    !tbody ||
    !tbodyLanjut ||
    tinggiBaris.length !== rows.length ||
    tinggiBaris.length === 0
  ) {
    return null
  }

  const gayaHalaman = window.getComputedStyle(halaman)
  const paddingAtas = parseFloat(gayaHalaman.paddingTop) || 0
  const paddingBawah = parseFloat(gayaHalaman.paddingBottom) || 0

  // Tinggi area yang boleh dipakai di dalam kertas.
  const areaCetak = TINGGI_KERTAS_PX - paddingAtas - paddingBawah

  const tinggiFooter = footer ? footer.getBoundingClientRect().height : 0

  // Ruang kosong di atas tabel, diukur dari bentuk halaman yang
  // sebenarnya (pertama dan lanjutan masing-masing).
  const atasKonten = halaman.getBoundingClientRect().top + paddingAtas
  const ruangPertama = tbody.getBoundingClientRect().top - atasKonten

  const atasKontenLanjut = halamanLanjut.getBoundingClientRect().top + paddingAtas
  const ruangBerikutnya = tbodyLanjut.getBoundingClientRect().top - atasKontenLanjut

  const tersediaPertama = areaCetak - ruangPertama - tinggiFooter - RUANG_AMAN_PX
  const tersediaBerikutnya = areaCetak - ruangBerikutnya - tinggiFooter - RUANG_AMAN_PX

  if (tersediaPertama <= 0 || tersediaBerikutnya <= 0) {
    return null
  }

  return {
    tinggiBaris,
    tersediaPertama,
    tersediaBerikutnya,
  }
}

const scrollToPage = (idx) => {
  const element = document.getElementById(`report-page-${idx}`)
  const stage = stageEl.value
  if (!element || !stage) return

  const stageRect = stage.getBoundingClientRect()
  const elRect = element.getBoundingClientRect()

  stage.scrollTo({
    top: elRect.top - stageRect.top + stage.scrollTop,
    behavior: 'smooth',
  })

  activePage.value = idx
}

const onStageScroll = () => {
  const stage = stageEl.value
  if (!stage) return

  const center = stage.scrollTop + stage.clientHeight / 2
  let nearest = 0
  let nearestDist = Infinity

  for (let i = 0; i < pages.value.length; i++) {
    const el = document.getElementById(`report-page-${i}`)
    if (!el) continue

    const dist = Math.abs(el.offsetTop - center)
    if (dist < nearestDist) {
      nearestDist = dist
      nearest = i
    }
  }

  if (activePage.value !== nearest) activePage.value = nearest
}

/** Panggil dialog cetak browser. */
const cetak = () => {
  window.print()
}

/*
 * Custom property `--page-size` juga diset di elemen `<html>`.
 *
 * Aturan `@page` dibaca browser terhadap AKAR DOKUMEN, bukan terhadap
 * elemen tempat aturan itu ditulis. Kalau nilainya hanya ada di
 * `.preview-shell`, browser bisa saja membaca custom property yang
 * tidak ada -- hasilnya `@page` jatuh ke nilai cadangan, dan cetakan
 * keluar A4 portrait padahal tabelnya landscape.
 *
 * Ditetapkan di sini (bukan di style global) supaya pengaruhnya hanya
 * untuk halaman ini: modul Pelaporan punya aturan `@page`-nya sendiri.
 */
onMounted(() => {
  document.documentElement.style.setProperty('--page-size', pageSizeValue.value)
})

onBeforeUnmount(() => {
  document.documentElement.style.removeProperty('--page-size')
})

onMounted(async () => {
  try {
    /*
     * Filter dan pencarian diteruskan lewat query string.
     *
     * `filter` = kategori tab yang sedang aktif.
     * `q` = kata kunci pencarian yang sedang diketik.
     *
     * Nilainya divalidasi: query string bisa disunting bebas oleh user,
     * jadi kategori yang tidak dikenal TIDAK boleh diteruskan ke store
     * (di sana `activeStatus` menentukan data mana yang dimuat).
     */
    const filter = route.query.filter
    const q = route.query.q

    if (INSTALASI_MENU_LIST.some((m) => m.key === filter)) {
      activeStatus.value = filter
    }

    if (typeof q === 'string') {
      searchQuery.value = q
    }

    document.title = `Cetak Status Instalasi - ${labelKategori.value}`

    // Identitas kop surat dimuat paralel dengan data tabel.
    await Promise.all([ensureIdentitas(), fetchData()])

    if (fetchError.value) {
      errorMsg.value = fetchError.value
    }

    /*
     * Pengukuran baris dilakukan DULUAN sebelum halaman disusun.
     *
     * Hasilnya dipakai untuk memecah baris ke halaman. Kalau pengukuran
     * gagal, `buildPages` memakai jumlah baris cadangan yang konservatif
     * -- cetakan tetap utuh, hanya sedikit lebih jarang.
     */
    const batas = await ukurBaris()

    buildPages(batas)

    if (pages.value.length === 0 && !errorMsg.value) {
      errorMsg.value = 'Tidak ada data status instalasi yang sesuai dengan filter.'
    }
  } catch (err) {
    errorMsg.value = err?.message || 'Gagal memuat data status instalasi.'
  } finally {
    isLoading.value = false
  }
})
</script>

<style scoped>
/*
 * Probe off-screen untuk pengukuran tinggi baris.
 *
 * `visibility: hidden` + `position: absolute` supaya tidak terlihat
 * user, tapi DOM dan layout tetap aktif -- tanpa itu, `offsetHeight` dan
 * `getBoundingClientRect()` selalu mengembalikan 0 dan pengukuran jadi
 * sia-sia.
 *
 * Lebarnya dikunci ke 297mm (A4 landscape) supaya pemenggalan teks di
 * dalam kolom sama persis dengan yang terjadi di halaman yang sungguhan.
 * Kalau lebarnya beda, baris yang terukur tidak sama dengan baris yang
 * dicetak -- dan pengukurannya jadi tidak berguna.
 */
.status-probe {
  position: absolute;
  top: 0;
  left: 0;
  width: 297mm;
  visibility: hidden;
  pointer-events: none;
  z-index: -1;
  overflow: visible;
}

.status-probe-inner {
  width: 297mm;
}

.preview-shell {
  /*
   * `position: relative` supaya probe off-screen jadi anak dari shell,
   * bukan dari viewport. Tanpa itu, probe bisa menambah tinggi
   * dokumen dan memunculkan scrollbar yang tidak ada gunanya.
   */
  position: relative;
  background: #312c2c;
  height: 100vh;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.preview-toolbar {
  position: sticky;
  top: 0;
  z-index: 50;
  background: #424242;
  border-bottom: 1px solid #2c2e31;
  padding: 12px 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.toolbar-left {
  display: flex;
  align-items: center;
  padding-left: 8px;
}

.toolbar-right {
  display: flex;
  align-items: center;
  gap: 14px;
}

.title {
  font-size: 0.95rem;
  font-weight: 600;
  margin: 0;
  color: #f8fafc;
  letter-spacing: 0.5px;
}

.toolbar-menu-btn {
  display: inline-flex;
  flex-direction: column;
  justify-content: space-between;
  width: 28px;
  height: 22px;
  padding: 4px 4px;
  background: transparent;
  border: 1px solid #475569;
  border-radius: 4px;
  cursor: pointer;
  margin-right: 12px;
}
.toolbar-menu-btn span {
  display: block;
  width: 100%;
  height: 2px;
  background: #f8fafc;
  border-radius: 1px;
  transition: background 0.15s ease;
}
.toolbar-menu-btn:hover {
  background: #525050;
  border-color: #94a3b8;
}

.print-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 7px 14px;
  border-radius: 8px;
  border: 1px solid #38bdf8;
  background: #0284c7;
  color: #ffffff;
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s ease;
}
.print-btn:hover {
  background: #0369a1;
}

.zoom-controls {
  display: flex;
  align-items: center;
  background: #2c2e31;
  padding: 4px;
  border-radius: 9px;
  user-select: none;
}

.zoom-btn {
  width: 30px;
  height: 30px;
  border-radius: 77px;
  color: #f8fafc;
  border: none;
  font-size: 1.2rem;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s ease;
}

.zoom-btn:hover {
  background: #525050;
}

.zoom-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.zoom-percent {
  min-width: 56px;
  text-align: center;
  color: #f8fafc;
  font-size: 0.9rem;
  font-weight: 600;
  cursor: pointer;
  padding: 0px 10px;
  border-radius: 999px;
  background: transparent;
  transition:
    background 0.15s ease,
    color 0.15s ease;
}

.zoom-percent.active:hover {
  background: #525050;
}

.page-indicator {
  color: #cbd5e1;
  font-size: 0.85rem;
  background: #2c2e31;
  padding: 4px 12px;
  border-radius: 6px;
}

.workspace-container {
  display: flex;
  flex: 1;
  height: calc(100vh - 57px);
  overflow: hidden;
}

.thumbnail-sidebar {
  width: 240px;
  background: #2c2e31;
  border-right: 1px solid #979797;
  padding: 20px 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 18px;
  overflow-y: auto;
  overflow-x: hidden;
  user-select: none;
  scrollbar-width: thin;
  scrollbar-color: #5a5e63 #1f2123;
}

.thumbnail-sidebar::-webkit-scrollbar {
  width: 14px;
  height: 14px;
}

.thumbnail-sidebar::-webkit-scrollbar-track {
  background: #1f2123;
  border-radius: 8px;
  margin: 4px 0;
}

.thumbnail-sidebar::-webkit-scrollbar-thumb {
  background: #5a5e63;
  border-radius: 8px;
  border: 3px solid #1f2123;
  min-height: 40px;
}

.thumbnail-sidebar::-webkit-scrollbar-thumb:hover {
  background: #7a7e83;
}

.thumbnail-sidebar::-webkit-scrollbar-thumb:active {
  background: #38bdf8;
}

.thumb-wrapper {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  cursor: pointer;
}

.thumb-wrapper.landscape .thumb-paper {
  width: 195px;
  height: 140px;
}

.thumb-wrapper.landscape .thumb-scale-container {
  width: 1120px;
  height: 790px;
  transform: scale(0.174);
}

.thumb-paper {
  position: relative;
  background: #ffffff;
  border: 2px solid #475569;
  border-radius: 3px;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.4);
  overflow: hidden;
}

.thumb-wrapper:hover .thumb-paper {
  border-color: #38bdf8;
}

.thumb-wrapper.active .thumb-paper {
  border-color: #38bdf8;
  box-shadow:
    0 0 0 2px #38bdf8,
    0 4px 10px rgba(0, 0, 0, 0.4);
}

.thumb-wrapper.active .thumb-number {
  color: #38bdf8;
}

.thumb-scale-container {
  position: absolute;
  top: 0;
  left: 0;
  transform-origin: top left;
  pointer-events: none;
}

.thumb-real-component {
  width: 100% !important;
  height: 100% !important;
  background: #ffffff !important;
  overflow: hidden !important;
}

.thumb-overlay {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  z-index: 10;
  background: transparent;
}

.thumb-number {
  color: #94a3b8;
  font-size: 0.78rem;
  font-weight: 600;
  max-width: 140px;
  text-align: center;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.thumb-empty {
  color: #94a3b8;
  font-size: 0.8rem;
  padding: 24px 12px;
  text-align: center;
  font-style: italic;
}

.preview-stage {
  flex: 1 1 0;
  min-width: 0;
  height: 100%;
  overflow: auto;
  padding: 10px 0;
  background: #2c2e31;
  scroll-behavior: smooth;
  position: relative;
}

.preview-stage::-webkit-scrollbar {
  width: 14px;
  height: 14px;
}
.preview-stage::-webkit-scrollbar-track {
  background: #1f2123;
  border-radius: 8px;
  margin: 4px 0;
}
.preview-stage::-webkit-scrollbar-track:horizontal {
  margin: 0 4px;
}
.preview-stage::-webkit-scrollbar-thumb {
  background: #5a5e63;
  border-radius: 8px;
  border: 3px solid #1f2123;
  min-height: 40px;
  min-width: 40px;
}
.preview-stage::-webkit-scrollbar-thumb:hover {
  background: #7a7e83;
}
.preview-stage::-webkit-scrollbar-thumb:active {
  background: #38bdf8;
}
.preview-stage {
  scrollbar-width: thin;
  scrollbar-color: #5a5e63 #1f2123;
}

.report-root {
  display: flex;
  flex-direction: column;
  gap: 24px;
  align-items: center;
  padding: 0 16px;
  width: max-content;
  min-width: 100%;
  margin: 0 auto;
}

.report-page-wrap {
  display: block;
  position: relative;
  flex-shrink: 0;
  margin-bottom: 24px;
  /* Jangan `overflow: hidden` di layar: kalau isi tabel sedikit lebih
     panjang dari A4, baris terakhir akan terpotong tanpa tanda. */
  overflow: visible;
  page-break-after: always;
  break-after: page;
}

.report-page-wrap:last-child {
  margin-bottom: 0;
  page-break-after: auto;
  break-after: auto;
}

.report-page-wrap :deep(.report-page) {
  margin: 0 auto !important;
  transform-origin: top center;
  transform: scale(var(--page-scale, 1));
}

/* Penting: jangan kunci `height` halaman di mode layar.
   Kalau tinggi halaman dikunci 210mm sementara isi tabel (jumlah baris
   variabel) lebih panjang, sisa baris terakhir TERPOTONG di preview --
   dan karena `overflow: hidden` tidak ada tanda-tandanya, user mengira
   datanya hilang. Tinggi di sini hanya `min-height`, jadi halaman tetap
   seukuran A4 tapi isinya tidak pernah terpotong. */
.report-page-wrap :deep(.report-page.surat-page.size-a4.landscape) {
  min-height: 210mm;
  height: auto;
}

.alert-error {
  background: #fee2e2;
  color: #991b1b;
  border-radius: 12px;
  padding: 10px 14px;
  margin: 16px;
  font-size: 0.9rem;
}

@media print {
  .no-print {
    display: none !important;
  }

  /* PENTING: .preview-shell di layar web memakai `height: 100vh` +
     `overflow: hidden`. Kalau itu ikut terbawa ke media print, Chrome
     mem-view dokumen sebagai satu "layar" dan tidak mem-fragmentasi
     isinya, sehingga semua halaman laporan keluar sebagai 1 halaman PDF
     dan sisanya terpotong. */
  .preview-shell {
    background: #ffffff;
    padding: 0;
    height: auto !important;
    overflow: visible !important;
  }
  .workspace-container {
    height: auto !important;
    overflow: visible !important;
  }
  .preview-stage {
    padding: 0;
    height: auto !important;
    overflow: visible !important;
  }

  /* Flex container tidak bisa dipaginasi dengan rapi oleh Chrome saat
     print, jadi diubah jadi block agar tiap .report-page-wrap menjadi
     satu halaman PDF. */
  .report-root {
    display: block !important;
    gap: 0 !important;
    padding: 0 !important;
    width: auto !important;
    min-width: 0 !important;
    margin: 0 !important;
  }

  /* .report-page-wrap memakai style inline width/height dalam PX hasil
     zoom preview. Nilai itu harus ditimpa supaya ukuran cetaknya tepat
     A4 (297mm x 210mm). */
  .report-page-wrap {
    width: 297mm !important;
    height: auto !important;
    margin: 0 !important;
    overflow: visible !important;
    zoom: 1 !important;
    page-break-after: always;
    break-after: page;
  }
  .report-page-wrap:last-child {
    margin-bottom: 0;
    page-break-after: auto;
    break-after: auto;
  }

  /* Tinggi halaman TIDAK dikunci saat print.
     Kalau `height: 210mm` + `overflow: hidden`, baris tabel yang sedikit
     melebihi area cetak akan TERPOTONG dan hilang dari PDF. Dengan
     `height: auto` + `min-height`, isi tetap utuh; pemenggalan halaman
     tetap dijamin oleh `break-after: page` di .report-page-wrap dan
     `@page { size: A4 }`. */
  .report-page-wrap :deep(.report-page.surat-page.size-a4.landscape) {
    transform: none !important;
    margin: 0 !important;
    width: 297mm !important;
    min-height: 210mm !important;
    height: auto !important;
    overflow: visible !important;
  }
  /*
    Ukuran kertas.

    `var(--page-size)` -- bukan ukuran yang ditulis langsung -- supaya
    aturan ini tidak bentrok dengan aturan `@page` milik modul Pelaporan.
    Custom property-nya di-set di elemen root halaman ini (lihat
    `pageSizeValue`).

    `margin: 0` karena margin kertas digabung dengan padding di dalam
    elemen `.report-page`; kalau keduanya aktif, margin terhitung dua kali
    dan area cetak menyempit (tabel lebar jadi terpotong).
  */
  @page {
    size: var(--page-size, A4 landscape);
    margin: 0;
  }
}
</style>

<!--
  Reset global khusus mode print.

  Aturan global `html, body { height: 100%; overflow-x: hidden }` di App.vue
  membuat <body> menjadi scroll-container. Akibatnya Chrome TIDAK
  mem-fragmentasi isi saat dicetak: seluruh dokumen keluar sebagai 1 halaman
  A4 dan halaman-halaman berikutnya terpotong (kata "1 page" di dialog print
  padahal laporannya multi halaman).

  Di bawah ini body/#app/#app-root dilepas dari tinggi tetap + overflow
  hidden supaya `break-after: page` pada .report-page-wrap benar-benar
  menghasilkan satu halaman PDF untuk setiap halaman laporan.

  Blok ini HANYA aktif di media print, jadi tampilan di layar web tidak
  berubah sama sekali.
-->
<style>
@media print {
  html,
  body {
    height: auto !important;
    min-height: 0 !important;
    overflow: visible !important;
  }

  #app,
  #app-root {
    height: auto !important;
    min-height: 0 !important;
    overflow: visible !important;
  }
}
</style>
