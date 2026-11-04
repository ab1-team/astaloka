<template>
  <!--
    `v-bind="$attrs"` itu WAJIB, bukan tambahan.

    Komponen ini punya DUA elemen root: `<Select>` dan `<small>` pesan
    validasi. Vue hanya bisa menempelkan atribut jatuh-tumpah (`class`,
    `style`, `id`, `data-*`) secara otomatis kalau template-nya punya satu
    root. Dengan dua root, Vue tidak punya tujuan yang jelas -- dan
    praktisnya atribut itu DIBUANG, bukan diteruskan ke mana pun.

    Akibatnya `class="tagihan-select"` di `CentralTagihanIndex.vue` tidak
    pernah sampai ke elemen apa pun. Select tetap memakai
    `.base-select { width: 100% }` yang tertulis di `ui-base.css`, jadi
    tiap filter selebar penuh dan `.tagihan-filters` -- yang
    `flex-wrap: wrap` -- turun jadi dua baris meskipun plenty ruang.

    `inheritAttrs: false` stopping Vue mencoba menempelkannya sendiri,
    lalu `v-bind="$attrs"` meneruskannya ke `<Select>`. Dua hal ini satu
    paket: mengaktifkan salah satu saja tidak memperbaiki apa pun.

    Jangan dihapus karena "cuma gaya": `id` untuk `<label for=...>` dan
    `aria-*` untuk pembaca layar juga lewat jalur yang sama.
  -->
  <!--
    Wrapper PrimeVue Select supaya filter di Panel Pusat seragam.

    Kenapa tidak `<select>` native langsung?

    1. Label panjang terpotong. `<select>` native memotong teks sesuai
       lebar elemen, jadi "Semua Lokasi" berubah jadi "Semua Lokasi…"
       di layar sempit. Yang tampil tidak lagi merupakan label yang
       sama dengan yang ditulis -- dan user tidak bisa memastikan
       sedang memfilter apa.

    2. Tidak ada pencarian. Daftar lokasi bisa tumbuh menjadi puluhan
       item; memilih satu berarti menggulir. PrimeVue `filter` memberi
       kotak pencarian di dalam dropdown-nya.

    3. Tidak konsisten antar halaman. Native select di Android, iOS,
       dan Safari membuka UI yang berbeda. Panel pusat dibaca dari
       perangkat yang varied, jadi tampilan filter tidak boleh
       bergantung perangkat.

    Props yang diteruskan (`filter`, `optionLabel`, `showClear`, dll)
    memang milik PrimeVue Select, bukan reimplementasi di sini --
    yang ada di file ini hanya tema, penerusan event, dan penerjemahan
    nilai kosong.
  -->
  <Select
    v-bind="$attrs"
    :model-value="nilaiInternal"
    :options="opsiTerisi"
    :option-label="optionLabel"
    :option-value="optionValue"
    :placeholder="placeholderTidakTepat"
    :filter="filter"
    :filter-placeholder="filterPlaceholder"
    :filter-match-mode="filterMatchMode"
    :reset-filter-on-hide="resetFilterOnHide"
    :show-clear="showClear"
    :disabled="disabled || !!error"
    :loading="loading"
    :scroll-height="scrollHeight"
    class="base-select"
    :class="{ 'is-error': !!error }"
    @update:model-value="handleChange"
    @change="$emit('change', $event)"
  />

  <!--
    Pesan validasi DIBAWAH select, bukan di dalam dropdown.

    PrimeVue Select memang bisa menampilkan pesan di dalam panelnya,
    tapi pesannya ikut hilang begitu dropdown ditutup -- dan orang yang
    menutupnya justru yang paling perlu membaca. Di bawah, pesannya
    tetap terlihat sampai isian yang salah diperbaiki.
  -->
  <small v-if="error" class="base-select__error">{{ error }}</small>
</template>

<script setup>
import { computed } from 'vue'
import Select from 'primevue/select'

/*
 * `inheritAttrs: false` + `v-bind="$attrs"` di `<Select>`.
 *
 * WAJIB berpasangan. Tuple ini yang membuat `class` dari pemanggil benar-benar
 * menempel. Tanpa `inheritAttrs: false`, Vue mencoba menempelkan atribut
 * sendiri dan memunculkan peringatan "extraneous non-props attributes";
 * tanpa `v-bind`, atribut tetap hilang.
 *
 * Tanpa tuple ini, `<BaseSelect class="apa-saja">` terlihat seperti bekerja
 * (tidak ada error di kode) tapi sebenarnya tidak melakukan apa-apa -- select
 * tetap selebar penuh apa pun class-nya.
 */
defineOptions({ inheritAttrs: false })

const props = defineProps({
  modelValue: { type: [String, Number], default: null },
  options: { type: Array, default: () => [] },
  // `optionLabel` PrimeVue menerima string (nama field) atau fungsi.
  // Default ke `label` karena itu kunci yang dipakai data lokasi/tagihan.
  optionLabel: { type: [String, Function], default: 'label' },
  optionValue: { type: [String, Function], default: null },
  placeholder: { type: String, default: 'Pilih...' },

  // Pencarian di dalam dropdown. Default ON: daftar lokasi dan aksi
  // audit tumbuh seiring waktu, dan menggulir 50 item lebih lambat
  // daripada mengetik 3 huruf.
  filter: { type: Boolean, default: true },
  filterPlaceholder: { type: String, default: 'Ketik untuk mencari...' },
  // Pencarian Select selalu mencocokkan terhadap field `optionLabel`
  // saja -- tidak ada prop untuk menambah field lain (yang punya itu
  // TreeSelect, bukan Select). Kalau nanti sebuah filter perlu cocok
  // juga pada domain atau slug, itu harus lewat label gabungan yang
  // sudah disiapkan, bukan lewat prop yang tidak ada.
  filterMatchMode: { type: String, default: 'contains' },

  /*
   * Apakah teks pencarian di dalam dropdown dikosongkan lagi saat dropdown
   * ditutup.
   *
   * Bawaan PrimeVue adalah `false`, dan itu MENYISIRKAN teks pencarian yang
   * diketik user.
   *
   * Akibatnya: user mengetik "mei", memilih Mei, lalu menutup dropdown.
   * Teks "mei" masih tertinggal di kotak pencarian. Waktu dropdown dibuka
   * lagi, daftar opsi yang tampil HANYA yang cocok dengan "mei" -- dan kalau
   * "Mei" tidak ada di sana, PrimeVue tidak menemukan opsi terpilih sama
   * sekali, sehingga LABEL select berubah jadi placeholder (teks "Semua
   * Bulan") padahal nilainya masih terisi.
   *
   * Gejalanya persis "kepilih bulan, tapi select-nya kosong lagi".
   * Box pencarian harus kembali kosong setiap kali dropdown ditutup -- apa
   * yang diketik adalah milik satu sesi pencarian, bukan milik select.
   */
  resetFilterOnHide: {
    type: Boolean,
    default: true,
  },

  // Tombol "Hapus filter". Halaman yang sudah menyediakan "Semua ..."
  // di daftar opsi sebaiknya memematikannya: tombolnya akan jadi
  // jalan kedua untuk hal yang sama.
  showClear: { type: Boolean, default: true },

  disabled: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },

  /*
   * Pesan validasi.
   *
   * String, bukan array, karena `BaseInput` dan `MaksMoneyInput` juga
   * memakai bentuk sama dan pemanggilnya memetakan 422 ke
   * `errors[field][0]` sendiri. Bentuk yang sama di ketiga komponen
   * membuat pemanggil tidak perlu tahu komponen mana yang sedang
   * dipakai.
   */
  error: { type: String, default: '' },
  // Batas tinggi daftar: 16rem ~= 8 baris. Tanpa batas, dropdown 50
  // lokasi setinggi layar dan menutupi filter di atasnya.
  scrollHeight: { type: String, default: '16rem' },
})

const emit = defineEmits(['update:modelValue', 'change'])

/*
 * ==========================================================================
 * SOAL "TIDAK ADA PILIHAN" -- bagian yang paling mudah salah di sini
 * ==========================================================================
 *
 * Halaman menulis filter kosong sebagai `null`:
 *
 *     const filters = reactive({ status: null, tenant_id: null })
 *     if (filters.tenant_id) params.tenant_id = filters.tenant_id
 *
 * Tapi `null` tidak bisa dipakai langsung sebagai nilai opsi di
 * PrimeVue. Alasannya `null` adalah penanda internal PrimeVue untuk
 * "belum ada pilihan": selama modelValue-nya `null`, komponen
 * menempelkan kelas `p-placeholder` ke label, dan kelas itu yang
 * mengatur warnanya.
 *
 * Dua kegagalan yang muncul kalau `null` langsung jadi nilai opsi
 * "Semua Lokasi":
 *
 *   - PrimeVue mencocokkan nilai terpilih dengan membandingkan
 *     identitas objek, bukan mencari berdasarkan nilai. Opsi bernilai
 *     `null` tidak pernah dianggap sebagai "yang terpilih" --
 *     sehingga begitu user memilih lokasi sungguhan, select tetap
 *     memakai gaya placeholder dan teks yang baru dipilih tampil
 *     redup seperti belum ada isinya.
 *
 *   - Sebaliknya, kalau "Semua Lokasi" cuma jadi `placeholder`,
 *     select yang sedang difilter tetap menampilkan "Semua Lokasi" --
 *     user tidak bisa membedakan belum-difilter dari sedang-difilter
 *     tanpa membuka dropdown.
 *
 * Penyelesaiannya: "Semua ..." tetap OPSI NYATA, tapi nilainya diganti
 * sentinel `__semua__` yang dijamin tidak bentrok dengan nilai data
 * (id lokasi berupa angka, status berupa kode seperti "UNPAID").
 * Parent tetap hanya pernah melihat `null`.
 */
const SEMUA = '__semua__'

// Nama field yang menyimpan nilai opsi. Kalau optionValue berupa
// fungsi, penerjemahan sentinel tidak bisa dilakukan di sini -- dan
// memang tidak perlu, karena pemakaian fungsi selalu disertai daftar
// opsi yang nilainya sudah pasti.
const fieldNilai = computed(() =>
  typeof props.optionValue === 'string' ? props.optionValue : 'value',
)

const opsiTerisi = computed(() => {
  const key = fieldNilai.value

  return (props.options || []).map((o) => {
    if (!o || typeof o !== 'object') return o

    // Hanya nilai kosong (null/undefined) yang diganti. Angka nol dan
    // string kosong adalah nilai data yang sah -- misalnya id 0 atau
    // kode status kosong -- dan tidak boleh ikut tertukar.
    return o[key] == null ? { ...o, [key]: SEMUA } : o
  })
})

const nilaiInternal = computed(() =>
  props.modelValue === null || props.modelValue === undefined || props.modelValue === ''
    ? SEMUA
    : props.modelValue,
)

/*
 * Teks placeholder yang dikirim ke PrimeVue diberi satu karakter
 * zero-width di depannya, supaya mustahil sama dengan teks opsi
 * mana pun.
 *
 * PrimeVue menempelkan kelas p-placeholder ke label dengan cara
 * membandingkan TEKS label terhadap prop placeholder, bukan dengan
 * melihat nilai modelValue. Karena opsi "Semua Lokasi" juga berisi
 * teks "Semua Lokasi", keduanya dianggap sama dan labelnya tetap
 * memakai gaya placeholder. Akibatnya teks yang benar-benar terpilih
 * tampil redup seperti belum ada isinya.
 *
 * Zero-width space tidak terlihat dan tidak mengubah ukuran teks,
 * tapi membuat perbandingan string gagal. PrimeVue tidak
 * menyediakan cara lain untuk mematikan perbandingan ini dari
 * luar komponen.
 *
 * Karena di wrapper ini "Semua ..." selalu berupa opsi dan bukan
 * placeholder kosong, teks mustahil ini benar-benar tidak pernah
 * tampil. Yang tersisa hanya atribut aria-placeholder, yang tetap
 * memberi pembaca layar konteks yang benar.
 */
const placeholderTidakTepat = computed(() => `\u200b${props.placeholder}`)

function handleChange(value) {
  // Diterjemahkan kembali ke `null` supaya parent tidak perlu tahu
  // soal sentinel, dan bentuk filternya tetap sama dengan yang
  // dipakai pemeriksaan `if (filters.x)` di halaman.
  const next = value === SEMUA || value === null || value === undefined ? null : value

  emit('update:modelValue', next)
  // `change` emit terpisah karena beberapa halaman memakainya untuk
  // reset pagination; menyatukannya ke `update:modelValue` akan
  // membingungkan pemanggil yang hanya ingin tahu "filter berubah".
  emit('change', next)
}
</script>

<style scoped>
/*
 * Tema diset di sini, bukan di tiap halaman, supaya semua filter
 * yang berbeda tetap terlihat seperti satu komponen.
 *
 * `!important` dipakai karena PrimeVue menyuntikkan styling-nya lewat
 * style sheet yang diimpor global -- specificity-nya lebih tinggi dari
 * scoped style. Tanpa ini, aturan yang ada di sini kalah dan komponen
 * tetap memakai warna bawaan tema, yang tidak sama dengan token halaman.
 */
.base-select {
  width: 100%;
  min-height: 2.35rem;
  border-radius: 8px !important;
  border-color: var(--color-border, #e2e8f0) !important;
  background: var(--color-gray-100, #f1f5f9) !important;
  font-size: 0.8rem !important;
  transition:
    border-color 0.18s ease,
    box-shadow 0.18s ease;
}

/*
 * Warna teks label diset di sini, bukan dibiarkan dari PrimeVue.
 *
 * Nilai bawaan Aura untuk warna teks form adalah abu-abu medium
 * dengan kontras 4.34:1 terhadap latar terang -- di bawah ambang
 * WCAG AA (4.5:1) untuk teks normal. Pada ukuran 0.8rem teks seperti
 * itu praktis hanya terbaca kalau orang benar-benar konsentrasi,
 * dan "Semua Lokasi" justru label yang paling penting untuk
 * dipastikan sedang aktif atau tidak.
 *
 * --color-text-main (#1e293b) memberi kontras 14:1. Bukan sekadar
 * mengikuti gaya proyek: teks filter bukan keterangan atau teks
 * kedaluwarsa, melainkan label kendali yang dibaca untuk mengambil
 * keputusan, jadi harus sejelas teks label biasa.
 */
.base-select :deep(.p-select-label) {
  color: var(--color-text-main, #1e293b) !important;
}

/*
 * Placeholder -- hanya relevan untuk pemakaian BaseSelect yang
 * TIDAK punya opsi "Semua" di daftarnya.
 *
 * --color-text-sub (#64748b) memberi kontras 4.76:1 terhadap latar
 * terang, di atas ambang 4.5:1, dan tetap lebih redup daripada teks
 * utama #1e293b -- jadi hierarki bacaannya terjaga tanpa melanggar
 * ambang.
 */
.base-select :deep(.p-select-label.p-placeholder) {
  color: var(--color-text-sub, #64748b) !important;
}

.base-select:not(.p-disabled):hover {
  border-color: var(--color-primary, #0ea5e9) !important;
}

.base-select:not(.p-disabled).p-focus {
  border-color: var(--color-primary, #0ea5e9) !important;
  background: var(--color-card-bg, #fff) !important;
  box-shadow: 0 0 0 3px rgb(14 165 233 / 0.14) !important;
}

.base-select.p-disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

/*
 * Select yang salah tidak diredupkan seperti `p-disabled`.
 *
 * Aturan `.p-disabled { opacity: 0.6 }` di atas sengaja tidak berlaku
 * ke select bermasalah: redupnya di sini akan terbaca sebagai "sudah
 * dikunci", bukan "perlu diperbaiki". Yang berubah hanya warna
 * batasnya -- cukup untuk menandai perhatian tanpa mengubah
 * hierarki halaman.
 */
.base-select.is-error,
.base-select.is-error:not(.p-disabled):hover {
  border-color: var(--color-danger, #dc2626) !important;
}

.base-select__error {
  display: block;
  margin-top: 0.3rem;
  font-size: 0.75rem;
  color: var(--color-danger, #dc2626);
}
</style>
