<template>
  <!--
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
    <!--
      Banner error.

      Tanpa ini, request yang gagal (403 karena menu dicabut dari jabatan,
      atau 500) membuat popup menampilkan "Tidak Ada Instalasi" -- yang
      ARTINYA "tidak ada instalasi", bukan "halaman ini gagal dimuat". Dua
      hal yang sama sekali, dan yang salah adalah yang kedua.
    -->
    <div
      v-if="errorMessage"
      class="mb-3! px-3! py-2! bg-rose-50! border! border-rose-200! text-rose-700! text-[11px]! font-semibold! rounded-lg! flex! items-center! gap-2!"
    >
      <font-awesome-icon icon="exclamation-triangle" class="text-xs!" />
      Gagal memuat data: {{ errorMessage }}
    </div>

    <DataTable
      v-model="searchQuery"
      :data="itemsList"
      :columns="columns"
      title="Detail Arsip Instalasi"
      searchPlaceholder="Cari nama atau nomor induk..."
      v-model:current-page="currentPage"
      v-model:per-page="perPage"
      :total-entries="serverTotal"
      :total-pages="totalPages"
      :show-entries="true"
      :no-card="true"
      server-side
      :loading="loading"
    >
      <template #column-status="{ row }">
        <span
          class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider rounded-md"
          :class="{
            'bg-slate-100 text-slate-600': row.status === 'Draft',
            'bg-blue-50 text-blue-600': row.status === 'Pasang',
            'bg-amber-50 text-amber-600': row.status === 'Prosesing',
            'bg-rose-50 text-rose-600': row.status === 'Unpaid',
          }"
        >
          {{ row.status }}
        </span>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
import DataTable from '@/presentations/components/ui/DataTable.vue'
import { usePopupData } from '@/composables/usePopupData'

const STATUS_MAP = {
  draft: { label: 'Draft' },
  pending: { label: 'Pasang' },
  surveyed: { label: 'Pasang' },
  unpaid: { label: 'Unpaid' },
  processing: { label: 'Prosesing' },
  completed: { label: 'Aktif' },
  suspended: { label: 'Blokir' },
  terminated: { label: 'Cabut' },
  cancelled: { label: 'Batal' },
  batal: { label: 'Batal' },
}

const columns = [
  { key: 'nomorInduk', title: 'Nomor Induk' },
  { key: 'customer', title: 'Customer' },
  { key: 'alamat', title: 'Alamat' },
  { key: 'tanggalOrder', title: 'Tanggal Order' },
  { key: 'status', title: 'Status' },
]

// Pakai endpoint ringan DashboardController::popupData?type=instalasi
// (sebelumnya ticketService.getTickets({ per_page: 100 }) menarik SEMUA tiket
//  dengan eager-load berat, lalu di-filter client-side)
//
// Paginasi & filter di server; composable juga mengembalikan nomor halaman
// ke 1 saat `per_page` berubah, dan membuang balasan lama yang tiba
// belakangan. Lihat `usePopupData.js` untuk alasannya.
const {
  searchQuery,
  currentPage,
  perPage,
  loading,
  serverTotal,
  totalPages,
  itemsList,
  errorMessage,
} = usePopupData('instalasi', {
  petakan: (t) => ({
    id: t.id,
    nomorInduk: t.nomorInduk,
    customer: t.customer,
    alamat: t.alamat,
    tanggalOrder: t.tanggalOrder,
    status: STATUS_MAP[t.status]?.label || t.status || '-',
    rawStatus: t.status,
  }),
})
</script>
