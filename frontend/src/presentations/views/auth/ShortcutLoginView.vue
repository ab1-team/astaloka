<template>
  <div class="sc-page">
    <div class="sc-page__inner">
      <!-- ===== Kepala halaman ===== -->
      <div class="sc-head">
        <div>
          <div class="flex items-center gap-2! mb-1!">
            <span class="sc-badge">
              <font-awesome-icon icon="user-shield" />
              Login Pintasan
            </span>
            <router-link to="/login" class="sc-back">
              <font-awesome-icon icon="arrow-left" />
              Login biasa
            </router-link>
          </div>

          <h1 class="text-2xl! font-bold! text-slate-900! tracking-tight!">Masuk Dashboard</h1>
          <p class="text-sm! text-slate-500! leading-relaxed!">
            Pilih salah satu user untuk langsung masuk tanpa mengetik email dan password.
          </p>
        </div>
      </div>

      <!--
        Peringatan Keamanan.

        Endpoint ini menerbitkan token untuk user mana pun yang dipilih, tanpa
        memeriksa password sama sekali. Halaman ini tidak boleh dipakai di
        server yang bisa dijangkau orang lain -- jadi peringatan ini bukan
        hiasan, dan tidak boleh dihapus tanpa menggantinya dengan mekanisme
        yang benar-benar membatasi akses.
      -->
      <p class="sc-warning">
        <font-awesome-icon icon="triangle-exclamation" class="sc-warning__icon" />
        <span>
          <strong>Tanpa autentikasi.</strong> Siapa pun yang tahu alamat halaman ini bisa masuk
          sebagai user mana pun di lokasi <strong>{{ lokasiAktif || 'ini' }}</strong
          >. Jangan publikasikan aplikasinya selama halaman ini hidup.
        </span>
      </p>

      <!--
        Lokasi yang sedang dilayani.

        Daftar ini HANYA berisi user lokasi di bawah, jadi kolomnya dibuang dan
        namanya pindah ke kepala halaman. Nationwide Kolom LOKASI di sini pernah
        menyesatkan: tombol di baris user menunjuk database yang berbeda dari
        database yang dibaca halaman ini, sehingga yang diklik orang bukan
        user yang sedang dibaca.
      -->
      <div v-if="lokasiAktif" class="sc-lokasi">
        <font-awesome-icon icon="location-dot" class="sc-loc-icon" />
        <div class="min-w-0!">
          <div class="font-semibold! text-sm! text-slate-900! truncate!">{{ lokasiAktif }}</div>
          <div class="sc-slug">{{ slugAktif }}</div>
        </div>
      </div>

      <!-- ===== Tabel ===== -->
      <DataTable
        :data="baris"
        :columns="kolom"
        title=""
        v-model:current-page="currentPage"
        v-model:per-page="perPage"
        :total-entries="baris.length"
        v-model="searchQuery"
        class="mt-4!"
        search-placeholder="Cari nama atau email..."
        empty-title="User Tidak Ditemukan"
        empty-message="Tidak ada user lokasi ini yang cocok dengan kata kunci pencarian."
        empty-icon="users-slash"
        :loading="loading"
      >
        <template #column-nama="{ row }">
          <div class="flex items-center gap-3!">
            <div
              class="w-9! h-9! rounded-full! flex items-center justify-center text-white! text-xs! font-bold! shrink-0!"
              :style="{ background: row.avatarColor }"
            >
              {{ row.initials }}
            </div>
            <div class="font-semibold! text-sm! text-slate-900!">{{ row.name }}</div>
          </div>
        </template>

        <template #column-email="{ row }">
          <div class="text-xs! font-medium! text-slate-600!">{{ row.email }}</div>
        </template>

        <template #column-role="{ row }">
          <span class="sc-role">{{ row.role }}</span>
        </template>

        <template #column-password="{ row }">
          <!--
            Kolom password.

            Teksnya hanya terisi kalau password user memang cocok dengan
            password demo yang sudah terdokumentasi (lihat
            `config/shortcut_login.php`). Password yang sudah diganti user
            tidak bisa ditampilkan -- kolomnya berisi hash bcrypt yang satu
            arah -- jadi baris itu tampil tersamar.

            Tombol "Masuk" tetap bekerja untuk keduanya, karena pintasan ini
            memang tidak memakai password.
          -->
          <code v-if="row.password" class="sc-pass">{{ row.password }}</code>
          <code v-else class="sc-pass sc-pass--masked"
            >&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;</code
          >
        </template>

        <template #column-aksi="{ row }">
          <BaseButton
            variant="primary"
            size="sm"
            icon="arrow-right"
            icon-right
            :loading="sedangMasuk === row.id"
            :disabled="sedangMasuk !== null && sedangMasuk !== row.id"
            @click="masuk(row)"
          >
            Masuk Dashboard
          </BaseButton>
        </template>
      </DataTable>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import axios from '@/utils/axios.js'
import { MySwal } from '@/utils/swal'
import { useUiStore } from '@/stores/uiStore'
import { showInvoiceLockModal } from '@/composables/useInvoiceLock'
import { getDashboardRoute } from '@/router/dashboardRoutes'
import { simpanTenantSlug } from '@/utils/tenantSlug'
import { simpanRingkasanPiutang } from '@/composables/useOverdueGenNotification'
import DataTable from '@/presentations/components/ui/DataTable.vue'
import BaseButton from '@/presentations/components/ui/BaseButton.vue'

/*
 * Halaman `/login/user`: daftar user LOKASI INI + tombol untuk langsung masuk
 * sebagai salah satunya.
 *
 * Ini memakai instance axios yang sama dengan aplikasi (`@/utils/axios.js`),
 * bukan `centralAxios`. Endpoint-nya berada di database lokasi, jadi ia butuh
 * `X-Tenant-Slug` yang dikirim instans itu -- dan responsnya memakai bentuk
 * yang sama dengan `POST /login`, sehingga penanganannya (penyimpanan token,
 * popup tagihan) bisa disalin apa adanya dari `LoginView.vue`.
 *
 * SATU LOKASI, BUKAN BANYAK. Backend membalas dengan satu grup lokasi --
 * lokasi yang sedang melayani request ini -- dan hanya user dari database itu.
 * Halaman ini karena itu tidak pernah punya daftar lintas lokasi, dan tidak
 * pernah meminta `tenant_id`: yang diklik orang dijamin user yang sedang
 * ditampilkan di atasnya. Sebelumnya `tenant_id` ikut dikirim, dan
 * `user_id` yang sama di dua lokasi bisa menunjuk akun yang berbeda.
 *
 * `useRouter` sengaja tidak dipakai: navigasi keluar dari halaman ini lewat
 * `window.location.href`, bukan `router.push` (lihat catatan di `masuk()`).
 */
const uiStore = useUiStore()

/**
 * Satu grup lokasi, bukan array banyak.
 *
 * Backend sengaja membalas dalam bentuk `data: [grup]` supaya bentuk
 * responsnya tidak berubah, dan halaman ini membaca elemen pertamanya. Kalau
 * backend suatu saat membalas array kosong (404 `NO_TENANT` tidak sampai ke
 * sini -- ia sudah jadi pesan error), `grup` bernilai null dan tabel kosong.
 */
const grup = ref(null)
const loading = ref(false)
const searchQuery = ref('')
const currentPage = ref(1)
const perPage = ref(10)
const sedangMasuk = ref(null)

/** Nama lokasi yang sedang dilayani, untuk kepala halaman dan peringatan. */
const lokasiAktif = computed(() => grup.value?.label || grup.value?.nama || '')

const slugAktif = computed(() => grup.value?.slug || '')

/** Samanya dengan LoginView: batas 8 jam, sesuai `config/sanctum.php`. */
const SESI_MILIDETIK = 8 * 60 * 60 * 1000

/*
 * Inisial untuk avatar.
 *
 * Nama di sini bisa satu kata ("Asta") atau berisi imbel panjang dengan gelar,
 * jadi ambil dua huruf pertama saja -- bukan semua inisialnya, yang untuk
 * "Asta Brata Wigya" menghasilkan kotak berisi tiga huruf dan jadi lebih
 * sempit dari nama aslinya.
 */
const initialsOf = (nama) => {
  const parts = String(nama ?? '')
    .trim()
    .split(/\s+/)
    .filter(Boolean)

  if (parts.length === 0) return '?'

  return parts
    .slice(0, 2)
    .map((w) => w.charAt(0).toUpperCase())
    .join('')
}

/*
 * Warna avatar, dipilih dari palet yang SENGAJA tidak memuat warna lokasi.
 *
 * `#0ea5e9` (biru bawaan) dihapus dari daftar ini. Avatar adalah identitas
 * orang, bukan identitas lokasi, dan warna yang sama dengan warna utama
 * aplikasi membuat sekilas terlihat seperti avatar itu menandai status --
 * padahal warna itu hanya kebetulan sama dengan warna bawaan.
 *
 * Menghapus satu warna saja mengubah urutan, dan itu tidak apa-apa: avatar
 * dihitung dari `user.id`, jadi tidak ada user yang "berpindah warna" antara
 * dua load -- hasilnya cuma berbeda dari sebelum, dan tetap stabil.
 */
const WARNA_AVATAR = ['#f43f5e', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899']

/*
 * Satu baris per user.
 *
 * `id` sekarang polos, tidak lagi digabung dari tenant_id + user_id.
 * Penggabungannya tidak dibutuhkan lagi karena hanya ada satu lokasi -- dan
 * justru jadi tidak konsisten sekarang: `DataTable` memakai `row.id` sebagai
 * key, jadi bentuk key-nya tidak boleh berubah-ubah antar muat.
 */
const semuaBaris = computed(() =>
  (grup.value?.users || []).map((user) => ({
    id: user.id,
    user_id: user.id,
    name: user.name,
    initials: initialsOf(user.name),
    avatarColor: WARNA_AVATAR[user.id % WARNA_AVATAR.length],
    email: user.email,
    role: user.role,
    password: user.password,
  })),
)

/*
 * Pencarian di frontend, bukan di server.
 *
 * Daftar ini sudah ter-download utuh untuk ditampilkan -- menambah endpoint
 * pencarian terpisah hanya menambah satu request dan satu tempat lagi yang
 * bisa tidak sinkron. Backend juga membatasi jumlah user
 * (`shortcut_login.per_location_limit`), jadi tidak ada yang bisa tumbuh
 * cukup besar untuk membenarkan pemisahan itu.
 *
 * Tidak ada lagi pencarian berdasarkan lokasi: hanya ada satu lokasi, jadi
 * mencocokkan nama lokasi akan selalu benar -- mengetik slug di kotak search
 * hanya memberi kesan ada filter yang diam-diam tidak mengubah apa pun.
 */
const baris = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()

  if (!q) return semuaBaris.value

  return semuaBaris.value.filter(
    (r) => r.name.toLowerCase().includes(q) || r.email.toLowerCase().includes(q),
  )
})

const kolom = [
  {
    key: 'nama',
    title: 'NAMA',
    tdClass: 'whitespace-nowrap!',
  },
  {
    key: 'email',
    title: 'EMAIL',
    tdClass: '',
  },
  {
    key: 'role',
    title: 'ROLE',
    tdClass: 'whitespace-nowrap!',
  },
  {
    key: 'password',
    title: 'PASSWORD',
    tdClass: 'whitespace-nowrap!',
  },
  {
    key: 'aksi',
    title: 'AKSI',
    tdClass: '',
  },
]

/*
 * Halaman kembali ke 1 setiap kata kunci berubah.
 *
 * Tanpa ini, mengetik di kotak search saat berada di halaman 3 sering
 * menyisakan tabel kosong: hasil pencarian tidak pernah punya 3 halaman, tapi
 * `DataTable` masih menampilkan halaman 3 yang sekarang tidak ada isinya.
 */
watch(searchQuery, () => {
  currentPage.value = 1
})

const muatDaftar = async () => {
  loading.value = true

  try {
    const res = await axios.get('/login-user')

    // Backend membalas `data: [grup]`. Yang diambil hanya elemen pertama --
    // dan itu sudah pasti satu-satunya, karena endpoint-nya hanya membaca
    // lokasi yang sedang melayaninya.
    grup.value = res.data?.data?.[0] ?? null
  } catch (error) {
    const status = error.response?.status

    /*
     * 404 punya dua sebab yang sangat berbeda, jadi pesannya dibedakan.
     *
     *   - `SHORTCUT_LOGIN_ENABLED=false` -> fitur memang dimatikan. Ini yang
     *     paling sering terjadi di produksi dan gejalanya harus langsung
     *     jelas, bukan "halaman kosong".
     *   - `code: NO_TENANT` -> request tidak membawa identitas lokasi, jadi
     *     backend tidak tahu database mana yang harus dibaca. Ini bukan salah
     *     server: buka lewat domain lokasi, atau (di development dengan
     *     TENANCY_LOOKUP=slug) kirim header X-Tenant-Slug.
     *
     * Keduanya 404, jadi `code` dari backend yang membedakan -- bukan tebakan
     * dari status.
     */
    const pesan =
      status === 404 && error.response?.data?.code !== 'NO_TENANT'
        ? 'Login pintasan dimatikan di server (SHORTCUT_LOGIN_ENABLED=false).'
        : status === 503
          ? error.response?.data?.message ||
            'Database lokasi tidak bisa dibuka. Periksa di panel pusat.'
          : error.response?.data?.message || 'Gagal memuat daftar user.'

    MySwal.fire({
      icon: 'error',
      title: 'Tidak Bisa Dimuat',
      text: pesan,
      confirmButtonColor: 'var(--color-primary, #3b82f6)',
    })
  } finally {
    loading.value = false
  }
}

const masuk = async (row) => {
  sedangMasuk.value = row.id

  try {
    /*
     * Body hanya `user_id`. `tenant_id` sengaja tidak ikut dikirim: backend
     * sudah membacanya dari lokasi yang sedang melayaninya, dan mengirimnya
     * hanya membuka satu pertanyaan lagi ("kalau tidak sama, mana yang
     * dipakai?"). Yang menentukan user mana yang masuk adalah lokasi tempat
     * request sampai -- bukan angka yang dikirim browser.
     */
    const res = await axios.post('/login-user/login', {
      user_id: row.user_id,
    })

    if (!res.data?.success) throw new Error(res.data?.message || 'Login gagal.')

    const data = res.data.data

    localStorage.setItem('auth_token', data.token)
    localStorage.setItem('auth_expires_at', (Date.now() + SESI_MILIDETIK).toString())

    /*
     * Simpan slug lokasi yang baru dipakai.
     *
     * Tanpa ini, `tenant_slug` yang tertinggal dari sesi sebelumnya tetap
     * terkirim sebagai `X-Tenant-Slug` pada SETIAP request sesudahnya,
     * termasuk `GET /api/branding`. Gejalanya: dashboard terbuka dengan
     * benar, tapi judul dan warnanya milik lokasi lain, dan tidak ada satu
     * pun request yang gagal.
     *
     * Nilainya dibaca dari respons login, bukan dari daftar di atas: daftar
     * bisa saja sudah selesai dimuat beberapa waktu lalu, sedangkan lokasi
     * aktif adalah yang terakhir dihitung backend saat token diterbitkan.
     */
    simpanTenantSlug(data.tenant_slug)

    uiStore.setUserData(data.user)
    uiStore.setUserRole(data.user.role)

    /*
     * Ringkasan generate piutang diserahterimakan ke halaman berikutnya.
     *
     * Backend sudah menjalankan generate piutang di jalur login ini (sama
     * seperti `POST /api/login`), jadi tagihan menunggak sudah terjurnal.
     * Tapi halaman ini memuat ulang SELURUH aplikasi lewat
     * `window.location.href`, sehingga ringkasan yang ada di memori JS
     * lenyap sebelum popup sempat tampil.
     *
     * Tanpa serah-terima ini, generate tetap jalan tapi user tidak pernah
     * melihat popup-nya -- gejalanya persis seperti "generate tidak jalan",
     * padahal jurnalnya sudah terbentuk.
     */
    simpanRingkasanPiutang(data.piutang_tunggakan)

    // Tidak pakai `router.push` + await: halaman ini berada di luar layout
    // aplikasi, jadi memuat komponen dashboard dari sini bisa menahan
    // navigasi sampai dashboard benar-benar siap. `window.location.href`
    // memuat ulang seluruh aplikasi -- persis seperti yang terjadi setelah
    // login biasa, dan tidak menyisakan state milik daftar user ini.
    window.location.href = getDashboardRoute(data.user.role)
  } catch (error) {
    sedangMasuk.value = null

    /*
     * 402 = lokasi ditahan tagihan. Ditangani dengan popup yang sama persis dengan
     * `LoginView.vue`, supaya angka yang tampil sama dan user punya jalan keluar
     * yang sama. Tanpa ini, pintasan jadi terlihat seperti cara melewati penagihan.
     */
    if (error.response?.status === 402) {
      await showInvoiceLockModal(error.response.data?.data, {
        logout: () => {
          window.location.href = '/login?logout=invoice'
        },
      })
      return
    }

    MySwal.fire({
      icon: 'error',
      title: 'Gagal Masuk',
      text: error.response?.data?.message || 'Tidak bisa masuk sebagai user ini.',
      confirmButtonColor: 'var(--color-primary, #3b82f6)',
    })
  }
}

onMounted(muatDaftar)
</script>

<style scoped>
/*
 * Latar dan jarak tepi mengikuti halaman daftar yang lain
 * (`--color-body-bg`), bukan latar gambar milik halaman login: halaman ini
 * sudah jadi halaman kerja biasa -- tabel, pencarian, paginasi -- dan akan
 * jauh lebih panjang dari satu layar. Latar gambar dengan
 * `background-attachment: fixed` justru membuat tabel yang panjang terasa
 * seperti satu halaman login yang kelamaan.
 *
 * `.sc-page` juga menyediakan jarak tepi dan lebar maksimum, karena halaman
 * ini berada di luar `MainView` yang biasanya pemilik keduanya.
 */
.sc-page {
  min-height: 100vh;
  background: var(--color-body-bg, #f1f5f9);
  padding: 1.5rem 1rem;
}

.sc-page__inner {
  width: 100%;
  max-width: 1400px;
  margin-inline: auto;
}

.sc-head {
  margin-bottom: 1rem;
}

.sc-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.25rem 0.7rem;
  border-radius: 9999px;
  background: var(--color-blue-100, #e0f2fe);
  color: var(--color-blue-800, #1e3a8a);
  font-size: 0.62rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.sc-back {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.25rem 0.7rem;
  border-radius: 9999px;
  border: 1px solid var(--color-border, #e2e8f0);
  background: var(--color-card-bg, #fff);
  color: var(--color-gray-500, #6b7280);
  font-size: 0.68rem;
  font-weight: 700;
  text-decoration: none;
  transition:
    border-color 0.15s ease,
    color 0.15s ease;
}

.sc-back:hover {
  border-color: var(--color-primary, #0ea5e9);
  color: var(--color-primary-dark, #0284c7);
}

/* Peringatan memakai kuning-oranye, bukan merah: ini peringatan, bukan
   kegagalan. Isinya soal risiko, dan tidak ada yang error di halaman ini. */
.sc-warning {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  margin: 0;
  padding: 0.7rem 0.9rem;
  border: 1px solid #fed7aa;
  border-radius: var(--border-radius-lg, 0.75rem);
  background: #fff7ed;
  color: var(--color-yellow-800, #92400e);
  font-size: 0.78rem;
  line-height: 1.55;
}

.sc-warning__icon {
  margin-top: 0.18rem;
  flex-shrink: 0;
  color: #ea580c;
}

/*
 * Nama lokasi aktif, di kepala halaman.
 *
 * Dipindah ke sini dari kolom tabel. Nunca kolom LOKASI per baris: kolom itu
 * menyiratkan bahwa setiap baris bisa milik lokasi berbeda, dan sekali
 * begitu tombol di baris itu tidak lagi jelas menekan user yang mana. Dengan
 * satu lokasi, namanya cukup ditulis sekali di atas tabel.
 */
.sc-lokasi {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.85rem;
  padding: 0.55rem 0.75rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-lg, 0.75rem);
  background: var(--color-card-bg, #fff);
}

.sc-loc-icon {
  color: var(--color-primary, #0ea5e9);
  flex-shrink: 0;
}

.sc-slug {
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
  font-size: 0.68rem;
  color: var(--color-gray-400, #94a3b8);
}

.sc-role {
  display: inline-block;
  padding: 0.15rem 0.5rem;
  border-radius: var(--border-radius-sm, 0.375rem);
  background: var(--color-blue-100, #e0f2fe);
  color: var(--color-blue-800, #1e3a8a);
  font-size: 0.62rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.sc-pass {
  display: inline-block;
  padding: 0.15rem 0.45rem;
  border-radius: var(--border-radius-sm, 0.375rem);
  background: var(--color-gray-100, #f1f5f9);
  color: var(--color-gray-700, #374151);
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
  font-size: 0.72rem;
}

.sc-pass--masked {
  color: var(--color-gray-400, #94a3b8);
  letter-spacing: 0.08em;
}
</style>
