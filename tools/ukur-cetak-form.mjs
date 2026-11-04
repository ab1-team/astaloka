/**
 * Ukur kapasitas baris per halaman untuk halaman cetakan.
 *
 * Dipakai untuk memastikan kop + tabel muat dalam satu halaman A4.
 * Area cetak = 297mm - padding atas/bawah 12mm = 273mm.
 *
 * Halaman yang diukur:
 *   - /usages/cetak_form          -> Cetak Form Input Pemakaian Air
 *   - /usages/cetak_daftar_tagihan -> Cetak Daftar Tagihan
 *
 * Jalankan: node tools/ukur-cetak-form.mjs
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { mkdtempSync, rmSync } from 'node:fs'
import { createServer } from 'node:net'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const APP = 'http://demo.astaloka.test:5173'
const API = 'http://demo.astaloka.test:5173/api'

/**
 * Port DevTools bebas.
 *
 * Port harus unik per proses: kalau port-nya sudah dipakai Chrome lain
 * (mis. tool lain yang sedang jalan), Chrome yang kita spawn gagal bind
 * dan skrip malah konek ke Chrome orang lain -- gejalanya login menggantung
 * tanpa error yang jelas.
 */
const portBebas = () =>
  new Promise((resolve, reject) => {
    const srv = createServer()
    srv.on('error', reject)
    srv.listen(0, '127.0.0.1', () => {
      const { port } = srv.address()
      srv.close(() => resolve(port))
    })
  })

const PORT = await portBebas()

/** Halaman yang diukur, beserta labelnya. */
const HALAMAN = [
  { label: 'Cetak Form Input', url: `${APP}/usages/cetak_form` },
  { label: 'Cetak Daftar Tagihan', url: `${APP}/usages/cetak_daftar_tagihan` },
]

const profil = mkdtempSync(join(tmpdir(), 'ukur-cetak-'))

const chrome = spawn(CHROME, [
  '--headless=new',
  '--disable-gpu',
  '--no-first-run',
  '--no-default-browser-check',
  `--remote-debugging-port=${PORT}`,
  `--user-data-dir=${profil}`,
  'about:blank',
])

process.on('exit', () => {
  try { chrome.kill() } catch {}
  try { rmSync(profil, { recursive: true, force: true }) } catch {}
})

// Tunggu endpoint devtools siap.
let wsUrl = null
for (let i = 0; i < 60; i++) {
  try {
    const res = await fetch(`http://127.0.0.1:${PORT}/json/version`)
    const json = await res.json()
    wsUrl = json.webSocketDebuggerUrl
    if (wsUrl) break
  } catch {}
  await sleep(250)
}

if (!wsUrl) {
  console.error('Chrome DevTools tidak siap')
  process.exit(1)
}

const ws = new WebSocket(wsUrl)
await new Promise((resolve, reject) => {
  ws.onopen = resolve
  ws.onerror = reject
})

let id = 0
const pending = new Map()

/*
 * Hitung request per URL, supaya request yang sama dua kali kelihatan.
 *
 * Disimpan di sini (bukan di listener terpisah) karena WebSocket hanya
 * punya SATU handler `onmessage`. Menambah listener kedua akan
 * menggantikannya, dan semua `await send(...)` menggantung selamanya --
 * gejalanya "Warning: Detected unsettled top-level await", bukan error
 * yang menyebut penyebabnya.
 */
const hitungRequest = new Map()

ws.onmessage = (ev) => {
  const msg = JSON.parse(ev.data)

  if (msg.method === 'Network.requestWillBeSent') {
    const url = msg.params?.request?.url || ''
    if (url.includes('/api/')) {
      const kunci = url.replace(/\?.*$/, '')
      hitungRequest.set(kunci, (hitungRequest.get(kunci) || 0) + 1)
    }
  }

  if (msg.id && pending.has(msg.id)) {
    const { resolve, reject } = pending.get(msg.id)
    pending.delete(msg.id)
    msg.error ? reject(new Error(JSON.stringify(msg.error))) : resolve(msg.result)
  }
}

const send = (method, params = {}, sessionId) =>
  new Promise((resolve, reject) => {
    const msgId = ++id
    pending.set(msgId, { resolve, reject })
    ws.send(JSON.stringify({ id: msgId, method, params, sessionId }))
  })

const { targetId } = await send('Target.createTarget', { url: 'about:blank' })
const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true })

await send('Page.enable', {}, sessionId)
await send('Runtime.enable', {}, sessionId)
await send('Network.enable', {}, sessionId)

const evaluate = async (expression) => {
  const res = await send(
    'Runtime.evaluate',
    { expression, awaitPromise: true, returnByValue: true },
    sessionId,
  )
  if (res.exceptionDetails) {
    throw new Error(res.exceptionDetails.exception?.description || 'eval gagal')
  }
  return res.result.value
}

// Login.
await send('Page.navigate', { url: `${APP}/login` }, sessionId)
await sleep(4000)

const loginResult = await evaluate(`(async () => {
  const res = await fetch('${API}/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email: 'admin@gmail.com', password: 'password' }),
  })
  const json = await res.json()
  if (!json?.success) return { ok: false, status: res.status, body: json }
  localStorage.setItem('auth_token', json.data.token)
  localStorage.setItem('auth_expires_at', String(Date.now() + 8 * 60 * 60 * 1000))
  localStorage.setItem('user_role', json.data.user.role)
  localStorage.setItem('user_data', JSON.stringify(json.data.user))
  localStorage.setItem('tenant_slug', json.data.tenant_slug || 'demo')
  return { ok: true, role: json.data.user.role }
})()`)

console.log('LOGIN:', JSON.stringify(loginResult))

/**
 * Script pengukuran, dijalankan di dalam halaman.
 *
 * Mengembalikan kapasitas baris untuk dua bentuk halaman:
 *   - lanjutan: kop saja
 *   - pertama  : kop + judul + blok meta
 */
const SKRIP_UKUR = `(() => {
  const MM = 96 / 25.4
  const wrap = document.querySelector('.report-page-wrap')
  const page = wrap?.querySelector('.report-page')
  const tabel = page?.querySelector('.data-table')
  if (!page || !tabel) return { ok: false, alasan: 'halaman laporan tidak ditemukan' }

  const metaAsli = page.querySelector('.meta-grid')
  const headerAsli = page.querySelector('.page-header')
  const metaHtml = metaAsli?.outerHTML || ''
  const headerHtml = headerAsli?.outerHTML || ''

  const tbody = tabel.querySelector('tbody')
  const template = tbody.querySelector('tr')
  if (!template) return { ok: false, alasan: 'tabel tanpa baris' }

  /*
   * Baris harus berisi konten SATU BARIS.
   *
   * Baris aslinya bisa berisi nama/alamat panjang yang membungkus dua
   * baris -- dan kalau baris itu dipakai sebagai template, tinggi satu
   * baris jadi terukur terlalu besar, sehingga kesimpulan "baris terakhir
   * muat" jadi terlalu optimistis. Isi ulang setiap sel dengan teks
   * pendek supaya yang diukur adalah tinggi baris NORMAL.
   */
  const contoh = template.cloneNode(true)
  contoh.querySelectorAll('td').forEach((td, i) => {
    td.textContent = i === 1 ? 'SAMPLE' : String(i + 1)
  })
  contoh.style.height = ''

  /*
   * min-height halaman DINONAKTIFKAN sementara selama pengukuran.
   * Halaman laporan punya min-height 297mm supaya di layar selalu
   * sebesar kertas -- tanpa itu, scrollHeight selalu minimal 273mm dan
   * pengukuran ini tidak pernah bisa membedakan "muat" dari "meluber".
   */
  const minHeightAsli = page.style.minHeight
  page.style.minHeight = '0px'

  const cs = getComputedStyle(page)
  const padTop = parseFloat(cs.paddingTop) || 0
  const padBottom = parseFloat(cs.paddingBottom) || 0
  const areaCetakPx = (297 - 24) * MM

  const tinggiIsi = () => page.scrollHeight - padTop - padBottom

  const pindai = () => {
    tbody.innerHTML = ''
    tbody.appendChild(contoh.cloneNode(true))

    let kapasitas = 1
    for (let n = 2; n <= 120; n++) {
      tbody.appendChild(contoh.cloneNode(true))
      if (tinggiIsi() > areaCetakPx) break
      kapasitas = n
    }

    tbody.innerHTML = ''
    for (let n = 0; n < kapasitas; n++) tbody.appendChild(contoh.cloneNode(true))

    const isi = tinggiIsi()
    const thead = tabel.querySelector('thead')

    return {
      kapasitasMaks: kapasitas,
      tinggiBarisPx:
        Math.round(
          ((tbody.getBoundingClientRect().height -
            (thead?.getBoundingClientRect().height || 0)) /
            kapasitas) *
            100,
        ) / 100,
      isiPx: Math.round(isi),
      isiMm: Math.round((isi / MM) * 10) / 10,
      muat: isi <= areaCetakPx,
    }
  }

  const pasang = (html, posisi) => {
    page.querySelectorAll('.meta-grid').forEach((el) => el.remove())
    page.querySelectorAll('.page-header').forEach((el) => el.remove())
    if (!html) return

    const holder = document.createElement('div')
    holder.innerHTML = html
    const node = holder.firstElementChild
    if (!node) return

    if (posisi === 'tabel') {
      // Meta harus duduk DI DALAM pembungkus tabel yang sama, karena di
      // template aslinya meta dan tabel berada di satu blok konten.
      tabel.parentElement.insertBefore(node, tabel)
      return
    }

    // Judul duduk tepat setelah kop.
    const kop = page.querySelector('.surat-kop')
    if (kop) kop.parentElement.insertBefore(node, kop.nextSibling)
  }

  // Halaman LANJUTAN: kop saja, tanpa judul dan tanpa meta.
  pasang('', null)
  const lanjutan = pindai()

  // Halaman PERTAMA: kop + judul + blok meta.
  pasang(headerHtml, 'kop')
  pasang(metaHtml, 'tabel')
  const pertama = pindai()

  page.style.minHeight = minHeightAsli

  return {
    ok: true,
    areaCetakMm: 273,
    kopPx: Math.round(page.querySelector('.surat-kop')?.getBoundingClientRect().height || 0),
    adaJudul: !!headerAsli,
    lanjutan,
    pertama,
  }
})()`

const ringkasan = []

for (const halaman of HALAMAN) {
  await send('Page.navigate', { url: halaman.url }, sessionId)
  await sleep(9000)

  const hasil = await evaluate(SKRIP_UKUR)

  console.log(`\n=== ${halaman.label} (${halaman.url}) ===`)
  console.log(JSON.stringify(hasil, null, 2))

  if (hasil.ok) {
    ringkasan.push({ label: halaman.label, ...hasil })
  }
}

console.log('\n=== RINGKASAN ===')
for (const r of ringkasan) {
  console.log(
    `${r.label}: lanjutan ${r.lanjutan.kapasitasMaks} baris (${r.lanjutan.isiMm}mm), ` +
      `pertama ${r.pertama.kapasitasMaks} baris (${r.pertama.isiMm}mm), area ${r.areaCetakMm}mm`,
  )
}

console.log('\n=== REQUEST API (per URL) ===')
for (const [url, n] of [...hitungRequest.entries()].sort((a, b) => b[1] - a[1])) {
  console.log(`${n}x ${url}`)
}

// Screenshot halaman pertama, supaya kop bisa diperiksa secara visual.
await send('Emulation.setDeviceMetricsOverride', {
  width: 1400,
  height: 1200,
  deviceScaleFactor: 1,
  mobile: false,
}, sessionId)
await send('Page.navigate', { url: HALAMAN[0].url }, sessionId)
await sleep(9000)

const { writeFileSync } = await import('node:fs')

const shot = await send(
  'Page.captureScreenshot',
  { format: 'png', captureBeyondViewport: false },
  sessionId,
)
const pathShot = 'tools/kop-cetak-form.png'
writeFileSync(pathShot, Buffer.from(shot.data, 'base64'))
console.log(`\nScreenshot: ${pathShot}`)

/*
 * Screenshot HALAMAN LANJUTAN.
 *
 * Di halaman lanjutan judul dan blok meta tidak ikut (cuma kop + tabel),
 * jadi kopnya harus dicek terpisah -- kalau markup-nya ternyata ikut
 * tersembunyi, ini yang akan menangkapnya.
 */
const jumlahHalaman = await evaluate(
  `document.querySelectorAll('.report-page-wrap').length`,
)
console.log(`Jumlah halaman: ${jumlahHalaman}`)

if (jumlahHalaman > 1) {
  await evaluate(
    `document.getElementById('report-page-1')?.scrollIntoView({ block: 'start' })`,
  )
  await sleep(1200)
  const shot2 = await send(
    'Page.captureScreenshot',
    { format: 'png', captureBeyondViewport: false },
    sessionId,
  )
  const pathShot2 = 'tools/kop-cetak-form-lanjutan.png'
  writeFileSync(pathShot2, Buffer.from(shot2.data, 'base64'))
  console.log(`Screenshot lanjutan: ${pathShot2}`)
}

ws.close()
chrome.kill()
process.exit(0)
