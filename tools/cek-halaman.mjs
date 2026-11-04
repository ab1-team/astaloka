/**
 * Cek cepat: halaman apa yang gagal render + pesan errornya.
 *
 * Dipakai untuk memastikan "halaman laporan tidak ditemukan" itu karena
 * memang tidak ada data, bukan karena halaman crash.
 *
 * Jalankan: node tools/cek-halaman.mjs <path>
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
const profil = mkdtempSync(join(tmpdir(), 'cek-halaman-'))

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

let wsUrl = null
for (let i = 0; i < 60; i++) {
  try {
    const res = await fetch(`http://127.0.0.1:${PORT}/json/version`)
    wsUrl = (await res.json()).webSocketDebuggerUrl
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
ws.onmessage = (ev) => {
  const msg = JSON.parse(ev.data)
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

await send('Page.navigate', { url: `${APP}/login` }, sessionId)
await sleep(4000)

await evaluate(`(async () => {
  const res = await fetch('${API}/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email: 'admin@gmail.com', password: 'password' }),
  })
  const json = await res.json()
  localStorage.setItem('auth_token', json.data.token)
  localStorage.setItem('auth_expires_at', String(Date.now() + 8 * 60 * 60 * 1000))
  localStorage.setItem('user_role', json.data.user.role)
  localStorage.setItem('user_data', JSON.stringify(json.data.user))
  localStorage.setItem('tenant_slug', json.data.tenant_slug || 'demo')
  return true
})()`)

for (const path of process.argv.slice(2)) {
  await send('Page.navigate', { url: `${APP}${path}` }, sessionId)
  await sleep(9000)

  const info = await evaluate(`(() => {
    const text = document.body.innerText.replace(/\\s+/g, ' ').trim()
    const sidebar = document.querySelector('.sidebar-brand-name, .brand-name, [class*="sidebar"] [class*="name"]')
    return {
      judul: document.title,
      adaHalamanLaporan: !!document.querySelector('.report-page'),
      jumlahHalaman: document.querySelectorAll('.report-page-wrap').length,
      adaKop: !!document.querySelector('.surat-kop'),
      adaLogo: !!document.querySelector('.kop-logo, .kop-logo-fallback'),
      namaSidebar: sidebar?.textContent?.trim() || null,
      teks: text.slice(0, 400),
    }
  })()`)

  console.log(`\n=== ${path} ===`)
  console.log(JSON.stringify(info, null, 2))
}

ws.close()
chrome.kill()
process.exit(0)
