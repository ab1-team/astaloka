<template>
  <Teleport to="body">
    <Transition name="modal-fade">
      <div
        v-if="show"
        class="fixed inset-0! z-50 flex items-center justify-center p-0! sm:p-4! md:p-8!"
      >
        <div class="absolute inset-0! bg-slate-900/60! backdrop-blur-sm!" @click="close"></div>

        <div
          class="relative w-full! h-full! max-w-7xl! bg-white rounded-none! sm:rounded-2xl! shadow-xl! border border-slate-200 flex flex-col overflow-hidden animate-slide-up"
        >
          <div
            class="flex items-center! justify-between! gap-3! px-4! sm:px-6! py-3! sm:py-4! border-b! border-slate-200! bg-white! shrink-0!"
          >
            <div class="flex items-center! gap-3! min-w-0! flex-1!">
              <div
                class="w-9! h-9! sm:w-10! sm:h-10! shrink-0! rounded-full! bg-cyan-600! text-white! flex items-center! justify-center!"
              >
                <font-awesome-icon icon="file-alt" />
              </div>
              <div class="min-w-0!">
                <h2
                  class="text-sm! sm:text-lg! font-semibold! text-slate-800 leading-tight truncate!"
                >
                  Hasil Input Pemakaian Air
                </h2>
                <p class="text-[10px]! sm:text-xs! text-slate-500! font-medium! truncate!">
                  Periode: {{ filter.bulan }} {{ filter.tahun }}
                </p>
              </div>
            </div>
            <!--
              Tombol tutup.

              Di HP ukurannya 40px, bukan 32px: ini satu-satunya jalan
              menutup modal selain mengetap area gelap di belakang, dan
              area gelap tidak selalu bisa dijangkau -- kalau modalnya
              penuh tinggi, tangan menutupi bagian bawah layar tempat
              area gelap berada.
            -->
            <button
              @click="close"
              aria-label="Tutup"
              class="w-10! h-10! sm:w-9! sm:h-9! shrink-0! hover:bg-slate-100! flex items-center! justify-center! text-slate-400! hover:text-slate-600! transition-all active:scale-95 rounded-full!"
            >
              <font-awesome-icon icon="times" />
            </button>
          </div>

          <div
            class="px-4! sm:px-6! py-3! sm:py-4! bg-slate-50/50! border-b! border-slate-100! flex flex-col! md:flex-row! md:items-center! justify-between! gap-3! sm:gap-4! shrink-0!"
          >
            <div class="flex flex-col! gap-y-1! text-xs! sm:text-sm! min-w-0!">
              <div class="flex items-center! gap-2! min-w-0!">
                <span class="text-slate-500! whitespace-nowrap! w-24! sm:w-28! shrink-0!"
                  >Teknisi</span
                >
                <span class="font-semibold! text-slate-700! truncate!"
                  >: {{ filter.cater || '-' }}</span
                >
              </div>
              <div class="flex items-center! gap-2! min-w-0!">
                <span class="text-slate-500! whitespace-nowrap! w-24! sm:w-28! shrink-0!"
                  >Maksimal Bayar</span
                >
                <span class="font-semibold! text-slate-700! truncate!">: {{ maksimalBayar }}</span>
              </div>
            </div>

            <div class="relative! w-full! md:w-72! shrink-0!">
              <div
                class="absolute! inset-y-0! left-0! pl-3! flex! items-center pointer-events-none!"
              >
                <font-awesome-icon icon="search" class="text-slate-400! text-xs!" />
              </div>
              <input
                type="text"
                v-model="searchQuery"
                placeholder="Cari ..."
                class="block w-full pl-9! pr-4! py-2! bg-white border border-slate-200 rounded-lg! text-sm! focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition-all shadow-sm!"
              />
            </div>
          </div>

          <!--
            Daftar untuk HP: kartu, bukan tabel.

            Tabelnya punya sepuluh kolom. Kalau tabel itu tetap dipakai di
            layar sempit, satu baris saja sudah lebih lebar dari layar --
            jadi user harus menggeser tabel ke kanan-kiri hanya untuk
            membaca nama pelanggan, lalu menggeser ke kiri lagi untuk
            kembali ke kolom checkbox. Di HP, informasi yang paling sering
            dicari (nama, meter, tagihan) justru berada di kolom paling
            kanan: yang paling sulit dijangkau.

            Jadi di bawah `md` tabelnya diganti kartu: semua angka yang
            dipakai user ada dalam satu kartu, tanpa perlu digeser. Tabel
            aslinya tetap utuh untuk layar lebar -- bukan karena sekadar
            alasan teknis, tapi karena di layar lebar memang jauh lebih padat
            dibandingkan satu kartu per pelanggan.
          -->
          <div
            class="md:hidden! flex-1! overflow-y-auto! overscroll-contain! bg-slate-50/60! pb-3!"
          >
            <!-- Pemilih semua, supaya tidak harus satu per satu di HP. -->
            <label
              class="sticky! top-0! z-10! flex! items-center! gap-2.5! px-3.5! py-2.5! bg-white/95! backdrop-blur! border-b! border-slate-100!"
            >
              <input type="checkbox" v-model="isAllSelected" class="cb-white" />
              <span class="text-xs! font-bold! text-slate-700!">
                Pilih semua ({{ allVisibleIds.length }} pelanggan)
              </span>
            </label>

            <div v-if="Object.keys(filteredGroups).length === 0" class="px-4! py-16! text-center!">
              <font-awesome-icon icon="filter" class="text-slate-200! text-4xl! mb-3!" />
              <p class="text-sm! font-bold! text-slate-500!">Tidak ada data</p>
              <p class="text-xs! text-slate-400! mt-1!">
                {{
                  searchQuery
                    ? 'Coba kata kunci lain.'
                    : 'Belum ada pemakaian yang tercatat pada periode ini.'
                }}
              </p>
            </div>

            <div v-for="(members, dusun) in filteredGroups" :key="dusun" class="mt-3!">
              <!--
                Kepala dusun.

                Nempel di atas (sticky) supaya user tahu sedang berada di
                dusun yang mana -- setelah menggulir beberapa kartu, dusun
                asalnya sudah keluar dari layar.
              -->
              <p
                class="sticky! top-[42px]! z-10! flex! items-center! justify-between! gap-2! px-3.5! py-1.5! bg-slate-200/90! backdrop-blur! text-[10px]! font-black! uppercase! tracking-wider! text-slate-600!"
              >
                <span>Dusun : {{ dusun }}</span>
                <span class="text-slate-500!">{{ members.length }}</span>
              </p>

              <div class="px-3! pt-2! space-y-2!">
                <!--
                  Seluruh kartu jadi area sentuh checkbox.

                  Di HP, mengetuk kotak checkbox 16px kecil adalah target
                  yang mudah terlewat -- dan efeknya bukan cuma satu centang
                  yang terlewat: pelanggan yang terpilih ikut tercetak.
                  Karena itu labelnya membungkus seluruh isi kartu.
                -->
                <label
                  v-for="item in members"
                  :key="item.id"
                  class="flex! items-start! gap-3! rounded-2xl! border! border-slate-200! bg-white! p-3! shadow-sm! transition-colors! active:bg-slate-50!"
                >
                  <input
                    type="checkbox"
                    :value="item.id"
                    v-model="selectedIds"
                    class="cb-white mt-0.5!"
                  />

                  <div class="min-w-0! flex-1!">
                    <div class="flex! items-start! justify-between! gap-2!">
                      <p class="text-sm! font-bold! text-slate-800! truncate! leading-tight!">
                        {{ item.nama }}
                      </p>
                      <span
                        class="shrink-0! inline-flex! items-center! px-1.5! py-0.5! rounded! text-[9px]! font-bold! uppercase! tracking-wider!"
                        :class="
                          item.status === 'PAID'
                            ? 'bg-emerald-100! text-emerald-700!'
                            : 'bg-slate-100! text-slate-600!'
                        "
                      >
                        {{ item.status }}
                      </span>
                    </div>

                    <p class="mt-0.5! text-[10px]! text-slate-400! truncate!">
                      {{ item.desa || '-' }}
                      <template v-if="item.rt && item.rt !== '-'">
                        <span class="text-slate-300!">·</span>
                        {{ item.rt }}
                      </template>
                      <span class="text-slate-300!">·</span>
                      {{ item.id }}
                    </p>

                    <div class="mt-2! grid! grid-cols-2! gap-2!">
                      <div class="rounded-lg! bg-slate-50! px-2! py-1.5!">
                        <p
                          class="text-[8px]! font-black! text-slate-400! uppercase! tracking-wider!"
                        >
                          Meter
                        </p>
                        <p class="mt-0.5! text-[11px]! font-bold! text-slate-600!">
                          {{ item.meterAwal }}
                          <span class="text-slate-300!">→</span>
                          <span class="text-cyan-600!">{{ item.meterAkhir }}</span>
                        </p>
                      </div>

                      <div class="rounded-lg! bg-slate-50! px-2! py-1.5!">
                        <p
                          class="text-[8px]! font-black! text-slate-400! uppercase! tracking-wider!"
                        >
                          Pemakaian
                        </p>
                        <p class="mt-0.5! text-[11px]! font-extrabold! text-slate-800!">
                          {{ item.pemakaian }} m³
                        </p>
                      </div>
                    </div>

                    <p class="mt-2! flex! items-baseline! justify-between! gap-2!">
                      <span
                        class="text-[9px]! font-black! text-slate-400! uppercase! tracking-wider!"
                      >
                        Tagihan Air
                      </span>
                      <span class="text-xs! font-extrabold! text-slate-800!">
                        Rp.
                        {{
                          Number(item.tagihan || 0).toLocaleString('id-ID', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2,
                          })
                        }}
                      </span>
                    </p>
                  </div>
                </label>
              </div>
            </div>
          </div>

          <!--
            Tabel untuk layar lebar.

            Sepuluh kolom muat dengan nyaman di layar lebar, dan tabel
            jauh lebih padat daripada kartu kalau dataAmountnya banyak
            sekaligus. `overflow-x-auto` tetap dipakai sebagai jaring
            pengaman untuk layar antara (tablet), bukan sebagai cara
            utama navigasi.
          -->
          <div
            class="hidden! md:block! flex-1 overflow-y-auto! overflow-x-auto! overscroll-contain! px-6! py-0! scrollbar-custom"
          >
            <table class="w-full min-w-[720px]! border-collapse text-xs md:text-sm!">
              <thead>
                <tr class="bg-slate-700! text-white! sticky! top-0! z-20!">
                  <th class="py-3! px-4! text-center! w-12! bg-[#334155]!">
                    <input type="checkbox" v-model="isAllSelected" class="cb-white" />
                  </th>
                  <th
                    class="py-3! px-4! text-left! font-semibold! uppercase! tracking-wider! bg-[#334155]!"
                  >
                    Nama
                  </th>
                  <th
                    class="py-3! px-4! text-left! font-semibold! uppercase! tracking-wider! bg-[#334155]!"
                  >
                    Desa
                  </th>
                  <th
                    class="py-3! px-4! text-center! font-semibold! uppercase! tracking-wider! bg-[#334155]!"
                  >
                    RT
                  </th>
                  <th
                    class="py-3! px-4! text-left! font-semibold! uppercase! tracking-wider! whitespace-nowrap! bg-[#334155]!"
                  >
                    No. Induk
                  </th>
                  <th
                    class="py-3! px-4! text-center! font-semibold! uppercase! tracking-wider! whitespace-nowrap! bg-[#334155]!"
                  >
                    Meter Awal
                  </th>
                  <th
                    class="py-3! px-4! text-center! font-semibold! uppercase! tracking-wider! whitespace-nowrap! bg-[#334155]!"
                  >
                    Meter Akhir
                  </th>
                  <th
                    class="py-3! px-4! text-center! font-semibold! uppercase! tracking-wider! bg-[#334155]!"
                  >
                    Pemakaian
                  </th>
                  <th
                    class="py-3! px-4! text-right! font-semibold! uppercase! tracking-wider! whitespace-nowrap! bg-[#334155]!"
                  >
                    Tagihan Air
                  </th>
                  <th
                    class="py-3! px-4! text-center! font-semibold! uppercase! tracking-wider! bg-[#334155]!"
                  >
                    Status
                  </th>
                </tr>
              </thead>
              <tbody>
                <template v-for="(members, dusun) in filteredGroups" :key="dusun">
                  <tr class="bg-slate-100/80! border-y! border-slate-200!">
                    <td colspan="10" class="py-2.5! px-4! font-bold text-slate-700!">
                      Dusun : {{ dusun }}
                    </td>
                  </tr>

                  <tr
                    v-for="item in members"
                    :key="item.id"
                    class="hover:bg-slate-50! transition-colors border-b! border-slate-100!"
                  >
                    <td class="py-3! px-4! text-center!">
                      <input
                        type="checkbox"
                        :value="item.id"
                        v-model="selectedIds"
                        class="cb-white"
                      />
                    </td>
                    <td class="py-3! px-4! font-medium text-slate-900!">{{ item.nama }}</td>
                    <td class="py-3! px-4! text-slate-600!">{{ item.desa }}</td>
                    <td class="py-3! px-4! text-center! text-slate-600!">{{ item.rt || '-' }}</td>
                    <td class="py-3! px-4! font-mono! text-slate-500! text-xs!">{{ item.id }}</td>
                    <td class="py-3! px-4! text-center! text-slate-600!">{{ item.meterAwal }}</td>
                    <td class="py-3! px-4! text-center! text-slate-600! font-semibold!">
                      {{ item.meterAkhir }}
                    </td>
                    <td class="py-3! px-4! text-center! text-slate-600!">{{ item.pemakaian }}</td>
                    <td class="py-3! px-4! text-right font-mono font-semibold text-slate-900!">
                      {{
                        Number(item.tagihan || 0).toLocaleString('id-ID', {
                          minimumFractionDigits: 2,
                          maximumFractionDigits: 2,
                        })
                      }}
                    </td>
                    <td class="py-3! px-4! text-center!">
                      <span
                        class="inline-flex! items-center! px-2! py-0.5! rounded text-[10px]! font-bold! uppercase! tracking-wider!"
                        :class="
                          item.status === 'PAID'
                            ? 'bg-emerald-100! text-emerald-700!'
                            : 'bg-slate-100! text-slate-600!'
                        "
                      >
                        {{ item.status }}
                      </span>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>

          <!--
            Kaki modal.

            Di HP selalu ada baris ringkasan pilihan di atas tombolnya.
            Tanpa itu, user tidak tahu berapa pelanggan yang sudah
            dicentang -- dan tombol cetak bisa saja ditekan tanpa satu
            pun pelanggan yang terpilih, yang hasilnya diam-diam tidak
            mencetak apa pun.

            Dua tombolnya tetap berdampingan: keduanya aksi cetak, jadi
            ukurannya sama dan tidak ada hierarki di antara keduanya.
            Label "Cetak Daftar Tagihan" disingkat jadi "Cetak Tagihan" di
            HP supaya tetap satu baris; dari `sm` ke atas kembali penuh.
          -->
          <div
            class="px-4! sm:px-6! py-3! sm:py-4! bg-slate-50! border-t! border-slate-200! shrink-0!"
          >
            <p class="mb-2! flex! items-center! justify-between! gap-2! text-[11px]! md:hidden!">
              <span class="text-slate-500!">Pelanggan dipilih</span>
              <span class="font-bold! text-slate-800!">{{ selectedIds.length }} orang</span>
            </p>

            <div class="flex! items-stretch! gap-2! sm:gap-3!">
              <button
                @click="handleCetakStruk"
                :disabled="selectedIds.length === 0"
                :title="
                  selectedIds.length === 0
                    ? 'Pilih minimal satu pelanggan untuk cetak struk'
                    : 'Cetak struk untuk ' + selectedIds.length + ' pelanggan'
                "
                class="flex-1! flex items-center! justify-center! gap-2! bg-amber-500! hover:bg-amber-600! disabled:opacity-50! disabled:cursor-not-allowed! text-white! px-2! sm:px-6! py-3.5! sm:py-2.5! font-semibold! transition-all active:scale-95 rounded-lg! shadow-md! shadow-amber-200!"
              >
                <font-awesome-icon icon="print" class="shrink-0!" />
                <span class="text-sm! whitespace-nowrap! leading-tight!">Cetak Struk</span>
              </button>

              <button
                @click="handleCetakDaftarTagihan"
                :disabled="selectedIds.length === 0"
                :title="
                  selectedIds.length === 0
                    ? 'Pilih minimal satu pelanggan untuk cetak daftar tagihan'
                    : 'Cetak daftar tagihan untuk ' + selectedIds.length + ' pelanggan'
                "
                class="flex-1! flex items-center! justify-center! gap-2! bg-cyan-600! hover:bg-cyan-700! disabled:opacity-50! disabled:cursor-not-allowed! text-white! px-2! sm:px-6! py-3.5! sm:py-2.5! font-semibold! transition-all active:scale-95 rounded-lg! shadow-md! shadow-cyan-200!"
              >
                <font-awesome-icon icon="print" class="shrink-0!" />
                <span class="text-sm! whitespace-nowrap! leading-tight!"
                  ><span class="sm:hidden!">Cetak Tagihan</span
                  ><span class="hidden! sm:inline!">Cetak Daftar Tagihan</span></span
                >
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  groupedData: {
    type: Object,
    default: () => ({}),
  },
  filter: {
    type: Object,
    default: () => ({}),
  },
})

const emit = defineEmits(['close'])

const router = useRouter()

const searchQuery = ref('')
const selectedIds = ref([])

const bulanList = [
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

const maksimalBayar = computed(() => {
  const bulanIdx = bulanList.indexOf(props.filter?.bulan)
  const tahun = Number(props.filter?.tahun) || new Date().getFullYear()
  if (bulanIdx === -1) return '25'
  return `25/${String(bulanIdx + 1).padStart(2, '0')}/${tahun}`
})

const close = () => {
  emit('close')
}

const allVisibleIds = computed(() => {
  return Object.values(filteredGroups.value)
    .flat()
    .map((item) => item.id)
})

const isAllSelected = computed({
  get: () =>
    allVisibleIds.value.length > 0 &&
    allVisibleIds.value.every((id) => selectedIds.value.includes(id)),
  set: (val) => {
    if (val) {
      const newSelections = new Set([...selectedIds.value, ...allVisibleIds.value])
      selectedIds.value = Array.from(newSelections)
    } else {
      selectedIds.value = selectedIds.value.filter((id) => !allVisibleIds.value.includes(id))
    }
  },
})

const filteredGroups = computed(() => {
  const hidePending = (items) => items.filter((i) => (i.status || '').toUpperCase() !== 'PENDING')

  if (!searchQuery.value) {
    const result = {}
    Object.entries(props.groupedData).forEach(([dusun, members]) => {
      const filtered = hidePending(members)
      if (filtered.length > 0) result[dusun] = filtered
    })
    return result
  }

  const query = searchQuery.value.toLowerCase()
  const result = {}

  Object.entries(props.groupedData).forEach(([dusun, members]) => {
    const matched = hidePending(members).filter(
      (item) =>
        (item.nama || '').toLowerCase().includes(query) ||
        String(item.id ?? '')
          .toLowerCase()
          .includes(query),
    )
    if (matched.length > 0) {
      result[dusun] = matched
    }
  })

  return result
})

const buildReportQuery = () => {
  const query = {
    tahun: props.filter?.tahun || '',
    bulan: props.filter?.bulan || '',
  }
  const teknisiId = props.filter?.teknisi
  const teknisiName = props.filter?.cater
  if (teknisiId) query.teknisi = teknisiId
  if (teknisiName) query.cater = teknisiName
  return query
}

const handleCetakDaftarTagihan = () => {
  if (selectedIds.value.length === 0) return
  sessionStorage.setItem('cetak_print_ids_daftar', selectedIds.value.join(','))
  const url = router.resolve({
    name: 'Cetak Daftar Tagihan',
    query: buildReportQuery(),
  }).href
  window.open(url, '_blank')
}

const handleCetakStruk = () => {
  if (selectedIds.value.length === 0) return
  sessionStorage.setItem('cetak_print_ids_struk', selectedIds.value.join(','))
  const url = router.resolve({
    name: 'Cetak Struk',
    query: buildReportQuery(),
  }).href
  window.open(url, '_blank')
}

const handleKeydown = (e) => {
  if (e.key === 'Escape' && props.show) {
    close()
  }
}

watch(
  () => props.show,
  (val) => {
    if (val) {
      document.body.style.overflow = 'hidden'
      selectedIds.value = [...allVisibleIds.value]
    } else {
      document.body.style.overflow = ''
    }
  },
)

onMounted(() => {
  window.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown)
  document.body.style.overflow = ''
})
</script>

<style scoped>
.cb-white {
  appearance: none;
  -webkit-appearance: none;
  width: 16px;
  height: 16px;
  background-color: #ffffff !important;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  cursor: pointer;
  position: relative;
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
  flex-shrink: 0;
}
.cb-white:hover {
  border-color: var(--color-primary, #0891b2);
}
.cb-white:focus {
  outline: none;
  box-shadow: 0 0 0 3px rgb(var(--color-primary-rgb, 8 145 178) / 0.2);
}
.cb-white:checked {
  background-color: #ffffff !important;
  border-color: var(--color-primary, #0891b2);
}
.cb-white:checked::after {
  content: '';
  position: absolute;
  left: 4px;
  top: 0px;
  width: 5px;
  height: 10px;
  border: solid var(--color-primary, #0891b2);
  border-width: 0 2px 2px 0;
  transform: rotate(45deg);
}
.modal-fade-enter-active,
.modal-fade-leave-active {
  transition: opacity 0.4s ease;
}

.modal-fade-enter-from,
.modal-fade-leave-to {
  opacity: 0;
}

@keyframes slide-up {
  from {
    transform: translateY(30px) scale(0.98);
    opacity: 0;
  }
  to {
    transform: translateY(0) scale(1);
    opacity: 1;
  }
}

.animate-slide-up {
  animation: slide-up 0.5s cubic-bezier(0.16, 1, 0.3, 1);
}

.scrollbar-custom::-webkit-scrollbar {
  width: 6px;
  height: 6px;
}

.scrollbar-custom::-webkit-scrollbar-track {
  background: transparent;
}

.scrollbar-custom::-webkit-scrollbar-thumb {
  background: #e2e8f0;
  border-radius: 10px;
}

.scrollbar-custom::-webkit-scrollbar-thumb:hover {
  background: #cbd5e1;
}
</style>
