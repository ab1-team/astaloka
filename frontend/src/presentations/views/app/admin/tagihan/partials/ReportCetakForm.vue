<template>
  <BaseReportLayout :lembaga="lembaga" :config="payload?.config" :no-kop="true">
    <!--
      Kop surat: logo di KIRI, identitas lembaga di KANAN.

      `no-kop` pada BaseReportLayout dipakai supaya layout ini tidak
      menggambar kop-nya sendiri. Alasannya: layout tersebut memakai
      `lembaga.nama` sebagai baris utama dan `lembaga.alamat_kab` sebagai
      baris kedua -- sedangkan halaman ini butuh susunan kop yang lebih
      lengkap (nama usaha besar, alamat, telepon, email, SK) dan judul
      "FORM INPUT PEMAKAIAN" tetap harus terlihat.

      Kop dirender di SETIAP halaman (sama seperti modul Pelaporan),
      supaya halaman lanjutan tetap jelas milik lembaga mana.
    -->
    <div class="surat-kop">
      <table class="kop-table">
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
            <div class="kop-info-sub" v-if="skLembaga">
              <i>SK Kemenkumham RI No. {{ skLembaga }}</i>
            </div>
          </td>
        </tr>
        <tr>
          <td colspan="2" style="padding: 0">
            <hr class="kop-single-divider" />
          </td>
        </tr>
      </table>
    </div>

    <template v-if="showMeta">
      <div class="page-header">
        <h2>FORM INPUT PEMAKAIAN</h2>
      </div>

      <div class="meta-grid">
        <div class="meta-col">
          <div class="meta-row">
            <span class="meta-label">Bulan Pemakaian</span>
            <span class="meta-sep">:</span>
            <span class="meta-value">{{ filter.bulan || '-' }} {{ filter.tahun || '' }}</span>
          </div>
          <div class="meta-row">
            <span class="meta-label">Tanggal Cetak</span>
            <span class="meta-sep">:</span>
            <span class="meta-value">{{ tanggalCetak }}, Pukul {{ jamCetak }}</span>
          </div>
        </div>
        <div class="meta-col meta-col-right">
          <div class="meta-row">
            <span class="met-label">Teknisi</span>
            <span class="meta-sep">:</span>
            <span class="meta-value">{{ filter.cater || 'Admin' }}</span>
          </div>
          <div class="meta-row">
            <span class="met-label">Dusun</span>
            <span class="meta-sep">:</span>
            <span class="meta-value">{{ dusun }}</span>
          </div>
        </div>
      </div>
    </template>

    <table class="data-table data-table-fixed">
      <colgroup>
        <col style="width: 5%" />
        <col style="width: 30%" />
        <col style="width: 16%" />
        <col style="width: 6%" />
        <col style="width: 8%" />
        <col style="width: 8%" />
        <col style="width: 28%" />
      </colgroup>
      <thead>
        <tr>
          <th class="text-center">No</th>
          <th class="text-center">Nama</th>
          <th class="text-center">No. Induk</th>
          <th class="text-center">RT</th>
          <th class="text-center">Awal</th>
          <th class="text-center">Akhir</th>
          <th class="text-center">Keterangan</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(item, idx) in items" :key="item.id" class="form-row">
          <td class="text-center">{{ startIndex + idx + 1 }}</td>
          <td class="text-left">{{ item.nama }}</td>
          <td class="text-center">{{ item.customer_code || item.id }}</td>
          <td class="text-center">{{ item.rt || '-' }}</td>
          <td class="text-center">{{ Number(item.meterAwal || 0).toLocaleString('id-ID') }}</td>
          <td class="text-center">
            {{ item.meterAkhir ? Number(item.meterAkhir).toLocaleString('id-ID') : '' }}
          </td>
          <td class="text-left">{{ item.keterangan || '' }}</td>
        </tr>
        <tr v-if="!items || items.length === 0">
          <td colspan="7" class="empty">Tidak ada data pelanggan pada dusun ini.</td>
        </tr>
      </tbody>
    </table>
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
const dusun = computed(() => props.payload?.dusun || '-')
const filter = computed(() => props.payload?.filter || {})
const lembaga = computed(() => props.payload?.lembaga || {})
const startIndex = computed(() => Number(props.payload?.startIndex || 0))
const showMeta = computed(() => props.payload?.showMeta !== false)

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
 * `lembaga.logo` sudah berupa URL absolut karena `cetakForm.vue` memakai
 * `useIdentitasLembaga` (yang memakai `storageLogoUrl`). Tapi supaya
 * komponen ini tetap aman kalau dipakai dengan payload bentuk lain
 * (mis. nama file mentah), URL-nya tetap dilewatkan `storageLogoUrl`.
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
   Logo di kiri, identitas lembaga di kanan — meniru gaya kop modul
   Pelaporan (`BaseReportLayout`), tapi ukurannya sedikit lebih besar
   supaya nama lembaga terbaca jelas di kertas A4.

   Tinggi kop ini diperhitungkan: halaman LANJUTAN memuat 54 baris
   tabel (lihat `utils/reportConfig.js`) dan seluruhnya harus muat dalam
   area cetak A4 (273mm). Logo 68px membuat baris terakhir keluar
   dari kertas, jadi ukurannya diturunkan ke 58px.

   Kalau logo diubah ukurannya, `tools/ukur-cetak-form.mjs` harus
   dijalankan ulang untuk memastikan halaman masih muat. */
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

/* Judul "FORM INPUT PEMAKAIAN" tetap di bawah kop, rata tengah.
   BaseReportLayout memberi `.page-header` style default (14px, margin
   bawah 12px) — di sini diturunkan sedikit supaya kop + judul + tabel
   tetap muat satu halaman A4.

   Selector memakai `:deep(.report-page)` supaya spesifikitasnya BELEHAT
   lebih tinggi dari aturan BaseReportLayout. Tanpa itu, dua aturan
   `.page-header h2` sama-sama berlaku dan hasilnya bergantung pada
   urutan file CSS — judul bisa ikut jadi 14px atau margin 12px. */
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

.meta-grid {
  display: flex;
  justify-content: space-between;
  align-items: flex-end; /* Menyelaraskan posisi Dusun ke bawah agar sejajar dengan Cater */
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
  justify-content: flex-end; /* Memastikan isi kolom kanan turun ke bawah sejajar Cater */
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
.met-label {
  min-width: 40px;
  display: inline-block;
}
.meta-sep {
  font-weight: 700;
}

.meta-value {
  font-weight: 600;
}

/* Styling Tabel & Font Size 11px (samakan dengan cetak tagihan) */
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
  font-size: 11px;
  line-height: 1.2;
}

.data-table th,
.data-table td {
  border: 1px solid #000;
  font-size: 11px;
  line-height: 1.2;
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

.form-row {
  height: auto;
}

.form-row td {
  padding: 2px 4px !important;
}

/* Header tabel berulang di setiap halaman saat print */
.data-table thead {
  display: table-header-group;
}

.data-table tfoot {
  display: table-footer-group;
}

/* Cegah satu baris form terpotong di antara halaman */
.data-table tbody tr {
  page-break-inside: avoid;
  break-inside: avoid;
}

/* Page-break setelah .report-page
   CATATAN: pemenggalan halaman saat print dilakukan oleh .report-page-wrap
   (parent-nya) di cetakForm.vue. Kalau break-after juga dipasang di sini,
   Chrome menghasilkan satu halaman kosong di antara setiap halaman laporan
   karena ada dua page-break berturut-turut. */
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
   Padding WAJIB sama antara layar dan print. Kalau preview memakai padding
   lebih besar (mis. bawaan BaseReportLayout 60px/90px) sementara print
   memakai 12mm/15mm, area cetak di preview jadi lebih sempit -> baris
   terakhir tabel terpotong saat ganti halaman di tampilan preview.

   Rule ini scoped (punya [data-v-...]) jadi hanya berlaku untuk halaman
   laporan form input ini, tidak mengubah laporan lain. */
.report-page.surat-page {
  padding: 12mm 15mm !important;
}
</style>

<style>
/* Ukuran halaman cetak.
   `height: auto` (bukan 297mm) supaya baris tabel terakhir tidak
   terpotong -- halaman tetap minimal setinggi A4 lewat `min-height`,
   tapi isinya boleh lebih panjang dan tidak dipotong diam-diam.
   Padding TIDAK diatur di sini supaya tidak bentrok dengan aturan
   scoped `padding: 12mm 15mm` di atas. */
@media print {
  .report-page.surat-page.size-a4.portrait {
    width: 210mm !important;
    height: auto !important;
    min-height: 297mm !important;
    margin: 0 !important;
  }
}
</style>
