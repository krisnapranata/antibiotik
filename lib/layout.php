<?php
// lib/layout.php — header/footer HTML bersama
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$sn = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
$BASE_URL = rtrim(preg_replace('#(/pages/.*|/index\.php)$#', '', $sn), '/') ?: '';

function layout_header(string $judul, string $aktif = ''): void
{
    global $BASE_URL;
    $flash = get_flash();
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($judul) ?> — Aplikasi Antibiotik</title>
    <link rel="stylesheet" href="<?= e($BASE_URL) ?>/assets/css/bootstrap.min.css">
    <style>
        body { background: #f1f5f9; }
        .table-fixed-header thead th { position: sticky; top: 0; background: #e2e8f0; z-index: 1; }
        .periode-header td { background: #0d6efd; color: #fff; font-weight: 600; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="<?= e($BASE_URL) ?>/index.php">
            &#128138; Antibiotik
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav">
                <li class="nav-item"><a class="nav-link <?= $aktif === 'beranda' ? 'active' : '' ?>" href="<?= e($BASE_URL) ?>/index.php">Beranda</a></li>
                <li class="nav-item"><a class="nav-link <?= $aktif === 'master' ? 'active' : '' ?>" href="<?= e($BASE_URL) ?>/pages/master.php">Master Antibiotik</a></li>
                <li class="nav-item"><a class="nav-link <?= $aktif === 'laporan' ? 'active' : '' ?>" href="<?= e($BASE_URL) ?>/pages/laporan.php">Laporan</a></li>
                <li class="nav-item"><a class="nav-link <?= $aktif === 'pengaturan' ? 'active' : '' ?>" href="<?= e($BASE_URL) ?>/pages/pengaturan.php">Pengaturan</a></li>
            </ul>
        </div>
    </div>
</nav>
<main class="container-fluid py-3 px-3 px-lg-4">
    <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['tipe']) ?> alert-dismissible fade show" role="alert">
        <?= e($flash['pesan']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php
}

function layout_footer(): void
{
    global $BASE_URL;
    ?>
</main>
<footer class="text-center text-muted small py-3">
    Aplikasi Antibiotik — cocok untuk semua rumah sakit
</footer>
<script src="<?= e($BASE_URL) ?>/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
}
