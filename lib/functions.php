<?php
// lib/functions.php — helper inti aplikasi Antibiotik
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('APP_ROOT', dirname(__DIR__));
define('CONFIG_FILE', APP_ROOT . '/config.json');
define('MASTER_FILE', APP_ROOT . '/master.json');

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function load_json(string $file, array $default = []): array
{
    if (!is_file($file)) {
        return $default;
    }
    $raw = file_get_contents($file);
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : $default;
}

function save_json(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents($file, $json . PHP_EOL, LOCK_EX) !== false;
}

function get_config(): array
{
    $default = [
        'db_host' => '127.0.0.1',
        'db_port' => '3306',
        'db_user' => 'root',
        'db_pass' => '',
        'db_name' => '',
    ];
    $cfg = load_json(CONFIG_FILE, $default);
    foreach ($default as $k => $v) {
        if (!isset($cfg[$k])) {
            $cfg[$k] = $v;
        }
    }
    return $cfg;
}

function db_connect(?array $cfg = null): PDO
{
    $cfg = $cfg ?? get_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $cfg['db_host'] ?? '127.0.0.1',
        $cfg['db_port'] ?? '3306',
        $cfg['db_name'] ?? ''
    );
    return new PDO(
        $dsn,
        $cfg['db_user'] ?? 'root',
        $cfg['db_pass'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, SESSION sql_mode=''",
        ]
    );
}

function get_master(): array
{
    $default = [
        'items' => [
            ['id' => 1, 'nama' => 'azitrom', 'tipe' => 'include', 'aktif' => true],
            ['id' => 2, 'nama' => 'amoxicillin', 'tipe' => 'include', 'aktif' => true],
            ['id' => 3, 'nama' => 'amoxil', 'tipe' => 'include', 'aktif' => true],
            ['id' => 4, 'nama' => 'aclam', 'tipe' => 'include', 'aktif' => true],
            ['id' => 5, 'nama' => 'cefixime', 'tipe' => 'include', 'aktif' => true],
            ['id' => 6, 'nama' => 'ciprofloxacin', 'tipe' => 'include', 'aktif' => true],
            ['id' => 7, 'nama' => 'cefadroxil', 'tipe' => 'include', 'aktif' => true],
            ['id' => 8, 'nama' => 'clindamycin', 'tipe' => 'include', 'aktif' => true],
            ['id' => 9, 'nama' => 'metronidazole', 'tipe' => 'include', 'aktif' => true],
            ['id' => 10, 'nama' => 'gentam', 'tipe' => 'include', 'aktif' => true],
            ['id' => 11, 'nama' => 'ceftriaxone', 'tipe' => 'include', 'aktif' => true],
            ['id' => 12, 'nama' => 'ceftazidime', 'tipe' => 'include', 'aktif' => true],
            ['id' => 13, 'nama' => 'cefoperazone', 'tipe' => 'include', 'aktif' => true],
            ['id' => 14, 'nama' => 'levofloxacin', 'tipe' => 'include', 'aktif' => true],
            ['id' => 15, 'nama' => 'ampicillin', 'tipe' => 'include', 'aktif' => true],
            ['id' => 16, 'nama' => 'meropenem', 'tipe' => 'include', 'aktif' => true],
            ['id' => 101, 'nama' => 'glibenclamide', 'tipe' => 'exclude', 'aktif' => true],
            ['id' => 102, 'nama' => 'salep', 'tipe' => 'exclude', 'aktif' => true],
        ],
    ];
    $master = load_json(MASTER_FILE, $default);
    if (!isset($master['items'])) {
        $master['items'] = [];
    }
    return $master;
}

function save_master(array $master): bool
{
    return save_json(MASTER_FILE, $master);
}

function next_master_id(array $master): int
{
    $max = 0;
    foreach ($master['items'] as $it) {
        $max = max($max, (int)($it['id'] ?? 0));
    }
    return $max + 1;
}

/**
 * Bangun klausa WHERE untuk kolom alias `b.nama_brng` (tabel databarang)
 * berdasarkan master list (include = harus cocok salah satu,
 * exclude = wajib tidak cocok). Return [sqlFragment, params].
 */
function build_antibiotik_filter(array $master): array
{
    $inc = [];
    $exc = [];
    foreach ($master['items'] as $it) {
        if (empty($it['aktif'])) {
            continue;
        }
        $nama = strtolower(trim((string)($it['nama'] ?? '')));
        if ($nama === '') {
            continue;
        }
        if (($it['tipe'] ?? 'include') === 'exclude') {
            $exc[] = $nama;
        } else {
            $inc[] = $nama;
        }
    }

    $parts = [];
    $params = [];
    if ($inc) {
        $or = [];
        foreach ($inc as $kw) {
            $or[] = "LOWER(b.nama_brng) LIKE ?";
            $params[] = '%' . $kw . '%';
        }
        $parts[] = '(' . implode(' OR ', $or) . ')';
    }
    foreach ($exc as $kw) {
        $parts[] = "LOWER(b.nama_brng) NOT LIKE ?";
        $params[] = '%' . $kw . '%';
    }
    if (!$parts) {
        return ['1=0', $params];
    }
    return [implode(' AND ', $parts), $params];
}

function periode_label(string $tgl, string $mode): string
{
    $ts = strtotime($tgl);
    if ($ts === false) {
        return '-';
    }
    $y = (int)date('Y', $ts);
    $m = (int)date('n', $ts);
    if ($mode === 'semester') {
        return $m <= 6 ? "S1 {$y}" : "S2 {$y}";
    }
    $q = (int)ceil($m / 3);
    return "Q{$q} {$y}";
}

function akhir_bulan_sekarang(): string
{
    return date('Y-m-t');
}

function tgl_id(?string $tgl): string
{
    if (!$tgl || $tgl === '0000-00-00') {
        return '-';
    }
    $ts = strtotime($tgl);
    return $ts === false ? $tgl : date('d-m-Y', $ts);
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $tipe, string $pesan): void
{
    $_SESSION['flash'] = ['tipe' => $tipe, 'pesan' => $pesan];
}

function get_flash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}
