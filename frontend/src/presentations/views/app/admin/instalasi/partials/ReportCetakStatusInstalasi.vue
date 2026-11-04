<template>
  <BaseReportLayout :lembaga="lembaga" :config="payload?.config" :no-kop="true">
    <!--
      Kop surat: logo di KIRI, identitas lembaga di KANAN.

      `no-kop` pada BaseReportLayout dipakai supaya layout ini tidak
      menggambar kop-nya sendiri. Alasannya: kop bawaan layout itu memakai
      `lembaga.nama` sebagai baris utama dan `lembaga.alamat_kab` sebagai
      baris kedua -- sedangkan identitas lembaga yang kita punya tidak
      pernah punya `alamat_kab` (placeholder "ALAMAT LEMBAGA" akan
      tercetak). Kop di bawah dibuat mengikuti gaya modul Pelaporan:
      logo di kiri, nama/alamat/telp/email/SK di kanan.

      Kop dirender di SETIAP halaman (sama seperti modul Pelaporan), supaya
      halaman lanjutan tetap jelas milik lembaga mana.
    -->
    <div class="surat-kop">
      <table class="kop-table">
        <tbody>
          <tr>
            <td width="86" class="logo-cell">
              <img
                v-if="logoSrc"
                :src="logoSrc"
                alt="logo"
                class="kop-logo"
                crossorigin="anonymous"
              />
              <div v-else class="kop-logo-fallback">{{ initials }}</div>
            </td>

            <td class="text-cell">
              <div class="kop-nama-usaha">{{ namaLembaga }}</div>
              <div class="kop-nama-kec">
                <b>{{ alamatLembaga }}</b>
              </div>
              <div class="kop-info-sub">
                <i v-if="teleponLembaga">Telp. {{ teleponLembaga }}</i>
                <i v-if="teleponLembaga && emailLembaga">, </i>
                <i v-if="emailLembaga">{{ emailLembaga }}</i>
              </div>
              <div v-if="skLembaga" class="kop-info-sub">
                <i>SK Kemenkumham RI No. {{ skLembaga }}</i>
              </div>
            </td>
          </tr>
          <tr>
            <td colspan="2" style="padding: 0">
              <hr class="kop-single-divider" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <template v-if="showMeta">
      <div class="page-header">
        <h2>DAFTAR STATUS INSTALASI</h2>
        <p class="page-subtitle">{{ labelKategori }}</p>
      </div>

      <div class="meta-grid">
        <div class="meta-col">
          <div class="meta-row">
            <span class="meta-label">Status</span>
            <span class="meta-sep">:</span>
            <span class="meta-value">{{ labelKategori }}</span>
          </div>
          <div class="meta-row">
            <span class="meta-label">Jumlah Data</span>
            <span class="meta-sep">:</span>
            <span class="meta-value">{{ total }} data</span>
          </div>
        </div>

        <div class="meta-col meta-col-right">
          <div class="meta-row">
            <span class="meta-label">Tanggal Cetak</span>
            <span class="meta-sep">:</span>
            <span class="meta-value">{{ tanggalCetak }}, Pukul {{ jamCetak }}</span>
          </div>
          <div v-if="pencarian" class="meta-row">
            <span class="meta-label">Pencarian</span>
            <span class="meta-sep">:</span>
            <span class="meta-value">"{{ pencarian }}"</span>
          </div>
        </div>
      </div>
    </template>

    <table class="data-table data-table-fixed">
      <colgroup>
        <col style="width: 3%" />
        <col style="width: 11%" />
        <col style="width: 15%" />
        <col style="width: 12%" />
        <col style="width: 9%" />
        <col style="width: 18%" />
        <col style="width: 9%" />
        <col style="width: 9%" />
        <col style="width: 7%" />
        <col style="width: 7%" />
      </colgroup>
      <thead>
        <tr>
          <th class="text-center">No</th>
          <th class="text-center">Kode Instalasi</th>
          <th class="text-left">Nama Pelanggan</th>
          <th class="text-center">NIK</th>
          <th class="text-center">No. Telp</th>
          <th class="text-left">Alamat</th>
          <th class="text-left">Desa/Dusun</th>
          <th class="text-left">Paket</th>
          <th class="text-center">Status</th>
          <th class="text-center">Tgl Order</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(item, idx) in items" :key="item.ticketId ?? idx" class="data-row">
          <td class="text-center">{{ startIndex + idx + 1 }}</td>
          <td class="text-center">{{ item.id }}</td>
          <td class="text-left">{{ item.name }}</td>
          <td class="text-center">{{ item.nik }}</td>
          <td class="text-center">{{ item.phone }}</td>
          <td class="text-left">{{ item.address }}</td>
          <td class="text-left">{{ item.village }}</td>
          <td class="text-left">{{ item.type }}</td>
          <td class="text-center">{{ item.status }}</td>
          <td class="text-center">{{ item.orderDate }}</td>
        </tr>
        <tr v-if="!items || items.length === 0">
          <td colspan="10" class="empty">
            Tidak ada data status instalasi yang sesuai dengan filter.
          </td>
        </tr>
      </tbody>
    </table>

    <div class="page-footer">
      <span class="page-footer-total">Total {{ total }} data</span>
      <span class="page-footer-page">Halaman {{ pageNumber }} dari {{ totalPages }}</span>
    </div>
  </BaseReportLayout>
</template>

<script setup>
import { computed } from 'vue'
import BaseReportLayout from '@/presentations/views/app/admin/pelaporan/layouts/BaseReportLayout.vue'
import { storageLogoUrl } from '@/utils/storage'

const props = defineProps({
  payload: { type: Object, default: () => ({}) },
  meta: { type: Object, default: () => ({}) },
})

const items = computed(() => props.payload?.items || [])
const lembaga = computed(() => props.payload?.lembaga || {})
const startIndex = computed(() => Number(props.payload?.startIndex || 0))
const showMeta = computed(() => props.payload?.showMeta !== false)
const total = computed(() => Number(props.payload?.total ?? items.value.length))
const labelKategori = computed(() => props.payload?.label || 'Semua Status')
const pencarian = computed(() => props.payload?.search || '')

const pageNumber = computed(() => Number(props.meta?.page || 1))
const totalPages = computed(() => Number(props.meta?.total || 1))

/**
 * Nama lembaga di kop.
 *
 * `nama_lembaga` dipakai lebih dulu (nama badan usaha yang diurus),
 * lalu `nama` sebagai cadangan. Kalau keduanya kosong, tampilkan
 * placeholder supaya baris kop tidak terlihat kosong saat data setting
 * belum diisi admin.
 */
const namaLembaga = computed(
  () => lembaga.value.nama_lembaga || lembaga.value.nama || 'NAMA LEMBAGA',
)

const alamatLembaga = computed(() => lembaga.value.alamat || '-')
const teleponLembaga = computed(() => lembaga.value.telepon || '')
const emailLembaga = computed(() => lembaga.value.email || '')
const skLembaga = computed(() => lembaga.value.sk_kemenkumham || '')

/**
 * URL logo.
 *
 * `storageLogoUrl` mengubah nama file menjadi URL absolut. Path relatif
 * (`/storage/...`) terlihat benar di layar, tapi tidak resolve saat
 * dokumen dicetak dari tab lain -- jadi logo tidak muncul di PDF.
 */
const logoSrc = computed(() => storageLogoUrl(lembaga.value.logo))

/** Inisial untuk fallback kalau logo belum diunggah. */
const initials = computed(
  () =>
    lembaga.value.initials ||
    String(lembaga.value.nama_lembaga || lembaga.value.nama || '')
      .split(' ')
      .filter((s) => s.length > 0)
      .slice(0, 2)
      .map((s) => s[0])
      .join('')
      .toUpperCase() ||
    'AL',
)

const now = new Date()

const tanggalCetak = computed(() =>
  now.toLocaleDateString('id-ID', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
  }),
)

const jamCetak = computed(() =>
  now.toLocaleTimeString('id-ID', {
    hour: '2-digit',
    minute: '2-digit',
  }),
)
</script>

<style scoped>
/* ================= Kop Surat =================
   Logo di kiri, identitas lembaga di kanan - meniru gaya kop modul
   Pelaporan (`BaseReportLayout`), supaya hasil cetak Status Instalasi
   terlihat serasi dengan laporan keuangan.

   Ukuran logo TIDAK harus diselaraskan manual dengan jumlah baris per
   halaman: `cetakStatusInstalasi.vue` mengukur tinggi baris dari DOM
   (probe off-screen) sebelum memecah baris ke halaman. Jadi kalau
   ukuran logo diubah, jumlah baris per halaman menyesuaikan sendiri. */
.surat-kop {
  width: 100%;
}

.kop-table {
  width: 100%;
  border-collapse: collapse;
  font-family: Arial, Helvetica, sans-serif;
}

.logo-cell {
  vertical-align: top;
  padding-right: 14px;
}

.kop-logo {
  height: 58px;
  max-width: 70px;
  object-fit: contain;
}

.kop-logo-fallback {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  background: #0c79f5;
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  font-weight: 800;
}

.text-cell {
  vertical-align: top;
  text-align: left;
}

.kop-nama-usaha {
  font-size: 15px;
  font-weight: 700;
  text-transform: uppercase;
  line-height: 1.15;
  color: #000000;
}

.kop-nama-kec {
  font-size: 12px;
  text-transform: uppercase;
  line-height: 1.2;
  margin-top: 1px;
  color: #000000;
}

.kop-info-sub {
  font-size: 10.5px;
  color: #000000;
  line-height: 1.2;
  margin-top: 1px;
}

.kop-single-divider {
  border: 0;
  border-top: 2.5px solid #000000;
  margin-top: 2px;
  margin-bottom: 6px;
  width: 100%;
}

/* ================= Judul & Meta =================

   `page-header` punya style default dari BaseReportLayout (14px, margin
   bawah 12px). Selector di sini memakai `:deep(.report-page)` supaya
   spesifisitasnya sedikit lebih tinggi -- kalau tidak, dua aturan yang
   sama-sama berlaku membuat hasilnya bergantung pada urutan file CSS. */
:deep(.report-page) .page-header {
  text-align: center;
  margin: 2px 0 6px;
}

:deep(.report-page) .page-header h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #000000;
}

:deep(.report-page) .page-subtitle {
  margin: 2px 0 0;
  font-size: 12px;
  font-weight: 700;
  color: #000000;
}

.meta-grid {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 12px;
  margin: 4px 0 6px;
  font-size: 12px;
}

.meta-col {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.meta-col-right {
  justify-content: flex-end;
}

.meta-row {
  display: flex;
  align-items: center;
  gap: 6px;
}

.meta-label {
  min-width: 95px;
  display: inline-block;
}

.meta-sep {
  font-weight: 700;
}

.meta-value {
  font-weight: 600;
}

/* ================= Tabel =================

   `table-layout: fixed` + `colgroup` membuat kolom patuh persentase,
   jadi tabel tetap rapi aunque isinya panjang. */
.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.data-table-fixed {
  table-layout: fixed;
}

.data-table-fixed th,
.data-table-fixed td {
  box-sizing: border-box;
  padding: 2px 4px;
  vertical-align: middle;
}

.data-table-fixed th {
  white-space: nowrap;
}

.data-table-fixed td {
  white-space: normal;
  word-break: break-word;
  overflow-wrap: anywhere;
  font-size: 10px;
  line-height: 1.15;
}

.data-table th,
.data-table td {
  border: 1px solid #000;
  font-size: 10px;
  line-height: 1.15;
}

.text-left {
  text-align: left !important;
}

.text-center {
  text-align: center !important;
}

.empty {
  text-align: center;
  font-style: italic;
  padding: 16px !important;
  color: #000000;
}

/* Header tabel berulang di setiap halaman saat print */
.data-table thead {
  display: table-header-group;
}

.data-table tfoot {
  display: table-footer-group;
}

/* Cegah satu baris terpotong di antara halaman */
.data-table tbody tr {
  page-break-inside: avoid;
  break-inside: avoid;
}

/* ================= Footer Halaman =================

   `margin-top: auto` pushing ke bawah karena `.report-content` di
   BaseReportLayout memakai flex column. */
.page-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: auto;
  padding-top: 6px;
  font-size: 10px;
  color: #000000;
}

.page-footer-page {
  font-weight: 700;
}

/* Catatan pemenggalan halaman saat print.

   Pemenggalan dilakukan oleh `.report-page-wrap` (parent-nya) di
   `cetakStatusInstalasi.vue`. Kalau `break-after` juga dipasang di sini,
   Chrome menghasilkan satu halaman kosong di antara setiap halaman
   laporan karena ada dua page-break berturut-turut. */
.report-page,
.report-page.surat-page {
  page-break-after: auto;
  break-after: auto;
}

.report-page:last-child {
  page-break-after: auto;
  break-after: auto;
}

/* Margin cetak & preview.

   Padding WAJIB sama antara layar dan print. Kalau preview memakai
   padding lebih besar (bawaan BaseReportLayout 60px/90px) sementara
   print memakai 12mm/15mm, area cetak di preview jadi lebih sempit ->
   baris terakhir tabel terpotong saat ganti halaman di preview. */
.report-page.surat-page {
  padding: 12mm 15mm !important;
}
</style>

<style>
/* Ukuran halaman cetak.

   `height: auto` (bukan 210mm) supaya baris tabel terakhir tidak
   terpotong -- halaman tetap minimal setinggi A4 lewat `min-height`,
   tapi isinya boleh lebih panjang dan tidak dipotong diam-diam.

   Padding TIDAK diatur di sini supaya tidak bentrok dengan aturan
   scoped `padding: 12mm 15mm` di atas. */
@media print {
  .report-page.surat-page.size-a4.landscape {
    width: 297mm !important;
    height: auto !important;
    min-height: 210mm !important;
    margin: 0 !important;
  }
}
</style>
