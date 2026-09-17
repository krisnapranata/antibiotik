<?php
// index.php — beranda aplikasi Antibiotik
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';

$cfg = get_config();
$dbOk = false;
$dbPesan = 'Belum dikonfigurasi';
if (!empty($cfg['db_name'])) {
    try {
        $pdo = db_connect($cfg);
        $pdo->query('SELECT 1');
        $dbOk = true;
        $dbPesan = 'Terhubung';
    } catch (Throwable $ex) {
        $dbPesan = 'Gagal: ' . $ex->getMessage();
    }
}

$master = get_master();
$jmlAktif = 0;
foreach ($master['items'] as $it) {
    if (!empty($it['aktif'])) {
        $jmlAktif++;
    }
}

layout_header('Beranda', 'beranda');
?>
<div class="row g-3">
    <div class="col-12">
        <div class="bg-white rounded-4 shadow-sm p-4">
            <h4 class="fw-bold mb-1">Aplikasi Antibiotik</h4>
            <p class="text-muted mb-0">
                Laporan pemakaian antibiotik (triwulan &amp; semester) berbasis daftar nama obat
                yang dikelola sendiri — dapat dipakai di semua rumah sakit.
            </p>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <h6 class="fw-bold">Master Antibiotik</h6>
                <p class="text-muted small">Kelola daftar nama obat yang dihitung sebagai antibiotik
                    (include) dan pengecualian (exclude).</p>
                <div class="mb-3"><span class="badge bg-primary"><?= (int)$jmlAktif ?> entri aktif</span></div>
                <a href="pages/master.php" class="btn btn-primary btn-sm">Buka Master</a>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <h6 class="fw-bold">Laporan</h6>
                <p class="text-muted small">Pasien pemakai antibiotik: nama, No.RM, dokter, poli,
                    ruangan inap — dikelompokkan triwulan / semester.</p>
                <a href="pages/laporan.php" class="btn btn-primary btn-sm">Buka Laporan</a>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <h6 class="fw-bold">Pengaturan Koneksi</h6>
                <p class="text-muted small">Atur koneksi database SIMRS rumah sakit ini.</p>
                <div class="mb-3">
                    <span class="badge bg-<?= $dbOk ? 'success' : 'danger' ?>"><?= e($dbPesan) ?></span>
                </div>
                <a href="pages/pengaturan.php" class="btn btn-outline-primary btn-sm">Buka Pengaturan</a>
            </div>
        </div>
    </div>
</div>
<?php layout_footer();
