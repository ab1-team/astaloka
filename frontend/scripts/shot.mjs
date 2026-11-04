/*
 * Ambil tangkapan layar dengan lebar viewport yang BENAR.
 *
 * `chrome --headless --screenshot --window-size=360,780` tidak bisa
 * dipakai untuk menguji tampilan HP: Chrome headless mengabaikan
 * `--window-size` untuk viewport halaman (hanya mengatur ukuran jendela
 * OS), jadi halaman tetap dirender pada lebar minimum 512px. Hasilnya
 * breakpoint `sm`/`md` yang seharusnya aktif di HP justru tidak aktif.
 *
 * Script ini memakai DevTools Protocol lewat WebSocket: halaman
 * dimuat dengan viewport persis seperti yang diminta, lalu halaman
 * penuh diambil sebagai gambar.
 *
 * Jalankan: `node scripts/shot.mjs <url> <keluaran.png> [lebar] [tinggi]`
 *
 * Untuk halaman yang butuh login, tambahkan kredensial lewat
 * environment variable:
 *
 *   $env:SHOT_EMAIL="teknisi@gmail.com"
 *   $env:SHOT_PASSWORD="password"
 *
 * Script lalu login lebih dulu, lalu membuka halaman yang diminta.
 *
 * Environment variable lain:
 *
 *   SHOT_WAIT_MS  - jeda sebelum foto diambil, dalam milidetik (default 4000)
 *   SHOT_CLICK    - teks tombol yang diklik sebelum foto diambil
 *   SHOT_EVAL     - JavaScript yang dijalankan sebelum foto diambil
 */

import { spawn } from 'node:child_process'
import { writeFileSync, mkdtempSync, rmSync, existsSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

/**
 * Lokasi Chrome.
 *
 * Urutannya dari yang paling umum ke yang paling jarang, supaya script
 * ini jalan di mesin siapa pun tanpa harus menyunting path-nya:
 *   1. `SHOT_CHROME` (kalau diisi)
 *   2. lokasi standar di Windows
 *   3. lokasi standar di macOS
 *   4. `chrome`/`chromium` di PATH (Linux, WSL, container)
 */
function cariChrome() {
  const kandidat = [
    process.env.SHOT_CHROME,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
  ].filter(Boolean)

  for (const path of kandidat) {
    if (existsSync(path)) return path
  }

  // Terakhir: andalkan PATH.
  return process.platform === 'win32' ? 'chrome.exe' : 'chromium'
}

const CHROME = cariChrome()

const [url, keluar, lebarArg = '360', tinggiArg = '780'] = process.argv.slice(2)

if (!url || !keluar) {
  console.error('Pakai: node scripts/shot.mjs <url> <keluaran.png> [lebar] [tinggi]')
  process.exit(1)
}

const lebar = Number(lebarArg)
const tinggi = Number(tinggiArg)
const WAIT_MS = Number(process.env.SHOT_WAIT_MS || 4000)
const profil = mkdtempSync(join(tmpdir(), 'shot-'))
const port = 9222 + Math.floor(Math.random() * 500)

const chrome = spawn(CHROME, [
  '--headless=new',
  '--disable-gpu',
  '--no-sandbox',
  '--hide-scrollbars',
  '--remote-debugging-port=' + port,
  '--user-data-dir=' + profil,
  'about:blank',
])

const tidur = (ms) => new Promise((r) => setTimeout(r, ms))

/** Tunggu endpoint DevTools siap. */
async function tungguDevTools() {
  for (let i = 0; i < 100; i++) {
    try {
      const res = await fetch(`http://127.0.0.1:${port}/json/version`)
      if (res.ok) return (await res.json()).webSocketDebuggerUrl
    } catch {
      /* belum siap */
    }
    await tidur(100)
  }
  throw new Error('DevTools tidak siap')
}

const wsUrl = await tungguDevTools()

// Node 22 sudah punya WebSocket bawaan, jadi paket `ws` tidak perlu
// ditambahkan ke dependensi proyek hanya untuk keperluan alat ini.
const { WebSocket } = globalThis

const ws = new WebSocket(wsUrl)
let idCounter = 0
const pending = new Map()

ws.addEventListener('message', (ev) => {
  const msg = JSON.parse(ev.data)
  if (msg.id && pending.has(msg.id)) {
    const { resolve, reject } = pending.get(msg.id)
    pending.delete(msg.id)
    if (msg.error) reject(new Error(JSON.stringify(msg.error)))
    else resolve(msg.result)
  }
})

await new Promise((resolve, reject) => {
  ws.addEventListener('open', resolve, { once: true })
  ws.addEventListener('error', reject, { once: true })
})

const kirim = (method, params = {}, sessionId) =>
  new Promise((resolve, reject) => {
    const id = ++idCounter
    pending.set(id, { resolve, reject })
    ws.send(JSON.stringify({ id, method, params, sessionId }))
  })

//_TARGET__
const { targetId } = await kirim('Target.createTarget', { url: 'about:blank' })
const { sessionId } = await kirim('Target.attachToTarget', { targetId, flatten: true })

await kirim('Emulation.setDeviceMetricsOverride', {
  width: lebar,
  height: tinggi,
  deviceScaleFactor: 2,
  mobile: true,
  screenWidth: lebar,
  screenHeight: tinggi,
}, sessionId)

await kirim('Page.enable', {}, sessionId)
await kirim('Runtime.enable', {}, sessionId)

/**
 * Kumpulkan error console dan exception halaman.
 *
 * Halaman yang gagal render sering terlihat "kosong saja" tanpa
 * penjelasan -- error-nya ada di console, bukan di layar.
 */
const pesanError = []
ws.addEventListener('message', (ev) => {
  const msg = JSON.parse(ev.data)
  if (msg.method === 'Runtime.exceptionThrown') {
    const d = msg.params?.exceptionDetails
    pesanError.push(
      'EXCEPTION: ' + (d?.exception?.description ?? d?.text ?? JSON.stringify(d)).slice(0, 500),
    )
  }
  if (msg.method === 'Runtime.consoleAPICalled' && msg.params?.type === 'error') {
    pesanError.push(
      'CONSOLE: ' +
        (msg.params.args ?? [])
          .map((a) => a.value ?? a.description ?? '')
          .join(' ')
          .slice(0, 500),
    )
  }
})

/**
 * Login lebih dulu kalau kredensial diberikan.
 *
 * Token disimpan di `localStorage` oleh frontend, jadi cara paling
 * andal untuk sampai ke halaman yang butuh login: minta token dari
 * API, lalu simpan sendiri ke `localStorage` sebelum membuka halaman.
 *
 * Mengisi form login lewat DOM tidak dipakai: halaman login dirender
 * setelah router SPA selesai boot, jadi sometimes script-nya berjalan
 * sebelum field-nya ada -- dan hasilnya login yang diam-diam gagal.
 */
async function login() {
  const email = process.env.SHOT_EMAIL
  const password = process.env.SHOT_PASSWORD
  if (!email || !password) return

  const asal = new URL(url).origin

  const res = await fetch(asal + '/api/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email, password }),
  })

  const data = await res.json()
  if (!data?.success || !data?.data?.token) {
    console.log('LOGIN GAGAL: ' + JSON.stringify(data).slice(0, 300))
    return
  }

  const token = data.data.token
  const user = data.data.user

  await kirim('Page.navigate', { url: asal + '/login' }, sessionId)
  await tidur(2500)

  const simpan = await kirim(
    'Runtime.evaluate',
    {
      expression: `(() => {
        localStorage.setItem('auth_token', ${JSON.stringify(token)});
        localStorage.setItem('auth_expires_at', String(Date.now() + 8 * 3600 * 1000));
        localStorage.setItem('user_data', JSON.stringify(${JSON.stringify(user)}));
        localStorage.setItem('user_role', ${JSON.stringify(user.role)});
        return Object.keys(localStorage).join(',');
      })()`,
      returnByValue: true,
    },
    sessionId,
  )
  console.log('LOGIN: ' + simpan.result.value)
}

await login()

await kirim('Page.navigate', { url }, sessionId)

// Tunggu halaman selesai memuat.
//
// Router SPA butuh waktu untuk evaluate skrip boot, memuat branding dari
// server, lalu mengganti isi halaman. Tanpa jeda, tangkapan layar hanya
// berisi halaman kosong -- yang terlihat seperti bug tampilan padahal
// masalahnya hanya screenshot terlalu cepat.
await tidur(WAIT_MS)

/**
 * Klik satu elemen sebelum pengambilan gambar.
 *
 * Dipakai untuk memotret modal: modalnya tidak punya URL sendiri, jadi
 * satu-satunya cara membukanya adalah mengklik tombolnya.
 *
 * Selector-nya diisi lewat `SHOT_CLICK`. Teks tombolnya lebih mudah
 * ditulis daripada selector CSS yang rapuh.
 */
async function klikTeks(teks) {
  const hasil = await kirim(
    'Runtime.evaluate',
    {
      expression: `(() => {
        const semua = [...document.querySelectorAll('button, a')];
        const cocok = semua.find((el) => (el.innerText || '').trim().includes(${JSON.stringify(teks)}));
        if (!cocok) return 'TIDAK ADA: ' + ${JSON.stringify(teks)};
        cocok.click();
        return 'DIKLIK: ' + (cocok.innerText || '').trim();
      })()`,
      returnByValue: true,
    },
    sessionId,
  )
  console.log('KLIK: ' + hasil.result.value)
  await tidur(WAIT_MS)
}

if (process.env.SHOT_CLICK) {
  await klikTeks(process.env.SHOT_CLICK)
}

/**
 * Jalankan JavaScript bebas sebelum pengambilan gambar.
 *
 * Dibutuhkan untuk langkah yang tidak bisa dijangkau `SHOT_CLICK`:
 * mengisi dropdown (Vue controlled component), atau membuka modal
 * yang tombolnya cuma ikon tanpa teks.
 *
 * Hasil `console.log` dari evaluate di-buang; yang penting skripnya
 * tidak melempar error, jadi hasilnya diperiksa lewat `DIAG`.
 */
if (process.env.SHOT_EVAL) {
  const hasil = await kirim(
    'Runtime.evaluate',
    {
      expression: `(async () => { ${process.env.SHOT_EVAL} })()`,
      returnByValue: true,
      awaitPromise: true,
    },
    sessionId,
  )
  console.log('EVAL: ' + JSON.stringify(hasil.result?.value ?? hasil.result?.description ?? ''))
  await tidur(WAIT_MS)
}

const ukuran = await kirim('Page.getLayoutMetrics', {}, sessionId)
const content = ukuran.cssContentSize || ukuran.contentSize
const tinggiPenuh = Math.min(Math.ceil(content.height), 8000)

/** Isi halaman, untuk diagnosing layar kosong. */
const cek = await kirim(
  'Runtime.evaluate',
  {
    expression:
      'JSON.stringify({url: location.href, title: document.title, text: (document.body.innerText||"").slice(0,400), w: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight})',
    returnByValue: true,
  },
  sessionId,
)
console.log('DIAG: ' + cek.result.value)

if (pesanError.length) {
  console.log('ERROR:\n' + pesanError.slice(0, 6).join('\n'))
}

const shot = await kirim(
  'Page.captureScreenshot',
  {
    format: 'png',
    captureBeyondViewport: true,
    clip: { x: 0, y: 0, width: lebar, height: tinggiPenuh, scale: 1 },
  },
  sessionId,
)

writeFileSync(keluar, Buffer.from(shot.data, 'base64'))
console.log(`${keluar}  viewport=${lebar}x${tinggi}  halaman=${content.width}x${content.height}`)

ws.close()
chrome.kill()
await tidur(300)
try {
  rmSync(profil, { recursive: true, force: true })
} catch {
  /* abaikan */
}
process.exit(0)
