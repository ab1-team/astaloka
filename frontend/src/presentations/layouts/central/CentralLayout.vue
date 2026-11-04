<template>
  <div class="central-layout" :class="{ 'central-layout--collapsed': !sidebarOpen }">
    <div
      class="central-layout__overlay"
      :class="{ 'is-open': mobileSidebarOpen }"
      @click="mobileSidebarOpen = false"
    ></div>

    <aside class="central-sidebar" :class="{ 'is-open': mobileSidebarOpen }">
      <div class="central-sidebar__brand">
        <div class="central-sidebar__mark">
          <font-awesome-icon icon="gem" />
        </div>
        <div v-if="sidebarOpen" class="central-sidebar__identity">
          <p class="central-sidebar__name">ASTALOKA</p>
          <p class="central-sidebar__role">Panel Pusat</p>
        </div>
      </div>

      <nav class="central-sidebar__nav">
        <router-link
          v-for="item in menuItems"
          :key="item.to"
          :to="item.to"
          class="central-sidebar__link"
          :class="{ 'is-active': isActive(item.to) }"
          :title="!sidebarOpen ? item.label : undefined"
          @click="mobileSidebarOpen = false"
        >
          <font-awesome-icon :icon="item.icon" class="central-sidebar__icon" />
          <span v-if="sidebarOpen" class="central-sidebar__label">{{ item.label }}</span>
        </router-link>
      </nav>

      <div class="central-sidebar__user" v-if="sidebarOpen">
        <div class="central-sidebar__avatar">
          {{ initials }}
        </div>
        <div class="central-sidebar__user-info">
          <p class="central-sidebar__user-name">{{ user?.name || 'Superadmin' }}</p>
          <p class="central-sidebar__user-mail">{{ user?.email }}</p>
        </div>
      </div>
    </aside>

    <div class="central-main">
      <header class="central-topnav">
        <button
          type="button"
          class="central-topnav__menu-btn"
          aria-label="Buka menu"
          @click="mobileSidebarOpen = true"
        >
          <font-awesome-icon icon="bars" />
        </button>

        <!--
          Tombol buka/tutup sidebar tinggal di navbar, tidak lagi menempel di
          dalam sidebar. Dulu tombolnya ikut geser bersama panelnya: panel
          melebar, tombolnya terdorong ke kanan; panel menyempit, tombolnya
          ikut ke kiri. Titik yang diklik jadi berbeda tiap kali buka-tutup.
          Di navbar posisinya tetap, jadi yang diklik selalu tempat yang sama.
        -->
        <button
          type="button"
          class="central-topnav__collapse"
          :aria-label="sidebarOpen ? 'Ciutkan menu' : 'Buka menu'"
          :title="sidebarOpen ? 'Ciutkan menu' : 'Buka menu'"
          @click="toggleSidebar"
        >
          <font-awesome-icon :icon="sidebarOpen ? 'angles-left' : 'angles-right'" />
        </button>

        <div class="central-topnav__title">
          <h1>{{ pageTitle }}</h1>
          <p>{{ pageSubtitle }}</p>
        </div>

        <div class="central-topnav__actions">
          <!--
            Tombol reload diganti notifikasi.

            Alasan penggantiannya, bukan sekadar "ikon lonceng lebih umum":
            di navbar ini sudah ada pemuatan otomatis. Dashboard, daftar
            tenant, dan daftar tagihan semuanya memanggil
            `useAutoRefresh` tiap 30-45 detik, jadi angka yang ditampilkan
            tidak pernah lebih tua dari itu. Tombol reload karena itu
            hampir tidak pernah melakukan sesuatu -- ia hanya memaksa
            satu request lagi yang hasilnya akan hilang sebelum tombol
            berikutnya ditekan.

            Yang hilang, dan tidak digantikan: akses pemuatan ulang yang
            dipaksa tangan. Kalau polling gagal diam-diam, atau session
            sudah kedaluwarsa dan semua request ditolak tanpa pesan,
            refresh adalah cara pertama untuk mencoba lagi sekarang.
            Karena itu panel ini tetap menyediakan "Segarkan" sendiri di
            kakinya, dan tombol itu tetap berguna untuk halaman yang belum
            punya polling -- di situ tombol reload lama masih satu-satunya
            cara.
          -->
          <div class="central-topnav__notif">
            <button
              type="button"
              class="central-topnav__btn central-topnav__btn--notif"
              title="Notifikasi"
              :aria-label="
                notifBadge ? `Notifikasi, ${notifBadge} hal perlu ditindaklanjuti` : 'Notifikasi'
              "
              :aria-expanded="notifOpen"
              aria-haspopup="dialog"
              @click="notifOpen = !notifOpen"
            >
              <font-awesome-icon icon="bell" />
              <span v-if="notifBadge" class="central-topnav__badge">{{ notifBadge }}</span>
            </button>

            <CentralNotificationPanel v-if="notifOpen" @close="notifOpen = false" />
          </div>

          <button type="button" class="central-topnav__logout" @click="handleLogout">
            <font-awesome-icon icon="right-from-bracket" />
            <span v-if="sidebarOpen">Keluar</span>
          </button>
        </div>
      </header>

      <main class="central-body">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { MySwal } from '@/utils/swal'
import { centralAuthService } from '@/services/central.service'
import { getCentralUser } from '@/utils/centralAxios'
import { useCentralNotifications } from '@/composables/useCentralNotifications'
import CentralNotificationPanel from '@/presentations/components/central/CentralNotificationPanel.vue'

const route = useRoute()
const router = useRouter()

const sidebarOpen = ref(true)
const mobileSidebarOpen = ref(false)
const notifOpen = ref(false)

const user = computed(() => getCentralUser())

// Satu instance untuk navbar dan panel: state modul, bukan state
// komponen. Kalau panel memakai composable sendiri, keduanya akan punya
// dua salinan angka yang bisa berbeda.
const notif = useCentralNotifications()

/*
 * Badge lonceng TIDAK dihitung di layout, dan tidak juga di dalam panel.
 *
 * Keduanya punya kebutuhan yang berbeda dan berlawanan waktu: badge harus
 * terlihat sebelum panel dibuka, panel hanya ada setelah lonceng diklik.
 * Kalau dipisah, aturan "apa yang_counts sebagai notifikasi" jadi tertulis
 * dua kali, dan dua salinan itu pasti akan berbeda setelah salah satunya
 * diubah.
 *
 * Jadi aturannya diletakkan di satu tempat -- `useCentralNotifications` --
 * yang dipakai navbar untuk badge dan panel untuk isi daftar. Keduanya
 * membaca state yang sama, jadi tidak mungkin beda.
 *
 * Seluruhnya dihitung dari `/central/dashboard`, bukan endpoint notifikasi
 * tersendiri, karena angka yang dibutuhkan sudah ada di sana. Endpoint
 * terpisah akan jadi permintaan kedua ke data yang sama dengan peluang
 * besar memberi angka yang berbeda kalau keduanya dipanggil pada saat
 * yang tidak sama persis -- dan di panel pusat, dua angka yang beda
 * adalah kebohongan, bukan pembulatan.
 *
 * Konsekuensi yang perlu diketahui: panel ini menampilkan HAL YANG BUKAAN
 * PERUBAH. Tagihan yang terlambat lalu dilunasi tidak muncul di sini. Jangan
 * pakai daftar ini sebagai sumber kebenaran tagihan -- untuk itu ada
 * `/central/tagihan`.
 */
const notifBadge = computed(() => notif.count.value)

/*
 * Menu panel pusat.
 *
 * Urutannya mengikuti urutan kerja admin, bukan urutan alfabetis: dashboard,
 * lalu hal yang paling sering diubah (tenant), lalu urusan yang dibaca sesekali
 * (tagihan, superadmin, struktur akun).
 *
 * TIDAK ada menu Audit. Halaman jejak audit dihapus 2026-10-15: log tetap
 * ditulis ke tabel `audit_logs` dan masih bisa dibaca langsung dari
 * database, tapi tidak ada antarmuka untuk memfilter dan membacanya.
 * Endpoint GET-nya ikut dihapus, jadi tidak ada jalan lain ke isi tabel
 * itu dari panel -- kalau jejaknya ternyata dibutuhkan, yang kembali harus
 * dibangun ulang, bukan hanya dipasang ulang.
 *
 * TIDAK ada menu Domain. Halaman yang biasanya menampilkan seluruh domain
 * lintas lokasi sudah dihapus, karena isinya sudah bisa dijawab dari satu
 * tempat yang lebih dekat dengan objeknya: domain milik sebuah tenant, jadi
 * dikelola di halaman tenant itu, di form editnya.
 *
 * Yang tetap ada dan tetap bekerja: tabel `tenant_domains`, wildcard, domain
 * utama, dan resolver yang memetakan host ke database yang benar. Yang hilang
 * hanya halaman daftarnya -- bukan kemampuannya.
 */
const menuItems = [
  { label: 'Dashboard', to: '/central', icon: 'gauge-high' },
  { label: 'Tenant', to: '/central/lokasi', icon: 'location-dot' },
  /*
   * Hak akses TIDAK lagi diatur dari aplikasi lokasi.
   *
   * `jabatan_menu_access` milik database tiap lokasi, jadi menyetel hak akses
   * dari panel pusat berarti menyeberang ke tiap database satu per satu.
   * Dulu itu berarti masuk ke tiap domain secara bergantian. Sekarang cukup
   * satu kali login di sini.
   *
   * Letaknya DI BAWAH "Tenant", bukan di bawah "Superadmin": yang diatur bukan
   * akun pusat, tapi menu yang boleh dibuka jabatan di tiap lokasi -- jadi ia
   * ikut kerabat dengan daftar lokasinya, bukan dengan daftar orangnya.
   */
  { label: 'Hak Akses', to: '/central/hak-akses', icon: 'user-shield' },
  { label: 'Tagihan', to: '/central/tagihan', icon: 'file-invoice-dollar' },
  /*
   * Rekening pusat TIDAK punya halaman di sini.
   *
   * Tabelnya ada dan dipakai setiap kali pembayaran dicatat, tapi
   * daftarnya dikelola langsung di database -- jadi tidak ada menu
   * yang menuju ke halaman yang isinya cuma form ubah satu baris.
   * Yang dipakai setiap hari (memilih kas atau rekening tujuan) tetap
   * tersedia di halaman Tagihan, tempat pembayarannya dicatat.
   */
  { label: 'Superadmin', to: '/central/superadmin', icon: 'user-shield' },
  { label: 'Struktur Akun', to: '/central/coa', icon: 'sitemap' },
  /*
   * Migrasi data, DI BAWAH "Struktur Akun" dan bukan di bawah "Tenant".
   *
   * Alurnya memang perlu memilih lokasi dulu, tapi yang ditulis ke
   * database tujuan adalah isi file -- bukan lokasi itu sendiri. Menu
   * "Tenant" mengelola tenant-nya; yang di sini menulis ke dalam
   * database tenant itu. Menaruhnya di bawah "Tenant" akan menyesatkan
   * orang yang mencarinya dari daftar lokasinya.
   *
   * Ikon `file-export` dipakai, bukan `database`: yang dipindahkan keluar
   * dari panel pusat dan masuk ke lokasi, dan `database` sudah dipakai
   * menu lain di panel ini.
   */
  { label: 'Migrasi Data', to: '/central/migrasi-data', icon: 'file-export' },
]

const pageMeta = {
  '/central': {
    title: 'Dashboard Pusat',
    subtitle: 'Ringkasan seluruh lokasi ASTALOKA.',
  },
  '/central/lokasi': {
    title: 'Manajemen Tenant',
    subtitle: 'Daftar, tambah, dan kelola seluruh tenant terdaftar beserta domainnya.',
  },
  '/central/hak-akses': {
    title: 'Hak Akses Lokasi',
    subtitle: 'Atur menu yang boleh dibuka setiap jabatan, untuk semua lokasi dari satu halaman.',
  },
  '/central/tagihan': {
    title: 'Tagihan Langganan',
    subtitle: 'Terbitkan dan pantau tagihan langganan tiap lokasi.',
  },
  '/central/superadmin': {
    title: 'User Pusat',
    // Menyebut batas halaman ini: yang dikelola di sini hanya akun pusat.
    // User lokasi diatur di aplikasi lokasi masing-masing -- satu tempat per
    // lokasi, bukan satu tempat untuk semuanya.
    subtitle:
      'Akun yang bisa masuk ke panel pusat. User lokasi diatur di aplikasinya masing-masing.',
  },
  '/central/coa': {
    title: 'Struktur Akun',
    subtitle: 'Kerangka akun level 1-3 yang berlaku untuk semua lokasi.',
  },
  '/central/migrasi-data': {
    title: 'Migrasi Data ke Lokasi',
    // Menyebut urutan langkahnya di sini, bukan cuma sekali di dalam
    // halaman: subtitle ikut terbaca di tab peramban dan di riwayat,
    // di tempat orang tidak sedang membaca paragraf penjelasan.
    subtitle:
      'Unduh template, isi, lalu unggah kembali. Data masuk ke database lokasi yang dipilih.',
  },
}

const pageTitle = computed(() => pageMeta[route.path]?.title || 'Panel Pusat')
const pageSubtitle = computed(() => pageMeta[route.path]?.subtitle || '')

const initials = computed(() => {
  const name = user.value?.name || 'SA'
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w.charAt(0).toUpperCase())
    .join('')
})

const isActive = (to) => (to === '/central' ? route.path === '/central' : route.path.startsWith(to))

const toggleSidebar = () => {
  sidebarOpen.value = !sidebarOpen.value
}

const handleLogout = async () => {
  const result = await MySwal.fire({
    title: 'Konfirmasi Logout',
    text: 'Keluar dari panel pusat?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#ef4444',
    cancelButtonColor: '#6b7280',
    confirmButtonText: 'Ya, keluar',
    cancelButtonText: 'Batal',
  })

  if (!result.isConfirmed) return

  /*
   * Nama diambil SEBELUM logout, bukan sesudahnya.
   *
   * `centralAuthService.logout()` menghapus seluruh jejak sesi dari
   * localStorage di blok `finally`-nya -- termasuk `central_user`. Kalau
   * nama diambil setelah pemanggilan itu, isinya sudah `null`, dan toast
   * perpisahan di halaman login hanya bisa berkata "Terima kasih!" tanpa
   * menyebut siapa yang sebenarnya baru saja keluar.
   *
   * `getCentralUser()` dipakai langsung, bukan `user.value`, supaya yang
   * terbaca jelas-jelas keadaan localStorage pada saat ini.
   */
  const nama = getCentralUser()?.name || ''

  // Sengaja ditelan: user harus tetap keluar dari panel meski server tidak
  // bisa dihubungi. Token server akan ditolak sendiri setelah kedaluwarsa.
  await centralAuthService.logout().catch(() => {})

  /*
   * `name` ikut dibawa di URL supaya halaman login bisa menyapa orangnya --
   * persis seperti yang dilakukan aplikasi lokasi di `MainView.vue`.
   *
   * Parameter itu dihapus lagi di sisi halaman login (`replaceState`), jadi
   * tidak menggantung di riwayat peramban atau di alamat yang disalin.
   */
  const namaDiUrl = nama ? `&name=${encodeURIComponent(nama)}` : ''
  router.replace(`/central/login?logout=1${namaDiUrl}`)
}
</script>

<style scoped>
/*
| Dimensi & warna diambil dari `main.css`, bukan angka sendiri.
|
| Panel pusat dan aplikasi lokasi dipakai bergantian oleh orang yang sama
| -- superadmin membuka panel pusat untuk mengecek tagihan, lalu masuk ke
| aplikasi lokasi untuk mengerjakan sesuatu. Kalau panelnya terlihat beda
| jauh, mereka merasa harus turun ke yang kedua, dan setelah beberapa kali
| berhenti memakai yang pertama.
|
| Sidebar sengaja mengikuti pola yang sudah ada di aplikasi lokasi:
| putih translucent + blur di atas konten, bukan panel gelap. Warna
| aksen diambil dari `--color-primary` (sky), sama seperti tombol dan
| link di seluruh aplikasi.
*/
.central-layout {
  --central-sidebar-width: 250px;
  --central-sidebar-collapsed: 68px;
  --central-topnav-height: 56px;

  display: flex;
  /*
  | `100dvh`, bukan `100vh`.
  |
  | Di HP, `100vh` memakai tinggi viewport TANPA address bar dan keyboard,
  | sehingga layout jadi lebih tinggi dari layar yang benar-benar terlihat.
  | Efeknya: bagian bawah halaman -- tempat daftar dan tombol aksi berada
  | -- terus berada di bawah garis lipatan, dan tidak akan pernah terlihat
  | sampai user melakukan scroll yang tidak disuruh aplikasi apa pun.
  |
  | `dvh` mengikuti tinggi viewport yang sedang terlihat, jadi saat address
  | bar muncul atau keyboard terbuka, layout ikut menyusut dan tombol di
  | bawah tetap terjangkau.
  */
  min-height: 100vh;
  min-height: 100dvh;
  background: var(--color-body-bg, #f1f5f9);
}

/* ===== SIDEBAR ===== */
.central-sidebar {
  width: var(--central-sidebar-width);
  flex-shrink: 0;
  /* Sama seperti `.sidebar-panel` di main.css: putih semi-transparan dengan
     blur, supaya konten di belakang tetap terbaca samar. */
  background: rgb(255 255 255 / 0.85);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border-right: 1px solid var(--color-border, #e2e8f0);
  color: var(--color-text-sub, #64748b);
  display: flex;
  flex-direction: column;
  position: sticky;
  top: 0;
  height: 100vh;
  z-index: 40;
  transition:
    width 0.22s ease,
    transform 0.22s ease;
}

.central-layout--collapsed .central-sidebar {
  width: var(--central-sidebar-collapsed);
}

.central-sidebar__brand {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 1rem;
  border-bottom: 1px solid var(--color-gray-200, #e5e7eb);
}

.central-sidebar__mark {
  width: 2.25rem;
  height: 2.25rem;
  flex-shrink: 0;
  border-radius: var(--border-radius-sm);
  background: var(--color-primary, #0ea5e9);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
}

.central-sidebar__identity {
  min-width: 0;
}

/* Gradien teks yang sama dengan `.sidebar-header-title` di main.css. */
.central-sidebar__name {
  font-size: 0.88rem;
  font-weight: 800;
  letter-spacing: -0.03em;
  background: linear-gradient(to right, #1e293b, #0ea5e9);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  margin: 0;
}

.central-sidebar__role {
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--color-primary, #0ea5e9);
  margin: 0.1rem 0 0;
}

/* Tombol buka/tutup sidebar, sekarang di navbar. Bentuk dan warna diambil
   dari `.central-topnav__btn` supaya sekelas dengan tombol lain di baris
   yang sama -- bukan tombol kecil yang dulu menempel di sidebar. */
.central-topnav__collapse {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.4rem 0.65rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-sm);
  background: var(--color-card-bg, #fff);
  color: var(--color-text-sub, #64748b);
  font-size: 0.76rem;
  flex-shrink: 0;
  box-shadow: var(--central-shadow-btn);
  transition: all 0.18s ease;
}

.central-topnav__collapse:hover {
  background: var(--color-primary-light, #e0f2fe);
  border-color: var(--color-primary, #0ea5e9);
  color: var(--color-primary-dark, #0284c7);
  box-shadow: var(--central-shadow-btn-hover);
}

.central-sidebar__nav {
  flex: 1;
  padding: 1rem 0.65rem;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.central-sidebar__nav::-webkit-scrollbar {
  width: 6px;
}

.central-sidebar__nav::-webkit-scrollbar-thumb {
  background: rgb(14 165 233 / 0.3);
  border-radius: 3px;
}

.central-sidebar__link {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.6rem 0.8rem;
  border-radius: var(--border-radius-sm);
  color: var(--color-text-sub, #64748b);
  text-decoration: none;
  font-size: 0.82rem;
  font-weight: 600;
  transition: all 0.18s ease;
  white-space: nowrap;
}

.central-sidebar__link:hover {
  background: var(--color-primary-light, #e0f2fe);
  color: var(--color-primary-dark, #0284c7);
}

/* Aktif memakai warna solid, bukan gradien. Pola solid di sidebar aplikasi
   lokasi sudah jadi tanda yang dikenal, jadi ini bukan sekadar soal
   estetika -- ini supaya keduanya langsung terbaca sebagai "halaman yang
   sedang dibuka" saat keduanya terlihat bersamaan. */
.central-sidebar__link.is-active {
  background: var(--color-primary, #0ea5e9);
  color: #fff;
  box-shadow: 0 6px 14px -6px rgb(14 165 233 / 0.7);
}

.central-sidebar__icon {
  width: 1.15rem;
  flex-shrink: 0;
  text-align: center;
}

.central-sidebar__user {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.9rem 1rem;
  border-top: 1px solid var(--color-gray-200, #e5e7eb);
}

.central-sidebar__avatar {
  width: 2.1rem;
  height: 2.1rem;
  flex-shrink: 0;
  border-radius: 50%;
  background: var(--color-primary, #0ea5e9);
  color: #fff;
  font-size: 0.78rem;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
}

.central-sidebar__user-info {
  min-width: 0;
}

.central-sidebar__user-name {
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--color-text-main, #1e293b);
  margin: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.central-sidebar__user-mail {
  font-size: 0.66rem;
  color: var(--color-text-sub, #64748b);
  margin: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ===== MAIN ===== */
.central-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

/* Topnav memakai tinggi yang sama dengan aplikasi lokasi (56px) supaya
   perpindahan panel <-> aplikasi tidak terasa seperti pindah aplikasi. */
.central-topnav {
  height: var(--central-topnav-height);
  background: var(--color-card-bg, #fff);
  border-bottom: 1px solid var(--color-border, #e2e8f0);
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0 1.5rem;
  position: sticky;
  top: 0;
  z-index: 30;
}

/*
 | Hamburger: satu-satunya cara membuka menu di layar sempit.
 |
 | Di bawah 1024px sidebar berubah jadi laci yang menutupi layar, dan
 | `.central-topnav__collapse` disembunyikan -- jadi tombol ini adalah
 | SATU-SATUNYA jalan keluar dari halaman mana pun yang punya sidebar.
 |
 | Target sentuh 27px adalah tempat salah klik paling sering terjadi, dan
 |panel pusat dipakai sambil berdiri, sering dengan satu tangan sambil
 | memegang meteran. `min-height`/`min-width` 2.75rem (44px) mengikuti
 | ukuran target sentuh yang dipakai iOS dan Android.
 |
 | Kelalaian ini tidak terlihat di desktop: topnav 56px masih muat tombol
 | 27px dengan rapi, jadi layout terlihat benar. Masalahnya baru muncul
 | saat dipakai di HP, yaitu tempat tombol ini paling dibutuhkan.
 */
.central-topnav__menu-btn {
  display: none;
  align-items: center;
  justify-content: center;
  background: none;
  border: none;
  color: var(--color-text-sub, #64748b);
  font-size: 1.1rem;
  /* `min-*` yang menentukan ukuran, bukan `padding`, supaya perubahan
     spacing atau ketebalan border tidak ikut memperkecil target. */
  padding: 0.4rem;
  min-height: 2.75rem;
  min-width: 2.75rem;
  /* Ikon Font Awesome adalah `inline`; tanpa ini baseline-nya membuat
     `align-items: center` menggeser ikon naik beberapa piksel. */
  line-height: 1;
  cursor: pointer;
  border-radius: 0.375rem;
}

.central-topnav__menu-btn:hover {
  background: var(--color-hover, rgb(15 23 42 / 0.05));
}

.central-topnav__menu-btn:focus-visible {
  outline: 2px solid var(--color-primary, #0ea5e9);
  outline-offset: 2px;
}

.central-topnav__title {
  flex: 1;
  min-width: 0;
}

.central-topnav__title h1 {
  font-size: 0.95rem;
  font-weight: 800;
  color: var(--color-text-main, #1e293b);
  margin: 0;
}

.central-topnav__title p {
  font-size: 0.7rem;
  color: var(--color-text-sub, #64748b);
  margin: 0;
}

.central-topnav__actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

/*
| Jangkar panel notifikasi.
|
| WAJIB, bukan opsional: tanpa `position: relative` di sini, panel
| `position: absolute; right: 0` mencari leluhur teratas yang terposisi --
| yaitu `.central-topnav` (yang `position: sticky`). `right: 0` lalu berarti
| "tepi kanan topnav", bukan "tepi kanan tombol lonceng", dan panel 22rem
| itu terhampar di layar tengah.
|
| `top: calc(100% + 0.5rem)` di panel mengukur dari bawah WRAPPER ini. Tanpa
| jangkar, `top` ikut terukur dari bawah topnav, jadi panel naik-turun
| mengikuti posisi tombol -- dan setiap badge yang muncul/lewat cukup untuk
| membuat panel melompat.
*/
.central-topnav__notif {
  position: relative;
  flex-shrink: 0;
}

/*
| Badge jumlah notifikasi.
|
| Sengaja sama persis dengan `.topnav-icon-badge` di `topnav-notifications.css`:
| nilai, warna, dan bentuknya sama persis. Dua angka yang sama harus tampil
| sama -- kalau badge panel pusat jadi Abu dan badge aplikasi jadi merah,
| orang mengira itu dua tingkat prioritas yang berbeda, padahal itu dua
| tempat yang menghitung hal yang sama.
|
| Diposisikan `absolute` di pojok tombol, bukan di dalam flow: kalau ikut
| flow, tombol lonceng ikut melebar saat badge muncul, dan posisi tombol
| pun bergeser -- jadi panel yang tepat di bawahnya ikut bergeser juga.
*/
.central-topnav__badge {
  position: absolute;
  top: -0.3rem;
  right: -0.3rem;
  min-width: 1rem;
  height: 1rem;
  padding: 0 0.25rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 0.5625rem;
  font-weight: 700;
  line-height: 1;
  color: #fff;
  background: var(--color-danger, #ef4444);
  /* Garis putih 2px, bukan bayangan: badge ini menempel di pojok tombol
     yang warnanya bisa berubah saat hover. Garis putih memotong bagian badge
     yang menimpa tombol, jadi badge selalu terbaca sebagai bentuk sendiri --
     kalau tidak, badge merah menempel di tombol merah dan lalu hilang. */
  border: 2px solid var(--color-card-bg, #fff);
  border-radius: 9999px;
  pointer-events: none;
}

/* Tombol lonceng jadi jangkar badge-nya, sekaligus sumber `position` panel
   di bawahnya. `position: relative` di sini TIDAK menggantikan jangkar di
   `.central-topnav__notif` -- keduanya dibutuhkan: satu untuk badge, satu
   untuk panel. */
.central-topnav__btn--notif {
  position: relative;
}

/* Tombol yang sedang membuka panelnya diberi warna yang sama seperti
   hover-nya, supaya ada penanda bahwa panelnya terbuka, selain isi panel
   itu sendiri. Tanpa ini, satu-satunya petunjuk panel terbuka adalah panel
   itu sendiri -- dan kalau panelnya tertutup atau terpotong layar, tidak
   ada yang tersisa untuk menutupnya. */
.central-topnav__btn[aria-expanded='true'] {
  background: var(--color-primary-light, #e0f2fe);
  border-color: var(--color-primary, #0ea5e9);
  color: var(--color-primary-dark, #0284c7);
}

.central-topnav__btn,
.central-topnav__logout {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.4rem 0.65rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-sm);
  background: var(--color-card-bg, #fff);
  color: var(--color-text-sub, #64748b);
  font-size: 0.76rem;
  font-weight: 600;
  /* Bayangan tombol, bukan elevasi kartu. Tombol setinggi 32px tidak
     membutuhkan kedalaman sebesar kartu; yang dibutuhkan hanya tepi yang
     tidak menyatu dengan navbar di belakanginya. */
  box-shadow: var(--central-shadow-btn);
  transition: all 0.18s ease;
}

.central-topnav__btn:hover {
  background: var(--color-primary-light, #e0f2fe);
  border-color: var(--color-primary, #0ea5e9);
  color: var(--color-primary-dark, #0284c7);
  box-shadow: var(--central-shadow-btn-hover);
}

.central-topnav__logout {
  background: var(--color-red-50, #fee2e2);
  border-color: var(--color-red-50, #fee2e2);
  color: var(--color-red-800, #991b1b);
  box-shadow: var(--central-shadow-btn-danger);
}

.central-topnav__logout:hover {
  background: #fee2e2;
  box-shadow: var(--central-shadow-btn-danger-hover);
}

/*
 | Token jarak halaman: jarak tepi dan lebar maksimum isi.
 |
 | Hanya dua token yang tersisa di sini. Token BAYANGAN sudah pindah ke
 | `:root` di `central-page.css`, karena ada empat modal yang dirender lewat
 | `<Teleport to="body">` -- elemen teleport keluar dari subtree
 | `.central-layout`, jadi tidak mewarisi apa pun yang didefinisikan di sini.
 | Akibatnya tombol di dalam modal kehilangan bayangan tanpa error apa pun,
 | sementara tombol yang identik di halaman biasa tetap punya bayangan.
 |
 | `--central-gutter` dan `--central-content-max` TETAP di sini, bukan di
 | `:root`, karena keduanya hanya dipakai oleh isi halaman panel pusat.
 | Memindahkannya ke `:root` hanya memperluas namespace global tanpa ada
 | yang perlu, dan --central-topnav-height tetap harus hidup di sini
 | karena `.bd` menghitung tingginya darinya.
 */
.central-layout {
  --central-gutter: 1.25rem;
  --central-content-max: 1200px;

  /*
  | Breakpoint panel pusat dalam SATU variabel.
  |
  | Nilai ini dipakai untuk keputusan sidebar jadi laci. Sebelumnya angka
  | yang sama ditulis langsung di `@media (max-width: 1024px)`, jadi menyetel
  | ulang angka breakpoint berarti harus dicari di beberapa tempat.
  |
  | Angka 1024px sendiri belum salah, tapi 1024px adalah lebar TABLET. Di
  | iPad dan HP besar, panel pusat masih tampil sebagai halaman penuh dengan
  | sidebar laci yang menutupi layar -- padahal layar selebar itu lega untuk
  | dua kolom tabel dan sidebar yang tetap terlihat. Yang hilang di sini
  | justru informasi: meja data harus digulir mendatar untuk membaca kolom
  | yang sebenarnya sudah muat.
  |
  | Sidebar jadi laci di bawah 64rem (1024px) tetap, tapi topnav dan isi
  | halaman di bawah 48rem (768px) memakai padding yang lebih rapat -- di
  | HP, 1.25rem per sisi memakan 40px dari lebar layar yang biasanya
  | 360-390px, dan tabel yang sudah harus di-scroll horizontal kehilangan
  | 10% lebar yang tidak terpakai.
  */
  --central-breakpoint-laci: 64rem;
  --central-breakpoint-hp: 48rem;
}

.central-body {
  flex: 1;
  min-width: 0;
  padding: var(--central-gutter);
  /*
  | Latar Abu muda + aksen tipis milik seluruh panel pusat, bukan khusus
  | dashboard.
  |
  | Dulu gradient ini menempel pada `.bd` di CentralDashboardView.vue. Begitulah
  | cara satu halaman membuat latar terlihat tanpa memotong tepi: di halaman
  | lain yang latarnya cuma abu muda polos, area di bawah topnav ke kanan-kiri
  | kartu dashboard terlihat seperti belang yang tidak sengaja ada.
  |
  | Dipindah ke sini karena ini memang latar halaman: apakah yang sedang dibuka
  | dashboard atau daftar tenant, warna di belakang isinya harus sama.
  */
  background:
    radial-gradient(1100px 560px at 10% -12%, rgb(14 165 233 / 0.07), transparent 62%),
    linear-gradient(180deg, #fbfdff 0%, var(--color-body-bg, #f1f5f9) 42%);
}

.central-layout__overlay {
  display: none;
}

@media (max-width: 64rem) {
  .central-sidebar {
    position: fixed;
    transform: translateX(-100%);
  }

  .central-sidebar.is-open {
    transform: translateX(0);
  }

  .central-layout__overlay {
    display: block;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.5);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.22s ease;
    z-index: 35;
  }

  .central-layout__overlay.is-open {
    opacity: 1;
    pointer-events: auto;
  }

  .central-topnav__menu-btn {
    display: flex;
  }

  /* Di layar sempit sidebar jadi laci yang menutupi layar, jadi tidak ada
     lagi "lebar" yang bisa diatur. Yang ada hanya buka/tutup lewat
     hamburger di sebelah kirinya, sehingga chevron disembunyikan di sini --
     kalau tidak, dua tombol itu saling bertentangan: yang satu mengatur
     lebar sidebar, padahal di layar ini sidebar tidak punya lebar. */
  .central-topnav__collapse {
    display: none;
  }
}

/*
 | HP: rapatkan sisipan supaya tabel dapat lebar yang lebih banyak.
 |
 | 1.25rem per sisi di layar 360px berarti 40px hilang sebelum konten apa
 | pun tampil. Untuk tabel yang memang harus di-scroll horizontal, itu
 | 10% layar yang terbuang -- dan tabel daftar lokasi punya tujuh kolom,
 | jadi sudah harus di-scroll sejak awal.
 */
@media (max-width: 48rem) {
  .central-layout {
    --central-gutter: 0.875rem;
  }

  .central-topnav {
    gap: 0.625rem;
    padding: 0 0.75rem;
  }

  /*
   | Ikon di topnav dirapatkan, tapi target sentuhnya TIDAK.
   |
   | Yang dikecilkan hanya jarak antar-ikon, bukan luas ketukannya:
   | `min-height`/`min-width` di `.central-topnav__menu-btn` tetap 2.75rem.
   | Kedua hal itu WAJIB dipisah -- mengecilkan keduanya sekaligus "agar
   | lebih rapi" membuat menu mustahil diklik di layar yang justru paling
   | butuh bisa diklik.
   */
  .central-topnav__actions {
    gap: 0.25rem;
  }
}

/*
 | Layar sangat pendek (HP dengan keyboard terbuka, atau jendela kecil di
 | laptop): topnav menyusut supaya isi halaman masih punya tempat.
 |
 | Tanpa ini, keyboard virtual memakan 40-50% tinggi layar dan karena
 | topnav `position: sticky`, ia tetap memakan ruang yang tidak berubah
 | padahal isinya sudah tidak relevan.
 */
@media (max-height: 32rem) {
  .central-layout {
    --central-topnav-height: 48px;
  }
}
</style>
