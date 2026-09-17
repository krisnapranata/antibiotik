<?php
// pages/master.php — kelola daftar nama antibiotik (include/exclude)
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/layout.php';

$master = get_master();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string)($_POST['aksi'] ?? '');

    if ($aksi === 'tambah') {
        $nama = strtolower(trim((string)($_POST['nama'] ?? '')));
        $tipe = ($_POST['tipe'] ?? 'include') === 'exclude' ? 'exclude' : 'include';
        if ($nama === '') {
            flash('danger', 'Nama obat tidak boleh kosong.');
        } else {
            $duplikat = false;
            foreach ($master['items'] as $it) {
                if (strtolower((string)$it['nama']) === $nama && $it['tipe'] === $tipe) {
                    $duplikat = true;
                    break;
                }
            }
            if ($duplikat) {
                flash('warning', "Entri \"{$nama}\" sudah ada.");
            } else {
                $master['items'][] = ['id' => next_master_id($master), 'nama' => $nama, 'tipe' => $tipe, 'aktif' => true];
                save_master($master);
                flash('success', "Entri \"{$nama}\" ditambahkan.");
            }
        }
        redirect($_SERVER['PHP_SELF']);
    }

    if ($aksi === 'simpan') {
        $id = (int)($_POST['id'] ?? 0);
        $nama = strtolower(trim((string)($_POST['nama'] ?? '')));
        $tipe = ($_POST['tipe'] ?? 'include') === 'exclude' ? 'exclude' : 'include';
        $aktif = !empty($_POST['aktif']);
        if ($nama === '') {
            flash('danger', 'Nama obat tidak boleh kosong.');
        } else {
            foreach ($master['items'] as &$it) {
                if ((int)$it['id'] === $id) {
                    $it['nama'] = $nama;
                    $it['tipe'] = $tipe;
                    $it['aktif'] = $aktif;
                    break;
                }
            }
            unset($it);
            save_master($master);
            flash('success', "Entri #{$id} disimpan.");
        }
        redirect($_SERVER['PHP_SELF']);
    }

    if ($aksi === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        $master['items'] = array_values(array_filter($master['items'], fn($it) => (int)$it['id'] !== $id));
        save_master($master);
        flash('success', "Entri #{$id} dihapus.");
        redirect($_SERVER['PHP_SELF']);
    }
}

layout_header('Master Antibiotik', 'master');
?>
<div class="bg-white rounded-4 shadow-sm p-3">
    <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
        <strong>Master Daftar Nama Antibiotik</strong>
        <span class="text-muted small ms-auto">Pencarian memakai pola LIKE %nama% terhadap nama_brng di databarang</span>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header fw-semibold">Tambah Entri</div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="aksi" value="tambah">
                        <div class="mb-2">
                            <label class="form-label small fw-semibold mb-1">Nama / kata kunci obat</label>
                            <input type="text" name="nama" class="form-control form-control-sm"
                                   placeholder="mis. amoxicillin, cefixime..." required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold mb-1">Tipe</label>
                            <select name="tipe" class="form-select form-select-sm">
                                <option value="include">Include (dihitung antibiotik)</option>
                                <option value="exclude">Exclude (pengecualian, mis. salep)</option>
                            </select>
                        </div>
                        <button class="btn btn-sm btn-primary w-100">Tambah</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card">
                <div class="card-header fw-semibold">Daftar Entri
                    <span class="badge bg-secondary"><?= count($master['items']) ?></span>
                </div>
                <div class="card-body p-0">
                    <div style="max-height:480px;overflow-y:auto">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>#</th>
                                    <th>Nama / Kata Kunci</th>
                                    <th>Tipe</th>
                                    <th>Aktif</th>
                                    <th style="width:220px">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($master['items'] as $it): ?>
                                <tr class="<?= empty($it['aktif']) ? 'opacity-50' : '' ?>">
                                    <form method="post">
                                        <input type="hidden" name="aksi" value="simpan">
                                        <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                        <td><?= (int)$it['id'] ?></td>
                                        <td><input type="text" name="nama" class="form-control form-control-sm"
                                                   value="<?= e((string)$it['nama']) ?>" style="min-width:160px"></td>
                                        <td>
                                            <select name="tipe" class="form-select form-select-sm">
                                                <option value="include" <?= $it['tipe'] !== 'exclude' ? 'selected' : '' ?>>Include</option>
                                                <option value="exclude" <?= $it['tipe'] === 'exclude' ? 'selected' : '' ?>>Exclude</option>
                                            </select>
                                        </td>
                                        <td><input type="checkbox" name="aktif" class="form-check-input" <?= !empty($it['aktif']) ? 'checked' : '' ?>></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary">Simpan</button>
                                    </form>
                                    <form method="post" class="d-inline"
                                          onsubmit="return confirm('Hapus entri #<?= (int)$it['id'] ?>?');">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Hapus</button>
                                    </form>
                                        </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$master['items']): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">Belum ada entri</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php layout_footer();
