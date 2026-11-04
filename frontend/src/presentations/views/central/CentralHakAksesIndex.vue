<template>
  <div class="hak-akses-wrap">
    <!--
      Penjelasan singkat di atas tabel.

      Halaman ini tidak punya form terpisah: yang bisa diubah ada DI DALAM
      baris tabel, jadi memo singkat ini dipisah agar tidak ikut bergeser
      bersama isi accordion yang sedang dibuka-tutup.
    -->
    <div class="hak-akses-intro">
      <font-awesome-icon icon="circle-info" />
      <p>
        Buka baris lokasi untuk mengatur menu per jabatan di lokasi itu. Pengaturan ini langsung
        berlaku di aplikasi lokasi -- tidak perlu logout, dan tidak perlu satu-satu masuk ke setiap
        domain.
      </p>
    </div>

    <DataTable
      v-model="searchQuery"
      :data="filteredLokasi"
      :columns="tableColumns"
      title=""
      v-model:current-page="currentPage"
      v-model:per-page="perPage"
      :total-entries="filteredLokasi.length"
      search-placeholder="Cari nama, slug, atau domain lokasi..."
      :loading="loading"
      expandable
      @expand="muatJabatan"
      empty-title="Lokasi Tidak Ditemukan"
      empty-message="Cek kembali kata kunci pencarian Anda."
      empty-icon="location-dot"
      class="hak-akses-table"
    >
      <template #column-lokasi="{ row }">
        <div class="flex flex-col">
          <span class="text-[13px]! font-bold! text-slate-800!">{{ row.label || row.nama }}</span>
          <span class="font-mono! text-[11px]! text-slate-400!">{{ row.slug }}</span>
        </div>
      </template>

      <template #column-domain="{ row }">
        <span class="font-mono! text-slate-600! text-[12px]!">{{ row.domain || '—' }}</span>
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

      <!--
        Isi accordion. Satu blok untuk satu lokasi, di dalam blok itu daftar
        jabatan -- jadi dua tingkat: baris tabel memilih lokasi, daftar di
        bawahnya memilih jabatan yang mau disetel.
      -->
      <template #expanded-row="{ row }">
        <div class="hak-akses-panel">
          <!-- Status pemuatan / kegagalan untuk lokasi ini saja. -->
          <div v-if="sedangMemuat(row)" class="hak-akses-panel__status">
            <font-awesome-icon icon="spinner" spin />
            <span>Memuat daftar jabatan di {{ row.label || row.slug }}...</span>
          </div>

          <div v-else-if="gagalMuat(row)" class="hak-akses-panel__status is-error">
            <font-awesome-icon icon="circle-exclamation" />
            <span>{{ row.gagalMuat }}</span>
            <BaseButton variant="ghost" size="sm" icon="rotate-left" @click="muatJabatan(row)">
              Coba lagi
            </BaseButton>
          </div>

          <div v-else-if="!(row.jabatan || []).length" class="hak-akses-panel__status">
            <font-awesome-icon icon="circle-info" />
            <span>
              Lokasi ini belum punya jabatan. Tambahkan dulu lewat kotak di bawah -- hak akses tiap
              jabatan bisa diatur setelah itu.
            </span>
          </div>

          <div v-else class="hak-akses-jabatan">
            <div
              v-for="j in row.jabatan"
              :key="j.id"
              class="hak-akses-jabatan__item"
              :class="{ 'is-open': jabatanTerbuka(row, j) }"
            >
              <!-- Baris jabatan:level kedua accordion. -->
              <button type="button" class="hak-akses-jabatan__head" @click="toggleJabatan(row, j)">
                <font-awesome-icon
                  icon="chevron-right"
                  class="hak-akses-jabatan__caret"
                  :class="{ 'is-open': jabatanTerbuka(row, j) }"
                />

                <span
                  class="w-7! h-7! rounded-lg! bg-linear-brand! text-white! flex! items-center! justify-center! shrink-0!"
                >
                  <font-awesome-icon icon="user-shield" class="text-xs!" />
                </span>

                <span class="min-w-0!">
                  <span class="block! text-[13px]! font-black! text-slate-700! truncate!">
                    {{ j.nama }}
                  </span>
                  <span
                    class="block! text-[10px]! font-bold! uppercase! tracking-widest! text-slate-400!"
                  >
                    {{ j.total_user }} pengguna
                  </span>
                </span>

                <span
                  class="ml-auto! px-2! py-0.5! rounded-lg! border! text-[10px]! font-black! uppercase! tracking-widest! shrink-0!"
                  :class="
                    j.configured
                      ? 'bg-blue-50! text-blue-700! border-blue-100!'
                      : 'bg-slate-50! text-slate-400! border-slate-200!'
                  "
                >
                  {{ j.configured ? `${jumlahDiizinkan(j)} menu diizinkan` : 'Belum diatur' }}
                </span>

                <!--
                  Peran jabatan. Dicetak di baris yang sama dengan jumlah menu
                  karena kedua angka itu menjawab pertanyaan berbeda dan
                  sering dikira satu: "berapa yang boleh" (kiri) dan "buat
                  siapa" (kanan). Technician dan Cater sengaja tidak tampil
                  terpisah -- keduanya memang memakai daftar yang sama.
                -->
                <span
                  class="px-2! py-0.5! rounded-lg! border! text-[10px]! font-black! uppercase! tracking-widest! shrink-0! hidden sm:inline-block!"
                  :class="badgeRole(j.role)"
                >
                  {{ ROLE_LABELS[j.role] || j.role || 'Tidak diketahui' }}
                </span>
              </button>

              <!-- Kotak centang, hanya saat jabatan ini yang terbuka. -->
              <div v-if="jabatanTerbuka(row, j)" class="hak-akses-jabatan__body">
                <!--
                  Jabatan yang memakai daftar centang role non-admin.

                  Dulu blok ini memperingatkan bahwa pengaturan di sini TIDAK
                  berlaku untuk teknisi/surveyor/pelanggan. Sekarang peringatan
                  itu tidak lagi benar -- centangnya benar-benar membatasi menu
                  mereka, jadi yang perlu ditampilkan justru role mana yang
                  dipakai, supaya superadmin tahu ia sedang mengatur pekerjaan
                  lapangan, bukan pekerjaan kantor.
                -->
                <div v-if="j.role && j.role !== 'admin'" class="hak-akses-catatan">
                  <font-awesome-icon :icon="ROLE_ICONS[j.role] || 'user-shield'" />
                  <p class="text-xs! text-blue-800! leading-relaxed!">
                    Jabatan <strong>{{ j.nama }}</strong> memakai daftar menu
                    <strong>{{ ROLE_LABELS[j.role] || j.role }}</strong
                    >. Yang di bawah hanya menu milik role itu -- menu admin, surveyor, atau role
                    lain tidak muncul di sini karena memang tidak akan pernah bisa dibuka oleh
                    pemegang jabatan ini.
                  </p>
                </div>

                <div v-else-if="!j.configured" class="hak-akses-catatan">
                  <font-awesome-icon icon="circle-info" />
                  <p class="text-xs! text-blue-800! leading-relaxed!">
                    Jabatan ini <strong>belum pernah</strong> diatur, jadi semua menu masih terbuka
                    untuknya. Yang Anda centang di bawah inilah yang akan membatasinya.
                  </p>
                </div>

                <!-- Aksi cepat: tiga tombol yang menyentuh seluruh daftar sekaligus. -->
                <div class="hak-akses__aksi">
                  <span class="hak-akses__aksi-label">Aksi cepat</span>

                  <BaseButton
                    variant="ghost"
                    size="sm"
                    icon="check-double"
                    class="hak-akses__btn"
                    @click="pilihSemua(j)"
                  >
                    Pilih semua
                  </BaseButton>
                  <BaseButton
                    variant="ghost"
                    size="sm"
                    icon="xmark"
                    class="hak-akses__btn"
                    @click="hapusSemua(j)"
                  >
                    Hapus semua
                  </BaseButton>
                  <BaseButton
                    variant="ghost"
                    size="sm"
                    icon="rotate-left"
                    :loading="sedangReset(pasangan(row, j))"
                    class="hak-akses__btn"
                    @click="kembalikanDefault(row, j)"
                  >
                    Kembalikan ke default
                  </BaseButton>

                  <span class="hak-akses__aksi-count">
                    {{ groupMenu(j).length }} kelompok menu
                  </span>
                </div>

                <!-- Group per menu, masing-masing jadi kartu sendiri. -->
                <div class="hak-akses__grid">
                  <section v-for="group in groupMenu(j)" :key="group.nama" class="hak-akses__group">
                    <label class="hak-akses__group-head">
                      <input
                        type="checkbox"
                        class="hak-akses__checkbox"
                        :checked="group.semuaDipilih"
                        :indeterminate.prop="group.sebagianDipilih"
                        :disabled="!group.bisaDiubah"
                        @change="toggleGroup(j, group)"
                      />

                      <span
                        class="w-7! h-7! rounded-lg! bg-blue-50! text-blue-600! flex! items-center! justify-center! shrink-0!"
                      >
                        <font-awesome-icon :icon="group.icon" class="text-xs!" />
                      </span>

                      <span class="text-sm! font-black! text-slate-700! truncate!">
                        {{ group.nama }}
                      </span>

                      <span class="hak-akses__group-count">
                        {{ group.terpilihCount }}/{{ group.bisaDiubahCount }}
                      </span>
                    </label>

                    <div class="hak-akses__group-body">
                      <label
                        v-for="item in group.items"
                        :key="item.key"
                        class="hak-akses__item"
                        :class="{ 'is-disabled': !item.bisaDiubah }"
                      >
                        <input
                          type="checkbox"
                          class="hak-akses__checkbox"
                          :checked="terpilih(j).has(item.key)"
                          :disabled="!item.bisaDiubah"
                          @change="toggleItem(j, item)"
                        />

                        <font-awesome-icon
                          :icon="item.icon"
                          class="w-3.5! text-slate-300! shrink-0!"
                        />

                        <span class="min-w-0!">
                          <span
                            class="block! text-[13px]! text-slate-700! truncate! leading-tight!"
                          >
                            {{ item.label }}
                          </span>
                          <!--
                            Alasan checkbox-nya mati. Tanpa keterangan, checkbox
                            mati terbaca seperti aplikasi yang rusak -- jadi
                            yang tampil selalu "kenapa", bukan sekadar "tidak
                            bisa".
                          -->
                          <span
                            v-if="item.keterangan"
                            class="block! text-[10px]! italic! text-slate-400! leading-tight! mt-0.5!"
                          >
                            {{ item.keterangan }}
                          </span>
                        </span>
                      </label>
                    </div>
                  </section>
                </div>

                <!-- Legenda: arti dari keterangan kecil di dalam daftar. -->
                <div class="hak-akses__legenda">
                  <span class="font-black! uppercase! tracking-widest! text-slate-300!"
                    >Keterangan</span
                  >
                  <span
                    ><strong class="text-slate-500!">Wajib terbuka</strong> &mdash; tidak bisa
                    dicabut</span
                  >
                  <span
                    ><strong class="text-slate-500!">Bukan untuk role ini</strong> &mdash; menu
                    milik role lain</span
                  >
                  <span
                    ><strong class="text-slate-500!">Tanpa entri sidebar</strong> &mdash; dibuka
                    lewat dashboard</span
                  >
                </div>

                <!-- Simpan: tombolnya memisahkan "ada yang berubah" dari "tidak". -->
                <div class="hak-akses__footer">
                  <p class="text-xs! text-slate-500! flex items-center gap-1.5!">
                    <font-awesome-icon
                      v-if="!adaPerubahan(j)"
                      icon="circle-check"
                      class="text-emerald-500!"
                    />
                    <font-awesome-icon v-else icon="circle-info" class="text-blue-500!" />
                    {{
                      adaPerubahan(j)
                        ? 'Ada perubahan yang belum disimpan.'
                        : 'Tidak ada perubahan untuk disimpan.'
                    }}
                  </p>

                  <BaseButton
                    variant="secondary"
                    icon="save"
                    :loading="sedangSimpan(pasangan(row, j))"
                    :disabled="!adaPerubahan(j)"
                    class="hak-akses__btn hak-akses__btn-simpan w-full sm:w-auto! rounded-xl! shadow-lg!"
                    @click="simpan(row, j)"
                  >
                    Simpan Hak Akses
                  </BaseButton>
                </div>
              </div>
            </div>
          </div>

          <!--
            Tambah jabatan. Ada DI DALAM panel lokasi, bukan di halaman terpisah,
            karena jabatan hanya berarti sesuatu sebagai bagian dari daftar hak
            akses lokasi itu: membuat jabatan di tempat lain memaksa superadmin
            pindah-pindah halaman untuk hal yang hasilnya langsung dipakai di
            sini.
          -->
          <form class="hak-akses-tambah" @submit.prevent="tambahJabatan(row)">
            <div class="hak-akses-tambah__isi">
              <font-awesome-icon icon="user-shield" class="hak-akses-tambah__ikon" />
              <input
                v-model="namaJabatanBaru[row.id]"
                type="text"
                maxlength="100"
                class="hak-akses-tambah__input"
                :placeholder="`Nama jabatan baru untuk ${row.label || row.slug}`"
                :aria-label="`Nama jabatan baru untuk ${row.label || row.slug}`"
              />
              <BaseButton
                type="submit"
                variant="ghost"
                size="sm"
                icon="plus"
                :loading="sedangTambah === row.id"
                :disabled="!bisaTambahJabatan(row)"
                class="hak-akses-tambah__tombol"
              >
                Tambah Jabatan
              </BaseButton>
            </div>

            <p class="hak-akses-tambah__keterangan">
              Jabatan baru mulai dengan semua menu terbuka. Daftar menunya mengikuti
              <strong class="text-slate-600!">namanya</strong>:
              <strong class="text-slate-600!">Surveyor</strong> mendapat menu lapangan,
              <strong class="text-slate-600!">Teknisi</strong> atau
              <strong class="text-slate-600!">Cater</strong> mendapat menu teknisi yang sama,
              <strong class="text-slate-600!">Pelanggan</strong> mendapat menu portalnya -- dan yang
              lain otomatis memakai menu <strong class="text-slate-600!">admin</strong>.
            </p>
          </form>
        </div>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch, onMounted } from 'vue'
import { locationService, centralMenuAccessService } from '@/services/central.service'
import { MySwal, showErrorToast, showSuccessToast, showWarningToast } from '@/utils/swal'
import { useUiStore } from '@/stores/uiStore'
import DataTable from '@/presentations/components/ui/DataTable.vue'
import BaseButton from '@/presentations/components/ui/BaseButton.vue'

/**
 * Ikon per group. Sama seperti di `HakAksesView` (versi lama, sudah dihapus)
 * dan `SidebarView`: katalog backend hanya menyebut NAMA group, ikon adalah
 * urusan tampilan.
 */
const GROUP_ICONS = {
  Utama: 'home',
  Pengaturan: 'cog',
  'Basis Data': 'database',
  'Master Instalasi': 'chart-bar',
  'Pencatatan Tagihan': 'file-invoice-dollar',
  Transaksi: 'money-bill-wave',
  Pelaporan: 'file-alt',
  Arsip: 'archive',
  Survey: 'clipboard-check',
  'Pusat Teknis': 'screwdriver-wrench',
  'Layanan Pelanggan': 'file-invoice-dollar',
}

/**
 * Nama peran untuk role yang dipakai sebuah jabatan.
 *
 * Datanya `role` dari backend, yang diturunkan dari NAMA jabatan -- bukan
 * dari user yang memakainya. Itu sebabnya `Cater` dan `Teknisi` jatuh ke
 * baris yang sama: keduanya memang satu tempat di aplikasi, dan daftar
 * centangnya memang sengaja sama.
 */
const ROLE_LABELS = {
  admin: 'Admin',
  surveyor: 'Surveyor',
  teknisi: 'Teknisi / Cater',
  pelanggan: 'Pelanggan',
}

/** Ikon untuk catatan role, sejajar dengan `ROLE_LABELS`. */
const ROLE_ICONS = {
  admin: 'user-shield',
  surveyor: 'clipboard-check',
  teknisi: 'screwdriver-wrench',
  pelanggan: 'file-invoice-dollar',
}

const uiStore = useUiStore()
const loading = computed(() => uiStore.loading)
const lokasi = ref([])

const searchQuery = ref('')
const currentPage = ref(1)
const perPage = ref(10)

/*
 * State accordion memakai PASANG (lokasi, jabatan), bukan jabatan saja.
 *
 * `id` jabatan hanya bermakna DI DALAM satu lokasi: nomor yang sama menunjuk
 * jabatan berbeda di lokasi berbeda. Karena itu `jabatanTerbuka()` dan
 * `pasangan()` menerima baris lokasi dan memeriksa keduanya sekaligus -- kalau
 * disimpan per jabatan saja, membuka jabatan "Admin" di lokasi kedua akan ikut
 * membuka jabatan "Admin" di lokasi pertama.
 *
 * Pasangan dipakai juga sebagai kunci Set yang menyimpan centangan LOKAL. Bentuk
 * `tenantId:jabatanId` disengaja: rangkaian dari dua angka bisa ditabrakkan kalau
 * dirangkai tanpa pemisah.
 */
const terbuka = ref(new Set())

/** `Map` dengan kunci pasangan; `terpilih` menyimpan isi, bukan status. */
const pilihan = ref(new Map())

const sedangSimpanKey = ref(null)
const sedangResetKey = ref(null)

/**
 * Isi kotak "tambah jabatan", dikunci per lokasi.
 *
 * Per lokasi, bukan satu string global, untuk alasan yang sama seperti state
 * centangan: superintendent bisa membuka dua lokasi sekaligus, dan teks yang
 * diketik di satu tidak boleh ikut muncul di kotak lokasi lain.
 */
const namaJabatanBaru = reactive({})

/** Lokasi yang sedang menambah jabatan, supaya spinner-nya hanya di situ. */
const sedangTambah = ref(null)

const tableColumns = [
  { key: 'lokasi', title: 'Lokasi' },
  { key: 'domain', title: 'Domain' },
  { key: 'status', title: 'Status', thClass: 'text-center!', tdClass: 'text-center!' },
]

const filteredLokasi = computed(() => {
  const keyword = searchQuery.value.trim().toLowerCase()

  if (!keyword) return lokasi.value

  return lokasi.value.filter(
    (row) =>
      row.label?.toLowerCase().includes(keyword) ||
      row.nama?.toLowerCase().includes(keyword) ||
      row.slug?.toLowerCase().includes(keyword) ||
      row.domain?.toLowerCase().includes(keyword),
  )
})

/*
 * Mengganti kata kunci atau ukuran halaman mengembalikan ke halaman 1.
 * Tanpa ini: sedang di halaman 3 lalu mengetik kata kunci yang hasilnya cuma 4
 * baris -- tabel tampil kosong dan pagination menulis "Showing 1 to 0 of 4".
 * Sama seperti di `CentralLokasiIndex`.
 */
watch([searchQuery, perPage], () => {
  currentPage.value = 1
})

/** Kunci unik untuk satu jabatan di satu lokasi. */
function pasangan(row, jabatan) {
  return `${row.id}:${jabatan.id}`
}

function jabatanTerbuka(row, jabatan) {
  return terbuka.value.has(pasangan(row, jabatan))
}

/**
 * Centangan lokal untuk satu jabatan.
 *
 * `Map` dipakai, bukan objek biasa: kuncinya berawalan angka (`3:1`), dan
 * sebagai kunci objek biasa itu akan dibaca sebagai indeks larik -- jadi
 * jabatan id 1 di lokasi 3 menimpa jabatan id 3 di lokasi 1. `Map` memperlakukan
 * kuncinya sebagai nilai apa adanya.
 */
function terpilih(jabatan) {
  return pilihan.value.get(jabatan.__key) ?? new Set()
}

function setTerpilih(jabatan, set) {
  pilihan.value.set(jabatan.__key, set)
  // `Map` bereaksi pada `set()` tapi Vue tidak bisa mendeteksi isinya berubah;
  // Ganti rujukannya supaya `terpilih()` di template ikut dihitung ulang.
  pilihan.value = new Map(pilihan.value)
}

/**
 * Menu yang sedang diizinkan, dihitung dari centangan LOKAL -- bukan dari
 * `selected` server. Setelah ada yang dicentang, angka yang benar untuk
 * ditunjukkan ke superadmin adalah pilihannya yang sekarang, bukan keadaannya
 * sebelum halaman ini dibuka.
 *
 * Menu `hidden` tidak ikut dihitung. Ia memang TIDAK tampil sebagai
 * checkbox (lihat `groupMenu()`), jadi memasukkannya membuat lencana
 * menulis angka yang tidak bisa dicocokkan dengan apa pun di layar --
 * mis. teknisi yang hanya punya dua entri terlihat "4 menu diizinkan".
 */
function jumlahDiizinkan(jabatan) {
  const tampil = new Set((jabatan.menu ?? []).filter((m) => !m.hidden).map((m) => m.key))

  return [...terpilih(jabatan)].filter((key) => tampil.has(key)).length
}

/**
 * Warna lencana peran.
 *
 * Dipisah sebagai kelas utilitas yang sudah ada (abu/biru) supaya tidak
 * menambah CSS baru untuk empat warna. Yang penting justru perbedaannya:
 * peran non-admin memakai abu yang sama dengan "Belum diatur", jadi
 * lencana itu terlihat sebagai keterangan, bukan sebagai peringatan --
 * memang tidak ada yang salah padanya.
 */
function badgeRole(role) {
  return role && role !== 'admin'
    ? 'bg-slate-50! text-slate-400! border-slate-200!'
    : 'bg-emerald-50! text-emerald-600! border-emerald-100!'
}

/**
 * Menu jabatan, dikelompokkan.
 *
 * `bisaDiubah` dan `keterangan` dihitung di sini, bukan dikirim backend,
 * karena ini murni aturan tampilan: menu terkunci (mis. selalu terbuka) dan
 * menu yang role-nya tidak cocok tidak bisa dicentang. Yang menentukan
 * boleh/tidaknya sebenarnya tetap `locked` dari backend.
 */
const groupMenu = (jabatan) => {
  const groups = []
  const byGroup = new Map()

  /*
   * Menu `hidden` (Arsip) disembunyikan dari DAFTAR SAJA, bukan dari
   * `jabatan.menu`.
   *
   * Yang menentukan apa yang dikirim ke backend adalah `terpilih(jabatan)`,
   * bukan hasil fungsi ini -- lihat `simpan()`. Kalau menu tersembunyi ikut
   * dibuang dari sini DAN tidak ada di sana, satu klik "Simpan" akan menghapus
   * pengaturan Arsip yang sudah tersimpan, karena `saveForJabatan()` menimpa
   * daftar secara utuh. Frontend di lokasi (`MenuAccessIndex.vue`) dan
   * `bentuk_respons_sama_dengan_aplikasi_lokasi` ikut bergantung pada itu.
   *
   * Menu ini tidak hilang dari katalog: `useOverdueGenNotification.js`
   * membuka `/app/arsip/piutang` saat ada tunggakan, dan `EnforceMenuAccess`
   * menolak endpointnya kalau jabatan tidak punya key-nya.
   */
  const tampil = jabatan.menu.filter((item) => !item.hidden)

  for (const item of tampil) {
    const nama = item.group || 'Lainnya'

    if (!byGroup.has(nama)) {
      byGroup.set(nama, [])
      groups.push(nama)
    }

    const bisaDiubah = !item.locked && !item.role_mismatch

    byGroup.get(nama).push({
      ...item,
      bisaDiubah,
      /*
       * Keterangan = kenapa checkbox ini yang berbentuk seperti ini.
       *
       * `tanpa_sidebar` dan `hidden` bedanya penting untuk yang membaca
       * daftar ini:
       *
       *   - `hidden`          : tidak tampil di sidebar DAN TIDAK bisa
       *                         dicentang di sini -- jadi tidak bisa
       *                         dicabut dari panel pusat.
       *   - `tanpa_sidebar`   : tidak tampil di sidebar, tapi TETAP bisa
       *                         dicentang di sini. Jadi endpointnya masih
       *                         bisa ditutup per jabatan.
       *
       * Menu role non-admin yang `tanpa_sidebar` (mis. "Daftar Survey"
       * milik surveyor) tetap wajib tampil di daftar ini -- kalau
       * disembunyikan, superadmin tidak akan pernah bisa mencabut hak
       * aksesnya.
       */
      keterangan: item.locked
        ? 'wajib terbuka'
        : item.role_mismatch
          ? `bukan untuk role ${(item.roles || []).join(', ')}`
          : item.tanpa_sidebar
            ? 'dibuka dari dashboard, bukan menu'
            : item.hidden
              ? 'tanpa entri di sidebar'
              : '',
    })
  }

  return groups.map((nama) => {
    const items = byGroup.get(nama)
    const pilih = terpilih(jabatan)

    /*
     * Status group HANYA boleh dilihat dari menu yang bisa diubah. Menu
     * terkunci selalu tercentang, jadi ikut dihitungnya akan membuat checkbox
     * group terlihat "tercentang sebagian" padahal tidak ada menu yang bisa
     * diubah ikut tercentang.
     */
    const bisaDiubah = items.filter((i) => i.bisaDiubah)
    const jumlahTerpilih = bisaDiubah.filter((i) => pilih.has(i.key)).length
    const semuaDipilih = bisaDiubah.length > 0 && jumlahTerpilih === bisaDiubah.length

    return {
      nama,
      icon: GROUP_ICONS[nama] || 'folder',
      items,
      terpilihCount: jumlahTerpilih,
      /*
       * Pembagi untuk lencana di header. WAJIB jumlah menu yang bisa diubah,
       * bukan `items.length`: mencentang semua menu yang bisa diubah sudah
       * menghasilkan "tercentang semua", dan lencana yang tetap menulis "2/3"
       * karena satu menu terkunci ikut terhitung di penyebut akan terlihat
       * seperti centangnya gagal.
       */
      bisaDiubahCount: bisaDiubah.length,
      bisaDiubah: bisaDiubah.length > 0,
      semuaDipilih,
      sebagianDipilih: jumlahTerpilih > 0 && !semuaDipilih,
    }
  })
}

/**
 * Menu yang dicentang SEKARANG, dibandingkan dengan yang tersimpan.
 *
 * Perbandingan ini yang membuat tombol simpan mati saat tidak ada perubahan.
 * Tanpa itu, superadmin menekan Simpan, melihat pesan "berhasil", lalu tidak
 * terjadi apa-apa -- dan tidak ada cara memastikan apakah yang sebenarnya
 * tersimpan.
 */
const adaPerubahan = (jabatan) => {
  const tersimpan = new Set(jabatan.menu.filter((m) => m.selected).map((m) => m.key))
  const pilih = terpilih(jabatan)

  if (tersimpan.size !== pilih.size) return true

  for (const key of tersimpan) {
    if (!pilih.has(key)) return true
  }

  return false
}

const sedangMemuat = (row) => row.sedangMemuat === true
const gagalMuat = (row) => Boolean(row.gagalMuat)
const sedangSimpan = (key) => sedangSimpanKey.value === key
const sedangReset = (key) => sedangResetKey.value === key

function toggleItem(jabatan, item) {
  const next = new Set(terpilih(jabatan))

  if (next.has(item.key)) {
    next.delete(item.key)
  } else {
    next.add(item.key)
  }

  setTerpilih(jabatan, next)
}

/**
 * Checkbox group menyentuh semua anaknya yang boleh diubah.
 *
 * Menu terkunci sengaja TIDAK ikut: ia tidak bisa dicabut, jadi ikut mengikuti
 * toggle akan tampak singkat lalu balik lagi -- orang akan mengira tombolnya
 * rusak.
 */
function toggleGroup(jabatan, group) {
  const next = new Set(terpilih(jabatan))
  const akanTerpilih = !group.semuaDipilih

  for (const item of group.items) {
    if (!item.bisaDiubah) continue

    if (akanTerpilih) {
      next.add(item.key)
    } else {
      next.delete(item.key)
    }
  }

  setTerpilih(jabatan, next)
}

function pilihSemua(jabatan) {
  setTerpilih(
    jabatan,
    new Set(jabatan.menu.filter((m) => !m.locked && !m.role_mismatch).map((m) => m.key)),
  )
}

function hapusSemua(jabatan) {
  // Hanya "semua" yang bisa dipilih; menu terkunci tetap tercentang.
  setTerpilih(jabatan, new Set(jabatan.menu.filter((m) => m.locked).map((m) => m.key)))
}

/**
 * Buka tutup satu jabatan.
 *
 * Data jabatan diAMBIL saat baris lokasinya di-expand, bukan di sini. Ini
 * function yang sebelumnya memanggil `muatJabatan()`, dan pemanggilannya tidak
 * pernah sampai: pemicunya ada di dalam daftar jabatan yang belum terisi --
 * jadi halaman tampil "belum punya jabatan" padahal isinya sudah ada.
 *
 * Daftar jadi diambil sekali per lokasi, saat accordion-nya dibuka, lewat
 * `@expand` di `DataTable`. Alasannya jumlah permintaan: daftar ini milik tiap
 * lokasi dan setiap permintaan menyeberang ke database yang berbeda, jadi memuat
 * semuanya sekaligus ketika halaman dibuka berarti satu login panel pusat
 * memicu N koneksi database untuk daftar yang kebanyakan tidak pernah dibuka.
 */ function toggleJabatan(row, jabatan) {
  const key = pasangan(row, jabatan)
  const next = new Set(terbuka.value)

  if (terbuka.value.has(key)) {
    next.delete(key)
  } else {
    next.add(key)
  }

  terbuka.value = next
}

/**
 * Ambil daftar jabatan satu lokasi, lalu simpan centangannya ke state lokal.
 *
 * Dipanggil dari `@expand` DataTable, jadi HANYA saat baris lokasi benar-benar
 * dibuka -- bukan setiap kali slot dirender ulang.
 *
 * Dijaga `sudahDimuat`: `DataTable` juga menyalakan `expand` dari tombol
 * chevron di dalam slot kolom, dan tanpa penjaga itu setiap render ulang akan
 * menembak request lagi ke database lokasi. Memuat ulang yang sama tidak
 * merusak apa pun, tapi setiap satu adalah satu sambungan ke database tenant --
 * dan accordion yang memuat dirinya sendiri berulang adalah gejala yang sulit
 * dibaca kalau koneksi database sedang lambat.
 */
async function muatJabatan(row) {
  if (row.sedangMemuat || row.sudahDimuat) return

  row.sedangMemuat = true
  row.gagalMuat = null

  try {
    const res = await centralMenuAccessService.getAll(row.id)

    // `menu_access.index` membalas `{success, data}` di dalam `.data` axios,
    // jadi isi daftarnya ada di `res.data.data`.
    const data = res?.data?.data ?? []
    row.jabatan = Array.isArray(data) ? data : []

    // Setiap jabatan diberi `__key` di sini, bukan di template: template
    // tidak boleh menulis state, dan fungsi `terpilih()` butuh kunci yang
    // sudah ada SEBELUM yang pertama kali dipanggil saat render.
    const pilihanBaru = new Map(pilihan.value)

    for (const j of row.jabatan) {
      j.__key = `${row.id}:${j.id}`
      pilihanBaru.set(j.__key, new Set((j.menu ?? []).filter((m) => m.selected).map((m) => m.key)))
    }

    pilihan.value = pilihanBaru

    /*
     * Hanya ditandai setelah request-nya benar-benar berhasil. Kalau ditandai
     * sejak awal, tombol "Coba lagi" ikut jadi mati dan lokasi yang sempat
     * bermasalah tidak pernah bisa dibuka tanpa memuat ulang halaman.
     */
    row.sudahDimuat = true
  } catch (err) {
    /*
     * Kegagalan dimuat di sini ditampilkan apa adanya, bukan lewat toast.
     *
     * Toast hilang dalam hitungan detik, sementara yang gagal justru bagian
     * yang paling perlu dilihat: "kenapa" lokasi ini tidak bisa dibuka. Kalau
     * errornya 503 karena database lokasi tidak terbaca, superadmin perlu
     * membacanya sampai selesai, bukan sambil kilat lewat.
     */
    console.error('[CentralHakAksesIndex] gagal memuat jabatan:', err)
    row.jabatan = []
    row.gagalMuat = err?.response?.data?.message || 'Daftar jabatan tidak bisa dimuat.'
  } finally {
    row.sedangMemuat = false
  }
}

/**
 * Simpan daftar menu untuk satu jabatan di satu lokasi.
 *
 * `menu_keys` yang dikirim adalah daftar LENGKAP, bukan tambahan: backend
 * menimpanya utuh.
 */
async function simpan(row, jabatan) {
  const key = pasangan(row, jabatan)
  sedangSimpanKey.value = key

  try {
    const res = await centralMenuAccessService.update(row.id, jabatan.id, [...terpilih(jabatan)])

    /*
     * Pakil ulang data dari server, bukan mempercayai yang terkirim. Backend
     * bisa menolak key yang tidak dikenal; kalau lokal tetap ditampilkan,
     * centangan yang ditolak akan terlihat "tersimpan" padahal tidak.
     */
    if (res?.data?.data?.menu) {
      jabatan.menu = res.data.data.menu
      jabatan.configured = res.data.data.configured
    }

    setTerpilih(jabatan, new Set((jabatan.menu ?? []).filter((m) => m.selected).map((m) => m.key)))

    /*
     * Key yang ditolak dilaporkan backend lewat `diabaikan`. Kalau diabaikan
     * diam-diam, superadmin akan melihat centangnya hilang setelah disimpan
     * tanpa tahu itu ditolak backend, bukan oleh frontend.
     */
    const diabaikan = res?.data?.data?.diabaikan || []

    if (diabaikan.length > 0) {
      showWarningToast(
        `${diabaikan.length} menu tidak dikenal backend dan tidak disimpan: ${diabaikan.join(', ')}`,
      )
    } else {
      showSuccessToast(res?.data?.message || 'Hak akses menu berhasil disimpan.')
    }
  } catch (err) {
    console.error('[CentralHakAksesIndex] gagal menyimpan:', err)

    /*
     * Backend menolak permintaan itu TIDAK mengubah apa pun -- jadi centangan
     * harus dikembalikan ke keadaan sebenarnya, kalau tidak layar akan
     * menampilkan pilihan yang tidak pernah disimpan.
     */
    syncCentangan(jabatan)
    showErrorToast(err)
  } finally {
    sedangSimpanKey.value = null
  }
}

/**
 * Kembalikan satu jabatan di satu lokasi ke default: semua menu boleh.
 *
 * Memakai endpoint reset, BUKAN menyimpan semua centang. Keduanya terlihat sama
 * di layar, tapi berbeda di server: reset menghapus barisnya sehingga jabatan
 * itu kembali ke kondisi "belum pernah diatur" -- dan menu yang ditambahkan
 * kemudian otomatis terbuka untuknya.
 */
async function kembalikanDefault(row, jabatan) {
  const key = pasangan(row, jabatan)

  const konfirmasi = await MySwal.fire({
    title: 'Kembalikan ke default?',
    html: `Jabatan <strong>${jabatan.nama}</strong> di lokasi <strong>${row.label || row.slug}</strong>
      akan kembali ke kondisi <strong>semua menu boleh</strong>.<br>
      Pengaturan yang sekarang akan dihapus.`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#0ea5e9',
    cancelButtonColor: '#6b7280',
    confirmButtonText: 'Ya, kembalikan',
    cancelButtonText: 'Batal',
  })

  if (!konfirmasi.isConfirmed) return

  sedangResetKey.value = key

  try {
    const res = await centralMenuAccessService.reset(row.id, jabatan.id)

    if (res?.data?.data?.menu) {
      jabatan.menu = res.data.data.menu
      jabatan.configured = res.data.data.configured
    }

    syncCentangan(jabatan)
    showSuccessToast(res?.data?.message || 'Hak akses dikembalikan ke default.')
  } catch (err) {
    console.error('[CentralHakAksesIndex] gagal mengembalikan ke default:', err)

    /*
     * Reset yang gagal berarti jabatan masih BATASAN LAMA, bukan default.
     * Centangan harus dikembalikan supaya layar tidak menampilkan keadaan yang
     * tidak sebenarnya berlaku di server.
     */
    syncCentangan(jabatan)
    showErrorToast(err)
  } finally {
    sedangResetKey.value = null
  }
}

/** Isi centangan dari data server, tanpa mengubah server. */
function syncCentangan(jabatan) {
  setTerpilih(jabatan, new Set((jabatan.menu ?? []).filter((m) => m.selected).map((m) => m.key)))
}

/**
 * Tombol "Tambah Jabatan" boleh ditekan kalau kotaknya berisi nama.
 *
 * `trim()` dipakai di sini, bukan hanya saat mengirim: nama yang isinya cuma
 * spasi akan lolos ke backend dan disimpan sebagai jabatan dengan nama kosong,
 * yang tampil sebagai baris kosong di daftar dan tidak bisa dibedakan dari
 *inion yang gagal dimuat.
 */
function bisaTambahJabatan(row) {
  if (sedangTambah.value !== null) return false

  return (namaJabatanBaru[row.id] ?? '').trim().length > 0
}

/**
 * Buat jabatan baru di satu lokasi, lalu masukkan ke daftarnya.
 *
 * Jabatan yang dikembalikan backend langsung dimasukkan ke `row.jabatan`
 * tanpa memuat ulang lokasi: bentuknya sama dengan hasil `getAll()`, jadi
 * tidak perlu permintaan kedua, dan daftar yang sedang terbuka tidak berkedip.
 */
async function tambahJabatan(row) {
  const nama = (namaJabatanBaru[row.id] ?? '').trim()

  if (!nama || sedangTambah.value !== null) return

  sedangTambah.value = row.id

  try {
    const res = await centralMenuAccessService.store(row.id, nama)
    const baru = res?.data?.data

    if (!baru?.id) {
      // Bentuk respons di luar yang diharapkan. Melanjutkannya diam-diam akan
      // menampilkan daftar yang terlihat berhasil padahal jabatan itu tidak
      // pernah tersimpan.
      throw new Error('Backend tidak mengembalikan jabatan yang baru dibuat.')
    }

    baru.__key = `${row.id}:${baru.id}`
    row.jabatan = [...(row.jabatan ?? []), baru]
    namaJabatanBaru[row.id] = ''

    // Centangan awal ikut diisi sekarang, bukan menunggu jabatan diklik.
    // Kalau tidak, `pilihan` tidak punya entri untuk jabatan ini sampai
    // `muatJabatan()` dipanggil lagi -- dancentangan yang tampil yang benar
    // hanya kebetulan, karena Set kosong berarti "tidak ada yang tercentang".
    pilihan.value = new Map(pilihan.value).set(baru.__key, new Set())
  } catch (err) {
    console.error('[CentralHakAksesIndex] gagal menambah jabatan:', err)
    showErrorToast(err)
  } finally {
    sedangTambah.value = null
  }
}

async function loadData() {
  try {
    const res = await locationService.getAll()
    const data = res.data?.data ?? []

    // Baris tabel butuh `jabatan`, `sedangMemuat`, `gagalMuat`, dan
    // `sudahDimuat` sejak awal supaya template tidak membaca properti
    // yang belum ada saat render pertama.
    lokasi.value = (Array.isArray(data) ? data : []).map((row) => ({
      ...row,
      jabatan: [],
      sedangMemuat: false,
      gagalMuat: null,
      sudahDimuat: false,
    }))
  } catch (error) {
    showErrorToast(error)
  }
}

onMounted(loadData)
</script>

<style scoped>
/*
 * Lebar maksimum & jarak tepi diambil dari `--central-*` di `CentralLayout.vue`,
 * sama seperti `CentralLokasiIndex.vue`.
 */
.hak-akses-wrap {
  width: 100%;
  max-width: var(--central-content-max, 1200px);
  margin-inline: auto;
}

/* Tabel hanya butuh tiga kolom, jadi tidak perlu min-width selebar tabel lokasi. */
.hak-akses-table :deep(table) {
  min-width: 520px;
}

/* `DataTable` sudah punya kartu sendiri; bayangannya disamakan dengan halaman lain. */
.hak-akses-table :deep(.shadow-sm) {
  box-shadow: var(--central-shadow-card);
}

.hak-akses-intro {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  margin-bottom: 0.85rem;
  padding: 0.7rem 0.9rem;
  border-radius: 12px;
  background: #f0f9ff;
  border: 1px solid #e0f2fe;
  color: #075985;
}

.hak-akses-intro :deep(svg) {
  margin-top: 2px;
  flex-shrink: 0;
}

.hak-akses-intro p {
  margin: 0;
  font-size: 0.75rem;
  line-height: 1.5;
}

/* Isi baris yang sudah dibuka: di dalam abu tabel, dengan ruang bernapas. */
.hak-akses-panel {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.hak-akses-panel__status {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  padding: 0.85rem 1rem;
  border-radius: 12px;
  background: #f8fafc;
  border: 1px dashed #e2e8f0;
  font-size: 0.75rem;
  color: #64748b;
}

.hak-akses-panel__status.is-error {
  background: #fef2f2;
  border-color: #fecaca;
  color: #b91c1c;
}

.hak-akses-jabatan {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.hak-akses-jabatan__item {
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  background: #fff;
  overflow: hidden;
}

.hak-akses-jabatan__item.is-open {
  border-color: #bfdbfe;
}

.hak-akses-jabatan__head {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.6rem 0.85rem;
  text-align: left;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.hak-akses-jabatan__head:hover {
  background: #f8fafc;
}

.hak-akses-jabatan__caret {
  color: #94a3b8;
  font-size: 0.7rem;
  transition: transform 0.25s ease;
  flex-shrink: 0;
}

.hak-akses-jabatan__caret.is-open {
  transform: rotate(90deg);
}

.hak-akses-jabatan__body {
  padding: 0.85rem;
  border-top: 1px solid #f1f5f9;
  background: #f8fafc;
}

.hak-akses-catatan {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  margin-bottom: 0.75rem;
  padding: 0.6rem 0.75rem;
  border-radius: 10px;
  background: #eff6ff;
  border: 1px solid #dbeafe;
}

.hak-akses-catatan p {
  margin: 0;
}

/*
 * Varian peringatan. Kuning, bukan biru, karena isinya bukan penjelasan
 * sondern "menyetel ini tidak akan berhasil" -- itu harus terbaca berbeda
 * dari catatan biasa sebelum orang mulai mencentang.
 */
.hak-akses-catatan.is-warning {
  background: #fffbeb;
  border-color: #fde68a;
}

/*
 * Checkbox bawaan browser tidak punya token aplikasi, jadi warna centangnya
 * harus diaktifkan lewat `accent-color`. Tanpa ini seluruh daftar centang tampil
 * abu-abu, dan checkbox group yang tercentang sebagian kehilangan artinya.
 *
 * `--color-primary` dipakai langsung (bukan `accent-color: primary`) supaya ikut
 * warna lokasi yang ditulis `utils/applyTheme.js`.
 */
.hak-akses__checkbox {
  width: 1rem;
  height: 1rem;
  cursor: inherit;
  flex-shrink: 0;
  accent-color: var(--color-primary, #0ea5e9);
}

/*
 * Ritme vertikal baris tombol.
 *
 * `ui-base.css` menulis ukuran tombol lewat `@apply !text-xs` / `!text-sm`. Di
 * Tailwind v4 utilitas font-size itu sekaligus membawa `line-height` dari
 * pasangan token, sehingga hasil akhirnya `line-height: <nilai> !important` pada
 * `.base-button--sm/md/lg`. Aturan scoped style biasa SELALU kalah dari
 * `!important` itu, bukan karena urutan cascade melainkan karena tingkat
 * importance-nya. Di sini tingkat importance-nya disamakan.
 *
 * `--tw-leading` ikut disetel karena `.base-button--*` menulis
 * `var(--tw-leading, ...)`: menyetel variabel ini membatalkan nilai fallback
 * tanpa perlu menebak line-height yang dimaksud.
 */
.hak-akses__btn {
  padding-top: 0.625rem !important;
  padding-bottom: 0.625rem !important;
  --tw-leading: 1.5 !important;
  line-height: 1.5 !important;
  gap: 0.4rem !important;
}

/* Ikon di dalam tombol mewarisi `line-height` body, jadi harus lewat `:deep()`. */
.hak-akses__btn :deep(.base-button__icon),
.hak-akses__btn :deep(.base-button__spinner) {
  line-height: 1;
}

.hak-akses__aksi {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  row-gap: 0.625rem;
  margin-bottom: 0.75rem;
}

.hak-akses__aksi-label,
.hak-akses__aksi-count {
  font-size: 10px;
  font-weight: 900;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: #94a3b8;
  line-height: 1;
}

.hak-akses__aksi-count {
  margin-left: auto;
  font-weight: 700;
}

/*
 * Group per menu, masing-masing jadi kartu sendiri.
 *
 * Grid supaya satu group terlihat utuh dalam sekali lihat; urutan atas-bawahnya
 * tetap mengikuti urutan katalog backend. `minmax(210px, 1fr)` dipilih supaya
 * tiga kolom muat di dalam panel accordion tanpa nama menu terpotong, dan tetap
 * turun ke satu kolom di layar sempit.
 */
.hak-akses__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 0.75rem;
}

.hak-akses__group {
  display: flex;
  flex-direction: column;
  border-radius: 14px;
  background: #fff;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 2px rgb(15 23 42 / 0.04);
  overflow: hidden;
}

.hak-akses__group-head {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.65rem 0.85rem;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
  cursor: pointer;
}

.hak-akses__group-count {
  margin-left: auto;
  padding: 0.1rem 0.45rem;
  border-radius: 8px;
  background: #fff;
  border: 1px solid #e2e8f0;
  font-size: 10px;
  font-weight: 900;
  color: #64748b;
  font-variant-numeric: tabular-nums;
  flex-shrink: 0;
}

.hak-akses__group-body {
  flex: 1;
}

.hak-akses__item {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.5rem 0.85rem;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.hak-akses__item + .hak-akses__item {
  border-top: 1px solid #f1f5f9;
}

.hak-akses__item:hover {
  background: rgb(240 249 255 / 0.6);
}

.hak-akses__item.is-disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.hak-akses__item.is-disabled:hover {
  background: transparent;
}

.hak-akses__legenda {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem 1.25rem;
  margin-top: 0.75rem;
  padding-top: 0.75rem;
  border-top: 1px solid #e2e8f0;
  font-size: 10px;
  color: #94a3b8;
}

.hak-akses__legenda span {
  line-height: 1.4;
}

.hak-akses__footer {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-top: 0.85rem;
  padding: 0.85rem;
  padding-top: 1rem;
  padding-bottom: 1rem;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  background: #fff;
}

@media (min-width: 640px) {
  .hak-akses__footer {
    flex-direction: row;
    align-items: center;
  }
}

.hak-akses__btn-simpan {
  white-space: nowrap;
}

/*
 * Kotak tambah jabatan. Berdampingan dengan daftar jabatan di atasnya, jadi
 * penanda visualnya dibuat berbeda: bukan kartu putih seperti isi accordion,
 * tapi strip abu dengan garis putus-putus -- terbaca sebagai "addendum", bukan
 * sebagai salah satu jabatan yang sudah ada.
 */
.hak-akses-tambah {
  margin-top: 0.75rem;
  padding-top: 0.75rem;
  border-top: 1px dashed #cbd5e1;
}

.hak-akses-tambah__isi {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.hak-akses-tambah__ikon {
  flex-shrink: 0;
  font-size: 13px;
  color: #94a3b8;
}

.hak-akses-tambah__input {
  flex: 1;
  min-width: 0;
  padding: 0.45rem 0.7rem;
  font-size: 12px;
  color: #1e293b;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  outline: none;
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}

.hak-akses-tambah__input::placeholder {
  color: #94a3b8;
}

.hak-akses-tambah__input:focus {
  background: #fff;
  border-color: #38bdf8;
  box-shadow: 0 0 0 3px rgb(56 189 248 / 15%);
}

.hak-akses-tambah__tombol {
  flex-shrink: 0;
  white-space: nowrap;
}

.hak-akses-tambah__keterangan {
  margin-top: 0.5rem;
  font-size: 10px;
  line-height: 1.5;
  color: #94a3b8;
}

/* Di layar sempit, tombol turun ke baris sendiri; input tetap penuh. */
@media (max-width: 639px) {
  .hak-akses-tambah__isi {
    flex-wrap: wrap;
  }

  .hak-akses-tambah__input {
    flex-basis: calc(100% - 2rem);
  }

  .hak-akses-tambah__tombol {
    margin-left: 1.75rem;
  }
}
</style>
