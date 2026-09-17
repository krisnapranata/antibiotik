<?php
// pages/laporan.php — laporan pasien pemakai antibiotik (triwulan / semester) + export Excel
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/layout.php';

function valid_tgl(string $t): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) && strtotime($t) !== false;
}

function query_laporan(PDO $pdo, string $dari, string $sampai, array $master): array
{
    [$filterSql, $fParams] = build_antibiotik_filter($master);

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
            WHERE r.tgl_peresepan BETWEEN ? AND ? AND {$filterSql}
            UNION ALL
            SELECT p.no_rawat, p.tgl_perawatan AS tgl, LOWER(p.status) AS jenis, b.nama_brng
            FROM detail_pemberian_obat p
            JOIN databarang b ON b.kode_brng = p.kode_brng
            WHERE p.tgl_perawatan BETWEEN ? AND ? AND {$filterSql}
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
        GROUP BY x.no_rawat, x.tgl, x.jenis,
                 pa.no_rkm_medis, pa.nm_pasien, dk.nm_dokter, pol.nm_poli, bg.nm_bangsal
        ORDER BY x.tgl DESC, x.no_rawat
    ";

    $params = array_merge(
        [$dari, $sampai],
        $fParams,
        [$dari, $sampai],
        $fParams
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function kelompokkan(array $rows, string $mode): array
{
    $hasil = [];
    foreach ($rows as $r) {
        $label = periode_label($r['tgl'], $mode);
        $hasil[$label][] = $r;
    }
    // urutkan periode: terbaru di atas (tahun + urutan kuartal/semester)
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

function export_excel(array $rows, string $mode, string $dari, string $sampai): never
{
    set_time_limit(600);
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

$dari = (string)($_GET['dari'] ?? '2025-01-01');
$sampai = (string)($_GET['sampai'] ?? akhir_bulan_sekarang());
if (!valid_tgl($dari)) {
    $dari = '2025-01-01';
}
if (!valid_tgl($sampai)) {
    $sampai = akhir_bulan_sekarang();
}
$tab = ($_GET['tab'] ?? 'triwulan') === 'semester' ? 'semester' : 'triwulan';

$error = null;
$rows = [];
try {
    $pdo = db_connect($cfg);
    $master = get_master();
    $rows = query_laporan($pdo, $dari, $sampai, $master);
} catch (Throwable $ex) {
    $error = $ex->getMessage();
}

if (($_GET['mode'] ?? '') === 'excel' && !$error) {
    export_excel($rows, $tab, $dari, $sampai);
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
            <button class="btn btn-sm btn-primary">Tampilkan</button>
        </div>
        <?php if ($rows): ?>
        <div class="col-auto">
            <a class="btn btn-sm btn-success"
               href="?dari=<?= urlencode($dari) ?>&sampai=<?= urlencode($sampai) ?>&tab=<?= e($tab) ?>&mode=excel">
                Export Excel
            </a>
        </div>
        <?php endif; ?>
    </form>

    <?php if ($error === null && $rows): ?>
    <div class="text-muted small mb-2">
        Ditemukan <strong><?= count($rows) ?></strong> baris
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
            </tbody>
        </table>
    </div>
    <?php elseif ($error === null): ?>
    <div class="alert alert-info">Tidak ada data untuk periode ini.</div>
    <?php endif; ?>
</div>
<?php layout_footer();
