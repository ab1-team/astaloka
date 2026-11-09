<?php

namespace App\Http\Controllers;

use App\Models\Calk;
use App\Models\Setting;
use App\Services\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SopController extends Controller
{
    public function index()
    {
        $s = Setting::first();

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
                // TANGGAL jatuh tempo / akhir bayar.
                'toleransiTunggakan' => (int) ($s?->toleransi_tunggakan ?? 0),
                // JUMLAH BULAN tunggakan sebelum sambungan diputus.
                'batasTagihan' => (int) ($s?->batas_tagihan ?? 0),
                // Alias lama untuk frontend yang belum diperbarui.
                'batasBulanTunggakan' => (int) ($s?->batas_tagihan ?? 0),
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
                // TANGGAL akhir bayar (1-31). Disimpan di
                // `settings.toleransi_tunggakan`.
                'toleransiTunggakan' => ['required', 'integer', 'min:1', 'max:31'],

                // JUMLAH BULAN tunggakan sebelum sambungan diputus.
                // Disimpan di `settings.batas_tagihan`.
                'batasTagihan' => ['required_without:batasBulanTunggakan', 'nullable', 'integer', 'min:0', 'max:24'],

                // Alias lama untuk frontend yang belum diperbarui.
                'batasBulanTunggakan' => ['required_without:batasTagihan', 'nullable', 'integer', 'min:0', 'max:24'],
            ], [
                'toleransiTunggakan.max' => 'Tanggal toleransi menunggak harus antara 1 dan 31.',
                'toleransiTunggakan.min' => 'Tanggal toleransi menunggak harus antara 1 dan 31.',
                'batasTagihan.max' => 'Batas tagihan maksimal 24 bulan.',
                'batasTagihan.min' => 'Batas tagihan tidak boleh negatif.',
                'batasBulanTunggakan.max' => 'Batas tagihan maksimal 24 bulan.',
                'batasBulanTunggakan.min' => 'Batas tagihan tidak boleh negatif.',
            ]);

            $tanggal = (int) $data['toleransiTunggakan'];
            $bulan = (int) ($data['batasTagihan'] ?? $data['batasBulanTunggakan'] ?? 0);

            $s = Setting::firstOrNew([]);
            $s->key = $s->key ?: 'sop';
            // Dua kolom, dua fungsi:
            //   - toleransi_tunggakan = TANGGAL jatuh tempo;
            //   - batas_tagihan       = JUMLAH BULAN sebelum suspend.
            $s->toleransi_tunggakan = $tanggal;
            $s->batas_tagihan = $bulan;
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
