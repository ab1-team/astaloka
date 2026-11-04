<?php

/**
 * Buang database uji yang tertinggal dari run sebelumnya.
 *
 * Dipakai manual setelah `php artisan test` selesai TERPUTUS (Ctrl+C,
 * timeout, kill). Test yang mem-provisioning lokasi membuat database
 * sendiri lewat `CREATE DATABASE` dan membersihkannya di `tearDown()` --
 * tapi kalau prosesnya mati di tengah jalan, `tearDown()` tidak pernah jalan
 * dan database-nya tertinggal selamanya.
 *
 * usage:
 *   php tools/bersihkan-db-uji.php --dry-run   # hanya tampilkan, tidak hapus
 *   php tools/bersihkan-db-uji.php             # benar-benar hapus
 *   php tools/bersihkan-db-uji.php --yatim     # + hapus DB yatim (tak terdaftar di pusat)
 *
 * `--dry-run` itu WAJIB dipakai lebih dulu. Perintah ini menghapus SELURUH
 * isi database yang cocok -- dan "seluruh isi" termasuk data yang tidak ada
 * salinannya di mana pun.
 *
 * `--yatim` menambah satu cakupan lagi: database yang NAMANYA berawalan
 * `astaloka_` tapi TIDAK terdaftar di registry pusat (tabel `tenants`) ikut
 * dihapus. Yang begini muncul kalau lokasi dihapus dari registry tanpa
 * database-nya ikut hilang -- sisa yang tidak akan pernah dibersihkan oleh
 * pola uji di bawah, karena namanya terlihat "normal".
 *
 * Flag itu TIDAK aktif secara default. Menyeret penghapusan database ke
 * dalam perilaku bawaan skrip berarti satu kesalahan penulisan nama akan
 * menghapus data yang tidak ada salinannya -- dan tidak ada yang bisa
 * membatalkan `DROP DATABASE`.
 */

$kering = in_array('--dry-run', $argv, true);
$yatim = in_array('--yatim', $argv, true);

/*
 * Database sistem MySQL.
 *
 * Tidak akan pernah cocok dengan awalan `astaloka_`, tapi tetap dicatat
 * eksplisit: daftar ini adalah "> TIDAK boleh dihapus, apa pun keadaannya",
 * dan menambah nama ke sini lebih murah daripada memikirkannya ulang
 * setiap kali aturan penghapusan berubah.
 */
$semuaPusat = [
    'information_schema',
    'mysql',
    'performance_schema',
    'sys',
];

$pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '');

$st = $pdo->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA');
$semua = $st->fetchAll(PDO::FETCH_COLUMN);

/*
 * Hanya database UJI yang boleh dihapus.
 *
 * PENTING: daftarnya per-pola, BUKAN `astaloka%`. Database LOKASI yang
 * asli juga berawalan `astaloka_` (mis. `astaloka_demo`), dan ikut
 * terhapus berarti data operasional hilang -- termasuk data yang tidak
 * ada salinannya di mana pun.
 *
 * Yang boleh:
 *   - `astaloka_testing*`            -> nama resmi DB uji, dengan atau
 *                                       tanpa suffix (`_central`, `_piutang`,
 *                                       `_iso`, ...)
 *   - `astaloka_<prefix>_<uniqid>`    -> DB lokasi palsu milik test
 *   - `<prefix>_<uniqid>_*`           -> DB lokasi palsu milik test yang
 *                                       TIDAK memakai prefix `astaloka_`
 *
 * Prefix TANPA `astaloka_` itu penting, bukan tambahan opsional.
 * Tiga test membuat database lokasi palsu tanpa awalan `astaloka_`:
 *
 *   - `CentralMenuAccessTest`  -> `str_replace('-','_',$prefix.'terpisah')`
 *   - `ShortcutLoginTest`      -> `str_replace('-','_',$prefix.'terpisah')`
 *   - `MigrateTenantsTest`     -> `$prefix.'_jalan'`, `$prefix.'_rusak'`, ...
 *
 * Pola yang HANYA bisa menerima `astaloka_<prefix>_` tidak akan pernah
 * cocok dengan tiga nama itu -- jadi database yang paling sering tertinggal
 * justru yang paling tidak pernah terhapus. Buktinya ada di server: test
 * yang selesai tiba-tiba meninggalkan `migtest_6ae2fe24237dd__lancar`.
 *
 * Pola di bawah ditulis sebagai "prefix lalu underscore" TANPA mensyaratkan
 * awalan `astaloka_`, jadi keenamnya ikut tertangkap -- dan tetap aman,
 * karena prefix-nya (`provtest`, `migtest`, ...) tidak mungkin jadi awal
 * nama database lokasi yang dipakai aplikasi.
 */
$polaUji = [
    // Database uji resmi, termasuk varian suffixed-nya.
    '/^astaloka_testing(_[a-z0-9_]+)?$/',
    // Database lokasi palsu milik test, dengan atau tanpa awalan `astaloka_`.
    '/^(astaloka_)?(provtest|statustest|hakakses|shortcut|migtest|domtest|brandtest|usatest|ringkasan|invlock|landingtest)_/',
];

/*
 * Database OPERASIONAL -- tidak boleh ikut terhapus, apa pun keadaannya.
 *
 * Daftar ini dibaca dari TIGA sumber, supaya ikut bergeser kalau nama
 * database lokasi ikut berubah:
 *
 *   1. Registry pusat (tabel `tenants`) -- sumber kebenaran nama database
 *      lokasi. Database yang TERDAFTAR di sini adalah data operasional,
 *      dan tidak boleh dihapus dalam keadaan apa pun.
 *   2. `backend/config/database.php` -- nama database pusat dan fallback
 *      koneksi lokasi.
 *   3. Daftar bawaan -- dipakai kalau sumber di atas tidak terbaca.
 *
 * PENTING: config `database.php` memanggil fungsi `env()`, yang hanya ada
 * kalau Laravel sudah dibootstrap. `require` config-nya secara langsung
 * (tanpa bootstrap) melempar Error "Call to undefined function env()" --
 * dan kalau error itu ditelan, daftarnya diam-diam jadi kosong. Skrip ini
 * karena itu BOOTSTRAP Laravel dulu, dan kalau gagal, tetap memakai daftar
 * bawaan yang bersifat konservatif.
 */
$operasional = ['astaloka', 'astaloka_demo', 'astaloka_bumdesmart'];

try {
    require __DIR__.'/../backend/vendor/autoload.php';

    $app = require __DIR__.'/../backend/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    // Registry pusat: nama database setiap lokasi yang benar-benar dipakai.
    foreach ((array) config('database.connections') as $koneksi) {
        if (! is_array($koneksi)) {
            continue;
        }

        if (! in_array($koneksi['driver'] ?? '', ['mysql', 'mariadb'], true)) {
            continue;
        }

        $db = $koneksi['database'] ?? null;

        if (is_string($db) && $db !== '' && $db !== ':memory:') {
            $operasional[] = $db;
        }
    }

    $central = config('database.connections.central.database');

    if (is_string($central) && $central !== '') {
        $operasional[] = $central;
    }

    // Nama database lokasi, langsung dari registry pusat.
    //
    // DSN-nya HARUS menyebut database pusat. Tanpa `dbname=`, MySQL
    // membalas "No database selected" dan tabel `tenants` tidak bisa
    // dibaca sama sekali -- daftar operasional jadi tidak lengkap.
    $centralPdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s',
            config('database.connections.central.host', '127.0.0.1'),
            (int) config('database.connections.central.port', 3306),
            $central
        ),
        config('database.connections.central.username', 'root'),
        (string) config('database.connections.central.password', '')
    );

    $stmt = $centralPdo->query(
        'SELECT `database` FROM `tenants` WHERE `database` IS NOT NULL AND `database` <> ""'
    );

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $db) {
        if (is_string($db) && $db !== '') {
            $operasional[] = $db;
        }
    }

    /*
     * Nama database LOKASI yang terdaftar di pusat.
     *
     * Dipisah dari `$operasional` karena perannya berbeda: `$operasional`
     * berisi semua yang TIDAK BOLEH dihapus (pusat, fallback koneksi, dan
     * lokasi yang terdaftar). Daftar ini berisi lokasi yang terdaftar --
     * dipakai untuk mengenali database yatim, yaitu yang namanya berawalan
     * `astaloka_` tapi tidak ada di registry.
     */
    $terdaftar = array_values(array_unique(array_filter($operasional, function ($nama) {
        return is_string($nama) && str_starts_with($nama, 'astaloka_');
    })));
} catch (Throwable $e) {
    // Config atau registry tidak terbaca -- pakai daftar bawaan saja.
    // Daftar bawaan tetap konservatif: isinya hanya nama-nama yang memang
    // sudah diketahui sebagai database operasional.
    echo 'PERINGATAN: daftar database operasional tidak lengkap ('.$e->getMessage().').', PHP_EOL;
    echo '            Yang dipakai: daftar bawaan.', PHP_EOL;

    // Tanpa registry, daftar lokasi terdaftar TIDAK boleh ditebak.
    // Menebaknya berarti menandai database operasional sebagai yatim --
    // dan `--yatim` menghapus yang ditandai yatim.
    $terdaftar = [];
}

$operasional = array_values(array_unique($operasional));

$dihapus = 0;
$disimpan = [];
$yatimDitemukan = [];

foreach ($semua as $nama) {
    if (in_array($nama, $operasional, true)) {
        $disimpan[] = $nama;

        continue;
    }

    $cocok = false;

    foreach ($polaUji as $pola) {
        if (preg_match($pola, $nama) === 1) {
            $cocok = true;
            break;
        }
    }

    /*
     * Database YATIM: berawalan `astaloka_` (jadi terlihat seperti database
     * lokasi), tapi TIDAK terdaftar di registry pusat.
     *
     * Bentuknya muncul kalau lokasi dihapus dari registry tanpa database-nya
     * ikut hilang. Polanya di atas tidak akan pernah menyentuhnya -- namanya
     * tidak mengandung `testing`, dan tidak punya prefix test.
     *
     * Syarat `--yatim` WAJIB menyala. Tanpa itu, database yang namanya
     * `astaloka_` dan kebetulan tidak terbaca registry-nya akan ikut
     * terhapus -- dan `DROP DATABASE` tidak punya tombol batal.
     *
     * Nama variabelnya `$isYatim`, bukan `$yatim`: yang kedua itu flag dari
     * command line. Menimpanya di dalam loop membuat flag itu ikut berubah
     * jadi `false` setelah database pertama diperiksa -- jadi setiap database
     * berikutnya lolos tanpa pernah dihitung sebagai yatim.
     */
    $isYatim = $yatim
        && $terdaftar !== []
        && str_starts_with($nama, 'astaloka_')
        && ! in_array($nama, $terdaftar, true)
        && ! in_array($nama, $semuaPusat, true);

    if (! $cocok && ! $isYatim) {
        continue;
    }

    // MySQL tidak menerima placeholder di `DROP DATABASE`, jadi nama
    // divalidasi lebih dulu.
    if (! preg_match('/^[A-Za-z0-9_]+$/', $nama)) {
        echo "SKIP (nama tidak aman): {$nama}", PHP_EOL;

        continue;
    }

    if ($isYatim && ! $cocok) {
        $yatimDitemukan[] = $nama;
    }

    $catatan = $isYatim && ! $cocok ? '  (yatim: tidak terdaftar di pusat)' : '';

    if ($kering) {
        echo "akan dihapus: {$nama}{$catatan}", PHP_EOL;

        $dihapus++;

        continue;
    }

    $pdo->exec("DROP DATABASE IF EXISTS `{$nama}`");
    echo "dropped: {$nama}{$catatan}", PHP_EOL;

    $dihapus++;
}

echo PHP_EOL;
echo ($kering ? 'akan dihapus: ' : 'dihapus: ').$dihapus.PHP_EOL;
echo 'disimpan (operasional): '.implode(', ', $disimpan).PHP_EOL;

if ($yatimDitemukan !== []) {
    echo 'yatim (tak terdaftar di pusat): '.implode(', ', $yatimDitemukan).PHP_EOL;
}

if ($kering) {
    echo PHP_EOL;
    echo 'Mode --dry-run: TIDAK ADA database yang dihapus.', PHP_EOL;
    echo 'Jalankan tanpa --dry-run untuk benar-benar menghapusnya.', PHP_EOL;
}
