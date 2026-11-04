<?php

namespace App\Http\Controllers;

use App\Models\Calk;
use App\Models\Setting;
use App\Services\BillingTolerance;
use App\Services\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SopController extends Controller
{
    public function index()
    {
        $s = Setting::first();

        /*
         * Angka TANGGAL yang dipakai semua aturan berbasis tanggal.
         *
         * Sumbernya `BillingTolerance::tanggal()` (kolom `batas_tagihan`),
         * jadi angka yang tampil di halaman SOP persis sama dengan yang
         * dipakai backend untuk jatuh tempo, generate piutang, ambang
         * piutang, dan deteksi advance payment.
         */
        $tanggal = app(BillingTolerance::class)->tanggal();

        $data = [
            'lembaga' => [
                'nama' => $s?->nama ?? '',
                'alamat' => $s?->alamat ?? '',
                'email' => $s?->email ?? '',
                'telepon' => $s?->telepon ?? '',
                'domain' => $s?->domain ?? '',
                'peraturan_desa' => $s?->peraturan_desa ?? '',
                'sk_kemenkumham' => $s?->sk_kemenkumham ?? '',
            ],
            'sistemTagihan' => [
                /*
                 * SATU ANGGAL untuk semua aturan berbasis tanggal.
                 *
                 * `batasTagihan` (jatuh tempo) dan `toleransiTunggakan`
                 * (generate piutang) dulu dua input terpisah, padahal
                 * satuan dan maknanya sama: "hari ke berapa dalam bulan".
                 * Sekarang keduanya digabung jadi satu angka yang
                 * disimpan di `settings.batas_tagihan`, dan
                 * `settings.toleransi_tunggakan` hanya salinannya.
                 *
                 * Field `batasTagihan` sengaja DIKIRIM dengan nilai yang
                 * sama: ada dua konsumen frontend yang membacanya
                 * (`ReportCetakDaftarTagihan.vue` untuk "Tgl Akhir
                 * Pembayaran" dan `BillingForm.vue` untuk pratinjau
                 * advance payment), dan keduanya harus melihat angka yang
                 * sama dengan yang dipakai backend.
                 */
                'batasTagihan' => $tanggal,
                'toleransiTunggakan' => $tanggal,
                /*
                 * Batas tunggakan dalam BULAN sebelum sambungan diputus.
                 *
                 * Satuannya berbeda dari angka di atas: yang ini JUMLAH
                 * BULAN, sementara `batasTagihan`/`toleransiTunggakan`
                 * adalah TANGGAL dalam bulan.
                 *
                 * `null` di database dibaca sebagai 3 (bawaan), supaya form
                 * tidak pernah menampilkan kolom kosong -- lihat
                 * `BillingTolerance::unpaidBillLimit()`.
                 */
                'batasBulanTunggakan' => app(BillingTolerance::class)->unpaidBillLimit(),
            ],
            'pasangBaru' => [
                'statusPembayaran' => (bool) ($s?->status_pembayaran ?? false),
            ],
            'pemakaianAir' => [
                /*
                 * Kamera meteran dibuka otomatis saat popup input pemakaian?
                 *
                 * `null` = belum pernah diatur -> pakai perilaku bawaan,
                 * yaitu kamera TIDAK dibuka otomatis. Yang wajib diisi cuma
                 * angka meter akhir; foto dan scan OCR sama-sama opsional,
                 * jadi memaksa teknisi menunggu kamera terbuka setiap kali
                 * popup muncul hanya menambah waktu, bukan data.
                 *
                 * `null` sengaja tidak dipaksa jadi `false` di sini: "belum
                 * diisi" dan "sengaja dimatikan" itu dua hal berbeda, dan
                 * hanya admin yang boleh membedakan lewat halaman SOP.
                 */
                'autoOpenMeterCamera' => $s?->auto_open_meter_camera,
            ],
            'logo' => [
                'logo' => $s?->logo ?? null,
            ],
            'whatsapp' => [
                'templateTagihan' => $s?->pesan_tagihan ?? '',
                'templatePembayaran' => $s?->pesan_pembayaran ?? '',
            ],
            'calk' => $this->buildCalkPayload($s),
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Identitas lembaga yang boleh dilihat semua role login.
     *
     * Dipakai oleh chrome bersama (judul sidebar, kop surat/cetak, nomor
     * kontak untuk laporan gangguan) yang dirender untuk admin, teknisi,
     * surveyor, dan pelanggan.
     *
     * `telepon` sengaja ikut dikembalikan: ini sumber nomor kontak resmi
     * yang dikelola admin lewat menu "Profil Lembaga" (`updateLembaga`).
     * Halaman pelanggan (mis. Lapor Gangguan) sebelumnya menampilkan nomor
     * hardcode `0812-3456-7890` yang tidak pernah sama dengan yang diisi
     * admin — jadi pelanggan menghubungi nomor yang bukan sebenarnya.
     *
     * Field kop (nama_lembaga, alamat, email, sk_kemenkumham, logo) juga
     * ikut dikembalikan. Semuanya berisi data publik institution yang
     * memang dicetak di kop surat resmi — tidak ada data pelanggan atau
     * rahasia di dalamnya. Ini penting karena kop surat dirender untuk
     * teknisi/surveyor juga, sementara `GET /settings/sop` (`index()` di
     * atas) khusus admin dan akan 403 untuk role lain. Tanpa field ini,
     * halaman cetak form input yang dipakai teknisi hanya bisa menampilkan
     * nama usaha hardcode yang bisa kedaluwarsa.
     */
    public function publicIdentity()
    {
        $s = Setting::first();

        return response()->json([
            'success' => true,
            'data' => [
                'nama' => $s?->nama ?? '',
                'nama_lembaga' => $s?->nama_lembaga ?? '',
                'alamat' => $s?->alamat ?? '',
                'email' => $s?->email ?? '',
                'telepon' => $s?->telepon ?? '',
                'sk_kemenkumham' => $s?->sk_kemenkumham ?? '',
                'logo' => $s?->logo ?? null,
            ],
        ]);
    }

    public function updateLembaga(Request $request)
    {
        try {
            $data = $request->validate([
                'nama' => 'nullable|string|max:150',
                'alamat' => 'nullable|string',
                'email' => 'nullable|email|max:150',
                'telepon' => 'nullable|string|max:30',
                'domain' => 'nullable|string|max:255',
                'peraturan_desa' => 'nullable|string|max:100',
                'sk_kemenkumham' => 'nullable|string|max:100',
            ]);

            $s = Setting::firstOrNew([]);
            $s->key = $s->key ?: 'sop';
            $s->fill($data);
            $s->save();

            return $this->success('Profil lembaga berhasil disimpan');
        } catch (\Throwable $e) {
            return $this->error($e);
        }
    }

    public function updatePasangBaru(Request $request)
    {
        try {
            $data = $request->validate([
                'statusPembayaran' => 'required|boolean',
            ]);

            $s = Setting::firstOrNew([]);
            $s->key = $s->key ?: 'sop';
            $s->status_pembayaran = (bool) $data['statusPembayaran'];
            $s->save();

            return $this->success('Aturan pasang baru berhasil disimpan');
        } catch (\Throwable $e) {
            return $this->error($e);
        }
    }

    public function updateSistemTagihan(Request $request)
    {
        try {
            $data = $request->validate([
                /*
                 * SATU TANGGAL untuk semua aturan berbasis tanggal.
                 *
                 * Ini menggantikan dua input terpisah yang tadinya ada:
                 * "Tanggal Jatuh Tempo" (`batasTagihan`) dan "Toleransi
                 * Menunggak" (`toleransiTunggakan`). Keduanya TANGGAL dalam
                 * bulan dengan satuan yang sama, jadi BEDA hanya nama
                 * -- dan dua input untuk satu angka membuat admin mengira
                 * ada dua aturan yang harus diselaraskan sendiri.
                 *
                 * Rentang 1-31, sama seperti `BillingTolerance::generateDay()`.
                 *
                 * Kenapa harus dibatasi di sini
                 * --------------------------------
                 *
                 * Tanpa batas ini, admin bisa menyimpan angka seperti 40
                 * atau 99: form menerima, toast bilang "berhasil
                 * disimpan", tapi `BillingTolerance::generateDay()`
                 * mengembalikan 0 untuk angka di luar 1-31 -- yang dibaca
                 * sebagai "SOP belum diisi". Akibatnya generate TIDAK PERNAH
                 * jalan, tanpa error, tanpa log. Gejalanya persis seperti
                 * yang sudah pernah dilaporkan: "sudah saya set tanggalnya
                 * tapi kok generate tidak jalan?".
                 *
                 * Jadi penolakan di sini bukan tambahan strict, tapi
                 * memindahkan pesan error dari "tidak jelas" ke "tepat di
                 * tempatnya".
                 *
                 * `batasTagihan` tetap DITERIMA sebagai alias lama, tapi
                 * nilainya wajib sama dengan `toleransiTunggakan` kalau
                 * ikut dikirim. Kalau berbeda, permintaan ditolak -- bukan
                 * diam-diam salah satu menimpa yang lain, karena itu
                 * kembali ke persis masalah yang diselesaikan di sini: satu
                 * settings dengan dua tanggal yang saling bertentangan.
                 */
                'toleransiTunggakan' => ['required', 'integer', 'min:1', 'max:31'],

                /*
                 * Alias lama dari `toleransiTunggakan`.
                 *
                 * Opsional. Kalau dikirim, WAJIB sama dengan
                 * `toleransiTunggakan` -- kalau tidak, ditolak dengan 422.
                 */
                'batasTagihan' => ['nullable', 'integer', 'min:1', 'max:31'],

                /*
                 * Batas tunggakan dalam BULAN sebelum sambungan diputus.
                 *
                 * Satuannya JUMLAH BULAN, jadi rangenya jauh lebih kecil
                 * dari angka tanggal di atas. Batas atas 24 bulan (2 tahun)
                 * dipilih karena angka yang lebih besar tidak masuk akal
                 * sebagai toleransi penagihan -- dan supaya nilainya muat
                 * di kolom `billing_unpaid_limit` (smallint).
                 *
                 * NILAI 0 DITERIMA: artinya "tidak pernah memutus sambungan
                 * otomatis". Ini pilihan yang sah, jadi tidak ditolak.
                 */
                'batasBulanTunggakan' => ['required', 'integer', 'min:0', 'max:24'],
            ], [
                'toleransiTunggakan.max' => 'Tanggal toleransi menunggak harus antara 1 dan 31.',
                'toleransiTunggakan.min' => 'Tanggal toleransi menunggak harus antara 1 dan 31.',
                'batasTagihan.max' => 'Tanggal jatuh tempo harus antara 1 dan 31.',
                'batasTagihan.min' => 'Tanggal jatuh tempo harus antara 1 dan 31.',
                'batasBulanTunggakan.max' => 'Batas tunggakan maksimal 24 bulan.',
                'batasBulanTunggakan.min' => 'Batas tunggakan tidak boleh negatif.',
            ]);

            $tanggal = (int) $data['toleransiTunggakan'];

            /*
             * Alias lama harus sama persis.
             *
             * Kalau klien lama masih mengirim `batasTagihan` dengan angka
             * yang berbeda dari `toleransiTunggakan`, kedua-duanya tidak
             * bisa berlaku -- dan memilih salah satu diam-diam akan
             * menghasilkan settings yang tidak konsisten tanpa pesan.
             * Jadi ditolak eksplisit, dengan pesan yang menyebut kedua
             * field.
             */
            if (array_key_exists('batasTagihan', $data)
                && $data['batasTagihan'] !== null
                && (int) $data['batasTagihan'] !== $tanggal) {
                throw ValidationException::withMessages([
                    'batasTagihan' => [
                        'Tanggal jatuh tempo dan tanggal toleransi menunggak harus sama, karena keduanya sekarang memakai satu angka.',
                    ],
                ]);
            }

            $s = Setting::firstOrNew([]);
            $s->key = $s->key ?: 'sop';
            // Satu angka, dua kolom: `batas_tagihan` adalah sumbernya
            // (dipakai seluruh pembaca langsung), `toleransi_tunggakan`
            // salinannya (dipakai `BillingTolerance` dan pelacak lama).
            $s->batas_tagihan = $tanggal;
            $s->toleransi_tunggakan = $tanggal;
            $s->billing_unpaid_limit = (int) $data['batasBulanTunggakan'];
            $s->save();

            return $this->success('Sistem tagihan berhasil disimpan');
        } catch (\Throwable $e) {
            return $this->error($e);
        }
    }

    /**
     * GET /settings/pemakaian-air
     * Ambil pengaturan pencatatan meteran.
     *
     * Berbeda dari `index()` yang `role:admin`, endpoint ini terbuka untuk
     * semua role yang sudah login -- karena yang membacanya bukan cuma admin
     * yang menyetelnya, tapi juga TEKNISI yang memakai popup input meter.
     *
     * `GET /settings/sop` tidak bisa dipakai untuk itu: route-nya `role:admin`,
     * jadi teknisi selalu kena 403. Akibatnya kalau flag ini hanya ada di
     * sana, frontend popup meter tidak pernah tahu harusnya kamera dibuka
     * atau tidak -- dan gejalanya bukan "403", melainkan kamera yang diam-diam
     * tidak pernah terbuka.
     */
    public function getPemakaianAir()
    {
        $s = Setting::first();

        return response()->json([
            'success' => true,
            'data' => [
                'autoOpenMeterCamera' => (bool) ($s?->auto_open_meter_camera ?? false),
            ],
        ]);
    }

    /**
     * POST /settings/sop/pemakaian-air
     * Simpan pengaturan pencatatan meteran.
     *
     * Payload: { autoOpenMeterCamera: boolean }
     *
     * `autoOpenMeterCamera` menentukan apakah kamera scan langsung terbuka
     * begitu popup "Input Pemakaian" muncul. Yang wajib diisi tetap hanya
     * angka meter akhir -- foto dan scan OCR tetap opsional, dan tombol scan
     * tetap bisa dipakai kapan saja.
     */
    public function updatePemakaianAir(Request $request)
    {
        try {
            $data = $request->validate([
                'autoOpenMeterCamera' => 'required|boolean',
            ]);

            $s = Setting::firstOrNew([]);
            $s->key = $s->key ?: 'sop';
            $s->auto_open_meter_camera = (bool) $data['autoOpenMeterCamera'];
            $s->save();

            return $this->success('Pengaturan pemakaian berhasil disimpan');
        } catch (\Throwable $e) {
            return $this->error($e);
        }
    }

    public function updateLogo(Request $request)
    {
        try {
            $request->validate([
                'logo' => 'required|file|image|max:2048',
            ]);

            $s = Setting::firstOrNew([]);
            $s->key = $s->key ?: 'sop';

            if ($s->logo) {
                $oldPath = 'sop/logo/'.$s->logo;
                if (Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            $file = $request->file('logo');
            $fileName = time().'_'.$file->getClientOriginalName();
            $file->storeAs('sop/logo', $fileName, 'public');

            $s->logo = $fileName;
            $s->save();

            return response()->json([
                'success' => true,
                'message' => 'Logo berhasil disimpan',
                'data' => [
                    'logo' => $fileName,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->error($e);
        }
    }

    public function updateWhatsapp(Request $request)
    {
        try {
            $data = $request->validate([
                'templateTagihan' => 'nullable|string',
                'templatePembayaran' => 'nullable|string',
            ]);

            $s = Setting::firstOrNew([]);
            $s->key = $s->key ?: 'sop';
            $s->pesan_tagihan = $data['templateTagihan'] ?? null;
            $s->pesan_pembayaran = $data['templatePembayaran'] ?? null;
            $s->save();

            return $this->success('Template WhatsApp berhasil disimpan');
        } catch (\Throwable $e) {
            return $this->error($e);
        }
    }

    /**
     * ============================================================
     * CALK (Catatan Atas Laporan Keuangan)
     * ============================================================
     *
     * Konsep diadaptasi dari `app/Models/Calk.php` & `resources/views/sop/partials/_calk.blade.php`
     * di aplikasi sidbm. Format JSON di field `settings.calk` mengikuti struktur:
     *
     *   {
     *     "peraturan_desa": "01 TAHUN 2022",
     *     "D": {
     *       "1": { "d": { "1": 25, "2": 25, "3": 50 } },
     *       "2": { "a": 0, "b": 0, "c": 0 }
     *     }
     *   }
     *
     * Point A ("Gambaran Umum") custom tersimpan di field `settings.calk_point_a` (HTML).
     * Catatan tambahan per periode ("Lain-lain") tetap memakai tabel `calks`
     * (lihat method `getCalkCatatan` / `updateCalkCatatan`).
     */

    /**
     * Bangun struktur payload CALK default dari settings saat ini.
     * Mengikuti struktur JSON yang dipakai sidbm.
     */
    private function buildCalkPayload(?Setting $s): array
    {
        $calk = $s?->calk ?? [];

        return [
            'peraturan_desa' => $calk['peraturan_desa'] ?? ($s?->peraturan_desa ?? ''),
            'D' => [
                '1' => [
                    'd' => [
                        '1' => (int) ($calk['D']['1']['d']['1'] ?? 0), // bantuan rumah tangga %
                        '2' => (int) ($calk['D']['1']['d']['2'] ?? 0), // pengembangan kapasitas %
                        '3' => (int) ($calk['D']['1']['d']['3'] ?? 0), // pelatihan masyarakat %
                    ],
                ],
                '2' => [
                    'a' => (float) ($calk['D']['2']['a'] ?? 0), // peningkatan modal DBM
                    'b' => (float) ($calk['D']['2']['b'] ?? 0), // penambahan investasi usaha
                    'c' => (float) ($calk['D']['2']['c'] ?? 0), // pendirian unit usaha
                ],
            ],
            'point_a' => $s?->calk_point_a ?? '',
        ];
    }

    /**
     * GET /settings/sop/calk
     * Ambil konfigurasi CALK (persentase bagian, laba ditahan, Point A).
     */
    public function getCalk()
    {
        $s = Setting::first();

        return response()->json([
            'success' => true,
            'data' => $this->buildCalkPayload($s),
        ]);
    }

    /**
     * POST /settings/sop/calk
     * Simpan konfigurasi CALK (persentase bagian, laba ditahan).
     *
     * Payload:
     *   peraturan_desa: string
     *   D.1.d.1: int (%)
     *   D.1.d.2: int (%)
     *   D.1.d.3: int (%)
     *   D.2.a: numeric (peningkatan modal DBM)
     *   D.2.b: numeric (penambahan investasi usaha)
     *   D.2.c: numeric (pendirian unit usaha)
     */
    public function updateCalk(Request $request)
    {
        try {
            $data = $request->validate([
                'peraturan_desa' => 'nullable|string|max:100',
                'bantuan_rumah_tangga' => 'required|numeric|min:0|max:100',
                'pengembangan_kapasitas' => 'required|numeric|min:0|max:100',
                'pelatihan_masyarakat' => 'required|numeric|min:0|max:100',
                'peningkatan_modal' => 'required|numeric|min:0',
                'penambahan_investasi' => 'required|numeric|min:0',
                'pendirian_unit_usaha' => 'required|numeric|min:0',
            ], [
                'bantuan_rumah_tangga.required' => 'Persentase bantuan rumah tangga wajib diisi.',
                'pengembangan_kapasitas.required' => 'Persentase pengembangan kapasitas wajib diisi.',
                'pelatihan_masyarakat.required' => 'Persentase pelatihan masyarakat wajib diisi.',
                'peningkatan_modal.required' => 'Nominal peningkatan modal wajib diisi.',
                'penambahan_investasi.required' => 'Nominal penambahan investasi wajib diisi.',
                'pendirian_unit_usaha.required' => 'Nominal pendirian unit usaha wajib diisi.',
            ]);

            // Konversi ke struktur sidbm
            $calk = [
                'peraturan_desa' => $data['peraturan_desa'] ?? '',
                'D' => [
                    '1' => [
                        'd' => [
                            '1' => (float) $data['bantuan_rumah_tangga'],
                            '2' => (float) $data['pengembangan_kapasitas'],
                            '3' => (float) $data['pelatihan_masyarakat'],
                        ],
                    ],
                    '2' => [
                        'a' => (float) $data['peningkatan_modal'],
                        'b' => (float) $data['penambahan_investasi'],
                        'c' => (float) $data['pendirian_unit_usaha'],
                    ],
                ],
            ];

            $s = Setting::firstOrNew([]);
            $s->key = $s->key ?: 'sop';
            $s->calk = $calk;
            // Samakan dengan kolom peraturan_desa supaya konsisten di view lembaga.
            if (! empty($data['peraturan_desa'])) {
                $s->peraturan_desa = $data['peraturan_desa'];
            }
            $s->save();

            return $this->success('Pengaturan CALK Berhasil Diperbarui.');
        } catch (\Throwable $e) {
            return $this->error($e);
        }
    }

    /**
     * GET /settings/sop/custom-calk
     * Ambil Point A (Gambaran Umum) kustom CALK.
     */
    public function getCustomCalk()
    {
        $s = Setting::first();

        return response()->json([
            'success' => true,
            'data' => [
                'point_a' => $s?->calk_point_a ?? '',
            ],
        ]);
    }

    /**
     * POST /settings/sop/custom-calk
     * Simpan Point A (Gambaran Umum) kustom CALK.
     *
     * Payload: { point_a: string (HTML) }
     */
    public function updateCustomCalk(Request $request)
    {
        try {
            $data = $request->validate([
                'point_a' => 'nullable|string',
            ], [
                'point_a.string' => 'Konten Point A harus berupa teks/HTML.',
            ]);

            $s = Setting::firstOrNew([]);
            $s->key = $s->key ?: 'sop';
            // HTML dari editor dirender apa adanya di laporan (v-html),
            // jadi harus disanitasi sebelum disimpan -- bukan saat
            // ditampilkan. Tanpa ini, markup berbahaya ikut tersimpan
            // dan dieksekusi setiap kali laporan dibuka.
            $s->calk_point_a = HtmlSanitizer::clean($data['point_a'] ?? '');
            $s->save();

            return $this->success('Custom CALK Berhasil Diperbarui.');
        } catch (\Throwable $e) {
            return $this->error($e);
        }
    }

    /**
     * GET /settings/sop/calk-catatan?tanggal=YYYY-MM-DD
     * Ambil catatan "Lain-lain" CALK untuk tanggal tertentu.
     * Mengikuti konsep `App\Models\Calk` di sidbm (lokasi + tanggal + catatan).
     */
    public function getCalkCatatan(Request $request)
    {
        $request->validate([
            'tanggal' => 'required|date_format:Y-m-d',
        ]);

        $calk = Calk::where('tanggal', $request->tanggal)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'tanggal' => $request->tanggal,
                'catatan' => $calk?->catatan ?? '',
            ],
        ]);
    }

    /**
     * POST /settings/sop/calk-catatan
     * Simpan catatan "Lain-lain" CALK untuk tanggal tertentu.
     *
     * Payload: { tanggal: YYYY-MM-DD, catatan: string (HTML) }
     */
    public function updateCalkCatatan(Request $request)
    {
        try {
            $data = $request->validate([
                'tanggal' => 'required|date_format:Y-m-d',
                'catatan' => 'nullable|string',
            ]);

            // Hapus catatan lama untuk tanggal tsb, lalu insert baru.
            // Sama pola dengan `PelaporanController::preview` di sidbm
            // (delete where lokasi+tanggal LIKE, lalu create).
            Calk::where('tanggal', $data['tanggal'])->delete();

            if (! empty($data['catatan'])) {
                Calk::create([
                    'tanggal' => $data['tanggal'],
                    // Catatan CALK dirender apa adanya di laporan, jadi
                    // harus disanitasi sebelum disimpan.
                    'catatan' => HtmlSanitizer::clean($data['catatan']),
                ]);
            }

            return $this->success('Catatan CALK Berhasil Diperbarui.');
        } catch (\Throwable $e) {
            return $this->error($e);
        }
    }

    private function success($message = 'Berhasil disimpan')
    {
        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    private function error($e)
    {
        $payload = [
            'success' => false,
            'message' => $e->getMessage(),
        ];
        if ($e instanceof ValidationException) {
            $payload['errors'] = $e->errors();

            return response()->json($payload, 422);
        }

        return response()->json($payload, 500);
    }
}
