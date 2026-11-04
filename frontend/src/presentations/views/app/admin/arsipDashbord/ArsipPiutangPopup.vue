<template>
  <!--
    POPUP dashboard (kotak "TUNGGAKAN" -> Lihat Detail).

    Halaman mandiri ada di `ArsipPiutangPage.vue`; komponen ini hanya
    untuk popup. Keduanya memakai tabel yang sama
    (`parts/ArsipPiutangTable.vue`) supaya angka yang muncul di popup
    dan di halaman tidak pernah berbeda.

    TIDAK pakai `ContentCard` di sini.

    Panel popup sudah punya border, `rounded-2xl`, dan shadow sendiri. Kalau
    di dalamnya masih ada card, card itu menempel PERSIS di tepi panel
    (`selisihKiri/Kanan/Atas` = 0), sehingga:
      - border card dan border panel bertumpuk di garis yang sama
      - pojok lengkungan card jatuh di titik yang sama dengan pojok panel,
        jadi yang terlihat bukan "card di dalam panel" tapi lengkungan yang
        "nempel" dan membentuk sudut yang aneh
      - sisi bawah card juga menyentuh footer popup

    Jadi isinya jadi milik panel saja, dijauhkan 0.75rem (`p-3`) supaya
    tabelnya terlihat masuk ke dalam, bukan menempel di tepi.

    `min-h-0!` WAJIB: div ini flex item di dalam body popup yang punya
    `flex: 1`. Tanpa `min-h-0`, div tidak boleh menyusut di bawah tinggi
    isinya, jadi tabelnya meluber dan terpotong `overflow-hidden` -- tidak ada
    scrollbar, user cuma bisa lihat "segitu data"-nya.

    `h-full!` + `flex! flex-col!` bikin DataTable di dalamnya ikut mengisi
    ruang sisa, dan tabel itulah yang jadi elemen yang digulir.
  -->
  <div class="h-full! min-h-0! flex! flex-col! p-3!">
    <div
      v-if="errorMessage"
      class="mb-3! px-3! py-2! bg-rose-50! border! border-rose-200! text-rose-700! text-[11px]! font-semibold! rounded-lg! flex! items-center! gap-2!"
    >
      <font-awesome-icon icon="exclamation-triangle" class="text-xs!" />
      Gagal memuat data: {{ errorMessage }}
    </div>

    <ArsipPiutangTable
      popup
      :items-list="itemsList"
      :columns="columns"
      :search-query="searchQuery"
      @update:search-query="searchQuery = $event"
      :current-page="currentPage"
      @update:current-page="currentPage = $event"
      :per-page="perPage"
      @update:per-page="perPage = $event"
      :server-total="serverTotal"
      :total-pages="totalPages"
      :loading="loading"
    />
  </div>
</template>

<script setup>
import ArsipPiutangTable from './parts/ArsipPiutangTable.vue'
import { usePopupData } from '@/composables/usePopupData'

const columns = [
  { key: 'nomorInduk', title: 'No. Pelanggan' },
  { key: 'customer', title: 'Nama' },
  { key: 'alamat', title: 'Alamat' },
  { key: 'periodeLabel', title: 'Periode' },
  { key: 'jatuhTempo', title: 'Jatuh Tempo' },
  { key: 'tanggalPencatatan', title: 'Tercatat' },
  { key: 'tagihan', title: 'Tagihan' },
  { key: 'denda', title: 'Denda' },
  { key: 'piutangTercatat', title: 'Piutang Tercatat' },
  { key: 'total', title: 'Total' },
  { key: 'status', title: 'Status' },
]

/*
 * Popup ini memakai endpoint `dashboard/popup-data?type=tunggakan`, BUKAN
 * `GET /piutang/arsip` seperti halaman mandiri.
 *
 * Alasannya: popup dashboard memakai endpoint yang SELALU dibolehkan
 * (`dashboard/*` ada di `always_allowed_api`), sedangkan `piutang/arsip`
 * dikunci oleh menu `arsip.piutang`. Kalau popup memakai endpoint yang
 * dikunci, admin yang jabatannya tidak punya "Arsip Piutang" akan
 * melihat kotak dashboard-nya error -- padahal kotak itu bukan halamannya.
 *
 * Datanya tetap sama persis: `DashboardController::popupTunggakan()`
 * meneruskan ke `ArsipPiutangService`, service yang sama dengan yang
 * dipakai halaman mandiri. Yang berbeda hanya FILTER: popup tidak punya
 * filter periode (kotak dashboard bersifat ringkasan "sekarang"), halaman
 * mandiri punya filter bulan/tahun pencatatan piutang.
 *
 * Popup TIDAK memakai filter periode, jadi tidak perlu parameter bulan.
 */
const {
  searchQuery,
  currentPage,
  perPage,
  loading,
  itemsList,
  serverTotal,
  totalPages,
  errorMessage,
} = usePopupData('tunggakan')
</script>
