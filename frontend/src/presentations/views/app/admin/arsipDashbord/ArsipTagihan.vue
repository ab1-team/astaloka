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
      atau 500) membuat popup menampilkan "Tidak Ada Tagihan" -- yang
      ARTINYA "tidak ada tagihan", bukan "halaman ini gagal dimuat". Dua
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
      v-model:selection="selectedRows"
      :data="itemsList"
      :columns="columns"
      title="Detail Arsip Tagihan"
      searchPlaceholder="Cari nama atau nomor pelanggan..."
      v-model:current-page="currentPage"
      v-model:per-page="perPage"
      :total-entries="serverTotal"
      :total-pages="totalPages"
      :show-entries="true"
      :no-card="true"
      server-side
      :loading="loading"
      selectable
    >
      <template #column-periode="{ row }">
        <span class="text-[12px] text-slate-600 font-medium">
          {{ row.periodeLabel }}
        </span>
      </template>
      <template #column-total="{ row }">
        <span class="font-semibold text-[12px] text-slate-700 font-mono whitespace-nowrap">
          {{ formatRupiah(row.total) }}
        </span>
      </template>
      <template #column-denda="{ row }">
        <span
          :class="[
            'font-semibold text-[12px] font-mono whitespace-nowrap',
            row.denda > 0 ? 'text-rose-600' : 'text-slate-400',
          ]"
        >
          {{ formatRupiah(row.denda) }}
        </span>
      </template>
      <template #column-jatuhTempo="{ row }">
        <span class="text-[12px] text-slate-600 font-medium">
          {{ formatDate(row.jatuhTempo) }}
        </span>
      </template>
      <template #column-status="{ row }">
        <span
          class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider rounded-md"
          :class="
            row.status === 'paid' ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'
          "
        >
          {{ row.status === 'paid' ? 'Lunas' : 'Belum Lunas' }}
        </span>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
import { ref, inject } from 'vue'
import DataTable from '@/presentations/components/ui/DataTable.vue'
import { usePopupData } from '@/composables/usePopupData'

const selectedRows = inject('tagihanSelection', ref([]))

const formatRupiah = (value) => {
  const n = Number(value) || 0
  return `Rp ${n.toLocaleString('id-ID')}`
}

const formatDate = (value) => {
  if (!value) return '-'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
}

const columns = [
  { key: 'nomorInduk', title: 'No. Pelanggan' },
  { key: 'customer', title: 'Nama' },
  { key: 'alamat', title: 'Alamat' },
  { key: 'periodeLabel', title: 'Periode' },
  { key: 'total', title: 'Total Tagihan' },
  { key: 'denda', title: 'Denda' },
  { key: 'jatuhTempo', title: 'Jatuh Tempo' },
  { key: 'status', title: 'Status' },
]

// Pakai endpoint ringan DashboardController::popupData?type=tagihan
// (sebelumnya getAllBills() menarik SEMUA halaman tagihan unpaid)
//
// Composable juga mengembalikan nomor halaman ke 1 saat `per_page` berubah,
// dan membuang balasan lama yang tiba belakangan. Lihat `usePopupData.js`.
const {
  searchQuery,
  currentPage,
  perPage,
  loading,
  serverTotal,
  totalPages,
  itemsList,
  errorMessage,
} = usePopupData('tagihan')
</script>
