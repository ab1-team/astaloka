<?php

namespace Tests\Feature;

use App\Console\Commands\ScheduleRunResilient;
use Tests\TestCase;

/**
 * Test untuk skrip pembungkus scheduler (`tools/scheduler-run-hidden.*`).
 *
 * LATAR BELAKANG
 * ==============
 *
 * Task Scheduler yang menjalankan `php.exe` (aplikasi konsol) SELALU membuka
 * jendela konsol baru setiap kali task berjalan. Karena task-nya berjalan
 * tiap menit, itu berarti satu jendela baru per menit -- dan karena Windows
 * Terminal menjadi default terminal di Win11, jendela itu muncul sebagai
 * TAB BARU di Windows Terminal, lalu hilang sendiri beberapa detik kemudian.
 *
 * Gejalanya persis seperti pada bug report: terminal "misterius" membuka tab
 * baru sendiri, beberapa saat kemudian tabnya hilang.
 *
 * Perbaikannya: task tidak lagi menjalankan `php.exe` langsung, tapi
 * `wscript.exe tools\scheduler-run-hidden.vbs`, yang menjalankan perintah yang
 * sama lewat `WScript.Shell.Run` dengan window style `0` (tersembunyi).
 *
 * Test ini memeriksa SKRIPNYA -- bukan environment Windows-nya, karena
 * `schedule:run` dan Task Scheduler tidak tersedia di semua mesin (Linux,
 * CI). Yang diperiksa adalah hal-hal yang membuat skrip benar DAN yang
 * sudah terbukti menjadi penyebab masalah.
 *
 * @see ScheduleRunResilient
 */
class SchedulerRunnerScriptTest extends TestCase
{
    private function toolsDir(): string
    {
        // `backend/tests/Feature` -> naik 3 tingkat = root repo.
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'tools';
    }

    private function readScript(string $name): string
    {
        $path = $this->toolsDir().DIRECTORY_SEPARATOR.$name;

        $this->assertFileExists($path, "File '$name' harus ada di folder tools.");

        return (string) file_get_contents($path);
    }

    public function test_skrip_vbs_dan_cmd_keduanya_ada(): void
    {
        $this->assertFileExists(
            $this->toolsDir().DIRECTORY_SEPARATOR.'scheduler-run-hidden.vbs'
        );

        $this->assertFileExists(
            $this->toolsDir().DIRECTORY_SEPARATOR.'scheduler-run-hidden.cmd'
        );
    }

    public function test_vbs_menjalankan_dengan_window_style_tersembunyi(): void
    {
        $vbs = $this->readScript('scheduler-run-hidden.vbs');

        // `shell.Run(command, 0, True)` -- argumen kedua `0` adalah window
        // style "hidden". Tanpa itu, `cmd.exe` membuka jendela yang terlihat
        // dan Windows Terminal menampilkannya sebagai tab baru.
        $this->assertMatchesRegularExpression(
            '/shell\.Run\([^,]+,\s*0\s*,/i',
            $vbs,
            'Skrip harus menjalankan perintah dengan window style 0 (tersembunyi).'
        );
    }

    public function test_vbs_menunggu_proses_selesai(): void
    {
        $vbs = $this->readScript('scheduler-run-hidden.vbs');

        // Argumen ketiga `True` = tunggu._exit code hanya bisa didapat
        // kalau proses selesai, dan exit code itu yang dicatat Task
        // Scheduler di "Last Result".
        $this->assertMatchesRegularExpression(
            '/shell\.Run\([^,]+,\s*0\s*,\s*True\s*\)/i',
            $vbs,
            'Skrip harus menunggu proses selesai supaya exit code bisa didapat.'
        );
    }

    public function test_vbs_menteruskan_exit_code_ke_task_scheduler(): void
    {
        $vbs = $this->readScript('scheduler-run-hidden.vbs');

        $this->assertMatchesRegularExpression(
            '/WScript\.Quit\s+rc\b/i',
            $vbs,
            'Exit code harus diteruskan supaya "Last Result" di Task Scheduler akurat.'
        );
    }

    public function test_cmd_menjalankan_schedule_run_dengan_whisper(): void
    {
        $cmd = $this->readScript('scheduler-run-hidden.cmd');

        $this->assertStringContainsString(
            'schedule:run',
            $cmd,
            'File cmd harus menjalankan schedule:run.'
        );

        // Tanpa `--whisper`, `schedule:run` mencetak "No scheduled commands
        // are ready to run." SETIAP MENIT. Kalau output itu masuk ke file
        // log, lognya akan penuh baris yang tidak berarti.
        $this->assertStringContainsString(
            '--whisper',
            $cmd,
            'Flag --whisper dipakai supaya output tidak membanjiri log.'
        );
    }

    public function test_cmd_menulis_output_ke_file_bukan_ke_layar(): void
    {
        $cmd = $this->readScript('scheduler-run-hidden.cmd');

        $this->assertMatchesRegularExpression(
            '/>\s*"%OUT_FILE%"\s*2>&1/i',
            $cmd,
            'Output harus dialihkan ke file, karena jendelanya disembunyikan.'
        );
    }

    public function test_cmd_hanya_menulis_log_saat_ada_error(): void
    {
        $cmd = $this->readScript('scheduler-run-hidden.cmd');

        // Run yang sukses harus TIDAK masuk log. Alasannya: `schedule:run`
        // yang sukses tetap mencetak "Running [...] DONE" untuk setiap job
        // yang jalan, dan `migrasi:bersihkan` jalan tiap 10 menit -- jadi
        // log akan penuh baris sukses yang tidak pernah dibaca, dan baris
        // error yang SESUNGGUHNYA jadi sulit ditemukan di antaranya.
        $this->assertStringContainsString(
            'IS_ERROR',
            $cmd,
            'Skrip harus membedakan run sukses dan run yang bermasalah.'
        );

        // Deteksi error harus berbasis isi output, bukan cuma exit code:
        // `schedule:run` mengembalikan 0 bahkan kalau job-nya gagal --
        // job yang gagal dilaporkan lewat exception handler, bukan lewat
        // exit code scheduler.
        $this->assertMatchesRegularExpression(
            '/findstr[^\n]*(error|exception|failed)/i',
            $cmd,
            'Isi output harus diperiksa untuk mendeteksi error.'
        );
    }

    public function test_cmd_tidak_menggandakan_blok_jalankan(): void
    {
        $cmd = $this->readScript('scheduler-run-hidden.cmd');

        // Dua `cd /d` berarti salah satunya duplikat sisa penulisan --
        // bukan bug fatal, tapi tanda skripnya belum dirapikan.
        $this->assertSame(
            1,
            substr_count($cmd, 'cd /d "%BACKEND_DIR%"'),
            'Perintah cd ke folder backend hanya boleh muncul sekali.'
        );
    }

    public function test_cmd_menghapus_file_output_kosong(): void
    {
        $cmd = $this->readScript('scheduler-run-hidden.cmd');

        // File output per run dibuat tiap menit. Kalau file kosong tidak
        // dihapus, folder `storage/logs` akan penuh file tmp yang tidak
        // pernah dibaca siapa pun.
        $this->assertMatchesRegularExpression(
            '/del\s+\/q\s+"%OUT_FILE%"/i',
            $cmd,
            'File output harus dihapus.'
        );
    }

    public function test_cmd_menjalankan_artisan_dari_folder_backend(): void
    {
        $cmd = $this->readScript('scheduler-run-hidden.cmd');

        // `artisan` harus dijalankan dengan folder kerja `backend`, kalau
        // tidak Laravel tidak menemukan `vendor/autoload.php`.
        $this->assertStringContainsString(
            'cd /d "%BACKEND_DIR%"',
            $cmd,
            'Perintah harus dijalankan dari folder backend.'
        );
    }

    public function test_skrip_pemasangan_task_menunjuk_ke_vbs(): void
    {
        $path = $this->toolsDir().DIRECTORY_SEPARATOR.'install-scheduler-task.ps1';

        $this->assertFileExists($path);

        $ps1 = (string) file_get_contents($path);

        // Task TIDAK boleh menjalankan `php.exe` langsung -- itu penyebab
        // jendela terminal muncul.
        $this->assertStringContainsString(
            'scheduler-run-hidden.vbs',
            $ps1,
            'Task harus menjalankan skrip VBS pembungkus.'
        );

        // Action-nya harus `wscript.exe`, bukan `php.exe`.
        $this->assertMatchesRegularExpression(
            '/New-ScheduledTaskAction[\s\S]*?-Execute\s+\$Wscript\b/i',
            $ps1,
            'Action task harus menjalankan wscript.exe, bukan php.exe.'
        );

        // Tidak boleh ada action yang menjalankan `php.exe` langsung.
        // Yang dicek hanya baris ACTION -- komentar documentation boleh
        // menyebut `php.exe` karena itu memang yang menjelaskan masalahnya.
        $actionBlock = $this->actionBlock($ps1);

        $this->assertStringNotContainsString(
            'php.exe',
            $actionBlock,
            'Action task tidak boleh menjalankan php.exe langsung.'
        );
    }

    /**
     * Ambil blok `New-ScheduledTaskAction` dari skrip PowerShell.
     */
    private function actionBlock(string $ps1): string
    {
        $start = strpos($ps1, 'New-ScheduledTaskAction');

        $this->assertNotFalse($start, 'Blok New-ScheduledTaskAction harus ada.');

        return substr($ps1, $start, 400);
    }

    public function test_skrip_pemasangan_task_bisa_menghapus_task(): void
    {
        $path = $this->toolsDir().DIRECTORY_SEPARATOR.'install-scheduler-task.ps1';

        $ps1 = (string) file_get_contents($path);

        $this->assertStringContainsString(
            'Unregister-ScheduledTask',
            $ps1,
            'Skrip harus bisa menghapus task yang ada.'
        );
    }
}
