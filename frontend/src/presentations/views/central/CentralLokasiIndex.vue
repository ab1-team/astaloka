<template>
  <div class="lokasi-wrap">
    <!--
      Tombol "Tambah Lokasi" DI LUAR kartu tabel, di baris sendiri di atasnya.

      Alasannya bukan sekadar tata letak. `DataTable` sudah membawa dua hal
      di dalam toolbar-nya: "Tampilkan ... data" di kiri dan kotak pencarian di
      kanan. Menitipkan tombol tambah lewat slot `search-actions` pernah
      dicoba -- hasilnya tombol duduk di dalam kartu, menempel pada kontrol
      yang isinya tidak terkait (menampilkan jumlah baris), sehingga tombol
      utama halaman terlihat seperti bagian dari kelompok angka itu.

      Yang dilakukan di sini: tombol diangkat ke luar, jadi kartu tabel
      kembali murni data, dan aksinya berdiri sendiri di tempat orang mengira
      akan mencari "tambah" -- pojok kanan, di atas tabel.

      Baris ini `justify-content: flex-end` karena memang tidak ada apa pun di
      sebelah kirinya: aksi utama halaman dan kontrol utama halaman sama-sama
      berada di ujung kanan.

      Tombolnya memakai ukuran STANDAR (`BaseButton` tanpa `size="sm"`), bukan
      ukuran kecil. Alasannya: "Tambah Lokasi" adalah aksi utama halaman ini --
      satu-satunya cara membuat baris baru -- dan `sm` di sini menjadikannya
      setipis tombol filter di dalam toolbar tabel, padahal keduanya tidak
      sekelas: yang satu membuat data, yang cuma memilih apa yang tampil.
      Warna biru gradien + bayangannya sudah sama persis dengan tombol utama
      di halaman panel pusat lain, jadi yang membedakan cuma ukurannya -- dan
      itu memang seharusnya berbeda.
    -->
    <div class="lokasi-topbar">
      <BaseButton
        to="/central/lokasi/tambah"
        variant="primary-gradient"
        icon="plus"
        class="lokasi-add!"
      >
        Tambah Lokasi
      </BaseButton>
    </div>

    <DataTable
      v-model="searchQuery"
      :data="filteredLocations"
      :columns="tableColumns"
      title=""
      v-model:current-page="currentPage"
      v-model:per-page="perPage"
      :total-entries="filteredLocations.length"
      search-placeholder="Cari nama, slug, atau domain..."
      :loading="loading"
      empty-title="Lokasi Tidak Ditemukan"
      empty-message="Mohon cek kembali kata kunci pencarian Anda atau daftarkan lokasi baru."
      empty-icon="location-dot"
      class="lokasi-table"
    >
      <template #column-lokasi="{ row }">
        <div class="flex flex-col">
          <span class="text-[13px]! font-bold! text-slate-800!">{{ row.label }}</span>
          <span class="font-mono! text-[11px]! text-slate-400!">{{ row.slug }}</span>
        </div>
      </template>

      <template #column-database="{ row }">
        <span class="font-mono! text-slate-600! text-[12px]!">{{ row.database }}</span>
        <font-awesome-icon
          v-if="row.database_ada"
          icon="circle-check"
          class="ml-1.5! text-emerald-500!"
          title="Database tersedia"
        />
        <font-awesome-icon
          v-else
          icon="circle-exclamation"
          class="ml-1.5! text-amber-500!"
          title="Database tidak ditemukan di server"
        />
      </template>

      <!--
        Domain diklik langsung membuka halaman web lokasi itu, di tab baru.

        Alasannya: kolom ini satu-satunya tempat di daftar ini yang menunjukkan
        alamat yang bisa dipakai orang. Kalau hanya teks, admin harus menyalin
        domain ke papan klip secara manual -- dan salah salin di situ berarti
        pengunjung mendarat di domain yang bukan miliknya.

        `tenantUrl()` dipakai, bukan `https://` yang ditempel langsung, karena
        ia sudah satu tempat untuk memutuskan http/https sesuai jenis host-nya:
        domain development (`.test`, `.local`, IP private) tidak punya
        sertifikat, dan tautan `https://` ke sana gagal sebelum halaman sempat
        dimuat. Host yang tidak punya padanan tautan (wildcard `*.x.test`,
        host tanpa titik seperti `srv01`) tetap tampil sebagai teks biasa --
        tautan yang selalu gagal hanya mengajari admin bahwa domainnya rusak,
        padahal yang rusak hanya tautannya.
      -->
      <template #column-domain="{ row }">
        <a
          v-if="tenantUrl(row.domain)"
          :href="tenantUrl(row.domain)"
          target="_blank"
          rel="noopener noreferrer"
          class="lokasi-domain"
        >
          {{ row.domain }}
          <font-awesome-icon icon="arrow-up-right-from-square" />
        </a>
        <span v-else class="font-mono! text-slate-600! text-[12px]!">
          {{ row.domain || '—' }}
        </span>
      </template>

      <template #column-tagihan="{ row }">
        <span
          v-if="(row.tagihan_belum_lunas || 0) > 0"
          class="inline-block! px-2! py-0.5! rounded-full! text-[10px]! font-extrabold! uppercase! tracking-wide! bg-rose-50! text-rose-600!"
        >
          {{ row.tagihan_belum_lunas }} · {{ formatRupiah(row.total_tagihan) }}
        </span>
        <span v-else class="text-[12px]! font-bold! text-emerald-600!">Lunas</span>
      </template>

      <template #column-berakhir="{ row }">
        <span class="font-mono! text-slate-600! text-[12px]!">{{ row.tgl_berakhir || '∞' }}</span>
      </template>

      <template #column-status="{ row }">
        <span
          class="inline-block! px-2! py-0.5! rounded-full! text-[10px]! font-extrabold! uppercase! tracking-wide!"
          :class="
            row.is_active ? 'bg-emerald-50! text-emerald-600!' : 'bg-slate-100! text-slate-500!'
          "
        >
          {{ row.is_active ? 'Aktif' : 'Nonaktif' }}
        </span>
      </template>

      <template #column-aksi="{ row }">
        <div class="flex items-center justify-end gap-2!">
          <BaseButton
            variant="ghost"
            size="sm"
            @click="goToDetail(row)"
            class="w-8! h-8! p-0! rounded-lg! border! border-slate-100! hover:border-blue-200! hover:bg-blue-50! text-slate-600! hover:text-blue-600! shadow-sm!"
            title="Lihat detail"
            icon="eye"
          />
          <BaseButton
            variant="ghost"
            size="sm"
            @click="goToEdit(row)"
            class="w-8! h-8! p-0! rounded-lg! border! border-slate-100! hover:border-blue-200! hover:bg-blue-50! text-slate-600! hover:text-blue-600! shadow-sm!"
            title="Ubah"
            icon="pen"
          />
          <BaseButton
            variant="ghost"
            size="sm"
            @click="handleToggleActive(row)"
            class="w-8! h-8! p-0! rounded-lg! border! border-slate-100! hover:border-red-200! hover:bg-red-50! text-slate-600! hover:text-red-600! shadow-sm!"
            :title="row.is_active ? 'Nonaktifkan' : 'Aktifkan kembali'"
            :icon="row.is_active ? 'ban' : 'rotate-left'"
          />
          <!--
            Hapus permanen. Tombol keempat, dan yang paling berbeda dari
            ketiganya.

            Ikon `trash-can` dipakai, bukan `trash` yang biasa, karena
            `trash` dipakai untuk "hapus tagihan" dan "hapus domain" --
            dan orang yang sudah terbiasa menekan `trash` di panel ini akan
            menekan `trash-can` dengan harapan yang sama. Yang di sini
            menghapus database beserta seluruh isinya, jadi harus terlihat
            berbeda bahkan di tingkat ikon.

            Warnanya tetap `hover:text-red-600` yang sama dengan tombol
            nonaktifkan. Bedanya di BENTUK: yang ini memakai ikon tempat
            sampah, dan konfirmasinya jauh lebih berat.
          -->
          <BaseButton
            variant="ghost"
            size="sm"
            @click="handleForceDelete(row)"
            class="w-8! h-8! p-0! rounded-lg! border! border-slate-100! hover:border-red-200! hover:bg-red-50! text-slate-600! hover:text-red-600! shadow-sm!"
            title="Hapus permanen beserta database"
            icon="trash-can"
          />
        </div>
      </template>
    </DataTable>

    <!--
      EmptyState bawaan DataTable punya tombol "Daftarkan Lokasi Pertama"
      yang hanya relevan saat tabel benar-benar kosong, bukan saat hasil
      pencarian tidak cocok. `DataTable` tidak menerima slot isi, jadi pemicunya
      ditiru di luar -- dan hanya saat tabel benar-benar kosong.
    -->
    <div v-if="!loading && locations.length === 0" class="lokasi-first">
      <router-link to="/central/lokasi/tambah" class="lokasi-first__cta">
        <font-awesome-icon icon="plus" />
        Daftarkan Lokasi Pertama
      </router-link>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { MySwal, showErrorToast, showSuccessToast } from '@/utils/swal'
import { useUiStore } from '@/stores/uiStore'
import { locationService } from '@/services/central.service'
import { formatRupiah } from '@/utils/format'
import { tenantUrl } from '@/utils/tenantUrl'
import DataTable from '@/presentations/components/ui/DataTable.vue'
import BaseButton from '@/presentations/components/ui/BaseButton.vue'

const router = useRouter()
const uiStore = useUiStore()

const loading = computed(() => uiStore.loading)
const locations = ref([])

/*
 * State pagination & pencarian disamakan dengan `usePelanggan` supaya tabel
 * ini berperilaku sama persis dengan Data Pelanggan: kotak "Tampilkan
 * 10/25/50/100 data" di kiri, search di kanan, pagination di kaki kartu.
 *
 * Backend `/central/lokasi` BELUM berpaginasi -- `LocationController::index`
 * selalu `get()` seluruh tabel. Karena itu `serverSide` tidak diaktifkan dan
 * pemotongan halaman dikerjakan di sisi peramban oleh `DataTable` sendiri.
 * Mengaktifkannya tanpa mengubah backend akan membuat "Showing 1 to 10 of 500"
 * padahal isinya hanya satu halaman server -- angka yang lebih parah bohongnya
 * daripada tidak ada pagination sama sekali.
 *
 * Kalau nanti daftarnya tumbuh sampai ratusan tenant, yang perlu ditambahkan
 * `paginate()` + `search` di `LocationController::index` dan menyalakan prop
 * `server-side` di sini. Pagination peramban sudah cukup untuk sekarang.
 */
const searchQuery = ref('')
const currentPage = ref(1)
const perPage = ref(10)

const tableColumns = [
  { key: 'lokasi', title: 'Lokasi' },
  { key: 'database', title: 'Database' },
  { key: 'domain', title: 'Domain' },
  { key: 'tagihan', title: 'Tunggakan', thClass: 'text-right!', tdClass: 'text-right!' },
  { key: 'berakhir', title: 'Berakhir' },
  { key: 'status', title: 'Status', thClass: 'text-center!', tdClass: 'text-center!' },
  { key: 'aksi', title: 'Aksi', thClass: 'text-right!', tdClass: 'text-right!' },
]

/*
 * Tidak ada filter status di halaman ini.
 *
 * Alasannya bukan kesederhanaan tampilan: select status hanya mengulang kolom
 * Status yang sudah ada di tabel, tapi menyingkirkan baris tanpa jejak alasan --
 * hasilnya daftar terlihat lebih pendek tanpa penjelasan, dan orang tetap
 * harus menggulir untuk mencari siapa yang nonaktif karena select-nya hilang.
 *
 * Daftar menampilkan seluruh lokasi apa adanya. Kalau sebuah lokasi
 * dinonaktifkan, efeknya sudah terlihat di kolom Status; menyembunyikannya
 * dari daftar bukan menambah kejelasan, hanya membuat orang mencari-cari.
 */
const filteredLocations = computed(() => {
  const keyword = searchQuery.value.trim().toLowerCase()

  if (!keyword) return locations.value

  return locations.value.filter(
    (row) =>
      row.label?.toLowerCase().includes(keyword) ||
      row.nama?.toLowerCase().includes(keyword) ||
      row.slug?.toLowerCase().includes(keyword) ||
      row.domain?.toLowerCase().includes(keyword) ||
      row.database?.toLowerCase().includes(keyword),
  )
})

/*
 * Mengganti kata kunci atau ukuran halaman mengembalikan ke halaman 1.
 *
 * Tanpa ini: sedang di halaman 3 lalu mengetik kata kunci yang hasilnya cuma 4
 * baris -- tabel tampil kosong dan pagination menulis "Showing 1 to 0 of
 * 4", padahal datanya ada. Kembali ke halaman 1 selalu benar karena baris
 * pertama hasil pencarian pasti ada kalau ada hasil sama sekali.
 *
 * `DataTable` tidak melakukan ini sendiri: ia memotong `data` sesuai
 * `currentPage` tanpa pernah memeriksa apakah halaman itu masih punya isi.
 * Halaman yang memfilter di sisi peramban (seperti `useDesa`) punya masalah
 * yang sama dan belum menyelesaikannya.
 */
watch([searchQuery, perPage], () => {
  currentPage.value = 1
})

const goToDetail = (row) => {
  router.push({ name: 'central-lokasi-detail', params: { id: row.id } })
}

const goToEdit = (row) => {
  router.push({ name: 'central-lokasi-edit', params: { id: row.id } })
}

/**
 * Menonaktifkan lokasi memakai DELETE, tapi efeknya SOFT di backend: is_active
 * di-set false, database dan datanya tidak disentuh. Sadari itu karena tombol
 * ikonnya berubah menjadi "aktifkan kembali" saat lokasi sudah nonaktif --
 * punitive wording "hapus" akan membuat user takut menekan tombol yang
 * sebenarnya tidak menghapus apa pun.
 *
 * Mengaktifkan kembali TIDAK memakai endpoint yang sama. `locationService`
 * punya `deactivate()` dan `activate()` yang terpisah, dan itu bukan
 * pemisahan yang kebetulan: DELETE di sini berarti "matikan", jadi
 * menggunakan DELETE untuk membangunkan kembali lokasi akan membuat satu
 * request berarti dua hal yang bertentangan.
 */
const handleToggleActive = async (row) => {
  if (row.is_active) {
    const result = await MySwal.fire({
      title: 'Nonaktifkan Lokasi?',
      html: `Lokasi <strong>${row.label}</strong> tidak lagi bisa diakses framework ini.<br>
        Database dan seluruh datanya <strong>tidak dihapus</strong> dan bisa diaktifkan kembali kapan saja.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#ef4444',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Ya, nonaktifkan',
      cancelButtonText: 'Batal',
    })

    if (!result.isConfirmed) return
  }

  try {
    const res = row.is_active
      ? await locationService.deactivate(row.id)
      : await locationService.activate(row.id)

    showSuccessToast(res.data?.message || 'Status lokasi diperbarui.')
    await loadData()
  } catch (error) {
    showErrorToast(error)
  }
}

/**
 * Hapus lokasi permanen, beserta database dan seluruh datanya.
 *
 * Berbeda dari "Nonaktifkan" di sebelahnya, yang ini BENAR-BENAR menghapus:
 * database lokasi ikut dibuang, jadi tidak ada yang bisa dipulihkan kalau
 * ternyata salah dihapus.
 *
 * Karena itu konfirmasinya dua tahap:
 *
 *   1. Dialog pertama -- kalimat yang menyebut apa yang hilang.
 *   2. Dialog kedua -- slug lokasi harus DIKETIK ULANG. Mengetik ulang
 *      memang lebih lambat daripada satu klik, dan itulah tujuannya:
 *      kehilangan database beserta seluruh data lokasi karena salah klik
 *      adalah kerusakan yang tidak bisa diperbaiki.
 *
 * Dialog kedua memakai `input` bawaan SweetAlert2, bukan `html` biasa.
 * Alasannya `html` mengizinkan HTML yang bisa disisipkan, dan isinya di
 * sini memuat nama lokasi yang berasal dari database -- jadi slug-nya
 * ditampilkan sebagai teks, bukan markup.
 */
const handleForceDelete = async (row) => {
  const konfirmasi = await MySwal.fire({
    title: 'Hapus Lokasi Permanen?',
    html: `Lokasi <strong>${row.label}</strong> akan dihapus <strong>permanen</strong>.<br><br>
      Database <code>${row.database}</code> beserta <strong>seluruh data di dalamnya</strong>
      akan ikut dihapus, dan <strong>tidak ada cara memulihkannya</strong>.<br><br>
      Kalau hanya ingin menonaktifkan sementara, gunakan tombol "Nonaktifkan" -- datanya tetap utuh.`,
    icon: 'error',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    cancelButtonColor: '#6b7280',
    confirmButtonText: 'Lanjut',
    cancelButtonText: 'Batal',
  })

  if (!konfirmasi.isConfirmed) return

  /*
   * Tahap kedua: slug harus diketik ulang.
   *
   * `input: 'text'` + `inputValidator` dipakai, bukan `html` yang memuat
   * `<input>` sendiri. SweetAlert2 punya validator bawaan yang menolak
   * input kosong, dan pesannya bisa ditulis sendiri -- jadi orang tahu
   * persis apa yang harus diketik, bukan hanya "salah".
   */
  const ketikSlug = await MySwal.fire({
    title: 'Ketik slug untuk konfirmasi',
    html: `Ketik <code>${row.slug}</code> untuk menghapus lokasi ini secara permanen.`,
    icon: 'warning',
    input: 'text',
    inputAttributes: {
      autocapitalize: 'none',
      autocorrect: 'off',
      spellcheck: 'false',
    },
    inputPlaceholder: row.slug,
    inputValidator: (value) => {
      if (!value || value.trim() !== row.slug) {
        return `Slug harus persis sama: ${row.slug}`
      }

      return null
    },
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    cancelButtonColor: '#6b7280',
    confirmButtonText: 'Hapus permanen',
    cancelButtonText: 'Batal',
  })

  if (!ketikSlug.isConfirmed) return

  try {
    const res = await locationService.forceDelete(row.id, row.slug)
    showSuccessToast(res.data?.message || 'Lokasi dihapus permanen.')
    await loadData()
  } catch (error) {
    showErrorToast(error)
  }
}

const loadData = async () => {
  try {
    const res = await locationService.getAll()
    locations.value = res.data?.data || []
  } catch (error) {
    showErrorToast(error)
  }
}

onMounted(loadData)
</script>

<style scoped>
/*
 * Lebar maksimum & jarak tepi diambil dari `--central-*` di `CentralLayout.vue`,
 * sama seperti halaman panel pusat yang lain. `.central-page` di
 * `central-page.css` sudah punya aturan yang persis sama; ditulis ulang di sini
 * supaya halaman ini tidak bergantung pada kelas global yang isinya masih
 * bisa berubah.
 */
.lokasi-wrap {
  width: 100%;
  max-width: var(--central-content-max, 1200px);
  margin-inline: auto;
}

/*
 * Tujuh kolom butuh ruang, dan di layar sempit ruang itu tidak ada.
 *
 * 760px bukan angka acak: itu jumlah lebar minimum yang masih membuat
 * nama lokasi ("Tirto Mulo"), nama database, dan nominal tagihan terbaca
 * tanpa satu huruf pun terpotong. Di bawah itu, tabel menyusut ke 360px --
 * tiap kolom jadi ~50px, dan yang tersisa hanya satu huruf per kolom.
 *
 * `min-width` lebih baik daripada menyembunyikan kolom. Kolom yang
 * disembunyikan hilang tanpa jejak, jadi admin tidak tahu ada data yang
 * tidak ditampilkan; kolom yang tergulir masih bisa dibaca, dan bilah
 * gulir di bawah tabel memberi petunjuk bahwa masih ada isi di sebelah
 * kanan.
 *
 * Angka ini tidak dipakai tabel lain: `DataTable` sengaja tidak memberi
 * default, karena tabel tiga kolom di layar 360px akan ikut tergulir
 * tanpa alasan.
 */
.lokasi-table :deep(table) {
  min-width: 760px;
}

/*
 * Baris tombol tambah, di atas kartu tabel.
 *
 * Jarak ke bawah `0.85rem` bukan `1rem`: kartu tabel punya border sendiri, dan
 * jarak yang persis sama dengan jarak antar-kartu di halaman lain membuat
 * tombol terbaca sebagai kepala kartu kedua yang tidak pernah berisi.
 */
.lokasi-topbar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  margin-bottom: 0.85rem;
}

/*
 * `DataTable` sudah punya kartu sendiri (putih, rounded-xl, shadow-sm), jadi
 * tidak perlu dibungkus kartu kedua. Yang perlu disamakan cuma bayangan kartu
 * supaya halaman ini tidak terlihat lebih datar dari dashboard, tagihan, dan
 * struktur akun yang semuanya memakai `--central-shadow-card`.
 */
.lokasi-table :deep(.shadow-sm) {
  box-shadow: var(--central-shadow-card);
}

.lokasi-add {
  white-space: nowrap;
}

/*
 * Tautan domain.
 *
 * Font tetap monospace supaya kolom ini terbaca sebagai "host", bukan sebagai
 * kalimat -- dan supaya tidak melompat ukurannya saat berubah jadi tautan.
 * Warna biru + garis bawah muncul saat hover, bukan selalu: kolom ini masih
 * kolom data, dan tautan yang selalu terlihat seperti tombol akan bersaing
 * dengan kolom Aksi yang memang berisi tombol.
 */
.lokasi-domain {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.75rem;
  color: var(--color-primary-dark, #0284c7);
  text-decoration: none;
  overflow-wrap: anywhere;
}

.lokasi-domain:hover {
  text-decoration: underline;
}

.lokasi-domain :deep(svg) {
  font-size: 0.6rem;
  opacity: 0.7;
}

/*
 * "Daftarkan Lokasi Pertama" muncul DI BAWAH kartu, bukan di dalam kartu.
 *
 * `EmptyState` punya slot untuk aksi, tapi `DataTable` tidak meneruskan slot
 * itu ke bawah -- jadi tombolnya tidak bisa masuk ke dalam kartu. Taruh di
 * bawah adalah pilihan yang sengaja: kalau tombol ini masuk ke dalam baris
 * kosong yang sama dengan tabel, ia akan terlihat seperti baris data yang
 * salah ketik, bukan seperti ajakan membuat data pertama.
 */
.lokasi-first {
  display: flex;
  justify-content: center;
  padding: 1rem 0 0;
}

.lokasi-first__cta {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.55rem 1rem;
  border-radius: 9px;
  background: var(--color-primary, #0ea5e9);
  color: #fff;
  font-size: 0.8rem;
  font-weight: 700;
  text-decoration: none;
  box-shadow: var(--central-shadow-btn-primary);
}

.lokasi-first__cta:hover {
  filter: brightness(1.08);
}
</style>
