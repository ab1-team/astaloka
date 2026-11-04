<?php

namespace App\Services;

use App\Http\Controllers\InstallationTicketController;
use App\Models\InstallationTicket;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Ekspor daftar status instalasi ke file Excel (.xlsx) dengan kop surat.
 *
 * MENGAPA ADA SERVICE INI
 * ======================
 *
 * Tombol "Export Excel" di halaman `/app/instalasi/status` sebelumnya
 * hanya stub (tidak melakukan apa-apa). Sekarang tombol itu memanggil
 * endpoint yang benar-benar menghasilkan file `.xlsx` BERGAYA KOP SURAT:
 * logo lembaga, nama, alamat, telepon/email, SK Kemenkumham, judul
 * laporan, baris meta, lalu tabel data yang sudah ber-border.
 *
 * Kenapa file DIBUAT DI BACKEND, bukan di frontend
 * ----------------------------------------------
 *
 * Frontend tidak punya pustaka Excel sama sekali (tidak ada
 * sheetjs/exceljs). Menulis `.xlsx` dari nol di browser berarti membuat
 * ZIP + XML sendiri -- rapuh dan besar. `phpoffice/phpspreadsheet` sudah
 * ada di `composer.json` dan dipakai oleh `TemplateGenerator`, jadi
 * dipakai lagi di sini.
 *
 * FILTER DI SERVER, BUKAN DI BROWSER
 * ==================================
 *
 * Query-nya menerima `kategori` dan `search`, persis seperti yang
 * dikirim halaman. Backend memetakan `kategori` ke status mentah
 * (lihat `KATEGORI_STATUS`) dengan aturan yang SAMA dengan
 * `stores/instalasiStore.js` di frontend. Kalau kedua sisi memakai
 * aturan berbeda, hasil ekspor tidak akan sama dengan yang tampil di
 * layar -- dan itu justru sumber kebingungan paling sering.
 *
 * PENCARIAN MENCAKUP FIELD YANG SAMA DENGAN HALAMAN
 * ==================================================
 *
 * Halaman mencari berdasarkan nama, kode instalasi (`customer_code`),
 * dan alamat. Ekspor memakai tiga field yang sama, supaya "yang
 * kelihatan di layar" = "yang keluar di file".
 *
 * @see InstallationTicketController::exportStatusExcel()
 */ class StatusInstalasiExcelExport
{
    /**
     * Pemetaan kategori -> status mentah (database).
     *
     * WAJIB SAMA dengan `stores/instalasiStore.js` (frontend). Kalau
     * satu sisi berubah dan sisi lain tidak, ekspor dan layarshowing
     * data berbeda -- dan user mengira ekspor salah.
     *
     * Perhatikan bahwa `draft` SENGAJA tidak ada di sini: tiket draf
     * adalah pekerjaan awal admin yang belum selesai, dan tidak pernah
     * muncul di tab mana pun pada halaman Status Instalasi.
     */
    public const KATEGORI_STATUS = [
        'permohonan' => ['pending'],
        'pasang_baru' => ['surveyed', 'unpaid', 'processing'],
        'aktif' => ['completed'],
        'blokir' => ['suspended'],
        'cabut' => ['terminated'],
    ];

    /**
     * Label kategori untuk judul laporan.
     *
     * Mengikuti `types/instalasiStatus.js` (frontend) supaya judul di
     * file Excel memakai kata yang sama dengan tabnya.
     */
    public const KATEGORI_LABEL = [
        'permohonan' => 'Permohonan',
        'pasang_baru' => 'Pasang Baru',
        'aktif' => 'Aktif',
        'blokir' => 'Blokir',
        'cabut' => 'Cabut',
    ];

    /**
     * Label status mentah (dari `types/instalasiStatus.js`).
     *
     * Ditulis ulang di sini supaya file Excel tidak bergantung pada
     * konstanta frontend -- dan supaya label yang tampil di Excel sama
     * persis dengan yang tampil di layar.
     */
    public const STATUS_LABEL = [
        'draft' => 'Draft',
        'pending' => 'Pending',
        'surveyed' => 'Surveyed',
        'unpaid' => 'Unpaid',
        'processing' => 'Processing',
        'completed' => 'Completed',
        'suspended' => 'Suspended',
        'terminated' => 'Terminated',
    ];

    /** Kolom tabel, dalam urutan tampil. */
    private const KOLOM = [
        ['No', 6],
        ['Kode Instalasi', 18],
        ['Nama Pelanggan', 26],
        ['NIK', 20],
        ['No. Telp', 16],
        ['Alamat', 40],
        ['Desa/Dusun', 18],
        ['Paket', 20],
        ['Status', 14],
        ['Tanggal Order', 16],
    ];

    /**
     * Bangun file Excel untuk satu kategori + satu kata kunci pencarian.
     *
     * @param  string  $kategori  salah satu key `KATEGORI_STATUS`
     * @param  string  $search  kata kunci pencarian (boleh kosong)
     * @return array{body: string, filename: string}
     */
    public function build(string $kategori, string $search = ''): array
    {
        $lembaga = Setting::first();

        $rows = $this->ambilRows($kategori, $search);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Status Instalasi');

        try {
            $this->gambarKopSurat($sheet, $lembaga);
            $this->gambarJudul($sheet, $kategori, $search, count($rows));
            $this->gambarTabel($sheet, $rows);

            $this->aturCetak($sheet, count($rows));

            $writer = new Xlsx($spreadsheet);

            ob_start();

            try {
                $writer->save('php://output');

                return [
                    'body' => (string) ob_get_clean(),
                    'filename' => $this->namaFile($kategori),
                ];
            } catch (Throwable $e) {
                // `ob_start()` tanpa `ob_end_clean()` di jalur gagal
                // menyisakan output buffer setengah jadi. Isinya ikut
                // terkirim bersama response dan membuat file unduhan
                // rusak (XML mendadak di depan workbook).
                ob_end_clean();

                throw $e;
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * Ambil baris yang cocok dengan kategori + pencarian.
     *
     * Relasi yang dipakai sengaja RINGAN: paket, desa, dan customer.
     * Relasi berat (tagihan bulanan, pembacaan meter, payments) tidak
     * dipakai -- untuk daftar status, isinya tidak dibutuhkan, dan
     * memuatnya membuat respons membengkak tidak proporsional.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function ambilRows(string $kategori, string $search): Collection
    {
        $statuses = self::KATEGORI_STATUS[$kategori] ?? [];

        $query = InstallationTicket::query()
            ->with([
                'package:id,name',
                'village:id,village_name',
                'customer:id,customer_code,ticket_id',
            ])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        $search = trim($search);

        if ($search !== '') {
            /*
             * Pencarian memakai tiga field: nama pemohon, alamat, dan kode
             * pelanggan. `customer_code` ada di relasi `customer`, jadi
             * harus dicari lewat `whereHas` -- bukan `orWhere` di level
             * tiket (kolomnya tidak ada di tabel `installation_tickets`).
             */
            $like = '%'.$search.'%';

            $query->where(function ($q) use ($like) {
                $q->where('applicant_name', 'like', $like)
                    ->orWhere('address', 'like', $like)
                    ->orWhereHas('customer', function ($cq) use ($like) {
                        $cq->where('customer_code', 'like', $like);
                    });
            });
        }

        return $query->get()->map(function ($ticket) {
            /*
             * Relasi `customer` adalah hasMany, jadi nilainya Kumpulan
             * (bisa kosong), bukan satu model. Kode yang ditampilkan
             * adalah kode customer TERAKHIR -- sama dengan frontend
             * (`stores/instalasiStore.js`), yang mengambil customer dengan
             * id terbesar.
             */
            $latestCustomer = $ticket->customer->sortByDesc('id')->first();

            return [
                'kode' => $latestCustomer?->customer_code
                    ?? '#INS-'.str_pad((string) $ticket->id, 4, '0', STR_PAD_LEFT),
                'nama' => $ticket->applicant_name ?: '-',
                'nik' => $ticket->nik ?: '-',
                'telp' => $ticket->phone ?: '-',
                'alamat' => $ticket->address ?: '-',
                'desa' => $ticket->village->village_name ?? '-',
                'paket' => $ticket->package->name ?? '-',
                'status' => self::STATUS_LABEL[$ticket->status] ?? $ticket->status,
                'tanggal' => $this->formatTanggal($ticket->order_date),
            ];
        });
    }

    /**
     * Kop surat: logo di kiri, identitas lembaga di kanan.
     *
     * Baris 1-3 dipakai untuk kop, baris 4 untuk garis pemisah, dan
     * baris 5 untuk judul laporan. Logo DISEMBARKAN sebagai gambar
     * (bukan teks) supaya sheet tetap bisa langsung dicetak.
     */
    private function gambarKopSurat(Worksheet $sheet, ?Setting $lembaga): void
    {
        $lastCol = 'J';

        $nama = $lembaga?->nama_lembaga ?: ($lembaga?->nama ?: 'NAMA LEMBAGA');
        $alamat = $lembaga?->alamat ?: '-';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', strtoupper((string) $nama));
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 14],
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(26);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', strtoupper((string) $alamat));
        $sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);

        $kontak = [];
        if ($lembaga?->telepon) {
            $kontak[] = 'Telp. '.$lembaga->telepon;
        }
        if ($lembaga?->email) {
            $kontak[] = $lembaga->email;
        }

        $sheet->mergeCells("A3:{$lastCol}3");
        $sheet->setCellValue('A3', implode(' | ', $kontak));
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
            'font' => ['italic' => true, 'size' => 10],
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(16);

        if ($lembaga?->sk_kemenkumham) {
            $sheet->mergeCells("A4:{$lastCol}4");
            $sheet->setCellValue('A4', 'SK Kemenkumham RI No. '.$lembaga->sk_kemenkumham);
            $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
                'font' => ['size' => 10],
                'alignment' => [
                    'horizontal' => 'center',
                    'vertical' => 'center',
                ],
            ]);
            $sheet->getRowDimension(4)->setRowHeight(16);
        }

        $this->sembunyikanLogo($sheet, $lembaga);
    }

    /**
     * Pasang logo lembaga di kop surat.
     *
     * Logo disimpan di `storage/app/public/sop/logo/`. URL relatif
     * (`/storage/...`) TIDAK bisa dipakai di sini -- file Excel dibaca
     * di komputer lain, di mana path itu tidak berarti apa-apa. Jadi
     * gambarnya disisipkan sebagai file biner.
     *
     * Hanya format yang didukung PhpSpreadsheet yang dipakai (png,
     * jpg, jpeg, gif). Format lain (mis. webp) sengaja dilewati --
     * memaksanya hanya membuat file ekspor rusak.
     */
    private function sembunyikanLogo(Worksheet $sheet, ?Setting $lembaga): void
    {
        $logo = $lembaga?->logo;

        if (! $logo) {
            return;
        }

        $extension = strtolower(pathinfo((string) $logo, PATHINFO_EXTENSION));

        if (! in_array($extension, ['png', 'jpg', 'jpeg', 'gif'], true)) {
            return;
        }

        $path = Storage::disk('public')->path('sop/logo/'.ltrim((string) $logo, '/'));

        if (! is_file($path)) {
            return;
        }

        try {
            $drawing = new Drawing;
            $drawing->setPath($path);
            $drawing->setCoordinates('A1');
            $drawing->setHeight(56);
            $drawing->setOffsetX(6);
            $drawing->setOffsetY(4);
            $sheet->getDrawingCollection()->append($drawing);
        } catch (Throwable) {
            /*
             * Logo adalah kosmetik. Kalau formatnya ternyata tidak
             * didukung (mis. WEBP yang lolos cek ekstensi tapi tidak
             * didukung PhpSpreadsheet), file ekspor TETAP harus
             * berhasil -- lebih baik tanpa logo daripada file rusak.
             */
        }
    }

    /**
     * Judul laporan + baris meta (jumlah data, tanggal cetak, filter).
     */
    private function gambarJudul(Worksheet $sheet, string $kategori, string $search, int $total): void
    {
        $lastCol = 'J';
        $label = self::KATEGORI_LABEL[$kategori] ?? 'Semua Status';

        $sheet->mergeCells("A5:{$lastCol}5");
        $sheet->setCellValue('A5', 'DAFTAR STATUS INSTALASI - '.strtoupper($label));
        $sheet->getStyle("A5:{$lastCol}5")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(22);

        $meta = 'Jumlah Data: '.$total.' data';

        if (trim($search) !== '') {
            $meta .= ' | Pencarian: "'.trim($search).'"';
        }

        $meta .= ' | Dicetak: '.$this->formatTanggal(now()->format('Y-m-d H:i:s'));

        $sheet->mergeCells("A6:{$lastCol}6");
        $sheet->setCellValue('A6', $meta);
        $sheet->getStyle("A6:{$lastCol}6")->applyFromArray([
            'font' => ['size' => 9, 'italic' => true],
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
        ]);
        $sheet->getRowDimension(6)->setRowHeight(16);
    }

    /**
     * Tabel data beserta border, lebar kolom, dan tinggi baris.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function gambarTabel(Worksheet $sheet, Collection $rows): void
    {
        $headerRow = 8;
        $lastCol = 'J';

        $border = [
            'allBorders' => [
                'borderStyle' => 'thin',
                'color' => ['rgb' => '000000'],
            ],
        ];

        // Header
        foreach (self::KOLOM as $index => $column) {
            $cell = $this->kolomLetter($index + 1);
            $sheet->setCellValue($cell.$headerRow, $column[0]);
        }

        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'DADDE6'],
            ],
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
                'wrapText' => true,
            ],
            'borders' => $border,
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(24);

        // Baris data
        $row = $headerRow + 1;

        foreach ($rows as $data) {
            $values = [
                $data['kode'],
                $data['nama'],
                $data['nik'],
                $data['telp'],
                $data['alamat'],
                $data['desa'],
                $data['paket'],
                $data['status'],
                $data['tanggal'],
            ];

            $sheet->setCellValue('A'.$row, (string) (($row - $headerRow)));

            foreach ($values as $offset => $value) {
                $cell = $this->kolomLetter($offset + 2);
                $sheet->setCellValue($cell.$row, (string) $value);
            }

            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'font' => ['size' => 10],
                'alignment' => [
                    'vertical' => 'center',
                    'wrapText' => true,
                ],
                'borders' => $border,
            ]);

            // Kolom "No" dan kolom yang isinya pendek dirata-tengah.
            $sheet->getStyle("A{$row}")->getAlignment()
                ->setHorizontal('center');
            $sheet->getStyle("C{$row}")->getAlignment()
                ->setHorizontal('center');
            $sheet->getStyle("E{$row}")->getAlignment()
                ->setHorizontal('center');
            $sheet->getStyle("H{$row}")->getAlignment()
                ->setHorizontal('center');
            $sheet->getStyle("I{$row}")->getAlignment()
                ->setHorizontal('center');
            $sheet->getStyle("J{$row}")->getAlignment()
                ->setHorizontal('center');

            $sheet->getRowDimension($row)->setRowHeight(18);

            $row++;
        }

        if ($rows->isEmpty()) {
            $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
            $sheet->setCellValue("A{$row}", 'Tidak ada data yang sesuai.');
            $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
                'font' => ['italic' => true, 'size' => 10],
                'alignment' => ['horizontal' => 'center'],
                'borders' => $border,
            ]);
        }

        // Lebar kolom mengikuti judul kolomnya.
        foreach (self::KOLOM as $index => $column) {
            $sheet->getColumnDimension($this->kolomLetter($index + 1))
                ->setAutoSize(false)
                ->setWidth($column[1]);
        }
    }

    /**
     * Pengaturan cetak: landscape, muat dalam satu lebar halaman,
     * header tabel diulang di tiap halaman.
     */
    private function aturCetak(Worksheet $sheet, int $total): void
    {
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(8, 8);
        $sheet->getPageMargins()->setTop(0.5)->setRight(0.4)->setBottom(0.5)->setLeft(0.4);

        $sheet->setShowGridlines(false);

        $sheet->getSheetView()->setZoomScale(90);

        // AutoFilter hanya berguna kalau ada baris data.
        if ($total > 0) {
            $sheet->setAutoFilter('A8:J'.(8 + $total));
        }
    }

    /**
     * Konversi indeks kolom (1-based) menjadi huruf kolom Excel.
     */
    private function kolomLetter(int $index): string
    {
        $result = '';

        while ($index > 0) {
            $index--;
            $result = chr(65 + ($index % 26)).$result;
            $index = intdiv($index, 26);
        }

        return $result;
    }

    /**
     * Format tanggal untuk tampilan Indonesia.
     */
    private function formatTanggal($value): string
    {
        if (! $value) {
            return '-';
        }

        try {
            $date = Carbon::parse($value);
        } catch (Throwable) {
            return (string) $value;
        }

        return $date->format('d/m/Y');
    }

    /**
     * Nama file unduhan.
     *
     * Tidak memakai spasi supaya aman di semua sistem file; label
     * kategori tetap terbaca.
     */
    private function namaFile(string $kategori): string
    {
        $label = self::KATEGORI_LABEL[$kategori] ?? 'Semua';
        $slug = strtolower(str_replace([' ', '/'], '-', $label));

        return 'Status_Instalasi_'.$slug.'_'.now()->format('Y-m-d').'.xlsx';
    }
}
