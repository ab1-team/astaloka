/**
 * Pemetaan baris tiket instalasi -> baris tabel "Status Instalasi".
 *
 * KENAPA FILE INI ADA
 * ==================
 *
 * Data yang sama dipakai di tiga tempat:
 *
 *   1. Halaman `/app/instalasi/status` (tabel utama).
 *   2. Halaman cetaknya (`/app/instalasi/status/cetak`).
 *   3. Ekspor Excel (backend, `StatusInstalasiExcelExport`).
 *
 * Side (1) dan (2) dulu menduplikasi pemetaannya: kalau satu berubah dan
 * yang lain tidak, hasil cetak berbeda dari hasil layar -- user mengira
 * cetakannya salah padahal datanya memang beda.
 *
 * Sekarang keduanya memakai fungsi yang sama dari file ini, jadi tidak
 * mungkin berbeda.
 *
 * CATATAN PENTING SOAL BACKEND
 * =============================
 *
 * Backend punya pemetaannya sendiri (`App\Services\StatusInstalasiExcelExport`).
 * Keduanya WAJIBsinkron: kategori -> status mentah harus sama. Kalau
 * backend menambah kategori baru dan frontend tidak, hasil ekspor tidak
 * akan sama dengan yang tampil di layar.
 *
 * Yang BELUM ikut: `draft`. Tiket berstatus draf adalah pekerjaan awal
 * admin yang belum selesai, dan tidak pernah muncul di tab mana pun --
 * jadi tidak ada di ekspor maupun di halaman.
 */

import { INSTALASI_RAW_STATUS_LABELS } from '@/types/instalasiStatus'

/**
 * Status mentah (database) -> kategori tab.
 *
 * `draft` sengaja tidak ada: draf tidak pernah ditampilkan.
 */
export const INSTALASI_CATEGORY_BY_STATUS = {
  pending: 'permohonan',
  surveyed: 'pasang_baru',
  unpaid: 'pasang_baru',
  processing: 'pasang_baru',
  completed: 'aktif',
  suspended: 'blokir',
  terminated: 'cabut',
}

/**
 * Kategori tab -> daftar status mentah di dalamnya.
 *
 * Kebalikannya dari `INSTALASI_CATEGORY_BY_STATUS`, tapi TIDAK selalu
 * bisa diturunkan (satu kategori bisa punya banyak status). Dipakai
 * backend sebagai daftar acuan; di frontend dipakai untuk memvalidasi
 * kategori yang dikirim ke server.
 */
export const INSTALASI_STATUSES_BY_CATEGORY = {
  permohonan: ['pending'],
  pasang_baru: ['surveyed', 'unpaid', 'processing'],
  aktif: ['completed'],
  blokir: ['suspended'],
  cabut: ['terminated'],
}

/** Warna avatar untuk kolom nama pelanggan. */
const WARNA_AVATAR = ['#3B82F6', '#8B5CF6', '#10B981', '#F59E0B', '#EF4444']

/**
 * Kategori tab untuk satu status mentah.
 *
 * Mengembalikan `null` kalau statusnya tidak termasuk kategori mana pun
 * (mis. `draft`).
 */
export const getCategoryByStatus = (rawStatus) => {
  return INSTALASI_CATEGORY_BY_STATUS[rawStatus] || null
}

/**
 * Ubah satu tiket jadi satu baris tabel.
 *
 * Field `id` adalah kode instalasi. Kalau tiket belum punya record
 * customer (biasanya masih di tahap permohonan), kode turunannya dipakai
 * sebagai pengganti: `#INS-0001`. Kodenya searching-friendly supaya
 * admin tetap bisa menemukan baris yang belum punya kode pelanggan.
 */
export const mapTicketToRow = (ticket) => {
  const status = ticket.status
  const category = getCategoryByStatus(status)

  // Customer adalah hasMany. Yang dipakai adalah customer TERAKHIR
  // (id terbesar) -- sama dengan yang dipakai backend saat ekspor.
  const customers = Array.isArray(ticket.customer)
    ? ticket.customer
    : ticket.customer
      ? [ticket.customer]
      : []

  const latestCustomer = [...customers].sort((a, b) => (b.id || 0) - (a.id || 0))[0]

  return {
    id: latestCustomer?.customer_code || `#INS-${String(ticket.id).padStart(4, '0')}`,
    ticketId: ticket.id,
    name: ticket.applicant_name || '-',
    nik: ticket.nik || '-',
    phone: ticket.phone || '-',
    initials: ticket.applicant_name
      ? ticket.applicant_name
          .split(' ')
          .map((n) => n[0])
          .join('')
          .toUpperCase()
          .substring(0, 2)
      : '?',
    color: WARNA_AVATAR[ticket.id % 5],
    type: ticket.package?.name || '-',
    packageId: ticket.package_id || null,
    address: ticket.address || '-',
    village: ticket.village?.name || ticket.village?.village_name || '-',
    villageId: ticket.village_id || null,
    lat: ticket.lat || null,
    lng: ticket.lng || null,
    orderDate: ticket.order_date || '-',
    category,
    status: INSTALASI_RAW_STATUS_LABELS[status] || status,
    rawStatus: status,
    createdAt: ticket.created_at || '-',
    updatedAt: ticket.updated_at || '-',
    rawData: ticket,
    surveyInfo: ticket.survey?.[0]
      ? {
          id: ticket.survey[0].id,
          distance_to_pipe_m: ticket.survey[0].distance_to_pipe_m,
          material_notes: ticket.survey[0].material_notes,
          photo_url: ticket.survey[0].photo_url,
          surveyed_at: ticket.survey[0].surveyed_at,
          surveyor_name: ticket.survey[0].surveyor?.name || '-',
          surveyor_id: ticket.survey[0].surveyor_id,
          ticket: ticket,
        }
      : null,
  }
}

/**
 * Kelompokkan tiket ke dalam map kategori -> baris tabel.
 *
 * Tiket dengan status yang tidak punya kategori (mis. `draft`) DIBUANG,
 * sama seperti sebelumnya di store.
 */
export const groupTicketsByCategory = (tickets) => {
  const map = {
    permohonan: [],
    pasang_baru: [],
    aktif: [],
    blokir: [],
    cabut: [],
  }

  if (!Array.isArray(tickets)) return map

  tickets.forEach((ticket) => {
    const category = getCategoryByStatus(ticket.status)

    if (!category) return

    map[category].push(mapTicketToRow(ticket))
  })

  return map
}

/**
 * Saring baris dengan kata kunci pencarian.
 *
 * Field yang dicari SAMA dengan yang dicari backend saat ekspor:
 * nama, kode instalasi, dan alamat. Kalau backend mencari lebih banyak
 * field, hasil ekspor akan berisi baris yang tidak terlihat di layar.
 */
export const filterRowsBySearch = (rows, query) => {
  const q = String(query || '')
    .trim()
    .toLowerCase()

  if (!q) return rows || []

  return (rows || []).filter(
    (item) =>
      String(item.name || '')
        .toLowerCase()
        .includes(q) ||
      String(item.id || '')
        .toLowerCase()
        .includes(q) ||
      String(item.address || '')
        .toLowerCase()
        .includes(q),
  )
}
