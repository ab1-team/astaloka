<template>
  <div class="pemakaian-air-root">
    <!--
      Kepala halaman.

      Di HP judul dan subjudul berdiri sendiri, terpisah dari tombol
      aksi. Kalau tombolnya ikut dijejajarkan dengan judul (tata letak
      `lg:flex-row` di bawah), di layar sempit subjudulnya terpotong atau
      tombolnya melebar tidak proporsional.
    -->
    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4!">
      <div class="flex-1! min-w-0!">
        <h1 class="text-xl md:text-2xl font-bold text-slate-900! tracking-tight mb-1!">
          Data Pemakaian
        </h1>
        <p class="text-xs md:text-sm text-slate-500! leading-relaxed!">
          Manage and monitor regional consumption cycles and billing.
        </p>
      </div>

      <!--
        Aksi utama di HP.

        "Input Pemakaian" adalah aksi yang paling sering dipakai, jadi
        dikasih baris penuh paling atas. Dua aksi lain -- cetak form dan
        lihat hasil input -- jarang dipakai dan berbagi satu baris di
        bawahnya.

        Di layar lebar (`lg`) tata letak kembali seperti semula: tiga
        tombol berdampingan di kanan, sejajar dengan judul.
      -->
      <div class="lg:hidden! w-full!">
        <BaseButton
          variant="primary-gradient"
          size="md"
          @click="handleInputPemakaian"
          class="w-full! rounded-xl! shadow-lg! shadow-indigo-200/50! text-sm! py-3!"
          icon="plus"
        >
          Input Pemakaian
        </BaseButton>

        <div class="mt-2! grid! grid-cols-2! gap-2!">
          <BaseButton
            variant="warning-gradient"
            size="md"
            @click="handleCetakFormInput"
            class="w-full! rounded-xl! shadow-lg! shadow-amber-200/50! text-xs! py-2.5!"
            icon="print"
          >
            Cetak Form
          </BaseButton>

          <BaseButton
            variant="success-gradient"
            size="md"
            @click="handleHasilInput"
            class="w-full! rounded-xl! shadow-lg! shadow-emerald-200/50! text-xs! py-2.5!"
            icon="file-alt"
          >
            Hasil Input
          </BaseButton>
        </div>
      </div>

      <div class="hidden! lg:flex! lg:flex-wrap! lg:items-center! gap-2 md:gap-3! lg:w-auto!">
        <BaseButton
          variant="warning-gradient"
          size="md"
          @click="handleCetakFormInput"
          class="rounded-xl! shadow-lg! shadow-amber-200/50! text-xs md:text-sm"
          icon="print"
        >
          Cetak Form
        </BaseButton>

        <BaseButton
          variant="success-gradient"
          size="md"
          @click="handleHasilInput"
          class="rounded-xl! shadow-lg! shadow-emerald-200/50! text-xs md:text-sm"
          icon="file-alt"
        >
          Hasil Input
        </BaseButton>

        <BaseButton
          variant="primary-gradient"
          size="md"
          @click="handleInputPemakaian"
          class="rounded-xl! shadow-lg! shadow-indigo-200/50! text-xs md:text-sm"
          icon="plus"
        >
          Input Pemakaian
        </BaseButton>
      </div>
    </div>

    <!--
      Filter periode.

      Tahun dan bulan berbagi satu baris -- dua dropdown setinggi 44px
      itu muat di layar 320px dengan sisa ruang yang cukup untuk teks
      filter. Filter teknisi tetap satu baris penuh di bawahnya, karena
      namanya bisa jauh lebih panjang ("Pilih Teknisi" yang sudah
      terpilih) dan dipotong di tengah kalimat jadi tidak berguna.

      Filter ini sengaja TIDAK ikut sticky. Saat dropdown-nya terbuka,
      isinya turun ke bawah dan akan tertutup header yang menempel; dan
      admin berganti periode atau teknisi paling banyak sekali sebulan.
    -->
    <ContentCard variant="elevated" padding="normal" hoverable class="mt-4!">
      <div class="grid! grid-cols-2! gap-3! lg:flex! lg:flex-row! lg:items-end! lg:gap-4!">
        <div class="min-w-0!">
          <SelectSearch
            v-model="filter.tahun"
            :options="[{ id: '', text: 'Tahun' }, ...tahunOptions.map((y) => ({ id: y, text: y }))]"
            placeholder="Tahun"
            no-margin
          />
        </div>

        <div class="min-w-0!">
          <SelectSearch
            v-model="filter.bulan"
            :options="[{ id: '', text: 'Bulan' }, ...bulanOptions.map((b) => ({ id: b, text: b }))]"
            placeholder="Bulan"
            no-margin
          />
        </div>

        <div class="col-span-2! min-w-0! lg:flex-1! lg:col-span-1!">
          <SelectSearch
            v-model="filter.teknisi"
            :options="[
              { id: '', text: 'Pilih Teknisi' },
              ...teknisiOptions.map((t) => ({ id: t.id, text: t.name })),
            ]"
            placeholder="Pilih Teknisi"
            no-margin
          />
        </div>
      </div>
    </ContentCard>

    <!--
      Ringkasan periode aktif.

      Di HP tabelnya diganti kartu, jadi angka "berapa data yang termuat"
      tidak lagi kelihatan dari tabel -- dan tanpa itu admin bisa saja
      mengira datanya kosong padahal filternya salah teknisi.
    -->
    <div class="mt-4! flex! items-center! justify-between! gap-3! md:hidden!">
      <p class="text-[11px]! text-slate-500! min-w-0! truncate!">
        Periode
        <strong class="font-bold! text-slate-700!">{{ filter.bulan }} {{ filter.tahun }}</strong>
      </p>
      <p class="text-[11px]! text-slate-400! shrink-0!">{{ filteredData.length }} data</p>
    </div>

    <!--
      Daftar data: kartu di HP, tabel di layar lebar.

      `DataTable` (tabel) dan `PemakaianCardList` (kartu) menampilkan
      data yang sama -- pemotongan halaman dan pencarian dikendalikan
      oleh state yang sama di `usePemakaianAir`, jadi keduanya tidak
      pernah menampilkan isi yang berbeda.

      Kenapa tidak satu komponen saja? Karena `DataTable` dipakai banyak
      halaman lain, dan mengubahnya untuk breakpoint HP berarti
     Looks seperti perubahan yang menyentuh seluruh aplikasi. Jadi
      `DataTable` TIDAK disentuh; untuk HP dipakai komponen terpisah ini.

      Yang disembunyikan bukan hanya tabelnya, tapi juga kotak
      "Cari..." milik `DataTable` -- kalau tidak, ada dua kotak pencarian
      yang hidup bersamaan di layar yang sama, dan keduanya mengubah
      data yang sama.
    -->
    <div class="md:hidden! mt-4!">
      <PemakaianCardList
        :data="filteredData"
        v-model="searchQuery"
        v-model:current-page="currentPage"
        :per-page="perPage"
        :total-pages="totalPages"
        :loading="isLoading"
        :status-colors="STATUS_COLORS"
        :empty-title="requireTeknisi ? 'Tidak Ada Data' : 'Pilih Teknisi Dahulu'"
        :empty-message="
          requireTeknisi
            ? 'Belum ada data yang tersedia di sini untuk saat ini.'
            : 'Silakan pilih teknisi pada filter di atas untuk mulai memuat data pemakaian.'
        "
        empty-icon="filter"
        search-placeholder="Cari nama atau ID pelanggan..."
      >
        <!--
          Aksi baris: ubah dan hapus.

          Di HP tombolnya dibuat penuh (setinggi 40px) dan diberi label
          teks, bukan ikon saja: di layar kecil ikon 32px mudah keliru
          ditekan -- dan "hapus" adalah tindakan yang tidak bisa dibatalkan
          dengan satu ketukan.
        -->
        <template #actions="{ row }">
          <BaseButton
            variant="ghost"
            size="sm"
            @click="handleEdit(row)"
            class="flex-1! h-10! rounded-xl! border! border-slate-200! bg-white! text-slate-700! font-bold! text-xs! shadow-sm!"
            icon="edit"
          >
            Ubah
          </BaseButton>

          <BaseButton
            variant="ghost"
            size="sm"
            @click="handleDelete(row)"
            class="flex-1! h-10! rounded-xl! border! border-red-200! bg-white! text-red-600! font-bold! text-xs! shadow-sm!"
            icon="trash"
          >
            Hapus
          </BaseButton>
        </template>
      </PemakaianCardList>
    </div>

    <div class="hidden! md:block!">
      <DataTable
        :data="filteredData"
        :columns="tableColumns"
        v-model:current-page="currentPage"
        v-model:per-page="perPage"
        :total-pages="totalPages"
        :visible-pages="visiblePages"
        :total-entries="filteredData.length"
        v-model="searchQuery"
        :loading="isLoading"
        :empty-title="requireTeknisi ? 'Tidak Ada Data' : 'Pilih Teknisi Dahulu'"
        :empty-message="
          requireTeknisi
            ? 'Belum ada data yang tersedia di sini untuk saat ini.'
            : 'Silakan pilih teknisi pada filter di atas untuk mulai memuat data pemakaian.'
        "
        empty-icon="filter"
        class="mt-6!"
        search-placeholder="Cari..."
      >
        <template #column-nama="{ row }">
          <div class="flex items-center gap-3!">
            <div
              class="w-9! h-9! rounded-full! flex items-center justify-center text-white! text-xs! font-bold! shrink-0!"
              :style="{ background: row.avatarColor }"
            >
              {{ row.initials }}
            </div>
            <div>
              <div class="font-semibold! text-sm! text-slate-900! mb-0.5!">
                {{ row.nama }}
              </div>
              <div class="text-xs! text-slate-400! font-normal!">ID: {{ row.id }}</div>
            </div>
          </div>
        </template>

        <template #column-meterRange="{ row }">
          <div class="flex items-center gap-2!">
            <span class="text-sm! font-medium! text-slate-500!">
              {{ row.meterAwal.toLocaleString('id-ID') }}
            </span>
            <span class="text-sm! text-slate-300!">→</span>
            <span class="text-sm! font-semibold! text-cyan-600!">
              {{ row.meterAkhir.toLocaleString('id-ID') }}
            </span>
          </div>
        </template>

        <template #column-pemakaian="{ row }">
          <span class="text-sm! font-semibold! text-slate-900!"> {{ row.pemakaian }}</span>
        </template>

        <template #column-tagihan="{ row }">
          <div class="font-bold! text-sm! text-slate-900!">
            Rp.
            {{
              Number(row.tagihan || 0).toLocaleString('id-ID', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
              })
            }}
          </div>
          <div
            v-if="Number(row.denda || 0) > 0"
            class="mt-0.5! text-[10px]! font-semibold! text-red-500! leading-tight!"
          >
            + denda <span v-if="row.denda_bulan"> {{ row.denda_bulan }}</span> Rp.{{
              Number(row.denda).toLocaleString('id-ID', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
              })
            }}
          </div>
        </template>

        <template #column-jatuhTempo="{ row }">
          <span class="text-sm! text-slate-700!">
            {{ formatDate(row.jatuhTempo) }}
          </span>
        </template>

        <template #column-status="{ row }">
          <span
            :class="[
              'inline-flex! items-center! gap-1! px-2! py-0.5! rounded-md! text-[10px]! font-bold! tracking-wider! uppercase! whitespace-nowrap!',
              STATUS_COLORS[row.status] || '',
            ]"
          >
            • {{ row.status }}
          </span>
        </template>

        <template #column-aksi="{ row }">
          <div class="flex items-center gap-2!">
            <BaseButton
              variant="ghost"
              size="sm"
              @click="handleEdit(row)"
              class="w-8! h-8! p-0! rounded-lg! border! border-slate-100! hover:border-blue-200! hover:bg-blue-50! text-slate-600! hover:text-blue-600! shadow-sm!"
              title="Ubah"
              icon="edit"
            />
            <BaseButton
              variant="ghost"
              size="sm"
              @click="handleDelete(row)"
              class="w-8! h-8! p-0! rounded-lg! border! border-slate-100! hover:border-red-200! hover:bg-red-50! text-slate-600! hover:text-red-600! shadow-sm!"
              title="Hapus"
              icon="trash"
            />
          </div>
        </template>
      </DataTable>
    </div>

    <HasilInputModal
      :show="showHasilModal"
      :grouped-data="groupedData"
      :filter="{ ...filter, cater: selectedTeknisiName }"
      @close="showHasilModal = false"
    />

    <EditPemakaianModal
      :show="showEditModal"
      :data="selectedRow"
      :on-save="handleSaveEdit"
      @close="showEditModal = false"
    />
  </div>
</template>

<script setup>
import { usePemakaianAir } from '@/composables/usePemakaianAir'
import SelectSearch from '@/presentations/components/SelectSearch.vue'
import DataTable from '@/presentations/components/ui/DataTable.vue'
import ContentCard from '@/presentations/components/ui/ContentCard.vue'
import BaseButton from '@/presentations/components/ui/BaseButton.vue'
import PemakaianCardList from '@/presentations/components/ui/PemakaianCardList.vue'
import HasilInputModal from './partials/hasilInputModal.vue'
import EditPemakaianModal from './editPemakaianAir.vue'
import { useRouter, useRoute } from 'vue-router'
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { parseLocalDate } from '@/utils/date'

const {
  filter,
  searchQuery,
  currentPage,
  perPage,
  tahunOptions,
  bulanOptions,
  teknisiOptions,
  selectedTeknisiName,
  filteredData,
  groupedData,
  totalPages,
  visiblePages,
  STATUS_COLORS,
  showEditModal,
  isLoading,
  selectedRow,
  handleApplyFilter,
  applyFilterFromQuery,
  handleEdit,
  handleSaveEdit,
  handleDelete,
  loadTeknisiOptions,
} = usePemakaianAir()

const requireTeknisi = computed(() => !!filter.value.teknisi)

let teknisiDebounceTimer = null
watch(
  () => filter.value.teknisi,
  (val) => {
    if (teknisiDebounceTimer) clearTimeout(teknisiDebounceTimer)
    teknisiDebounceTimer = setTimeout(() => {
      if (val) {
        handleApplyFilter()
      }
    }, 300)
  },
)
onUnmounted(() => {
  if (teknisiDebounceTimer) clearTimeout(teknisiDebounceTimer)
})

onMounted(async () => {
  await loadTeknisiOptions()
  await loadFromRoute()
})

const route = useRoute()

/**
 * Muat data sesuai filter di URL.
 *
 * Halaman ini dibuka dari "Input Pemakaian Air" setelah meter disimpan.
 * Query `tahun`/`bulan`/`teknisi` dibawa ke sini supaya tabel langsung
 * menampilkan periode dan teknisi yang sama -- baris yang baru disimpan
 * jadi terlihat tanpa perlu memilih filter manual.
 *
 * Filter teknisi wajib dipilih dulu oleh admin (lihat `requireTeknisi`),
 * jadi datanya hanya dimuat kalau filter teknisi-nya benar-benar ada.
 */
const loadFromRoute = async () => {
  applyFilterFromQuery(route.query)

  if (filter.value.teknisi) {
    await handleApplyFilter()
  }
}

const showHasilModal = ref(false)

const handleHasilInput = () => {
  showHasilModal.value = true
}

const router = useRouter()

const handleInputPemakaian = () => {
  router.push({
    name: 'Input Pemakaian Air',
    query: {
      tahun: filter.value.tahun,
      bulan: filter.value.bulan,
      teknisi: filter.value.teknisi,
    },
  })
}

const handleCetakFormInput = () => {
  const teknisiId = filter.value.teknisi
  const teknisiName =
    teknisiOptions.value.find((t) => String(t.id) === String(teknisiId))?.name ||
    selectedTeknisiName.value
  const url = router.resolve({
    name: 'Cetak Form',
    query: {
      tahun: filter.value.tahun,
      bulan: filter.value.bulan,
      teknisi: teknisiId,
      cater: teknisiName,
    },
  }).href
  window.open(url, '_blank')
}

const tableColumns = [
  { key: 'nama', title: 'NAMA / NO. INDUK' },
  { key: 'meterRange', title: 'METER' },
  { key: 'pemakaian', title: 'PEMAKAIAN' },
  { key: 'tagihan', title: 'TAGIHAN' },
  { key: 'jatuhTempo', title: 'TGL AKHIR BAYAR' },
  { key: 'status', title: 'STATUS' },
  { key: 'aksi', title: 'AKSI' },
]

/**
 * Format "TGL AKHIR BAYAR".
 *
 * `monthly_bills.due_date` SUDAH merupakan tanggal jatuh tempo itu sendiri
 * (hari `batas_tagihan`/`toleransi_tunggakan`, mis. 27), dan backend memakai
 * tanggal yang sama untuk menandai tagihan menunggak (`due_date < hari ini`).
 * Jadi tanggalnya ditampilkan APA ADANYA -- tidak dikurangi satu hari.
 *
 * `due_date` dikirim sebagai UTC midnight ("2026-11-27T00:00:00.000000Z").
 * `new Date(...)` mengartikannya sebagai instant UTC, sehingga di zona waktu
 * negatif `getDate()` bisa jadi 26. Parse eksplisit sebagai LOCAL supaya
 * tanggalnya pasti utuh.
 */
const formatDate = (val) => {
  if (!val) return '-'
  const d = parseLocalDate(val)
  if (!d) return val
  return d.toLocaleDateString('id-ID', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}
</script>

<style scoped>
.pemakaian-air-root {
  width: 100%;
}
</style>
