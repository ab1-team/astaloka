<template>
  <div class="input-pemakaian-air-root min-h-screen">
    <!--
      Kepala halaman (tablet ke atas).

      Di HP kepala halaman punya bentuk sendiri -- lihat blok `md:hidden`
      di bawah. Alasannya: di layar sempit, judul + tombol kembali +
      keterangan "Tagihan otomatis saat simpan meter" tidak muat dalam
      satu baris tanpa dipotong, dan keterangan itu justru yang memberi
      tahu admin bahwa menyimpan meter akan langsung membuat tagihan.
    -->
    <div
      class="hidden! md:flex! flex-col! md:flex-row! md:items-center! justify-between! gap-4! mb-6!"
    >
      <div class="flex! items-center! gap-3! md:gap-4!">
        <BaseButton
          variant="ghost"
          icon="chevron-left"
          class="w-8! h-8! md:w-10! md:h-10! p-0! rounded-full! bg-white! shadow-sm!"
          @click="handleBack"
        />
        <div>
          <h1 class="text-xl! md:text-2xl! font-extrabold! text-slate-800! tracking-tight!">
            Input Pemakaian Air
          </h1>
          <p class="text-xs! md:text-sm! text-slate-500!">
            Silakan input meter akhir pelanggan pada form di bawah ini
          </p>
        </div>
      </div>

      <div v-if="uiStore.userRole !== 'teknisi'" class="shrink-0!">
        <div
          class="flex! items-center! gap-2! text-xs! text-slate-500! bg-slate-50! px-3! py-2! rounded-xl!"
        >
          <font-awesome-icon icon="info-circle" class="text-cyan-500!" />
          <span>Tagihan otomatis saat simpan meter</span>
        </div>
      </div>
    </div>

    <div class="hidden! md:block!">
      <ContentCard variant="default" padding="none" hoverable class="overflow-hidden!">
        <DataTable
          :data="filteredData"
          :columns="tableColumns"
          title=""
          v-model:current-page="currentPage"
          v-model:per-page="perPage"
          :total-pages="totalPages"
          :visible-pages="visiblePages"
          :total-entries="filteredData.length"
          v-model="searchQuery"
          @row-click="handleRowClick"
          row-clickable
          search-placeholder="Cari Pelanggan..."
          :no-card="true"
        >
          <template #column-meterAwal="{ row }">
            <span class="text-slate-500!">{{ formatMeter(row.meterAwal) }}</span>
          </template>

          <!--
            Tidak ada slot kolom meter akhir / pemakaian di tabel desktop.

            Kedua slot itu dulu ada, tapi `tableColumns` tidak memuat
            kunci `meterAkhir` maupun `pemakaian` -- dan `DataTable`
            hanya me-render slot untuk kolom yang terdaftar, jadi slotnya
            tidak pernah terpakai. Me-render input di sana pun tidak
            berguna: tidak ada tombol simpan di tabel, jadi angka yang
            diketik tidak akan di mana-mana.

            Pencatatan di desktop sama dengan di HP: klik baris untuk
            membuka popup input meter. Satu jalur untuk kedua layar.
          -->
        </DataTable>
      </ContentCard>
    </div>

    <!--
      Kepala halaman (HP).

      Judul dan tombol kembali dirapatkan jadi satu blok ringkas, dan
      keterangan "Tagihan otomatis" dipindah ke baris ringkasan di bawah
      daftar -- di sana masih terbaca, tapi tidak lagi memakan tinggi
      layar yang paling mahal: ruang untuk mengetik angka meter.
    -->
    <div
      class="md:hidden! bg-white! rounded-[2.25rem]! border! border-slate-100! shadow-[0_20px_50px_rgba(0,0,0,0.04)]! overflow-hidden!"
    >
      <div class="px-4! pt-4! pb-3! border-b! border-slate-50!">
        <div class="flex! items-center! justify-between! gap-2!">
          <div class="flex! items-center! gap-2.5! min-w-0!">
            <BaseButton
              variant="ghost"
              icon="chevron-left"
              class="w-8! h-8! shrink-0! p-0! rounded-full! bg-slate-100! text-slate-500!"
              @click="handleBack"
            />
            <div class="min-w-0!">
              <h1 class="text-base! font-black! text-slate-800! leading-tight! truncate!">
                Input Pemakaian Air
              </h1>
              <div class="flex! items-center! gap-2! min-w-0!">
                <p class="text-[10px]! text-slate-400! truncate!">
                  {{ customers.length }} pelanggan belum dicatat
                </p>

                <!--
                  Penanda "tagihan otomatis".

                  Di HP labelnya dipendekkan jadi "Otomatis" -- dengan ikon
                  petir, artinya sudah jelas. Bentuk penuh "Tagihan
                  otomatis" memakan ruang yang cukup untuk membuat judul
                  halaman terpotong jadi "Input Pem...", dan judul yang
                  terpotong lebih buruk daripada penanda yang singkat.

                  Di layar paling sempit (bawah `sm`) penandanya turun ke
                  baris subtitle, bukan tetap di kanan atas. Alasannya:
                  di 320px, ruang di samping judul hanya cukup untuk
                  "~Input Pem..." -- judul halaman yang terpotong jauh
                  lebih mengganggu daripada satu baris subtitle yang
                  sedikit lebih tinggi.
                -->
                <span
                  class="sm:hidden! shrink-0! inline-flex! items-center! gap-1! px-1.5! py-0.5! rounded-md! bg-cyan-50! text-cyan-700! text-[9px]! font-black! uppercase! tracking-wider!"
                  title="Tagihan otomatis dibuat begitu meter disimpan"
                >
                  <font-awesome-icon icon="bolt" class="text-[9px]!" />
                  Otomatis
                </span>
              </div>
            </div>
          </div>

          <!--
            Penanda "tagihan otomatis" untuk layar `sm` ke atas.

            Di sinilah posisinya natural: judul sudah punya ruang cukup,
            jadi penanda bisa berdiri sendiri di kanan atas.
          -->
          <span
            class="hidden! sm:inline-flex! shrink-0! items-center! gap-1! px-2! py-1! rounded-lg! bg-cyan-50! text-cyan-700! text-[9px]! font-black! uppercase! tracking-wider!"
            title="Tagihan otomatis dibuat begitu meter disimpan"
          >
            <font-awesome-icon icon="bolt" class="text-[9px]!" />
            Tagihan otomatis
          </span>
        </div>

        <div class="mt-3! relative!">
          <span class="absolute! left-3.5! top-1/2! -translate-y-1/2! text-slate-400! text-sm!">
            <font-awesome-icon icon="search" />
          </span>
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Cari nama atau no. induk..."
            class="w-full! pl-10! pr-4! py-3! bg-slate-50! border! border-slate-200! rounded-2xl! text-sm! text-slate-700! focus:outline-none! focus:ring-2! focus:ring-cyan-500/10! focus:border-cyan-400! transition-all!"
          />
        </div>
      </div>

      <!--
        Daftar pelanggan.

        Kartu HP hanya MENAMPILKAN status pencatatan -- tanpa kolom
        input, tanpa tombol simpan, tanpa ikon kamera.

        Kenapa tidak ada input langsung: ikon kamera yang dulu
        menemani kolom itu membuka popup yang SAMA. Jadi ada dua jalan
        menuju form yang identik -- kolom di kartu untuk mengetik, ikon
        kamera untuk membuka popup -- dan teknisi harus menebak mana
        yang dipakai. Dua jalan untuk satu pekerjaan selalu berakhir
        dengan salah satu terabaikan.

        Sekarang ada satu jalan: ketuk baris "Meter Akhir" di bawah,
        popup terbuka, isi angkanya di sana (termasuk foto dan scan
        kalau memang diperlukan), lalu simpan.
      -->
      <div v-if="isLoading" class="p-4! space-y-3!">
        <div
          v-for="n in 3"
          :key="`skeleton-${n}`"
          class="rounded-2xl! border! border-slate-100! p-4! space-y-3!"
        >
          <div class="h-3! w-1/2! rounded! bg-slate-100! animate-pulse!"></div>
          <div class="h-10! rounded-xl! bg-slate-100! animate-pulse!"></div>
        </div>
      </div>

      <div v-else-if="paginatedData.length === 0" class="p-12! text-center!">
        <font-awesome-icon icon="search" class="text-slate-200! text-4xl! mb-3!" />
        <p class="text-slate-400! text-sm!">Data tidak ditemukan</p>
        <p class="text-slate-300! text-xs! mt-1!">
          {{
            customers.length === 0
              ? 'Semua pelanggan periode ini sudah dicatat.'
              : 'Coba kata kunci lain.'
          }}
        </p>
      </div>

      <div v-else class="p-3! space-y-3!">
        <article
          v-for="row in paginatedData"
          :key="row.noInduk"
          class="rounded-2xl! border! border-slate-100! shadow-[0_4px_20px_rgba(0,0,0,0.02)]! overflow-hidden!"
        >
          <div class="p-3.5!">
            <div class="flex! items-start! gap-3!">
              <div
                class="w-10! h-10! rounded-xl! bg-linear-brand! flex! items-center! justify-center! text-white! font-bold! text-sm! shadow-sm! shrink-0!"
              >
                {{ row.nama.charAt(0) }}
              </div>
              <div class="min-w-0! flex-1!">
                <p class="text-sm! font-black! text-slate-800! truncate! leading-tight!">
                  {{ row.nama }}
                </p>
                <div class="mt-1! flex! items-center! gap-1.5! text-[9px]!">
                  <span class="font-bold! text-slate-400! font-mono! truncate!">
                    ID: {{ row.noInduk }}
                  </span>
                  <span class="text-slate-300! shrink-0!">|</span>
                  <span class="font-bold! text-slate-400! truncate!">{{ row.dusun }}</span>
                </div>
              </div>
            </div>

            <!-- Angka pembanding: meter awal dan pemakaian bulan lalu. -->
            <div
              class="mt-3! grid! grid-cols-2! gap-2! bg-slate-50! p-2.5! rounded-xl! border! border-slate-100/50!"
            >
              <div class="pl-2!">
                <p
                  class="text-[8px]! font-black! text-slate-400! uppercase! tracking-wider! mb-0.5!"
                >
                  METER AWAL
                </p>
                <p class="text-xs! font-bold! text-slate-700!">
                  {{ formatMeter(row.meterAwal) }} m³
                </p>
              </div>
              <div class="pl-3! border-l! border-slate-200!">
                <p
                  class="text-[8px]! font-black! text-slate-400! uppercase! tracking-wider! mb-0.5!"
                >
                  PAKAI LALU
                </p>
                <p class="text-xs! font-bold! text-cyan-600!">
                  {{ formatMeter(row.usageLalu) }} m³
                </p>
              </div>
            </div>

            <!--
              Keterangan meter akhir.

              Baris ini SATU-SATUNYA pintu ke form input: tidak ada kolom
              yang bisa diketik, tidak ada tombol simpan, dan tidak ada
              ikon kamera terpisah. Dulu ketiganya ada sekaligus di kartu
              ini -- ikon kameranya justru membuka popup, sehingga ada dua
              jalan ke form yang sama dan teknisi harus menebak mana yang
              dipakai.

              Sekarang statusnya ditampilkan sebagai keterangan, dan
              mengetuk baris inilah yang membuka popup. Satu jalan.
            -->
            <button
              type="button"
              class="mt-3! w-full! flex! items-center! gap-3! rounded-xl! border-2! border-dashed! border-cyan-200! bg-cyan-50/40! px-3.5! py-3! text-left! cursor-pointer! transition-all! active:bg-cyan-50!"
              @click="handleRowClick(row)"
            >
              <span
                class="w-9! h-9! rounded-lg! bg-white! border! border-cyan-100! flex! items-center! justify-center! text-cyan-500! shrink-0!"
              >
                <font-awesome-icon icon="gauge-high" class="text-sm!" />
              </span>
              <span class="min-w-0! flex-1!">
                <span
                  class="block! text-[9px]! font-black! text-cyan-600! uppercase! tracking-wider!"
                >
                  Meter Akhir (Bulan Ini)
                </span>
                <span class="block! text-xs! font-bold! text-slate-400! mt-0.5!">
                  Belum dicatat. Ketuk untuk mengisi meter.
                </span>
              </span>
              <font-awesome-icon icon="chevron-right" class="text-cyan-400! shrink-0!" />
            </button>
          </div>
        </article>
      </div>

      <!--
        Paginasi HP.

        Mirip pola di `PemakaianCardList`, tapi nomor halamannya lebih
        sedikit: satu kartu memakai tinggi layar yang jauh lebih besar
        daripada satu baris tabel, jadi jumlah kartu per halaman ikut
        dikecilkan.
      -->
      <div
        v-if="totalPages > 1"
        class="px-4! py-4! bg-slate-50/50! border-t! border-slate-100! flex! items-center! justify-between! gap-3!"
      >
        <span class="text-[11px]! font-bold! text-slate-500! shrink-0!">
          Halaman {{ currentPage }} / {{ totalPages }}
        </span>

        <div class="flex! items-center! gap-2!">
          <BaseButton
            variant="ghost"
            size="sm"
            :disabled="currentPage === 1"
            aria-label="Halaman sebelumnya"
            @click="currentPage--"
            class="w-10! h-10! p-0! rounded-full! bg-white! border! border-slate-200! text-slate-500! shadow-sm!"
          >
            <font-awesome-icon icon="chevron-left" class="text-[10px]!" />
          </BaseButton>
          <BaseButton
            variant="ghost"
            size="sm"
            :disabled="currentPage === totalPages"
            aria-label="Halaman berikutnya"
            @click="currentPage++"
            class="w-10! h-10! p-0! rounded-full! bg-white! border! border-slate-200! text-slate-500! shadow-sm!"
          >
            <font-awesome-icon icon="chevron-right" class="text-[10px]!" />
          </BaseButton>
        </div>
      </div>
    </div>

    <InputMeterModal
      :show="isModalOpen"
      :customer="selectedCustomer"
      @close="isModalOpen = false"
      @save="handleSaveMeter"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useUiStore } from '@/stores/uiStore'
import { meterService } from '@/services/meter.service'
import { MySwal } from '@/utils/swal.js'
import BaseButton from '@/presentations/components/ui/BaseButton.vue'
import DataTable from '@/presentations/components/ui/DataTable.vue'
import ContentCard from '@/presentations/components/ui/ContentCard.vue'
import InputMeterModal from './inputMeterModal.vue'

const router = useRouter()
const uiStore = useUiStore()

const isLoading = ref(false)
const customers = ref([])

const getMonthNumber = (m) => {
  if (!m) return new Date().getMonth() + 1
  if (!isNaN(m)) return parseInt(m)

  const monthNamesId = [
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
  const idxId = monthNamesId.indexOf(m)
  if (idxId !== -1) return idxId + 1

  const monthNamesEn = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'Mei',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
  ]
  const idxEn = monthNamesEn.indexOf(m)
  if (idxEn !== -1) return idxEn + 1

  return new Date().getMonth() + 1
}

const fetchData = async () => {
  try {
    isLoading.value = true
    const { month, year, bulan, tahun } = router.currentRoute.value.query

    const monthVal = getMonthNumber(month || bulan)
    const yearVal = parseInt(year || tahun) || new Date().getFullYear()

    const res = await meterService.getPendingReadings({ month: monthVal, year: yearVal })
    if (res.data) {
      // Map data backend ke format tabel jika perlu
      customers.value = res.data.map((item) => {
        // Meter Awal diambil dari pencatatan bulan lalu
        const awal =
          item.meter_readings && item.meter_readings.length > 0
            ? item.meter_readings[0].meter_value
            : item.initial_meter_reading

        // Pemakaian bulan lalu: dari MonthlyBill > MeterReading > 0
        let usageLalu = 0
        if (item.monthly_bills && item.monthly_bills.length > 0) {
          usageLalu = item.monthly_bills[0].usage_m3
        } else if (item.meter_readings && item.meter_readings.length > 0) {
          usageLalu = Math.max(
            0,
            item.meter_readings[0].meter_value - (item.initial_meter_reading || 0),
          )
        }

        const address = item.ticket?.address || ''
        let rtVal = '-'
        const rtMatch = address.match(/rt\s*[:.]?\s*(\d+)/i)
        if (rtMatch) {
          rtVal = 'RT ' + rtMatch[1]
        }
        const dusunVal = address ? address.split(',')[0] : '-'

        return {
          nama: item.user?.name || item.ticket?.applicant_name || 'Tanpa Nama',
          noInduk: item.customer_code || '-',
          dusun: dusunVal,
          rt: rtVal,
          meterAwal: awal || 0,
          usageLalu: usageLalu,
          id: item.id,
        }
      })
    }
  } catch {
    // Daftar pemakaian gagal diambil. Tabel dibiarkan apa adanya supaya
    // halaman tidak ikut gagal.
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  fetchData()
})

/**
 * Kembali ke daftar pemakaian.
 *
 * Query periode dan teknisi diteruskan, supaya daftar yang dibuka kembali
 * menampilkan periode yang sama dengan yang tadi dicatat -- bukan periode
 * default hari ini.
 */
const handleBack = () => {
  const { month, year, bulan, tahun, teknisi } = router.currentRoute.value.query

  const query = {
    tahun: String(parseInt(year || tahun, 10) || new Date().getFullYear()),
    bulan: String(getMonthNumber(month || bulan)),
  }

  if (teknisi) query.teknisi = String(teknisi)

  if (uiStore.userRole === 'teknisi') {
    router.push({ name: 'Input Pemakaian Air Teknisi', query })
  } else {
    router.push({ name: 'Pemakaian', query })
  }
}

const isModalOpen = ref(false)
const selectedCustomer = ref({})

const perPage = ref(10)
const searchQuery = ref('')
const currentPage = ref(1)

watch(perPage, () => {
  currentPage.value = 1
})

const handleRowClick = (row) => {
  selectedCustomer.value = { ...row }
  isModalOpen.value = true
}

/**
 * Simpan meteran ke server.
 *
 * Satu-satunya jalur penyimpanan: popup input meter (dengan foto atau
 * scan). Dulu ada jalur kedua -- mengetik langsung di kartu HP lalu
 * menekan tombol simpan di kartu -- tapi jalur itu dihapus karena
 * membuka dua pintu ke form yang sama. Sekarang kartu hanya
 * menampilkan status, dan mengetuknya membuka popup ini.
 *
 * Pelanggan yang disimpan DIHAPUS dari daftar, dan halaman TIDAK
 * dialihkan: popup tertutup, barisnya hilang, teknisi langsung melihat
 * pelanggan berikutnya yang belum dicatat. Pengalihan justru merugikan
 * di sini -- teknisi yang mengisi belasan kartu akan terlempar keluar
 * setiap kali menyimpan, dan harus memilih teknisi + periode lagi
 * sebelum bisa lanjut.
 */
const simpanMeteran = async (customer, meterValue, photo) => {
  try {
    uiStore.setLoading(true)

    const { month, year, bulan, tahun } = router.currentRoute.value.query
    const monthVal = getMonthNumber(month || bulan)
    const yearVal = parseInt(year || tahun) || new Date().getFullYear()

    const formData = new FormData()
    formData.append('customer_id', customer.id)
    formData.append('meter_value', meterValue)
    // Foto meteran hanya bukti pendukung dan boleh tidak ada. Backend
    // menerima `photo` kosong, jadi field ini hanya dikirim kalau ada.
    if (photo) {
      formData.append('photo', photo)
    }
    formData.append('reading_month', monthVal)
    formData.append('reading_year', yearVal)

    const res = await meterService.submitReading(formData)
    const data = res?.data ?? res

    if (data?.suspended) {
      await MySwal.fire({
        title: 'Pelanggan Disuspend!',
        text: 'Tagihan sudah dibuat. Pelanggan memiliki tunggakan dan statusnya diubah menjadi SUSPENDED.',
        icon: 'warning',
        confirmButtonText: 'Mengerti',
        confirmButtonColor: '#f59e0b',
        customClass: {
          popup: 'rounded-2xl!',
          confirmButton: 'rounded-xl! px-5! py-2.5! font-bold!',
        },
      })
    } else {
      uiStore.success('Meter & tagihan berhasil disimpan!')
    }

    const index = customers.value.findIndex((c) => c.noInduk === customer.noInduk)
    if (index !== -1) {
      customers.value.splice(index, 1)
    }

    isModalOpen.value = false

    // Sengaja TIDAK dialihkan ke halaman daftar. Pilihan ini dibuat
    // setelah jalur simpan langsung dari kartu dihapus: popup kini satu-
    // satunya pintu, jadi pengalihan berarti teknisi terlempar ke daftar
    // setiap kali menyimpan -- lalu harus memilih teknisi dan periode
    // lagi sebelum lanjut ke pelanggan berikutnya.
    //
    // Admin pun begitu: halaman "Data Pemakaian" mengharuskan teknisi
    // dipilih dulu (lihat `requireTeknisi` di pemakaianAir.vue), jadi
    // kembali ke sana tanpa filter membuat tabel kosong dan baris yang
    // baru disimpan seolah hilang.
    //
    // Yang terjadi sekarang: popup tertutup, barisnya sudah terhapus dari
    // `customers` di atas, dan daftar tinggal menampilkan pelanggan yang
    // belum dicatat. Satu ketukan lagi ke pelanggan berikutnya.
  } catch (error) {
    uiStore.error(error.response?.data?.message || 'Gagal menyimpan data')
  } finally {
    uiStore.setLoading(false)
  }
}

const handleSaveMeter = async (payload) => {
  await simpanMeteran(selectedCustomer.value, payload.meterValue, payload.photo)
}

const tableColumns = [
  { key: 'nama', title: 'NAMA PELANGGAN', tdClass: 'font-medium! text-slate-500!' },
  { key: 'dusun', title: 'DUSUN / ALAMAT', tdClass: 'text-slate-500!' },
  {
    key: 'noInduk',
    title: 'NO.INDUK',
    tdClass: 'text-slate-500! hidden! sm:table-cell!',
    thClass: 'hidden! sm:table-cell!',
  },
  {
    key: 'meterAwal',
    title: 'METER AWAL',
    tdClass: 'text-right! w-32! text-slate-500!',
  },
  {
    key: 'usageLalu',
    title: 'PAKAI BULAN LALU',
    tdClass: 'text-right! w-40! text-blue-600! font-bold!',
  },
]

const formatMeter = (val) => {
  const num = Number(val)
  if (isNaN(num)) return val
  return num.toString()
}

const filteredData = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()

  const list = q
    ? customers.value.filter((item) => {
        return (
          item.nama.toLowerCase().includes(q) ||
          item.noInduk.toLowerCase().includes(q) ||
          item.dusun.toLowerCase().includes(q)
        )
      })
    : customers.value

  // Urutkan nama pelanggan A -> Z (locale id-ID supaya huruf &
  // ejaan Indonesia disortir dengan benar).
  return [...list].sort((a, b) =>
    a.nama.localeCompare(b.nama, 'id', { sensitivity: 'base', numeric: true }),
  )
})

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * perPage.value
  const end = start + perPage.value
  return filteredData.value.slice(start, end)
})

const totalPages = computed(() => {
  return Math.max(1, Math.ceil(filteredData.value.length / perPage.value))
})

const visiblePages = computed(() => {
  const pages = []
  for (let i = 1; i <= Math.min(3, totalPages.value); i++) {
    pages.push(i)
  }
  return pages
})
</script>
