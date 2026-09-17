<?php
// pages/pengaturan.php — konfigurasi koneksi DB SIMRS + tes koneksi
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/layout.php';

$cfg = get_config();
$testHasil = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string)($_POST['aksi'] ?? 'simpan');

    $newCfg = [
        'db_host' => trim((string)($_POST['db_host'] ?? '')),
        'db_port' => trim((string)($_POST['db_port'] ?? '3306')),
        'db_user' => trim((string)($_POST['db_user'] ?? '')),
        'db_pass' => (string)($_POST['db_pass'] ?? ''),
        'db_name' => trim((string)($_POST['db_name'] ?? '')),
    ];

    if ($aksi === 'tes') {
        try {
            $pdo = db_connect($newCfg);
            $pdo->query('SELECT 1');
            $testHasil = ['ok' => true, 'pesan' => 'Koneksi berhasil ke ' . $newCfg['db_host'] . ':' . $newCfg['db_port'] . ' / ' . $newCfg['db_name']];
        } catch (Throwable $ex) {
            $testHasil = ['ok' => false, 'pesan' => 'Koneksi gagal: ' . $ex->getMessage()];
        }
        $cfg = $newCfg; // tampilkan yang sedang dicoba
    } else {
        if ($newCfg['db_host'] === '' || $newCfg['db_name'] === '') {
            flash('danger', 'Host dan nama database wajib diisi.');
        } else {
            save_json(CONFIG_FILE, $newCfg);
            flash('success', 'Konfigurasi koneksi disimpan.');
        }
        $cfg = $newCfg;
        redirect($_SERVER['PHP_SELF']);
    }
}

layout_header('Pengaturan', 'pengaturan');
?>
<div class="bg-white rounded-4 shadow-sm p-3" style="max-width:720px">
    <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
        <strong>Pengaturan Koneksi Database SIMRS</strong>
    </div>

    <?php if ($testHasil): ?>
    <div class="alert alert-<?= $testHasil['ok'] ? 'success' : 'danger' ?>">
        <?= e($testHasil['pesan']) ?>
    </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="aksi" value="simpan">
        <div class="row g-2">
            <div class="col-md-8">
                <label class="form-label small fw-semibold mb-1">Host</label>
                <input type="text" name="db_host" class="form-control form-control-sm" value="<?= e($cfg['db_host']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Port</label>
                <input type="text" name="db_port" class="form-control form-control-sm" value="<?= e($cfg['db_port']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-semibold mb-1">Username</label>
                <input type="text" name="db_user" class="form-control form-control-sm" value="<?= e($cfg['db_user']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-semibold mb-1">Password</label>
                <input type="password" name="db_pass" class="form-control form-control-sm" value="<?= e($cfg['db_pass']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Nama Database</label>
                <input type="text" name="db_name" class="form-control form-control-sm" value="<?= e($cfg['db_name']) ?>" required>
            </div>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary btn-sm">
                Simpan Konfigurasi
            </button>
            <button type="submit" name="aksi" value="tes" class="btn btn-outline-primary btn-sm">
                Tes Koneksi (tanpa simpan)
            </button>
        </div>
    </form>
    <div class="alert alert-light border small mt-3 mb-0">
        Konfigurasi tersimpan di file <code>config.json</code> pada folder aplikasi.
        Ubah sesuai server tiap rumah sakit — halaman Laporan memakai pengaturan ini.
    </div>
</div>
<?php layout_footer();
