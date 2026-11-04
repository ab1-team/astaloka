<template>
  <div
    class="sidebar-panel"
    :class="{
      collapsed: !sidebarOpen,
      'mobile-open': mobileSidebarOpen,
      blurred: uiStore.activeModalCount > 0,
    }"
  >
    <div class="sidebar-header">
      <div class="sidebar-header-content">
        <div class="sidebar-header-title" v-show="sidebarOpen || mobileSidebarOpen">
          {{ roleTitle }}
        </div>
        <div class="sidebar-header-sub" v-show="sidebarOpen || mobileSidebarOpen">
          {{ roleSubtitle }}
        </div>
      </div>
      <BaseButton
        variant="ghost"
        class="sidebar-toggle-btn-sidebar"
        @click="mobileSidebarOpen ? $emit('close-mobile-sidebar') : $emit('toggle-sidebar')"
        v-show="sidebarOpen || mobileSidebarOpen"
        icon="bars"
      />
    </div>

    <div class="sidebar-toggle-collapsed" v-show="!sidebarOpen">
      <BaseButton
        variant="ghost"
        class="sidebar-toggle-btn-collapsed"
        @click="$emit('toggle-sidebar')"
        icon="bars"
      />
    </div>

    <div class="sidebar-divider" v-show="sidebarOpen"></div>

    <nav class="sidebar-nav">
      <template v-for="(item, index) in filteredMenuItems" :key="index">
        <router-link
          v-if="!item.children"
          :to="item.to"
          class="sidebar-nav-item"
          :class="{ active: $route.path === item.to }"
          :title="!sidebarOpen ? item.label : ''"
          @click="handleMenuClick"
        >
          <font-awesome-icon :icon="item.icon" class="sidebar-nav-icon" />
          <span class="sidebar-nav-label">{{ item.label }}</span>
        </router-link>

        <div v-else class="sidebar-nav-group">
          <router-link
            to="#"
            class="sidebar-nav-item"
            :class="{ 'submenu-open': openSubmenus[item.label] }"
            :title="!sidebarOpen ? item.label : ''"
            @click.prevent="toggleSubmenu(item.label)"
          >
            <font-awesome-icon :icon="item.icon" class="sidebar-nav-icon" />
            <span class="sidebar-nav-label">{{ item.label }}</span>
            <font-awesome-icon
              v-if="sidebarOpen"
              icon="chevron-down"
              class="sidebar-nav-chevron"
              :class="{ rotated: openSubmenus[item.label] }"
            />
          </router-link>

          <div class="sidebar-submenu" :class="{ open: openSubmenus[item.label] && sidebarOpen }">
            <template v-for="(sub, subIdx) in item.children" :key="subIdx">
              <div v-if="sub.children" class="sidebar-nav-group">
                <router-link
                  to="#"
                  class="sidebar-submenu-item is-parent"
                  :class="{ 'submenu-open': openSubmenus[sub.label] }"
                  @click.prevent="toggleSubmenu(sub.label)"
                >
                  <span class="sidebar-submenu-marker"></span>
                  <span>{{ sub.label }}</span>
                  <font-awesome-icon
                    v-if="sidebarOpen"
                    icon="chevron-down"
                    class="sidebar-nav-chevron"
                    :class="{ rotated: openSubmenus[sub.label] }"
                    width="12"
                  />
                </router-link>
                <div
                  class="sidebar-submenu nested"
                  :class="{ open: openSubmenus[sub.label] && sidebarOpen }"
                >
                  <router-link
                    v-for="(nested, nestedIdx) in sub.children"
                    :key="nestedIdx"
                    :to="nested.to"
                    class="sidebar-submenu-item nested-item"
                    :class="{ active: isActive(nested.to) }"
                    @click="handleMenuClick"
                  >
                    <span class="sidebar-submenu-marker"></span>
                    <span>{{ nested.label }}</span>
                  </router-link>
                </div>
              </div>

              <router-link
                v-else
                :to="sub.to"
                class="sidebar-submenu-item"
                :class="{ active: isActive(sub.to) }"
                @click="handleMenuClick"
              >
                <span class="sidebar-submenu-marker"></span>
                <span>{{ sub.label }}</span>
              </router-link>
            </template>
          </div>
        </div>
      </template>
    </nav>

    <div class="sidebar-new-entry-area">
      <BaseButton
        variant="info"
        class="new-entry-btn"
        :class="{ 'icon-only': !sidebarOpen }"
        size="md"
        :title="!sidebarOpen ? 'Tentang Asta Brata Teknologi' : ''"
        :aria-label="!sidebarOpen ? 'Tentang Asta Brata Teknologi' : undefined"
        @click="showAbtModal = true"
      >
        <span v-if="sidebarOpen" class="new-entry-btn__label">
          <span class="new-entry-btn__brand">Asta Brata</span>
          <span class="new-entry-btn__sub">Teknologi</span>
        </span>
        <font-awesome-icon v-else icon="gem" class="new-entry-btn__collapsed-icon" />
      </BaseButton>
    </div>

    <AbtAstaBrataModal :show="showAbtModal" @close="showAbtModal = false" />
  </div>
</template>

<script setup>
import { onMounted, watch, computed, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useUiStore } from '@/stores/uiStore'
import { useMenuAccessStore } from '@/stores/menuAccessStore'
import BaseButton from '@/presentations/components/ui/BaseButton.vue'
import AbtAstaBrataModal from '@/presentations/components/ui/AbtAstaBrataModal.vue'
import { useIdentitasLembaga, ensureIdentitas } from '@/composables/useIdentitasLembaga'

/**
 * Group yang punya ikon sendiri di sidebar.
 *
 * Katalog backend hanya menyertakan group sebagai NAMA ("Basis Data"),
 * bukan ikon. Ikon sidebar adalah keputusan tampilan, jadi di frontend --
 * yang tetap satu-satunya tempat concerns tampilan itu hidup. Group yang
 * tidak ada di sini memakai ikon cadangan, supaya menambah group baru di
 * katalog tidak membuat sidebar gagal diam-diam.
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
 * Ikon item tetap "Beranda".
 *
 * Sengaja ditulis terpisah dari `GROUP_ICONS`, bukan diambil dari
 * `GROUP_ICONS.Utana`: "Utama" itu NAMA GROUP di katalog, sedangkan
 * Beranda di sini bukan group -- ia item tunggal yang berdiri sendiri.
 * Kalau nanti group "Utama" diisi menu lain, keduanya jadi dua hal
 * berbeda dan hanya satu yang boleh berubah.
 */
const ICON_BERANDA = 'home'

/**
 * Submenu yang punya halaman turunannya sendiri.
 *
 * "Pelanggan" dan "Desa" punya Tambah/Edit yang merupakan halaman
 * tersendiri, sehingga jadi group bersarang. Menu lainnya tidak
 * punya, jadi jadi anak biasa.
 *
 * Kuncinya path menu induk, bukan label, supaya perubahan label di katalog
 * tidak ikut memutus hierarki ini.
 */
const NESTED_PARENTS = ['/app/data-pelanggan', '/app/data-desa']

/** Halaman anak dari group bersarang tersebut. */
const NESTED_CHILDREN = [
  { parent: '/app/data-pelanggan', label: 'Tambah Pelanggan', to: '/app/data-pelanggan/tambah' },
  { parent: '/app/data-pelanggan', label: 'Data Pelanggan', to: '/app/data-pelanggan' },
  { parent: '/app/data-desa', label: 'Tambah Desa', to: '/app/data-desa/tambah' },
  { parent: '/app/data-desa', label: 'Data Desa', to: '/app/data-desa' },
]

const props = defineProps({
  sidebarOpen: {
    type: Boolean,
    default: true,
  },
  mobileSidebarOpen: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['toggle-sidebar', 'close-mobile-sidebar'])
const uiStore = useUiStore()
const route = useRoute()
const menuAccessStore = useMenuAccessStore()

// Popup "Tentang Aplikasi Ini" — kredit untuk Asta Brata Teknologi.
const showAbtModal = ref(false)

const roleTitle = computed(() => {
  if (uiStore.lembagaName) {
    return uiStore.lembagaName
  }
  switch (uiStore.userRole) {
    case 'surveyor':
      return 'Portal Surveyor'
    case 'teknisi':
      return 'Pusat Teknisi'
    case 'pelanggan':
      return 'Aplikasi Pelanggan'
    default:
      return 'Portal Admin'
  }
})

const roleSubtitle = computed(() => {
  if (uiStore.lembagaName) {
    return uiStore.userRole?.toUpperCase() || 'PORTAL'
  }
  switch (uiStore.userRole) {
    case 'surveyor':
      return 'OPERASI LAPANGAN'
    case 'teknisi':
      return 'PUSAT TEKNIS'
    case 'pelanggan':
      return 'LAYANAN PELANGGAN'
    default:
      return 'PUSAT MANAJEMEN'
  }
})

const { namaUsaha } = useIdentitasLembaga()

const loadLembagaName = async () => {
  try {
    // PENTING: pakai endpoint identitas publik, bukan getAll().
    // getAll() (= GET /settings/sop) hanya untuk admin, sedangkan
    // SidebarView ini dirender untuk semua role — memakai getAll() membuat
    // teknisi/surveyor/pelanggan kena 403 di setiap halaman, dan karena
    // error-nya ditelan `catch`, judulnya diam-diam jatuh ke teks hardcoded.
    //
    // Data diambil dari `useIdentitasLembaga`, bukan request sendiri:
    // endpoint yang dipakai sama persis dengan yang dipakai kop surat, dan
    // `useIdentitasLembaga` menyimpan satu cache modul. Kalau sidebar
    // request sendiri, satu sesi yang membuka halaman ber-sidebar dan
    // halaman cetak akan menembak endpoint yang sama dua kali.
    //
    // `ensureIdentitas()` (bukan membaca `namaUsaha` langsung) karena
    // nilainya baru ada setelah request selesai — kalau langsung dibaca,
    // judulnya selalu kosong saat komponen baru mount.
    await ensureIdentitas()
    uiStore.setLembagaName(namaUsaha.value.trim())
  } catch {
    // silent: fallback ke judul role
  }
}

onMounted(() => {
  loadLembagaName()
  menuAccessStore.loadCatalog()
})

const openSubmenus = reactive({})

function toggleSubmenu(label) {
  if (props.sidebarOpen) {
    openSubmenus[label] = !openSubmenus[label]
  }
}

function isActive(to) {
  if (!to) return false
  return route.path === to
}

function isActiveOrChild(to) {
  if (!to) return false
  return route.path === to || route.path.startsWith(to + '/')
}

/**
 * Menu yang benar-benar dirender sidebar, sudah dirapikan jadi pohon
 * group > anak > (cucun).
 *
 * Disusun dari katalog backend, bukan dari daftar yang ditulis di sini.
 * Dua filter yang sebelumnya ada di `filteredMenuItems` -- role dan
 * jabatan -- sekarang sudah digabung oleh backend jadi satu nilai
 * `allowed`, jadi frontend tidak perlu menilai hak akses sendiri.
 *
 * Pengecualiannya satu: "Beranda" tidak dari katalog, tapi ditambahkan
 * sebagai item tetap di akhir fungsi ini. Alasannya ada di blok `beranda`.
 *
 * Sisa satu hal yang MASIH dinilai di sini: `hidden`. Item ber-hidden ada
 * di router dan bisa dibuka dari dashboard (Arsip), tapi tidak pernah jadi
 * entri sidebar. Yang disembunyikan adalah menunya, bukan halamannya.
 *
 * Group tanpa anak yang boleh TIDAK dirender. Kalau tetap dirender, user
 * akan melihat group yang bisa dibuka tapi isinya kosong -- dan mengira
 * menunya hilang karena bug, bukan karena haknya memang dicabut.
 */
const filteredMenuItems = computed(() => {
  const role = uiStore.userRole

  const bolehTampil = menuAccessStore.items.filter((item) => {
    if (!item.allowed) return false
    if (item.hidden) return false
    // `tanpa_sidebar`: halaman tetap boleh dibuka (dan tetap bisa dicabut
    // di panel pusat Hak Akses), tapi tidak jadi entri menu. Dipakai untuk
    // halaman yang desain per-role-nya dibuka dari dashboard.
    if (item.tanpa_sidebar) return false
    // `roles` kosong berarti tidak ada batasan role -- jangan diperlakukan
    // sebagai "tidak ada role yang boleh", itu akan menyembunyikan menu
    // yang justru terbuka untuk semua orang.
    if (Array.isArray(item.roles) && item.roles.length > 0 && !item.roles.includes(role)) {
      return false
    }
    return true
  })

  /*
   * Beranda: item tetap, bukan dari katalog.
   *
   * Katalog `config/menu_access.php` sengaja tidak memuat "/app" -- bukan
   * kelalaian, tapi keputusan: `/app` adalah halaman tujuan SETELAH login,
   * bukan pekerjaan yang perlu dikerjakan, jadi tidak ada gunanya
   * menawarkannya centang untuk mengunci.
   *
   * Konsekuensinya harus ditangani di sini: sidebar dibangun dari katalog,
   * jadi entri yang tidak ada di katalog tidak akan pernah muncul -- dan
   * "Beranda" hilang dari sidebar hanya karena tidak bisa dikunci.
   *
   * Ditambahkan sebagai item tetap, di luar daftar group, supaya:
   *   - tidak pernah hilang, apa pun konfigurasi jabatan
   *   - tidak muncul sebagai group "Utama" yang isinya cuma satu
   *   - selalu bisa dibuka, apa pun jobatannya
   *
   * Sengaja TIDAK ikut disaring oleh `allowed`: kalau nanti katalognya ikut
   * memuat "Beranda" dan suatu jabatan menicentangnya mati, user akan
   * mendarat di halaman yang tidak ada isinya. Beranda harus selalu bisa
   * dibuka -- itulah bedanya dengan menu yang benar-benar bisa dicabut.
   */
  const beranda = {
    key: 'beranda',
    label: 'Beranda',
    icon: ICON_BERANDA,
    /*
     * Targetnya `/app`, bukan `getDashboardRoute(role)`.
     *
     * Di pamsides-v2 "Beranda" selalu menunjuk `/app`, untuk semua role.
     * Dan `/app` merender dashboard per-role lewat `DashboardHome.vue` --
     * jadi teknisi yang mengklik "Beranda" tetap mendarat di dashboard
     * teknisi, tidak perlu URL khusus.
     *
     * Bedanya dengan versi lama (`getDashboardRoute(role)`) terlihat di
     * penanda aktif: dengan `/app`, "Beranda" TIDAK pernah menyala di
     * `/app/teknisi` -- persis seperti di pamsides-v2, yang memang tidak
     * menyorot "Beranda" saat user sudah berada di dashboard teknisi.
     *
     * Halaman tujuan setelah login tetap memakai `getDashboardRoute(role)`
     * (lihat `router/dashboardRoutes.js`) -- itu tidak berubah.
     */
    to: '/app',
  }

  // Katalog mengirim group dalam urutan kemunculannya. Preserve urutan itu:
  // mengurutkan ulang berdasarkan nama akan memindahkan "Pencatatan Tagihan"
  // ke belakang "Master Instalasi", dan urutan itu memang disengaja.
  const groupsBaru = []
  const byGroup = new Map()
  const tingkatAtas = []

  for (const item of bolehTampil) {
    /*
     * Menu `top_level` berdiri sendiri, di luar group apa pun.
     *
     * Dipakai untuk menu yang desain per-role-nya memang berdiri sendiri:
     * "Buat Survey Baru" milik surveyor adalah link tingkat atas di
     * pamsides-v2, bukan anak group "Survey".
     *
     * Menu seperti ini tetap PUNYA `group` di katalog -- supaya panel pusat
     * Hak Akses masih mengelompokkannya bersama menu sekelompoknya. Yang
     * berbeda hanya cara sidebar merendernya.
     */
    if (item.top_level === true) {
      tingkatAtas.push({
        key: item.key,
        label: item.label,
        icon: item.icon,
        to: String(item.path || '').includes(':') ? null : item.path,
        top_level: true,
      })

      continue
    }

    const group = item.group || ''

    if (!byGroup.has(group)) {
      byGroup.set(group, [])
      groupsBaru.push(group)
    }

    byGroup.get(group).push({
      key: item.key,
      label: item.label,
      icon: item.icon,
      /*
       * Path ber-parameter (`.../hasil-instalasi/:id`) TIDAK boleh jadi
       * tautan sidebar.
       *
       * Dipakai apa adanya, tautannya akan mengarah ke
       * `/app/teknisi/hasil-instalasi/:id` -- dengan `:id` LITERAL, bukan
       * id tiket sebenarnya. Route `teknisi/hasil-instalasi/:id` tidak
       * akan cocok, dan user mendarat di halaman kosong.
       *
       * Halaman ber-parameter begitu memang tidak bisa jadi tautan statis:
       * butuh id yang belum diketahui. Cara yang benar adalah membukanya
       * dari tempat yang tahu id-nya -- dashboard teknisi, yang menautkan
       * `/app/teknisi/hasil-instalasi/${task.ticketId}`. Karena itu menu
       * seperti itu di katalog diberi `hidden`, dan di sini ia cukup
       * dilewati.
       */
      to: String(item.path || '').includes(':') ? null : item.path,
      // Menu yang(group)-nya wajib tetap tampil sebagai group, walaupun
      // isinya cuma satu anak. Lihat `group_tetap` di `config/menu_access.php`.
      groupTetap: item.group_tetap === true,
    })
  }

  const hasil = groupsBaru
    .map((group) => {
      /*
       * Anak tanpa `to` (path ber-parameter) dibuang di sini, bukan
       * direnders sebagai tautan rusak.
       *
       * Group yang seluruh anaknya dibuang menjadi group kosong -- dan
       * group kosong TIDAK dirender (lihat filter di bawah), supaya
       * user tidak melihat judul group yang tidak bisa dibuka.
       */
      const children = byGroup.get(group).filter((child) => child.to)

      if (children.length === 0) return null

      const denganAnak = children.map((child) => {
        // Menu yang punya halaman turunan sendiri jadi group bersarang.
        // Anaknya diambil dari NESTED_CHILDREN, bukan dari katalog, karena
        // halaman turunan itu tidak punya entri menu sendiri -- kalau
        // ditambah ke katalog, sidebar akan menampilkan "Data Pelanggan"
        // dua kali: sebagai group dan sebagai link biasa.
        if (!NESTED_PARENTS.includes(child.to)) return child

        return {
          ...child,
          children: NESTED_CHILDREN.filter((n) => n.parent === child.to),
        }
      })

      /*
       * Group dengan satu anak saja ditampilkan sebagai link langsung,
       * bukan sebagai submenu yang harus diklik dulu. Lebih cepat, dan
       * supaya tidak ada menu yang cuma punya satu isi tapi butuh dua klik.
       *
       * Pengecualian: anak yang `groupTetap` -- group-nya wajib tetap
       * terlihat sebagai group, karena susunan menu itu bagian dari desain
       * per-role (teknisi membuka "Master Instalasi", bukan langsung
       * "Input Pemakaian Air").
       */
      if (denganAnak.length === 1 && !denganAnak[0].children && !denganAnak[0].groupTetap) {
        return denganAnak[0]
      }

      return {
        label: group,
        icon: GROUP_ICONS[group] || 'folder',
        children: denganAnak,
      }
    })
    .filter(Boolean)

  /*
   * Susunan akhir sidebar.
   *
   * Urutannya: "Beranda", lalu menu tingkat atas, lalu group.
   *
   * Menu tingkat alas diletakkan DI SESUDAH "Beranda" karena di pamsides-v2
   * "Buat Survey Baru" juga begitu: sesudah "Beranda", sebelum group
   * "Pengaturan" milik admin. Menu tingkat atas tidak boleh mendahului
   * "Beranda" -- "Beranda" selalu yang pertama.
   */
  const tingkatAtasValid = tingkatAtas.filter((item) => item.to)

  // Beranda mendahului semua group, jadi posisinya tetap pertama walaupun
  // daftar group berubah karena jabatan lain yang hak aksesnya baru disimpan.
  return [beranda, ...tingkatAtasValid, ...hasil]
})

/**
 * Buka submenu yang memuat halaman yang sedang dibuka.
 *
 * Menjalankan ini lagi saat daftar menu berubah penting: setelah admin
 * menyimpan hak akses, group yang tadi tersembunyi bisa muncul --
 * dan kalau submenu yang menjadi tujuannya tidak ikut terbuka, user
 * mendarat di sidebar yang terlihat benar tapi submenunya tertutup.
 */
function syncOpenSubmenus() {
  filteredMenuItems.value.forEach((item) => {
    if (!item.children) return
    const childActive = item.children.some((sub) => {
      if (sub.children) {
        return sub.children.some((n) => isActiveOrChild(n.to))
      }
      return isActiveOrChild(sub.to)
    })
    if (childActive) {
      openSubmenus[item.label] = true
    }
    item.children.forEach((sub) => {
      if (sub.children) {
        const nestedActive = sub.children.some((n) => isActiveOrChild(n.to))
        if (nestedActive) openSubmenus[sub.label] = true
      }
    })
  })
}

watch(
  () => route.path,
  () => {
    syncOpenSubmenus()
  },
)

watch(
  () => filteredMenuItems.value,
  () => {
    syncOpenSubmenus()
  },
)

syncOpenSubmenus()

syncOpenSubmenus()

function handleMenuClick() {
  if (window.innerWidth < 1024) {
    emit('close-mobile-sidebar')
  }
}

watch(
  () => props.sidebarOpen,
  (newVal) => {
    if (!newVal) {
      Object.keys(openSubmenus).forEach((key) => {
        openSubmenus[key] = false
      })
    }
  },
)

watch(
  () => route.path,
  () => {
    syncOpenSubmenus()
  },
)

watch(
  () => uiStore.settingsVersion,
  () => {
    loadLembagaName()
  },
)
</script>

<style scoped>
.sidebar-panel.blurred {
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  pointer-events: none;
  user-select: none;
}
</style>
