<template>
  <div class="custom-select-search" :class="{ 'mb-2!': !noMargin }">
    <!-- Label -->
    <label v-if="label" class="select-label">{{ label }}</label>

    <div class="select-wrapper" ref="dropdownRef">
      <!-- Input Area -->
      <div
        class="select-display"
        :class="{
          'select-display--active': isOpen,
          'select-display--selected': multiple && selectedOptions.length > 0,
          'select-display--error': error,
          'select-display--disabled': disabled,
          'select-display--multiple': multiple,
        }"
        @click="toggleDropdown"
      >
        <div class="select-content">
          <font-awesome-icon v-if="icon" :icon="icon" class="select-content-icon" />
          <template v-if="multiple">
            <div v-if="selectedOptions.length === 0" class="placeholder">
              {{ placeholder }}
            </div>
            <div v-else class="flex! flex-wrap! gap-1! items-center!">
              <span
                v-for="opt in selectedOptions"
                :key="getValue(opt)"
                class="chip-tag"
                @click.stop
              >
                {{ getLabel(opt) }}
                <button type="button" class="chip-remove" @click.stop="removeOption(opt)">
                  <font-awesome-icon icon="times" />
                </button>
              </span>
            </div>
          </template>
          <template v-else>
            <span v-if="!selectedOption" class="placeholder">
              {{ placeholder }}
            </span>
            <span v-else class="selected-text" :class="{ 'has-icon': icon }">
              {{ getLabel(selectedOption) }}
            </span>
          </template>
        </div>
        <font-awesome-icon
          icon="chevron-down"
          class="chevron-icon"
          :class="{ 'chevron-icon--active': isOpen }"
        />
      </div>

      <!-- Dropdown Menu -->
      <transition name="fade-slide">
        <div
          v-if="isOpen"
          ref="panelRef"
          class="select-dropdown"
          :class="{ 'select-dropdown--ujung': geserKeUjung }"
        >
          <!-- Search Header -->
          <div v-if="searchable" class="search-container">
            <div class="search-input-wrapper">
              <font-awesome-icon icon="search" class="search-icon" />
              <input
                ref="searchInput"
                v-model="searchQuery"
                type="text"
                :placeholder="searchPlaceholder"
                class="search-input-field"
                @click.stop
                @keydown.esc="closeDropdown"
              />
            </div>
          </div>

          <!-- Options List -->
          <div class="options-list custom-scrollbar">
            <div
              v-for="option in filteredOptions"
              :key="getValue(option)"
              class="option-item"
              :class="{ 'option-item--selected': isSelected(option) }"
              @click="selectOption(option)"
            >
              <template v-if="multiple">
                <label class="option-checkbox" @click.stop>
                  <input
                    type="checkbox"
                    :checked="isSelected(option)"
                    @change="selectOption(option)"
                  />
                  <span>{{ getLabel(option) }}</span>
                </label>
              </template>
              <template v-else>
                <div class="option-label">
                  {{ getLabel(option) }}
                </div>
                <div v-if="isSelected(option)" class="selected-indicator">
                  <font-awesome-icon icon="check" />
                </div>
              </template>
            </div>

            <!-- Empty State -->
            <div v-if="filteredOptions.length === 0" class="empty-state">
              <font-awesome-icon icon="search" class="text-slate-200! text-3xl! mb-2!" />
              <p>Tidak ada data ditemukan</p>
            </div>
          </div>
        </div>
      </transition>
    </div>

    <!-- Error/Hint -->
    <div v-if="error" class="error-text">{{ error }}</div>
    <div v-if="hint && !error" class="hint-text">{{ hint }}</div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'

const props = defineProps({
  modelValue: {
    type: [String, Number, Object, Array],
    default: null,
  },
  options: {
    type: Array,
    default: () => [],
  },
  label: {
    type: String,
    default: '',
  },
  placeholder: {
    type: String,
    default: 'Pilih opsi...',
  },
  icon: {
    type: String,
    default: '',
  },
  searchable: {
    type: Boolean,
    default: true,
  },
  multiple: {
    type: Boolean,
    default: false,
  },
  searchPlaceholder: {
    type: String,
    default: 'Cari...',
  },
  labelKey: {
    type: String,
    default: 'label',
  },
  valueKey: {
    type: String,
    default: 'value',
  },
  error: {
    type: String,
    default: '',
  },
  hint: {
    type: String,
    default: '',
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  noMargin: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:modelValue', 'change'])

// State
const isOpen = ref(false)
const searchQuery = ref('')
const dropdownRef = ref(null)
const searchInput = ref(null)

/*
 * Panel dropdown yang melewati tepi kanan layar.
 *
 * Panelnya ber-`position: absolute` dan ber-`min-width: max(100%, 11rem)`,
 * jadi panel bisa LEBIH LEBAR dari inputnya. Kalau inputnya kebetulan
 * berada dekat tepi kanan layar (mis. filter "Bulan" di HP 320px),
 * panelnya menonjol keluar layar -- isinya terpotong viewport dan
 * ikon centang di sebelah kanan hilang.
 *
 * `geserKeUjung` menggeser jangkarnya ke KANAN input supaya panel tumbuh
 * ke kiri, bukan ke kanan. Diukur ulang setiap kali panel dibuka.
 */
const panelRef = ref(null)
const geserKeUjung = ref(false)

const cekTepiPanel = () => {
  const panel = panelRef.value
  if (!panel) {
    geserKeUjung.value = false
    return
  }
  // Sisakan 8px supaya panel tidak menempel di tepi layar.
  const ruangKanan = window.innerWidth - panel.getBoundingClientRect().right
  geserKeUjung.value = ruangKanan < 8
}

// Komputasi
const selectedOption = computed(() => {
  if (props.multiple) return null
  if (props.modelValue === null || props.modelValue === undefined) return null
  return (
    props.options.find((opt) => getValue(opt) === getValue(props.modelValue)) || props.modelValue
  )
})

const selectedOptions = computed(() => {
  if (!props.multiple) return []
  const values = Array.isArray(props.modelValue) ? props.modelValue : []
  return props.options.filter((opt) => values.includes(getValue(opt)))
})

const filteredOptions = computed(() => {
  if (!props.searchable || !searchQuery.value) return props.options
  const query = searchQuery.value.toLowerCase()
  return props.options.filter((opt) => getLabel(opt).toLowerCase().includes(query))
})

// Fungsi-fungsi penanganan
const getLabel = (opt) => {
  if (opt === null || opt === undefined) return ''
  if (typeof opt === 'object') {
    // Jika labelKey tidak ada, coba atribut umum
    return opt[props.labelKey] ?? opt.text ?? opt.name ?? opt.label ?? ''
  }
  return opt
}

const getValue = (opt) => {
  if (opt === null || opt === undefined) return null
  if (typeof opt === 'object') {
    // Jika valueKey tidak ada, coba atribut umum
    return opt[props.valueKey] ?? opt.id ?? opt.value ?? null
  }
  return opt
}

const isSelected = (opt) => {
  const v = getValue(opt)
  if (props.multiple) {
    return Array.isArray(props.modelValue) && props.modelValue.includes(v)
  }
  return v === getValue(props.modelValue)
}

const toggleDropdown = () => {
  if (props.disabled) return
  isOpen.value = !isOpen.value
}

const closeDropdown = () => {
  isOpen.value = false
  searchQuery.value = ''
}

const selectOption = (opt) => {
  const v = getValue(opt)
  if (props.multiple) {
    const current = Array.isArray(props.modelValue) ? [...props.modelValue] : []
    const idx = current.indexOf(v)
    if (idx >= 0) current.splice(idx, 1)
    else current.push(v)
    emit('update:modelValue', current)
    emit(
      'change',
      props.options.filter((o) => current.includes(getValue(o))),
    )
  } else {
    emit('update:modelValue', v)
    emit('change', opt)
    closeDropdown()
  }
}

const removeOption = (opt) => {
  if (!props.multiple) return
  const v = getValue(opt)
  const current = Array.isArray(props.modelValue) ? [...props.modelValue] : []
  const idx = current.indexOf(v)
  if (idx >= 0) current.splice(idx, 1)
  emit('update:modelValue', current)
  emit(
    'change',
    props.options.filter((o) => current.includes(getValue(o))),
  )
}

// Penanganan klik di luar elemen
const handleClickOutside = (event) => {
  if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
    closeDropdown()
  }
}

// Pantau fokus elemen
watch(isOpen, (newVal) => {
  if (newVal) {
    // Ukur tepi panel SETELAH Vue merender panelnya (v-if baru muncul
    // setelah tick berikutnya), kalau tidak panelRef masih null.
    nextTick(cekTepiPanel)
    if (props.searchable) {
      setTimeout(() => {
        searchInput.value?.focus()
      }, 50)
    }
  } else {
    geserKeUjung.value = false
  }
})

onMounted(() => {
  document.addEventListener('mousedown', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('mousedown', handleClickOutside)
})
</script>

<style scoped>
@reference "@/assets/css/main.css";

.custom-select-search {
  @apply w-full transition-all duration-300;
}

.select-label {
  @apply block text-sm font-normal text-slate-500 mb-1.5 ml-1;
}

.select-wrapper {
  @apply relative w-full;
}

.select-display {
  @apply flex items-center justify-between w-full h-11 px-4 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-700 transition-all duration-300 cursor-pointer;
  @apply hover:border-blue-400 hover:bg-white hover:shadow-md hover:shadow-blue-500/5;
}

.select-display--active {
  @apply bg-white border-blue-500 ring-4 ring-blue-500/10 shadow-lg shadow-blue-500/5;
}

.select-display--selected {
  @apply bg-blue-50 border-blue-300 border-l-4 border-l-blue-600 text-blue-900;
}

.select-display--selected:hover {
  @apply bg-blue-50 border-blue-400 shadow-none;
}

.select-display--error {
  @apply border-red-300 bg-red-50/30;
}

.select-display--disabled {
  @apply bg-slate-100 text-slate-400 cursor-not-allowed border-slate-200 shadow-none hover:shadow-none;
}

.select-display--multiple {
  @apply min-h-[44px] h-auto py-1.5 items-start;
}

.chip-tag {
  @apply inline-flex items-center gap-1 px-2.5 py-1 bg-white text-blue-900 text-xs font-semibold rounded-md border border-blue-200;
}

.chip-remove {
  @apply inline-flex items-center justify-center w-4 h-4 text-blue-500 hover:text-white hover:bg-blue-600 rounded;
}

.option-checkbox {
  @apply flex items-center gap-2 text-sm text-slate-700 cursor-pointer w-full;
}

.option-checkbox span {
  @apply text-slate-700;
}

.option-item--selected .option-checkbox span {
  @apply text-blue-900 font-semibold;
}

.option-checkbox input[type='checkbox'] {
  @apply w-4 h-4 rounded cursor-pointer;
  appearance: none;
  -webkit-appearance: none;
  background-color: #fff;
  border: 2px solid #cbd5e1;
  transition: all 0.15s ease;
}

.option-checkbox input[type='checkbox']:hover {
  border-color: var(--color-primary-400, #60a5fa);
}

.option-checkbox input[type='checkbox']:checked {
  background-color: var(--color-primary-600, #2563eb);
  border-color: var(--color-primary-600, #2563eb);
}

.option-checkbox input[type='checkbox']:checked::after {
  content: '';
  display: block;
  width: 5px;
  height: 9px;
  margin: 1px auto 0;
  border-right: 2px solid #fff;
  border-bottom: 2px solid #fff;
  transform: rotate(45deg);
}

.option-checkbox input[type='checkbox']:focus {
  outline: none;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
}

.select-content {
  @apply flex items-center gap-3 overflow-hidden;
}

.select-content-icon {
  @apply text-slate-400 text-sm shrink-0;
}

.placeholder {
  @apply text-slate-400 text-sm truncate font-normal;
}

.selected-text {
  @apply text-slate-700 text-sm font-medium truncate;
}

.chevron-icon {
  @apply text-slate-400 text-xs transition-transform duration-300 shrink-0 ml-2;
}

.chevron-icon--active {
  @apply rotate-180 text-blue-500;
}

/*
 * PANEL DROPDOWN.
 *
 * Lebarnya TIDAK lagi dikunci sama dengan lebar input (`left-0 right-0`).
 * Sebaliknya, panel ini mengikuti lebar ISI-nya, dengan batas bawah
 * dan batas atas.
 *
 * Kenapa tidak dikunci?
 *
 * Karena beberapa filter punya input yang SANGAT sempit -- filter
 * "Tahun" di halaman pemakaian cuma ~88px. Waktu dropdown-nya ikut
 * dikunci 88px, isinya tidak muat:
 *
 *   - kolom pencarian yang ada di dalam dropdown terpotong jadi "Ca",
 *   - opsi yang sedang dipilih (yang menambah garis penanda 4px dan
 *     ikon centang) jadi lebih sempit lagi, sehingga teks "2026"
 *     terpotong jadi "20...".
 *
 * Dua-duanya masalah nyata: user tidak bisa melihat angka tahunnya
 * utuh, dan tidak bisa mengetik di kotak pencarian karena teksnya
 * sendiri sudah terpotong.
 *
 * `min-width: max(100%, 11rem)` menggabungkan dua syarat sekaligus:
 * panelnya TIDAK PERNAH lebih sempit dari inputnya (jadi dropdown yang
 * normally lebar, mis. "Pilih Teknisi", tetap mengikuti inputnya seperti
 * sebelumnya), DAN panelnya selalu punya ruang minimal 11rem (176px)
 * untuk opsi pendek seperti "2026" plus kotak pencarian -- bahkan
 * kalau inputnya cuma 88px.
 *
 * Dua aturan `min-width` terpisah tidak bisa dipakai di sini: yang
 * belakangan menimpa yang pertama, jadi hanya satu yang berlaku.
 *
 * `max-width` mencegah panel meluber keluar layar untuk dropdown yang
 * isinya panjang.
 */
.select-dropdown {
  @apply absolute top-full left-0 mt-2 max-w-[min(28rem,calc(100vw-2rem))] bg-white border border-slate-200 rounded-2xl shadow-xl shadow-slate-200/50 z-[9999] overflow-hidden;
  min-width: max(100%, 11rem);
}

/*
 * PANEL YANG DITEPI KANAN LAYAR.
 *
 * `min-width` di atas membuat panel bisa lebih lebar dari inputnya.
 * Kalau inputnya dekat tepi kanan layar, panelnya akan menonjol keluar
 * viewport dan isinya terpotong (termasuk ikon centang di sebelah
 * kanan).
 *
 * `left: auto; right: 0` membalik jangkarnya: sekarang panel tumbuh
 * ke arah KIRI, sehingga selalu muat di dalam layar.
 *
 * Nilainya ditentukan JS di `cekTepiPanel()` saat panel dibuka, karena
 * CSS tidak bisa tahu apakah inputnya dekat tepi layar atau tidak.
 */
.select-dropdown--ujung {
  left: auto;
  right: 0;
}

.search-container {
  @apply p-2.5 border-b border-slate-100 bg-slate-50/50;
}

.search-input-wrapper {
  @apply relative flex items-center;
}

.search-icon {
  @apply absolute left-3 text-slate-400 text-sm;
}

.search-input-field {
  @apply w-full pl-9 pr-3 py-2 bg-white border border-slate-200 rounded-xl text-sm text-slate-700 transition-all;
  @apply placeholder:text-slate-400 placeholder:font-normal;
  @apply focus:outline-none focus:border-blue-400 focus:ring-4 focus:ring-blue-500/5;
}

.options-list {
  @apply max-h-60 overflow-y-auto p-1;
}

.option-item {
  @apply flex items-center justify-between px-3 py-2.5 rounded-xl cursor-pointer transition-all duration-200;
}

.option-item:hover {
  @apply bg-blue-50 text-blue-700;
}

/*
 * OPSI YANG SEDANG DIPILIH.
 *
 * Baris terpilih punya garis penanda 4px di sebelah kiri. Garis itu
 * memakan ruang teks -- jadi kalau padding-nya ikut `px-3` seperti
 * baris biasa, teks baris terpilih mulai 4px lebih kanan (16px)
 * dibanding baris lain (12px).
 *
 * `pl-2` (8px) dipakai supaya 4px border + 8px padding = 12px, persis
 * sama dengan `px-3` (12px) di baris biasa. Hasilnya semua teks opsi
 * mulai di kolom yang sama, dan garis penanda tetap terlihat.
 *
 * Padding KANAN sengaja tidak disentuh -- tetap `px-3` dari
 * `.option-item`.
 */
.option-item--selected {
  @apply bg-blue-50! border-l-4 border-l-blue-600 text-blue-900 font-semibold pl-2!;
}
.option-item--selected:hover {
  @apply bg-blue-100! text-blue-900;
}

/*
 * TEKS OPSI.
 *
 * `min-width: 0` WAJIB ada di sini.
 *
 * Tanpa itu, `flex` memberi teks ukuran minimum otomatis -- yaitu
 * ukuran kata yang tidak bisa dipecah ("2026" = 31px). Di dropdown
 * sempit, baris terpilih hanya menyisakan 27px untuk teks, dan teks itu
 * dipotong jadi "20...".
 *
 * `min-width: 0` melepas batasan minimum itu, sehingga `truncate`
 * benar-benar bekerja: teks panjang dipotong dengan elipsis, dan
 * teks pendek seperti "2026" punya ruang untuk ditampilkan utuh.
 */
.option-label {
  @apply text-sm truncate font-medium min-w-0;
}

/*
 * IKON CENTANG.
 *
 * Ikonnya ikut boleh menyusut -- tidak seperti teks, yang tidak boleh
 * terpotong. `shrink-0` di sini berarti ikon tidak pernah hilang,
 * tapi teks di sebelahnya yang mengalah kalau ruangnya memang tidak
 * cukup.
 */
.selected-indicator {
  @apply text-xs ml-2 shrink-0;
}

.empty-state {
  @apply py-8 px-4 text-center text-sm text-slate-400 font-medium;
}

.error-text {
  @apply mt-1.5 ml-1 text-xs text-red-600 font-medium;
}

.hint-text {
  @apply mt-1.5 ml-1 text-xs text-slate-400;
}

.fade-slide-enter-active,
.fade-slide-leave-active {
  transition: all 0.2s ease-out;
}

.fade-slide-enter-from,
.fade-slide-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

.custom-scrollbar::-webkit-scrollbar {
  width: 5px;
}

.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
  @apply bg-slate-200 rounded-full;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  @apply bg-slate-300;
}
</style>
