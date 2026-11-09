<template>
  <div class="pemakaian-card-list">
    <!--
      Kotak pencarian.

      Di HP, tabel enam sampai tujuh kolom tidak bisa muat: satu baris
     pun sudah melebihi lebar layar, jadi user harus menggeser tabel ke
      kanan-kiri hanya untuk membaca nama pelanggan. Karena itu di bawah
      `md` tabel diganti kartu (komponen ini), dan pencarian ikut dipindah
      ke sini -- input "Cari..." milik `DataTable` ikut disembunyikan
      bersamaan dengan tabelnya, supaya tidak ada dua kotak pencarian yang
      hidup bersamaan di layar yang sama.

      Ikonnya FontAwesome, bukan emoji, sama seperti `DataTable`: emoji
      digambar sistem operasi dengan gayanya sendiri dan terlihat
      berbeda dari ikon di komponen lain.
    -->
    <div class="relative! mb-3!">
      <span
        class="absolute! left-3.5! top-1/2! -translate-y-1/2! text-sm! text-slate-400! pointer-events-none!"
      >
        <font-awesome-icon icon="search" />
      </span>
      <input
        :value="modelValue"
        type="text"
        :placeholder="searchPlaceholder"
        class="w-full! pl-9! pr-4! py-2.5! bg-white! border! border-slate-200! rounded-xl! text-sm! text-slate-900! placeholder:text-slate-400! placeholder:font-normal! hover:border-slate-300! focus:outline-none! focus:border-cyan-600! focus:ring-2! focus:ring-cyan-500/15! transition-all!"
        @input="onSearch"
      />
    </div>

    <!-- Skeleton saat data masih dimuat. -->
    <div v-if="loading" class="space-y-3!">
      <div
        v-for="n in 3"
        :key="`skeleton-${n}`"
        class="rounded-2xl! border! border-slate-100! bg-white! p-4! overflow-hidden!"
      >
        <div class="flex! items-center! gap-3! mb-3!">
          <div class="w-10! h-10! rounded-xl! bg-slate-100! animate-pulse!"></div>
          <div class="flex-1! space-y-2!">
            <div class="h-3! w-2/5! rounded! bg-slate-100! animate-pulse!"></div>
            <div class="h-2.5! w-1/4! rounded! bg-slate-100! animate-pulse!"></div>
          </div>
        </div>
        <div class="grid! grid-cols-2! gap-2!">
          <div class="h-10! rounded-xl! bg-slate-100! animate-pulse!"></div>
          <div class="h-10! rounded-xl! bg-slate-100! animate-pulse!"></div>
        </div>
      </div>
    </div>

    <!--
      Keadaan kosong.

      Pesannya sama persis dengan pesan di `DataTable`, supaya jenis
      informasi yang diberikan user sama di HP maupun di layar lebar:
      "tidak ada data" dan "kenapa tidak ada data" itu dua hal berbeda.
    -->
    <div
      v-else-if="paginatedRows.length === 0"
      class="rounded-2xl! border! border-slate-100! bg-white!"
    >
      <EmptyState :title="emptyTitle" :message="emptyMessage" :icon="emptyIcon" />
    </div>

    <div v-else class="space-y-3!">
      <article
        v-for="row in paginatedRows"
        :key="row.id"
        class="rounded-2xl! bg-white! border! border-slate-100! shadow-sm! overflow-hidden!"
      >
        <!-- Nama, nomor induk, dan status. -->
        <div class="flex! items-start! gap-3! p-3.5!">
          <div
            class="w-10! h-10! rounded-xl! flex! items-center! justify-center! text-white! text-xs! font-bold! shrink-0!"
            :style="{ background: row.avatarColor }"
          >
            {{ row.initials }}
          </div>

          <div class="min-w-0! flex-1!">
            <p class="text-sm! font-bold! text-slate-800! truncate! leading-tight!">
              {{ row.nama }}
            </p>
            <p class="mt-1! text-[10px]! text-slate-400! truncate!">
              ID: {{ row.id }}
              <template v-if="row.dusun && row.dusun !== '-'">
                <span class="text-slate-300!">·</span>
                {{ row.dusun }}
              </template>
            </p>
          </div>

          <span
            :class="[
              'inline-flex! items-center! gap-1! px-2! py-0.5! rounded-md! text-[10px]! font-bold! tracking-wider! uppercase! whitespace-nowrap! shrink-0!',
              statusColors[row.status] || '',
            ]"
          >
            • {{ row.status }}
          </span>
        </div>

        <!--
          Empat angka utama.

          Dipisah dengan garis rambut (`gap-px` di atas latar abu) supaya
          blok-bloknya terbaca sebagai satu kesatuan tanpa garistebal yang
          mengambil ruang layar HP.
        -->
        <div class="grid! grid-cols-2! gap-px! bg-slate-100! border-t! border-slate-100!">
          <div class="bg-white! px-3.5! py-2.5!">
            <p class="text-[9px]! font-black! text-slate-400! uppercase! tracking-wider!">Meter</p>
            <p class="mt-1! text-[11px]! font-bold! text-slate-500!">
              {{ formatMeter(row.meterAwal) }}
              <span class="text-slate-300!">→</span>
              <span class="text-cyan-600!">{{ formatMeter(row.meterAkhir) }}</span>
            </p>
          </div>

          <div class="bg-white! px-3.5! py-2.5!">
            <p class="text-[9px]! font-black! text-slate-400! uppercase! tracking-wider!">
              Pemakaian
            </p>
            <p class="mt-1! text-xs! font-extrabold! text-slate-800!">
              {{ row.pemakaian }} <span class="text-[10px]! font-bold! text-slate-400!">m³</span>
            </p>
          </div>

          <div class="bg-white! px-3.5! py-2.5!">
            <p class="text-[9px]! font-black! text-slate-400! uppercase! tracking-wider!">
              Tagihan
            </p>
            <p class="mt-1! text-xs! font-extrabold! text-slate-800!">
              Rp. {{ formatRupiah(row.tagihan) }}
            </p>
            <p
              v-if="Number(row.denda || 0) > 0"
              class="mt-0.5! text-[10px]! font-bold! text-red-500! leading-tight!"
            >
              + denda <span v-if="row.denda_bulan">{{ row.denda_bulan }}</span> Rp.{{
                formatRupiah(row.denda)
              }}
            </p>
          </div>

          <div class="bg-white! px-3.5! py-2.5!">
            <p class="text-[9px]! font-black! text-slate-400! uppercase! tracking-wider!">
              Jatuh Tempo
            </p>
            <p class="mt-1! text-xs! font-bold! text-slate-700!">
              {{ formatDate(row.jatuhTempo) }}
            </p>
          </div>
        </div>

        <!--
          Aksi baris.

          Slot-nya diisi oleh halaman pemanggil, bukan ditulis di sini:
          admin punya tombol ubah/hapus, teknisi tidak punya sama sekali.
          Jadi komponen ini tidak perlu tahu peran pemanggilnya.
        -->
        <div
          v-if="$slots.actions"
          class="flex! items-center! justify-end! gap-2! px-3.5! py-2.5! border-t! border-slate-100! bg-slate-50/60!"
        >
          <slot name="actions" :row="row" />
        </div>
      </article>
    </div>

    <!-- Paginasi ringkas khusus HP. -->
    <div
      v-if="!loading && totalPages > 1"
      class="mt-4! flex! flex-col! gap-2.5! rounded-2xl! border! border-slate-100! bg-white! px-3.5! py-3!"
    >
      <p class="text-[11px]! text-slate-500! text-center!">
        Menampilkan {{ showingInfo.start }}–{{ showingInfo.end }} dari
        <strong class="font-bold! text-slate-800!">{{ showingInfo.total }}</strong> data
      </p>

      <div class="flex! items-center! justify-center! gap-1!">
        <button
          type="button"
          :disabled="currentPage <= 1"
          aria-label="Halaman sebelumnya"
          class="w-9! h-9! rounded-lg! border! border-slate-200! bg-white! text-slate-600! flex! items-center! justify-center! disabled:opacity-40! disabled:cursor-not-allowed! active:scale-95! transition-all!"
          @click="goTo(currentPage - 1)"
        >
          <font-awesome-icon icon="chevron-left" class="text-[10px]!" />
        </button>

        <template v-for="(page, index) in compactPages" :key="`${page}-${index}`">
          <span
            v-if="page === '...'"
            class="min-w-[24px]! h-9! flex! items-center! justify-center! text-slate-400! text-xs!"
          >
            ...
          </span>
          <button
            v-else
            type="button"
            :aria-current="page === currentPage ? 'page' : undefined"
            class="min-w-[36px]! h-9! px-1! rounded-lg! border! text-xs! font-bold! flex! items-center! justify-center! transition-all!"
            :class="
              page === currentPage
                ? 'bg-cyan-600! border-cyan-600! text-white! shadow-sm!'
                : 'bg-white! border-slate-200! text-slate-600! active:scale-95!'
            "
            @click="goTo(page)"
          >
            {{ page }}
          </button>
        </template>

        <button
          type="button"
          :disabled="currentPage >= totalPages"
          aria-label="Halaman berikutnya"
          class="w-9! h-9! rounded-lg! border! border-slate-200! bg-white! text-slate-600! flex! items-center! justify-center! disabled:opacity-40! disabled:cursor-not-allowed! active:scale-95! transition-all!"
          @click="goTo(currentPage + 1)"
        >
          <font-awesome-icon icon="chevron-right" class="text-[10px]!" />
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import EmptyState from './EmptyState.vue'

const props = defineProps({
  /** Baris yang sudah difilter di halaman pemanggil. */
  data: {
    type: Array,
    default: () => [],
  },
  /** Isi kotak pencarian (dipakai `v-model` oleh halaman pemanggil). */
  modelValue: {
    type: String,
    default: '',
  },
  currentPage: {
    type: Number,
    default: 1,
  },
  perPage: {
    type: Number,
    default: 10,
  },
  totalPages: {
    type: Number,
    default: 1,
  },
  loading: {
    type: Boolean,
    default: false,
  },
  /** Peta warna status, dari `types/pemakaianAir.js`. */
  statusColors: {
    type: Object,
    default: () => ({}),
  },
  emptyTitle: {
    type: String,
    default: 'Tidak Ada Data',
  },
  emptyMessage: {
    type: String,
    default: 'Belum ada data pemakaian air untuk periode ini di wilayah Anda.',
  },
  emptyIcon: {
    type: String,
    default: 'filter',
  },
  searchPlaceholder: {
    type: String,
    default: 'Cari nama pelanggan...',
  },
})

const emit = defineEmits(['update:modelValue', 'update:currentPage'])

const onSearch = (event) => {
  emit('update:modelValue', event.target.value)
}

const paginatedRows = computed(() => {
  const start = (props.currentPage - 1) * props.perPage
  return props.data.slice(start, start + props.perPage)
})

const showingInfo = computed(() => {
  const total = props.data.length
  if (total === 0) return { start: 0, end: 0, total: 0 }
  const start = (props.currentPage - 1) * props.perPage + 1
  const end = Math.min(props.currentPage * props.perPage, total)
  return { start, end, total }
})

/**
 * Nomor halaman yang ditampilkan di HP.
 *
 * `DataTable` menampilkan maksimal lima nomor sekaligus plus penanda
 * "..." -- itu muat di layar lebar, tapi di HP baris paginasi jadi
 * sempit sekali: lima tombol 32px plus tombol panah sudah memakan
 * seluruh lebar layar 320px.
 *
 * Di sini cakupannya dipersempit: halaman pertama, halaman terakhir,
 * dan halaman di sekitar posisi sekarang. Sisanya ditandai "..." supaya
 * user tahu masih ada halaman lain di antaranya.
 */
const compactPages = computed(() => {
  const total = props.totalPages
  const current = props.currentPage
  const pages = []

  if (total <= 5) {
    for (let i = 1; i <= total; i++) pages.push(i)
    return pages
  }

  pages.push(1)

  const start = Math.max(2, current - 1)
  const end = Math.min(total - 1, current + 1)

  if (start > 2) pages.push('...')
  for (let i = start; i <= end; i++) pages.push(i)
  if (end < total - 1) pages.push('...')

  pages.push(total)

  return pages
})

const goTo = (page) => {
  if (page < 1 || page > props.totalPages || page === props.currentPage) return
  emit('update:currentPage', page)
}

/** Angka meter dengan pemisah ribuan gaya Indonesia. */
const formatMeter = (val) => Number(val || 0).toLocaleString('id-ID')

/** Nominal rupiah dengan dua angka di belakang koma. */
const formatRupiah = (val) =>
  Number(val || 0).toLocaleString('id-ID', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })

/**
 * Tanggal jatuh tempo.
 *
 * `due_date` dari backend SUDAH tanggal jatuh tempo itu sendiri (hari
 * `batas_tagihan`/`toleransi_tunggakan`). Backend juga memakai tanggal yang
 * sama untuk menandai tunggakan (`due_date < hari ini`), jadi tanggalnya
 * ditampilkan APA ADANYA -- tidak dikurangi satu hari.
 */
const formatDate = (val) => {
  if (!val) return '-'
  const d = new Date(val)
  if (Number.isNaN(d.getTime())) return val
  return d.toLocaleDateString('id-ID', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}
</script>

<style scoped>
.pemakaian-card-list {
  width: 100%;
}
</style>
