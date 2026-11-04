<template>
  <!--
    TABEL Arsip Piutang.

    Dipakai di DUA konteks, dan itu sebabnya komponen ini dipisah dari
    halaman:

      1. Popup dashboard (kotak "TUNGGAKAN" -> Lihat Detail).
      2. Halaman mandiri /app/arsip/piutang.

    Yang membedakan keduanya hanya gaya, bukan datanya:

      - Popup: panelnya sudah punya border + rounded + shadow. Menaruh card
        di dalamnya membuat border-nya menumpuk persis di garis yang sama
        dengan border panel, pojok lengkungan card jatuh di pojok panel,
        dan sisi bawah card menyentuh footer popup. Jadi di popup TIDAK ada
        card -- isinya dijauhkan 0.75rem (`p-3`) supaya terlihat masuk ke
        dalam, bukan menempel di tepi.

      - Halaman mandiri: tidak ada panel, jadi tabel dibungkus card
        (`ContentCard`) supaya tidak menempel mentah ke latar halaman.

    `min-h-0!` WAJIB di popup: div ini flex item di dalam body popup yang
    punya `flex: 1`. Tanpa `min-h-0`, div tidak boleh menyusut di bawah
    tinggi isinya, jadi tabelnya meluber dan terpotong `overflow-hidden` --
    tidak ada scrollbar, user cuma bisa lihat "segitu data"-nya.
  -->
  <div :class="popup ? 'h-full! min-h-0! flex! flex-col! p-3!' : 'w-full!'">
    <DataTable
      :model-value="searchQuery"
      @update:model-value="(v) => emit('update:searchQuery', v)"
      :data="itemsList"
      :columns="columns"
      :title="popup ? 'Detail Arsip Piutang' : ''"
      searchPlaceholder="Cari nama atau nomor..."
      :current-page="currentPage"
      @update:current-page="(v) => emit('update:currentPage', v)"
      :per-page="perPage"
      @update:per-page="(v) => emit('update:perPage', v)"
      :total-entries="serverTotal"
      :total-pages="totalPages"
      :show-entries="true"
      :no-card="true"
      server-side
      :loading="loading"
      empty-title="Tidak Ada Piutang"
      empty-message="Tidak ada piutang pelanggan yang belum tertagih pada periode ini."
      empty-icon="file-invoice-dollar"
    >
      <template #column-nomorInduk="{ row }">
        <span class="text-[12px] text-slate-600 font-medium font-mono whitespace-nowrap">
          {{ row.nomorInduk }}
        </span>
      </template>

      <!--
        Nama dan Alamat TIDAK boleh membungkus.

        Alasannya bukan soal "nama bisa panjang" -- melainkan soal KETINGGIAN
        BARIS. Tabel ini punya sebelas kolom dan hampir semuanya sudah
        `whitespace-nowrap` (nomor induk, tanggal, rupiah, status). Dua kolom
        sisanya -- Nama dan Alamat -- kalau dibiarkan membungkus, tinggi
        barisnya jadi berbeda-beda: baris dengan alamat panjang jadi dua
        baris teks, baris berikutnya satu baris, dan saat digulir vertikal
        itu terlihat seperti tabel yang salah render.

        Konten yang tidak muat sudah ditangani scroll horizontal di
        `DataTable` (`overflow-x-auto`) -- dan `min-width: 0` di
        `.main-content` yang membuatnya benar-benar bekerja. Jadi membungkus
        teks di sini tidak menyelesaikan apa pun, cuma membuat tabel terlihat
        berantakan.
      -->
      <template #column-customer="{ row }">
        <span class="text-[13px] text-slate-700 font-semibold whitespace-nowrap">
          {{ row.customer }}
        </span>
      </template>

      <template #column-alamat="{ row }">
        <span class="text-[12px] text-slate-500 whitespace-nowrap">
          {{ row.alamat }}
        </span>
      </template>

      <template #column-periodeLabel="{ row }">
        <span class="text-[12px] text-slate-600 font-medium whitespace-nowrap">
          {{ row.periodeLabel }}
        </span>
      </template>

      <template #column-jatuhTempo="{ row }">
        <div class="flex flex-col">
          <span class="text-[12px] text-slate-700 font-medium whitespace-nowrap">
            {{ formatTanggal(row.jatuhTempo) }}
          </span>
          <span class="text-[10px] text-rose-500 font-bold whitespace-nowrap">
            {{ row.hariMenunggak > 0 ? `Telat ${row.hariMenunggak} hari` : '' }}
          </span>
        </div>
      </template>

      <template #column-tanggalPencatatan="{ row }">
        <span
          class="text-[12px] font-medium whitespace-nowrap"
          :class="row.tanggalPencatatan ? 'text-slate-600' : 'text-slate-400'"
        >
          {{ row.tanggalPencatatan ? formatTanggal(row.tanggalPencatatan) : '-' }}
        </span>
      </template>

      <template #column-tagihan="{ row }">
        <span
          :class="[
            'text-[12px] font-semibold font-mono whitespace-nowrap',
            row.tagihan > 0 ? 'text-slate-700' : 'text-slate-400',
          ]"
        >
          {{ formatRupiah(row.tagihan) }}
        </span>
      </template>

      <template #column-denda="{ row }">
        <span
          :class="[
            'text-[12px] font-semibold font-mono whitespace-nowrap',
            row.denda > 0 ? 'text-rose-600' : 'text-slate-400',
          ]"
        >
          {{ formatRupiah(row.denda) }}
        </span>
      </template>

      <template #column-piutangTercatat="{ row }">
        <span
          :class="[
            'text-[12px] font-semibold font-mono whitespace-nowrap',
            row.piutangTercatat > 0 ? 'text-cyan-700' : 'text-slate-400',
          ]"
        >
          {{ formatRupiah(row.piutangTercatat) }}
        </span>
      </template>

      <template #column-total="{ row }">
        <span class="text-[12px] text-slate-800 font-bold font-mono whitespace-nowrap">
          {{ formatRupiah(row.total) }}
        </span>
      </template>

      <!--
        Status bukan lagi teks hardcoded "Belum Lunas" seperti versi lama.

        Bedanya penting: "Belum Lunas" cuma menyatakan tagihannya belum
        dibayar. Yang hilang darinya adalah informasi apakah piutangnya
        SUDAH dibukukan di akun Piutang Usaha atau belum -- dan itu
        informasi yang paling menentukan di halaman ini, karena hanya
        piutang yang sudah dibukukan yang membebani akun 1.1.03.01.
      -->
      <template #column-status="{ row }">
        <span
          class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider rounded-md whitespace-nowrap"
          :class="
            row.statusKey === 'tercatat' ? 'bg-cyan-50 text-cyan-700' : 'bg-amber-50 text-amber-600'
          "
        >
          {{ row.status || (row.statusKey === 'tercatat' ? 'Tercatat' : 'Menunggu Generate') }}
        </span>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
import DataTable from '@/presentations/components/ui/DataTable.vue'

defineProps({
  itemsList: { type: Array, default: () => [] },
  columns: { type: Array, required: true },
  searchQuery: { type: String, default: '' },
  currentPage: { type: Number, default: 1 },
  perPage: { type: Number, default: 10 },
  serverTotal: { type: Number, default: 0 },
  totalPages: { type: Number, default: 1 },
  loading: { type: Boolean, default: false },
  /**
   * True saat dipakai di dalam popup dashboard.
   *
   * Menentukan gaya, bukan data: di popup tidak boleh ada card, dan tabel
   * harus ikut mengisi ruang sisa panel.
   */
  popup: { type: Boolean, default: false },
})

defineEmits(['update:searchQuery', 'update:currentPage', 'update:perPage'])

const formatRupiah = (value) => {
  const n = Number(value)
  return `Rp ${(Number.isFinite(n) ? n : 0).toLocaleString('id-ID')}`
}

const formatTanggal = (value) => {
  if (!value) return '-'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>
