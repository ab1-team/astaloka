<template>
  <div class="arsip-piutang-root">
    <!-- Judul + penjelasan -->
    <div class="flex! flex-col! lg:flex-row! lg:items-start! lg:justify-between! gap-4! mb-6!">
      <div class="flex-1!">
        <h1 class="text-xl md:text-2xl font-bold text-slate-900! tracking-tight mb-1!">
          Arsip Piutang
        </h1>
        <p class="text-xs md:text-sm text-slate-500! leading-relaxed">
          Daftar piutang pelanggan yang belum tertagih. Piutang masuk pembukuan saat
          <strong>generate piutang</strong> dijalankan — bukan saat tagihan dibuat. Tagihan periode
          Mei, misalnya, baru tercatat sebagai piutang pada generate bulan Juni.
        </p>
      </div>

      <div class="flex! flex-wrap! gap-2 md:gap-3! w-full lg:w-auto!">
        <BaseButton
          variant="info-gradient"
          size="md"
          :loading="loading"
          @click="fetchData"
          class="w-full! lg:w-auto! rounded-xl! shadow-md! text-xs md:text-sm"
          icon="redo-alt"
        >
          Muat Ulang Data
        </BaseButton>
      </div>
    </div>

    <!-- Filter periode -->
    <ContentCard variant="elevated" padding="small" class="mb-4!">
      <div class="flex! flex-wrap! items-end! gap-3!">
        <!--
          Filter bulan/tahun pakai `BaseSelect` (PrimeVue Select), bukan
          `<select>` native.

          Alasannya dua:

            1. Ada kotak PENCARIAN di dalam dropdown-nya. Daftar bulan
               cuma 12, tapi daftar tahun bertambah setiap tahun -- dan
               "Semua Tahun" jadi pilihan yang jauh lebih sering dipakai
               daripada tahun tertentu. Kotak pencarian membuat pilihan itu
               satu ketukan, bukan menggulir.

            2. Label panjang tidak terpotong. `<select>` native memotong
               teks sesuai lebar elemen, jadi "Semua Bulan" bisa berubah
               jadi "Semua Bul…". Yang tampil tidak lagi sama dengan yang
               tertulis -- dan user tidak bisa memastikan sedang memfilter
               apa.

          "Semua Bulan" / "Semua Tahun" tetap OPSI NYATA (bukan placeholder
          kosong), jadi filter aktif selalu terlihat -- lihat catatan
          sentinel `__semua__` di `BaseSelect.vue`.
        -->
        <div class="flex! flex-col! gap-1!">
          <label
            for="arsip-piutang-bulan"
            class="text-[11px]! font-bold! text-slate-500! uppercase! tracking-wider!"
          >
            Bulan Pencatatan
          </label>
          <BaseSelect
            id="arsip-piutang-bulan"
            v-model="bulanTerpilih"
            :options="opsiBulan"
            option-label="label"
            option-value="value"
            placeholder="Semua Bulan"
            filter-placeholder="Cari bulan..."
            :show-clear="false"
            class="arsip-filter-select"
          />
        </div>

        <div class="flex! flex-col! gap-1!">
          <label
            for="arsip-piutang-tahun"
            class="text-[11px]! font-bold! text-slate-500! uppercase! tracking-wider!"
          >
            Tahun
          </label>
          <BaseSelect
            id="arsip-piutang-tahun"
            v-model="tahunTerpilih"
            :options="opsiTahun"
            option-label="label"
            option-value="value"
            placeholder="Semua Tahun"
            filter-placeholder="Cari tahun..."
            :show-clear="false"
            class="arsip-filter-select arsip-filter-select--tahun"
          />
        </div>

        <!--
          Tombol reset.

          Tingginya SENGAJA disamakan dengan tinggi select (`2.35rem`).
          `items-end` pada baris filter aligning semua anak ke garis bawah,
          dan tanpa tinggi yang sama tombol ini terlihat melayang: dasar
          teksnya sejajar dengan dasar select, tapi kotak tombolnya tidak --
          hasilnya satu langkah lebih rendah dan baris filter terasa
          miring.

          `v-if` disembunyikan kalau tidak ada filter aktif. Tombol yang
          selalu ada tapi tidak melakukan apa-apa adalah noise, bukan
          kendali.

          Ikon `rotate-left` (bukan `times`) karena yang dilakukan adalah
          mengembalikan filter ke keadaan awal -- bukan menutup atau
          membatalkan dialog.
        -->
        <BaseButton
          v-if="filterAktif"
          variant="ghost"
          size="sm"
          icon="rotate-left"
          @click="resetFilter"
          class="arsip-filter-reset"
        >
          Reset Filter
        </BaseButton>

        <div class="flex-1!"></div>

        <!-- Info tanggal generate -->
        <div class="text-[11px]! text-slate-500! leading-relaxed! pb-1! text-right!">
          <div v-if="ringkasan.generate_terakhir">
            Generate terakhir:
            <strong class="text-slate-700!">{{
              formatTanggal(ringkasan.generate_terakhir)
            }}</strong>
          </div>
          <div v-if="ringkasan.generate_berikutnya">
            Generate berikutnya:
            <strong class="text-slate-700!">{{
              formatTanggal(ringkasan.generate_berikutnya)
            }}</strong>
          </div>
          <div v-if="!ringkasan.generate_berikutnya">
            Tanggal generate piutang <strong class="text-amber-600!">belum diatur di SOP</strong>
          </div>
        </div>
      </div>
    </ContentCard>

    <!-- Kartu ringkasan -->
    <div class="grid! grid-cols-1! sm:grid-cols-2! lg:grid-cols-4! gap-4! mb-4!">
      <div class="bg-white! rounded-xl! shadow-sm! px-4! py-3! border! border-slate-100!">
        <div class="text-[10px]! font-bold! text-slate-400! uppercase! tracking-wider! mb-1!">
          Total Piutang Tercatat
        </div>
        <div class="text-lg! font-extrabold! text-slate-800! font-mono! whitespace-nowrap!">
          {{ formatRupiah(ringkasan.periode?.total_piutang) }}
        </div>
        <div class="text-[10px]! text-slate-400! mt-0.5!">
          {{ ringkasan.periode?.jml_tercatat ?? 0 }} tagihan sudah dibukukan
        </div>
      </div>

      <div class="bg-white! rounded-xl! shadow-sm! px-4! py-3! border! border-slate-100!">
        <div class="text-[10px]! font-bold! text-slate-400! uppercase! tracking-wider! mb-1!">
          Total Tagihan Menunggak
        </div>
        <div class="text-lg! font-extrabold! text-slate-800! font-mono! whitespace-nowrap!">
          {{ formatRupiah(ringkasan.periode?.total_tagihan) }}
        </div>
        <div class="text-[10px]! text-slate-400! mt-0.5!">
          {{ ringkasan.periode?.jml_pelanggan ?? 0 }} pelanggan
        </div>
      </div>

      <div class="bg-white! rounded-xl! shadow-sm! px-4! py-3! border! border-slate-100!">
        <div class="text-[10px]! font-bold! text-slate-400! uppercase! tracking-wider! mb-1!">
          Denda
        </div>
        <div class="text-lg! font-extrabold! text-rose-600! font-mono! whitespace-nowrap!">
          {{ formatRupiah(ringkasan.periode?.total_denda) }}
        </div>
        <div class="text-[10px]! text-slate-400! mt-0.5!">Denda tertagih pada periode ini</div>
      </div>

      <div class="bg-white! rounded-xl! shadow-sm! px-4! py-3! border! border-slate-100!">
        <div class="text-[10px]! font-bold! text-slate-400! uppercase! tracking-wider! mb-1!">
          Menunggu Generate
        </div>
        <div class="text-lg! font-extrabold! text-amber-600! font-mono! whitespace-nowrap!">
          {{ ringkasan.periode?.jml_menunggu ?? 0 }}
        </div>
        <div class="text-[10px]! text-slate-400! mt-0.5!">Tagihan belum dibukukan jadi piutang</div>
      </div>
    </div>

    <!-- Tabel -->
    <ContentCard variant="elevated" padding="none" class="overflow-hidden!">
      <div
        v-if="errorMessage"
        class="px-4! py-3! bg-rose-50! border-b! border-rose-200! text-rose-700! text-xs! font-semibold! flex! items-center! gap-2!"
      >
        <font-awesome-icon icon="exclamation-triangle" class="text-sm!" />
        Gagal memuat data: {{ errorMessage }}
      </div>

      <ArsipPiutangTable
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
    </ContentCard>

    <div class="text-center py-8 text-[10px] text-slate-400 font-semibold tracking-[2px] uppercase">
      ASTALOKA · LAYANAN PUBLIK MASYARAKAT
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BaseButton from '@/presentations/components/ui/BaseButton.vue'
import BaseSelect from '@/presentations/components/ui/BaseSelect.vue'
import ContentCard from '@/presentations/components/ui/ContentCard.vue'
import ArsipPiutangTable from './parts/ArsipPiutangTable.vue'
import { useArsipPiutang } from '@/composables/useArsipPiutang'

const route = useRoute()
const router = useRouter()

const namaBulan = [
  'Januari',
  'Februari',
  'Maret',
  'April',
  'Mei',
  'Juni',
  'Juli',
  'Agustus',
  'September',
  'Oktober',
  'November',
  'Desember',
]

/*
 * Filter periode.
 *
 * Default-nya BULAN BERJALAN, bukan semua bulan: permintaan aslinya adalah
 * "lihat siapa yang punya piutang bulan ini", dan bulan berjalan itulah
 * yang baru saja jadi piutang setelah generate terakhir.
 *
 * Nilai awal diambil dari query string (`?bulan=6&tahun=2026`) supaya
 * tautan dari dropdown avatar dan dari popup generate piutang bisa
 * langsung membuka periode yang dimaksud.
 */
const bulanAwal = Number(route.query.bulan)
const tahunAwal = Number(route.query.tahun)

const bulanDefault = bulanAwal >= 1 && bulanAwal <= 12 ? bulanAwal : new Date().getMonth() + 1
const tahunDefault = tahunAwal >= 2000 && tahunAwal <= 2100 ? tahunAwal : new Date().getFullYear()

const bulanTerpilih = ref(bulanDefault)
const tahunTerpilih = ref(tahunDefault)

/*
 * Opsi dropdown untuk `BaseSelect`.
 *
 * Bentuknya `{ label, value }` -- pasangan yang dipakai `BaseSelect`
 * (lihat `option-label` / `option-value` di pemanggilnya).
 *
 * "Semua Bulan" dan "Semua Tahun" bernilai `null`. `BaseSelect` mengganti
 * nilai kosong itu dengan sentinel `__semua__` saat merender, lalu
 * menerjemahkannya kembali jadi `null` saat user memilih -- jadi parent
 * tetap hanya pernah melihat `null` atau angka, sama seperti filter
 * biasa di halaman ini.
 */
const opsiBulan = computed(() => [
  { label: 'Semua Bulan', value: null },
  ...namaBulan.map((nama, index) => ({ label: nama, value: index + 1 })),
])

const opsiTahun = computed(() => {
  const sekarang = new Date().getFullYear()
  const hasil = [{ label: 'Semua Tahun', value: null }]

  for (let t = sekarang + 1; t >= sekarang - 5; t--) {
    hasil.push({ label: String(t), value: t })
  }

  return hasil
})

const {
  searchQuery,
  currentPage,
  perPage,
  loading,
  itemsList,
  serverTotal,
  totalPages,
  errorMessage,
  ringkasan,
  fetchData,
} = useArsipPiutang({ bulan: bulanTerpilih, tahun: tahunTerpilih })

/*
 * Reset filter.
 *
 * Kembali ke periode AWAL halaman ini -- bulan dan tahun yang dipakai saat
 * halaman dibuka -- bukan ke "semua bulan".
 *
 * Bedanya penting. Nilai awal halaman ini adalah bulan berjalan (atau periode
 * dari query string), jadi "kembali ke awal" berarti "kembali ke yang
 * ditampilkan pertama kali". Kalau reset justru mengosongkan filter,
 * hasilnya "semua bulan" -- yang justru BERBEDA dari yang dibuka user, dan
 * tidak pernah jadi yang mereka maksud saat menekan "Reset Filter".
 *
 * "Semua Bulan" / "Semua Tahun" tetap bisa dipilih manual dari dropdown-nya.
 */
const resetFilter = () => {
  bulanTerpilih.value = bulanDefault
  tahunTerpilih.value = tahunDefault
}

/*
 * Ada filter aktif atau tidak.
 *
 * Yang dibandingkan adalah nilai SEKARANG dengan nilai AWAL halaman. Selama
 * dua-duanya sama, tidak ada yang perlu di-reset -- dan tombolnya tidak
 * perlu tampil.
 *
 * Menilai "nilai !== null" tidak bisa dipakai di sini: nilai awal halaman
 * ini justru bulan dan tahun BERJALAN (lihat `bulanDefault` di atas), jadi
 * kriteria itu selalu benar dan tombolnya akan selalu muncul -- bahkan
 * saat tidak ada yang perlu di-reset. Tombol yang selalu ada tapi tidak
 * melakukan apa-apa adalah noise, bukan kendali.
 */
const filterAktif = computed(
  () => bulanTerpilih.value !== bulanDefault || tahunTerpilih.value !== tahunDefault,
)

/*
 * Filter ikut ditulis ke query string.
 *
 * Alasannya: halaman ini bisa dibuka dari dropdown avatar dan dari popup
 * generate piutang. Tanpa query string, kedua tempat itu selalu membuka
 * periode default -- dan orang yang ingin melihat periode lain harus
 * memilih ulang filter setiap kali membuka halaman.
 */
const sinkronkanQuery = () => {
  const query = {}

  if (bulanTerpilih.value !== null) query.bulan = bulanTerpilih.value
  if (tahunTerpilih.value !== null) query.tahun = tahunTerpilih.value

  router.replace({ query }).catch(() => {})
}

// `watch` di sini sengaja TIDAK dipakai untuk memanggil fetchData: filter
// sudah reaktif di `useArsipPiutang`, jadi perubahan-nya sudah memicu
// fetch sendiri. Yang perlu di sini hanya menyelaraskan URL.
watch([bulanTerpilih, tahunTerpilih], sinkronkanQuery)

const columns = [
  { key: 'nomorInduk', title: 'No. Pelanggan' },
  { key: 'customer', title: 'Nama' },
  { key: 'alamat', title: 'Alamat' },
  { key: 'periodeLabel', title: 'Periode Tagihan', thClass: 'whitespace-nowrap!' },
  { key: 'jatuhTempo', title: 'Jatuh Tempo', thClass: 'whitespace-nowrap!' },
  {
    key: 'tanggalPencatatan',
    title: 'Tercatat',
    thClass: 'whitespace-nowrap!',
  },
  { key: 'tagihan', title: 'Tagihan', thClass: 'text-right!', tdClass: 'text-right!' },
  { key: 'denda', title: 'Denda', thClass: 'text-right!', tdClass: 'text-right!' },
  {
    key: 'piutangTercatat',
    title: 'Piutang Tercatat',
    thClass: 'text-right!',
    tdClass: 'text-right!',
  },
  { key: 'total', title: 'Total Tagihan', thClass: 'text-right!', tdClass: 'text-right!' },
  { key: 'status', title: 'Status' },
]

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

onMounted(() => {
  // `useArsipPiutang` sudah mengambil data saat mount. Fungsi ini
  // sengaja hanya ada supaya halaman yang dibuka dari tautan dengan
  // query string juga punya filter yang sesuai di URL.
  sinkronkanQuery()
})
</script>

<style scoped>
/*
 * Tinggi baris filter.
 *
 * SATU sumber kebenaran untuk select dan tombol reset. Kalau keduanya
 * punya tinggi yang berbeda, baris yang pakai `items-end` akan terlihat
 * miring: kotak tombolnya lebih pendek dari select dan berdiri sedikit
 * lebih rendah.
 *
 * Nilainya 2.75rem, bukan 2.35rem (min-height bawaan `.base-select`).
 * Yang-BAWAAN itu terlalu pendek untuk isi select yang sebenarnya: label
 * PrimeVue punya padding 8px vertikal + line-height sendiri, jadi select
 * aslinya render setinggi ~43px, bukan 37.6px. Kalau tombol reset ikut
 * 2.35rem, justru tombolnya yang jadi terlalu pendek -- masalahnya cuma
 * pindah tempat.
 */
.arsip-piutang-root {
  width: 100%;
  --arsip-filter-height: 2.75rem;
}

/*
 * Lebar dropdown filter.
 *
 * WAJIB lewat `:deep()`. `BaseSelect.vue` merender elemen
 * `<div class="p-select ...">` dari dalam file-nya sendiri, jadi elemen itu
 * punya `data-v-BASESELECT`, bukan `data-v-ARSIPPIUTANGPAGE`.
 *
 * Dan elemen root komponen anak TIDAK mewarisi scope-id parent kalau
 * komponennya punya lebih dari satu root -- dan `BaseSelect` punya dua
 * (`<Select>` + `<small>` untuk pesan validasi). Akibatnya aturan
 * `.arsip-filter-select` yang ditulis scoped dikompilasi jadi
 *
 *     .arsip-filter-select[data-v-ARSIPPIUTANGPAGE] { ... }
 *
 * dan tidak pernah cocok dengan elemen yang benar-benar dirender. Gejalanya
 * persis seperti "CSS-nya ada tapi tidak jalan", tanpa satu pun error.
 *
 * Aturan yang menyasar isi komponen anak SELALU perlu `:deep()`.
 *
 * `height` (bukan `min-height`) dipakai supaya tinggi select PASTI sama
 * dengan tinggi tombol reset di sebelahnya. `min-height` hanya batas
 * bawah: kalau isinya lebih tinggi, select akan tumbuh melebihi tombol --
 * dan masalahnya balik lagi, tapi di arah sebaliknya.
 */
.arsip-piutang-root :deep(.arsip-filter-select) {
  width: 11rem;
  max-width: 100%;
  height: var(--arsip-filter-height);
}

/* Tahun lebih pendek -- digitnya jauh lebih sedikit dari nama bulan. */
.arsip-piutang-root :deep(.arsip-filter-select--tahun) {
  width: 8rem;
}

/*
 * Tombol reset disejajarkan dengan select di sebelahnya.
 *
 * `!important` dipakai karena `.base-button--sm` menulis padding, font-size,
 * dan radius-nya dengan `!important` juga (lihat `ui-base.css`). Tanpa itu,
 * aturan di file ini kalah dan tombolnya tetap terlihat beda.
 *
 * Border tipis dipakai supaya tombol ini terbaca sebagai bagian dari
 * kelompok filter, bukan tombol aksi utama yang berdekatan dengan select.
 */
.arsip-piutang-root :deep(.arsip-filter-reset) {
  height: var(--arsip-filter-height) !important;
  padding-left: 0.75rem !important;
  padding-right: 0.75rem !important;
  border: 1px solid var(--color-border, #e2e8f0) !important;
  border-radius: 8px !important;
  background: #fff !important;
  color: #475569 !important;
  font-size: 0.8rem !important;
  white-space: nowrap;
}

.arsip-piutang-root :deep(.arsip-filter-reset:hover) {
  background: #f8fafc !important;
  border-color: var(--color-primary, #0ea5e9) !important;
  color: var(--color-primary, #0ea5e9) !important;
}
</style>
