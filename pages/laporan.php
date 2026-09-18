<?php
// pages/laporan.php — laporan pemakaian antibiotik: rekap per dokter, rincian per obat,
// detail triwulan/semester, export Excel (periode) & CSV (per dokter)
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/layout.php';

const DOKTER_TANPA_DPJP = '__tanpa_dpjp__';

function valid_tgl(string $t): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) && strtotime($t) !== false;
}

/** Klausa WHERE dokter (DPJP) untuk outer query. Return [sql, params]. */
function dokter_where(string $dokter): array
{
    if ($dokter === '') {
        return ['', []];
    }
    if ($dokter === DOKTER_TANPA_DPJP) {
        return ["WHERE (reg.kd_dokter IS NULL OR reg.kd_dokter = '')", []];
    }
    return ['WHERE reg.kd_dokter = ?', [$dokter]];
}

/** Detail pasien pemakai antibiotik (opsional filter dokter & obat). */
function query_laporan(PDO $pdo, string $dari, string $sampai, array $master, string $dokter = '', string $obat = ''): array
{
    [$filterSql, $fParams] = build_antibiotik_filter($master);
    $obatSql = $obat !== '' ? ' AND b.nama_brng = ?' : '';
    [$dokterSql, $dParams] = dokter_where($dokter);

    $sql = "
        SELECT x.no_rawat, x.tgl, x.jenis,
               pa.no_rkm_medis, pa.nm_pasien,
               dk.nm_dokter AS dpjp,
               pol.nm_poli,
               IFNULL(bg.nm_bangsal, '-') AS ruangan,
               GROUP_CONCAT(DISTINCT x.nama_brng ORDER BY x.nama_brng SEPARATOR ', ') AS antibiotik
        FROM (
            SELECT r.no_rawat, r.tgl_peresepan AS tgl, r.status AS jenis, b.nama_brng
            FROM resep_obat r
            JOIN resep_dokter d ON d.no_resep = r.no_resep
            JOIN databarang b ON b.kode_brng = d.kode_brng
            WHERE r.tgl_peresepan BETWEEN ? AND ? AND {$filterSql}{$obatSql}
            UNION ALL
            SELECT p.no_rawat, p.tgl_perawatan AS tgl, LOWER(p.status) AS jenis, b.nama_brng
            FROM detail_pemberian_obat p
            JOIN databarang b ON b.kode_brng = p.kode_brng
            WHERE p.tgl_perawatan BETWEEN ? AND ? AND {$filterSql}{$obatSql}
        ) x
        JOIN reg_periksa reg ON reg.no_rawat = x.no_rawat
        JOIN pasien pa ON pa.no_rkm_medis = reg.no_rkm_medis
        LEFT JOIN dokter dk ON dk.kd_dokter = reg.kd_dokter
        LEFT JOIN poliklinik pol ON pol.kd_poli = reg.kd_poli
        LEFT JOIN (
            SELECT ki.no_rawat, ki.kd_kamar
            FROM kamar_inap ki
            JOIN (
                SELECT no_rawat, MAX(CONCAT(tgl_masuk, ' ', jam_masuk)) AS mk
                FROM kamar_inap
                GROUP BY no_rawat
            ) k2 ON k2.no_rawat = ki.no_rawat
               AND CONCAT(ki.tgl_masuk, ' ', ki.jam_masuk) = k2.mk
        ) ki ON ki.no_rawat = x.no_rawat
        LEFT JOIN kamar km ON km.kd_kamar = ki.kd_kamar
        LEFT JOIN bangsal bg ON bg.kd_bangsal = km.kd_bangsal
        {$dokterSql}
        GROUP BY x.no_rawat, x.tgl, x.jenis,
                 pa.no_rkm_medis, pa.nm_pasien, dk.nm_dokter, pol.nm_poli, bg.nm_bangsal
        ORDER BY x.tgl DESC, x.no_rawat
    ";

    $params = array_merge(
        [$dari, $sampai],
        $fParams,
        $obat !== '' ? [$obat] : [],
        [$dari, $sampai],
        $fParams,
        $obat !== '' ? [$obat] : [],
        $dParams
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Rekap semua dokter: jumlah jenis antibiotik, jumlah pasien, jumlah kunjungan. */
function query_rekap_dokter(PDO $pdo, string $dari, string $sampai, array $master): array
{
    [$filterSql, $fParams] = build_antibiotik_filter($master);

    $sql = "
        SELECT IFNULL(dk.kd_dokter, '') AS kd_dokter,
               IFNULL(dk.nm_dokter, '-') AS nm_dokter,
               COUNT(DISTINCT x.nama_brng) AS jml_jenis_obat,
               COUNT(DISTINCT pa.no_rkm_medis) AS jml_pasien,
               COUNT(DISTINCT x.no_rawat) AS jml_kunjungan
        FROM (
            SELECT r.no_rawat, r.tgl_peresepan AS tgl, b.nama_brng
            FROM resep_obat r
            JOIN resep_dokter d ON d.no_resep = r.no_resep
            JOIN databarang b ON b.kode_brng = d.kode_brng
            WHERE r.tgl_peresepan BETWEEN ? AND ? AND {$filterSql}
            UNION ALL
            SELECT p.no_rawat, p.tgl_perawatan AS tgl, b.nama_brng
            FROM detail_pemberian_obat p
            JOIN databarang b ON b.kode_brng = p.kode_brng
            WHERE p.tgl_perawatan BETWEEN ? AND ? AND {$filterSql}
        ) x
        JOIN reg_periksa reg ON reg.no_rawat = x.no_rawat
        JOIN pasien pa ON pa.no_rkm_medis = reg.no_rkm_medis
        LEFT JOIN dokter dk ON dk.kd_dokter = reg.kd_dokter
        GROUP BY dk.kd_dokter, dk.nm_dokter
        ORDER BY jml_pasien DESC, nm_dokter ASC
    ";

    $params = array_merge([$dari, $sampai], $fParams, [$dari, $sampai], $fParams);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Rincian per obat untuk satu dokter (opsional filter obat). */
function query_rincian_obat(PDO $pdo, string $dari, string $sampai, array $master, string $dokter, string $obat = ''): array
{
    [$filterSql, $fParams] = build_antibiotik_filter($master);
    $obatSql = $obat !== '' ? ' AND b.nama_brng = ?' : '';
    [$dokterSql, $dParams] = dokter_where($dokter);

    $sql = "
        SELECT x.nama_brng,
               COUNT(DISTINCT pa.no_rkm_medis) AS jml_pasien,
               COUNT(DISTINCT x.no_rawat) AS jml_kunjungan
        FROM (
            SELECT r.no_rawat, r.tgl_peresepan AS tgl, b.nama_brng
            FROM resep_obat r
            JOIN resep_dokter d ON d.no_resep = r.no_resep
            JOIN databarang b ON b.kode_brng = d.kode_brng
            WHERE r.tgl_peresepan BETWEEN ? AND ? AND {$filterSql}{$obatSql}
            UNION ALL
            SELECT p.no_rawat, p.tgl_perawatan AS tgl, b.nama_brng
            FROM detail_pemberian_obat p
            JOIN databarang b ON b.kode_brng = p.kode_brng
            WHERE p.tgl_perawatan BETWEEN ? AND ? AND {$filterSql}{$obatSql}
        ) x
        JOIN reg_periksa reg ON reg.no_rawat = x.no_rawat
        JOIN pasien pa ON pa.no_rkm_medis = reg.no_rkm_medis
        {$dokterSql}
        GROUP BY x.nama_brng
        ORDER BY jml_pasien DESC, x.nama_brng ASC
    ";

    $params = array_merge(
        [$dari, $sampai],
        $fParams,
        $obat !== '' ? [$obat] : [],
        [$dari, $sampai],
        $fParams,
        $obat !== '' ? [$obat] : [],
        $dParams
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Daftar nama obat aktual yang muncul di data (untuk datalist). */
function query_obat_list(PDO $pdo, string $dari, string $sampai, array $master): array
{
    [$filterSql, $fParams] = build_antibiotik_filter($master);

    $sql = "
        SELECT DISTINCT nama_brng
        FROM (
            SELECT b.nama_brng
            FROM resep_obat r
            JOIN resep_dokter d ON d.no_resep = r.no_resep
            JOIN databarang b ON b.kode_brng = d.kode_brng
            WHERE r.tgl_peresepan BETWEEN ? AND ? AND {$filterSql}
            UNION ALL
            SELECT b.nama_brng
            FROM detail_pemberian_obat p
            JOIN databarang b ON b.kode_brng = p.kode_brng
            WHERE p.tgl_perawatan BETWEEN ? AND ? AND {$filterSql}
        ) o
        ORDER BY nama_brng ASC
    ";

    $params = array_merge([$dari, $sampai], $fParams, [$dari, $sampai], $fParams);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_column($stmt->fetchAll(), 'nama_brng');
}

/** Daftar semua dokter (untuk datalist). */
function query_dokter_list(PDO $pdo): array
{
    return $pdo
        ->query("SELECT kd_dokter, nm_dokter FROM dokter ORDER BY nm_dokter ASC")
        ->fetchAll();
}

function nama_dokter_dari_kd(PDO $pdo, string $kd): string
{
    if ($kd === '' || $kd === DOKTER_TANPA_DPJP) {
        return $kd === DOKTER_TANPA_DPJP ? 'Tanpa DPJP' : '-';
    }
    $stmt = $pdo->prepare("SELECT nm_dokter FROM dokter WHERE kd_dokter = ? LIMIT 1");
    $stmt->execute([$kd]);
    $row = $stmt->fetch();
    return $row ? (string)$row['nm_dokter'] : $kd;
}

function sanitasi_nama_file(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? '';
    $s = trim($s, '_');
    return $s !== '' ? $s : 'file';
}

function kelompokkan(array $rows, string $mode): array
{
    $hasil = [];
    foreach ($rows as $r) {
        $label = periode_label($r['tgl'], $mode);
        $hasil[$label][] = $r;
    }
    uksort($hasil, function (string $a, string $b): int {
        $f = function (string $s): array {
            $parts = explode(' ', $s);
            return [
                (int)($parts[1] ?? 0),
                (int)substr($parts[0], 1),
                substr($parts[0], 0, 1),
            ];
        };
        return $f($b) <=> $f($a);
    });
    return $hasil;
}

function export_csv(
    array $rincian,
    array $detailRows,
    string $nmDokter,
    string $namaObat,
    string $dari,
    string $sampai,
    string $mode
): never {
    set_time_limit(600);

    $baseName = sanitasi_nama_file($nmDokter);
    if ($namaObat !== '') {
        $baseName .= '_' . sanitasi_nama_file($namaObat);
    }
    $fname = $baseName . '_' . date('Ymd') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: max-age=0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 untuk Excel

    // ===== Blok 1: Rekapitulasi =====
    fputcsv($out, ['LAPORAN ANTIBIOTIK PER DOKTER — ' . $nmDokter], ';');
    fputcsv($out, ['Periode: ' . tgl_id($dari) . ' s.d ' . tgl_id($sampai)], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['REKAPITULASI'], ';');
    fputcsv($out, ['Dokter', 'Obat', 'Jumlah Pasien', 'Jumlah Kunjungan'], ';');
    foreach ($rincian as $r) {
        fputcsv($out, [$nmDokter, $r['nama_brng'], (int)$r['jml_pasien'], (int)$r['jml_kunjungan']], ';');
    }

    $pasienUnik = [];
    $kunjunganUnik = [];
    foreach ($detailRows as $r) {
        $pasienUnik[$r['no_rkm_medis']] = true;
        $kunjunganUnik[$r['no_rawat']] = true;
    }
    fputcsv($out, ['TOTAL', count($rincian) . ' jenis obat', count($pasienUnik), count($kunjunganUnik)], ';');

    // ===== Blok 2: Detail pasien =====
    fputcsv($out, [], ';');
    fputcsv($out, ['DETAIL PASIEN'], ';');
    fputcsv($out, ['No', 'Periode', 'Tanggal', 'Nama Pasien', 'No.RM', 'Poli', 'Ruangan Inap', 'Jenis', 'Antibiotik'], ';');
    $no = 0;
    foreach (kelompokkan($detailRows, $mode) as $label => $groupRows) {
        foreach ($groupRows as $r) {
            $no++;
            fputcsv($out, [
                $no,
                $label,
                tgl_id($r['tgl']),
                $r['nm_pasien'],
                $r['no_rkm_medis'],
                $r['nm_poli'] ?: '-',
                $r['ruangan'],
                $r['jenis'] === 'ranap' ? 'Ranap' : 'Ralan',
                $r['antibiotik'],
            ], ';');
        }
    }

    fclose($out);
    exit;
}

function export_excel(array $rows, string $mode, string $dari, string $sampai): never
{
    set_time_limit(600);
    ini_set('memory_limit', '512M');
    require APP_ROOT . '/vendor/autoload.php';

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->removeSheetByIndex(0);

    $headers = ['No', 'Tanggal', 'Nama Pasien', 'No.RM', 'Dokter (DPJP)', 'Poli', 'Ruangan Inap', 'Jenis', 'Antibiotik'];
    $widths = [5, 12, 28, 12, 26, 22, 20, 10, 50];

    $groups = kelompokkan($rows, $mode);

    foreach ($groups as $label => $groupRows) {
        $sheetName = mb_substr(str_replace(['/', '\\', '?', '*', '[', ']', ':'], '-', $label), 0, 31);
        $ws = $spreadsheet->createSheet();
        $ws->setTitle($sheetName);

        $ws->setCellValue('A1', 'LAPORAN PEMAKAIAN ANTIBIOTIK — ' . $label);
        $ws->mergeCells('A1:I1');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $ws->setCellValue('A2', 'Periode: ' . tgl_id($dari) . ' s.d ' . tgl_id($sampai) . '   |   Jenis periode: ' . ($mode === 'semester' ? 'Semester' : 'Triwulan'));
        $ws->mergeCells('A2:I2');
        $ws->getStyle('A2')->getFont()->setSize(9);

        $hrow = 4;
        foreach ($headers as $ci => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 1);
            $cell = $ws->getCell($col . $hrow);
            $cell->setValue($h);
            $cell->getStyle()->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
                'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]],
            ]);
            $ws->getColumnDimension($col)->setWidth($widths[$ci]);
        }

        $dataRows = [];
        foreach ($groupRows as $i => $r) {
            $dataRows[] = [
                $i + 1,
                tgl_id($r['tgl']),
                $r['nm_pasien'],
                $r['no_rkm_medis'],
                $r['dpjp'] ?: '-',
                $r['nm_poli'] ?: '-',
                $r['ruangan'],
                $r['jenis'] === 'ranap' ? 'Ranap' : 'Ralan',
                $r['antibiotik'],
            ];
        }
        if ($dataRows) {
            $ws->fromArray($dataRows, null, 'A' . ($hrow + 1));
            $lastRow = $hrow + count($dataRows);
            $ws->getStyle('A' . ($hrow + 1) . ':I' . $lastRow)->applyFromArray([
                'font' => ['size' => 10],
                'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]],
            ]);
            $lastRow = max($lastRow, $hrow + 1);
        } else {
            $lastRow = $hrow + 1;
        }

        $ws->freezePane('A5');
        $ws->setAutoFilter('A' . $hrow . ':I' . $lastRow);
    }

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $fname = 'laporan_antibiotik_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit;
}

// ===== Main =====
$cfg = get_config();
if (empty($cfg['db_name'])) {
    layout_header('Laporan', 'laporan');
    echo '<div class="alert alert-warning">Database belum dikonfigurasi. Buka <a href="pengaturan.php">Pengaturan</a> dulu.</div>';
    layout_footer();
    exit;
}

$dari = (string)($_GET['dari'] ?? date('Y-01-01'));
$sampai = (string)($_GET['sampai'] ?? akhir_bulan_sekarang());
if (!valid_tgl($dari)) {
    $dari = date('Y-01-01');
}
if (!valid_tgl($sampai)) {
    $sampai = akhir_bulan_sekarang();
}
$tab = ($_GET['tab'] ?? 'triwulan') === 'semester' ? 'semester' : 'triwulan';
$dokter = trim((string)($_GET['dokter'] ?? ''));
$obat = trim((string)($_GET['obat'] ?? ''));
$mode = (string)($_GET['mode'] ?? '');

$error = null;
$pdo = null;
$master = [];
$dokterList = [];
$obatList = [];
$rekap = [];
$rincian = [];
$rows = [];
$nmDokterTerpilih = '';

try {
    $pdo = db_connect($cfg);
    $master = get_master();
    $dokterList = query_dokter_list($pdo);
    $rekap = query_rekap_dokter($pdo, $dari, $sampai, $master);
    $obatList = query_obat_list($pdo, $dari, $sampai, $master);

    if ($mode === 'excel') {
        $rows = query_laporan($pdo, $dari, $sampai, $master, $dokter, $obat);
        export_excel($rows, $tab, $dari, $sampai);
    }

    if ($mode === 'csv') {
        if ($dokter === '') {
            flash('warning', 'Pilih dokter dulu sebelum export CSV.');
            redirect('laporan.php?dari=' . urlencode($dari) . '&sampai=' . urlencode($sampai) . '&tab=' . urlencode($tab));
        }
        $nmDokterTerpilih = nama_dokter_dari_kd($pdo, $dokter);
        $rincian = query_rincian_obat($pdo, $dari, $sampai, $master, $dokter, $obat);
        $rows = query_laporan($pdo, $dari, $sampai, $master, $dokter, $obat);
        export_csv($rincian, $rows, $nmDokterTerpilih, $obat, $dari, $sampai, $tab);
    }

    if ($dokter !== '') {
        $nmDokterTerpilih = nama_dokter_dari_kd($pdo, $dokter);
        $rincian = query_rincian_obat($pdo, $dari, $sampai, $master, $dokter, $obat);
        $rows = query_laporan($pdo, $dari, $sampai, $master, $dokter, $obat);
    }
} catch (Throwable $ex) {
    $error = $ex->getMessage();
}

layout_header('Laporan', 'laporan');
?>
<div class="bg-white rounded-4 shadow-sm p-3">
    <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
        <strong>Laporan Pemakaian Antibiotik</strong>
        <span class="text-muted small ms-auto">Resep (resep_obat) + pemberian (detail_pemberian_obat)</span>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="get" class="row g-2 mb-3 align-items-end">
        <div class="col-auto">
            <label class="form-label small mb-1 fw-semibold">Dari</label>
            <input type="date" name="dari" class="form-control form-control-sm" value="<?= e($dari) ?>" style="width:150px">
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1 fw-semibold">Sampai</label>
            <input type="date" name="sampai" class="form-control form-control-sm" value="<?= e($sampai) ?>" style="width:150px">
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1 fw-semibold">Periode</label>
            <select name="tab" class="form-select form-select-sm">
                <option value="triwulan" <?= $tab === 'triwulan' ? 'selected' : '' ?>>Triwulan</option>
                <option value="semester" <?= $tab === 'semester' ? 'selected' : '' ?>>Semester</option>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1 fw-semibold">Dokter</label>
            <input list="listDokter" name="dokter" class="form-control form-control-sm" placeholder="Ketik / pilih dokter..." value="<?= e($dokter) ?>" style="width:230px">
            <datalist id="listDokter">
                <?php foreach ($dokterList as $dk): ?>
                <option value="<?= e($dk['kd_dokter']) ?>" label="<?= e($dk['nm_dokter']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1 fw-semibold">Obat</label>
            <input list="listObat" name="obat" class="form-control form-control-sm" placeholder="Semua obat..." value="<?= e($obat) ?>" style="width:250px">
            <datalist id="listObat">
                <?php foreach ($obatList as $nb): ?>
                <option value="<?= e($nb) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="col-auto">
            <button class="btn btn-sm btn-primary">Tampilkan</button>
        </div>
        <div class="col-auto">
            <a class="btn btn-sm btn-outline-secondary" href="laporan.php">Reset</a>
        </div>
        <div class="col-auto">
            <a class="btn btn-sm btn-success"
               href="?dari=<?= urlencode($dari) ?>&sampai=<?= urlencode($sampai) ?>&tab=<?= e($tab) ?>&mode=excel">
                Export Excel (semua periode)
            </a>
        </div>
    </form>

    <?php if ($error === null): ?>

    <div class="row g-3">
        <div class="col-lg-<?= $dokter !== '' ? '7' : '12' ?>">
            <div class="card">
                <div class="card-header fw-semibold d-flex align-items-center">
                    Rekapitulasi per Dokter
                    <span class="badge bg-secondary ms-2"><?= count($rekap) ?> dokter</span>
                </div>
                <div class="card-body p-0">
                    <div style="max-height:520px;overflow-y:auto">
                        <table class="table table-sm table-hover align-middle mb-0 table-fixed-header">
                            <thead class="table-light">
                                <tr>
                                    <th>Dokter</th>
                                    <th class="text-center">Jumlah Jenis Antibiotik</th>
                                    <th class="text-center">Jumlah Pasien</th>
                                    <th class="text-center">Jumlah Kunjungan</th>
                                    <th style="width:80px"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rekap as $r):
                                $kd = $r['kd_dokter'] === '' ? DOKTER_TANPA_DPJP : $r['kd_dokter'];
                                $aktif = ($dokter === $kd);
                            ?>
                                <tr class="<?= $aktif ? 'table-primary' : '' ?>">
                                    <td><?= e($r['nm_dokter']) ?></td>
                                    <td class="text-center"><?= (int)$r['jml_jenis_obat'] ?></td>
                                    <td class="text-center"><?= (int)$r['jml_pasien'] ?></td>
                                    <td class="text-center"><?= (int)$r['jml_kunjungan'] ?></td>
                                    <td>
                                        <a class="btn btn-sm btn-outline-primary py-0"
                                           href="?dari=<?= urlencode($dari) ?>&sampai=<?= urlencode($sampai) ?>&tab=<?= e($tab) ?>&dokter=<?= urlencode($kd) ?>">
                                            Lihat
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$rekap): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">Tidak ada data untuk periode ini</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($dokter !== ''): ?>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header fw-semibold d-flex align-items-center">
                    Rincian: <?= e($nmDokterTerpilih) ?>
                    <?php if ($obat !== ''): ?>
                        <span class="badge bg-info ms-2">Obat: <?= e($obat) ?></span>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-success ms-auto"
                       href="?dari=<?= urlencode($dari) ?>&sampai=<?= urlencode($sampai) ?>&tab=<?= e($tab) ?>&dokter=<?= urlencode($dokter) ?>&obat=<?= urlencode($obat) ?>&mode=csv">
                        Export CSV
                    </a>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Obat</th>
                                <th class="text-center">Pasien</th>
                                <th class="text-center">Kunjungan</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rincian as $r): ?>
                            <tr>
                                <td><?= e($r['nama_brng']) ?></td>
                                <td class="text-center"><?= (int)$r['jml_pasien'] ?></td>
                                <td class="text-center"><?= (int)$r['jml_kunjungan'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rincian): ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">Tidak ada data</td></tr>
                        <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th>TOTAL: <?= count($rincian) ?> jenis obat</th>
                                <th class="text-center"><?php
                                    $tp = [];
                                    foreach ($rows as $rr) { $tp[$rr['no_rkm_medis']] = true; }
                                    echo count($tp);
                                ?></th>
                                <th class="text-center"><?php
                                    $tk = [];
                                    foreach ($rows as $rr) { $tk[$rr['no_rawat']] = true; }
                                    echo count($tk);
                                ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($dokter !== ''): ?>
    <div class="mt-3">
        <div class="text-muted small mb-2">
            Detail pasien: <strong><?= count($rows) ?></strong> baris
            (<?= $tab === 'semester' ? 'Semester' : 'Triwulan' ?>)
            untuk periode <strong><?= e(tgl_id($dari)) ?> s.d <?= e(tgl_id($sampai)) ?></strong>
        </div>
        <div style="max-height:640px;overflow-y:auto">
            <table class="table table-sm table-bordered table-hover align-middle mb-0 table-fixed-header">
                <thead class="table-light">
                    <tr>
                        <th style="width:45px">No</th>
                        <th style="width:90px">Tanggal</th>
                        <th>Nama Pasien</th>
                        <th>No.RM</th>
                        <th>Dokter (DPJP)</th>
                        <th>Poli</th>
                        <th>Ruangan Inap</th>
                        <th style="width:70px">Jenis</th>
                        <th>Antibiotik</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $noGlobal = 0;
                foreach (kelompokkan($rows, $tab) as $label => $groupRows):
                    $noPeriode = 0;
                ?>
                    <tr class="periode-header">
                        <td colspan="9"><?= e($label) ?> — <?= count($groupRows) ?> data</td>
                    </tr>
                    <?php foreach ($groupRows as $r):
                        $noGlobal++;
                        $noPeriode++;
                    ?>
                    <tr>
                        <td class="text-center"><?= $noPeriode ?></td>
                        <td><?= e(tgl_id($r['tgl'])) ?></td>
                        <td><?= e($r['nm_pasien']) ?></td>
                        <td><?= e($r['no_rkm_medis']) ?></td>
                        <td><?= e($r['dpjp'] ?: '-') ?></td>
                        <td><?= e($r['nm_poli'] ?: '-') ?></td>
                        <td><?= e($r['ruangan']) ?></td>
                        <td><span class="badge bg-<?= $r['jenis'] === 'ranap' ? 'info' : 'secondary' ?>"><?= $r['jenis'] === 'ranap' ? 'Ranap' : 'Ralan' ?></span></td>
                        <td><?= e($r['antibiotik']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">Tidak ada data</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php elseif (!$rekap): ?>
    <div class="alert alert-info">Tidak ada data untuk periode ini.</div>
    <?php else: ?>
    <div class="alert alert-light border small">Klik tombol <strong>Lihat</strong> pada tabel rekapitulasi untuk melihat rincian per obat, detail pasien, dan export CSV per dokter.</div>
    <?php endif; ?>

    <?php endif; ?>
</div>
<?php layout_footer();
