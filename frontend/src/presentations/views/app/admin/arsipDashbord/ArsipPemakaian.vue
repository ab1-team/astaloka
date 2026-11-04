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
      atau 500) membuat popup menampilkan "Tidak Ada Pemakaian" -- yang
      ARTINYA "tidak ada pemakaian", bukan "halaman ini gagal dimuat". Dua
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
      title="Detail Arsip Pemakaian"
      searchPlaceholder="Cari nama atau nomor pelanggan..."
      v-model:current-page="currentPage"
      v-model:per-page="perPage"
      :total-entries="serverTotal"
      :total-pages="totalPages"
      :show-entries="true"
      :no-card="true"
      server-side
      :loading="loading"
    >
      <template #column-tagihan="{ row }">
        <span class="font-semibold text-[12px] text-slate-700 font-mono whitespace-nowrap">
          {{ row.total != null ? formatRupiah(row.total) : '-' }}
        </span>
      </template>
      <template #column-status="{ row }">
        <span
          class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider rounded-md"
          :class="
            row.status === 'paid'
              ? 'bg-emerald-50 text-emerald-600'
              : row.status === 'unpaid'
                ? 'bg-amber-50 text-amber-600'
                : 'bg-slate-100 text-slate-500'
          "
        >
          {{
            row.status === 'paid'
              ? 'Sudah Dicatat'
              : row.status === 'unpaid'
                ? 'Belum Lunas'
                : 'Belum Dicatat'
          }}
        </span>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
import DataTable from '@/presentations/components/ui/DataTable.vue'
import { usePopupData } from '@/composables/usePopupData'

const formatRupiah = (value) => {
  const n = Number(value) || 0
  return `Rp ${n.toLocaleString('id-ID')}`
}

const columns = [
  { key: 'nomorInduk', title: 'No. Pelanggan' },
  { key: 'customer', title: 'Nama' },
  { key: 'alamat', title: 'Alamat' },
  { key: 'periodeLabel', title: 'Periode' },
  { key: 'total', title: 'Tagihan' },
  { key: 'status', title: 'Status' },
]

// Pakai endpoint ringan DashboardController::popupData?type=pemakaian
// (sebelumnya: 2 endpoint paralel + getAllBills loop semua halaman)
//
// `month` & `year` dipakai karena pemakaian dihitung dari tagihan pada
// periode berjalan -- tanpa keduanya, server memakai default-nya sendiri.
//
// Composable juga mengembalikan nomor halaman ke 1 saat `per_page` berubah,
// dan membuang balasan lama yang tiba belakangan. Lihat `usePopupData.js`.
const now = new Date()
const {
  searchQuery,
  currentPage,
  perPage,
  loading,
  serverTotal,
  totalPages,
  itemsList,
  errorMessage,
} = usePopupData('pemakaian', {
  params: { month: now.getMonth() + 1, year: now.getFullYear() },
})
</script>
