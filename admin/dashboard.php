
<?php
session_start();

if (
    !isset($_SESSION['id_user'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../koneksi.php';

if (!isset($koneksi) || !($koneksi instanceof mysqli)) {
    die('Koneksi mysqli $koneksi tidak ditemukan di koneksi.php.');
}

mysqli_set_charset($koneksi, 'utf8mb4');

$namaAdmin = $_SESSION['nama'] ?? 'Admin';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rows(mysqli $db, string $sql): array
{
    $result = $db->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function projectRoot(): string
{
    return dirname(__DIR__);
}

function projectUrl(): string
{
    $script = str_replace(
        '\\',
        '/',
        $_SERVER['SCRIPT_NAME'] ?? '/admin/dashboard.php'
    );

    return rtrim(dirname(dirname($script)), '/');
}

/*
 * Mencari gambar kebaya dan bukti pembayaran.
 * Lokasi bukti pembayaran:
 * penyewa/bukti_pembayaran/
 */
function imageUrl($value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    // Data URI gambar.
    if (preg_match(
        '~^data:image/(jpeg|jpg|png|gif|webp);base64,~i',
        $value
    )) {
        return $value;
    }

    // URL gambar eksternal.
    if (filter_var($value, FILTER_VALIDATE_URL)) {
        $scheme = strtolower(
            (string) parse_url($value, PHP_URL_SCHEME)
        );

        return in_array($scheme, ['http', 'https'], true)
            ? $value
            : '';
    }

    $root = realpath(projectRoot());

    if ($root === false) {
        return '';
    }

    $root = str_replace('\\', '/', $root);

    // Bersihkan path dari database.
    $normalized = str_replace('\\', '/', $value);
    $normalized = preg_replace('#^\./+#', '', $normalized);
    $normalized = ltrim($normalized, '/');

    // Jangan izinkan path keluar dari folder project.
    if (in_array('..', explode('/', $normalized), true)) {
        return '';
    }

    /*
     * Jika nilai database berupa Base64 mentah,
     * cek apakah isinya benar-benar gambar.
     */
    if (
        strlen($normalized) > 200 &&
        preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $normalized)
    ) {
        $decoded = base64_decode($normalized, true);

        if ($decoded !== false && $decoded !== '') {
            $info = @getimagesizefromstring($decoded);

            $allowed = [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp'
            ];

            if (
                $info !== false &&
                in_array($info['mime'] ?? '', $allowed, true)
            ) {
                return 'data:' . $info['mime'] .
                    ';base64,' . base64_encode($decoded);
            }
        }
    }

    $filename = basename($normalized);

    /*
     * Cari file menggunakan beberapa kemungkinan path.
     * Termasuk folder penyewa/bukti_pembayaran.
     */
    $paths = [
        $normalized,
        'penyewa/' . $normalized,
        'penyewa/bukti_pembayaran/' . $filename,
        'bukti_pembayaran/' . $filename,
        'uploads/bukti_pembayaran/' . $filename,
        'uploads/' . $filename,
        'admin/bukti_pembayaran/' . $filename
    ];

    foreach (array_unique($paths) as $relativePath) {
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = ltrim($relativePath, '/');

        if (in_array('..', explode('/', $relativePath), true)) {
            continue;
        }

        $realFile = realpath($root . '/' . $relativePath);

        // Pastikan file benar-benar ada di dalam project.
        if (
            $realFile === false ||
            !is_file($realFile)
        ) {
            continue;
        }

        $realFile = str_replace('\\', '/', $realFile);

        if (strpos($realFile, $root . '/') !== 0) {
            continue;
        }

        // Pastikan file adalah gambar yang didukung.
        $info = @getimagesize($realFile);

        $allowed = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp'
        ];

        if (
            $info === false ||
            !in_array($info['mime'] ?? '', $allowed, true)
        ) {
            continue;
        }

        // Buat URL berdasarkan lokasi file sebenarnya.
        $actualRelativePath = substr(
            $realFile,
            strlen($root) + 1
        );

        $encodedPath = implode(
            '/',
            array_map(
                'rawurlencode',
                explode('/', $actualRelativePath)
            )
        );

        return projectUrl() . '/' . $encodedPath;
    }

    return '';
}

function countTable(mysqli $db, string $table): int
{
    $allowed = [
        'users',
        'kebaya',
        'kategori_kebaya',
        'penyewaan',
        'pembayaran'
    ];

    if (!in_array($table, $allowed, true)) {
        return 0;
    }

    $result = $db->query(
        "SELECT COUNT(*) AS total FROM `$table`"
    );

    return $result
        ? (int) $result->fetch_assoc()['total']
        : 0;
}

// CSRF.
if (empty($_SESSION['csrf_admin'])) {
    $_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_admin'];
$tab = (string) ($_GET['tab'] ?? 'dashboard');

$allowedTabs = [
    'dashboard',
    'kebaya',
    'kategori',
    'pembayaran',
    'penyewaan',
    'users',
    'fitting',
    'pengembalian',
    'log'
];

if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'dashboard';
}

$error = '';

/*
 * PROSES AKSI ADMIN
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Token tidak valid. Muat ulang halaman.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        try {
            if ($action === 'verifikasi' || $action === 'tolak') {
                $id = (int) ($_POST['id_pembayaran'] ?? 0);

                $status = $action === 'verifikasi'
                    ? 'diverifikasi'
                    : 'ditolak';

                $catatan = trim(
                    (string) ($_POST['catatan_petugas'] ?? '')
                );

                $stmt = $koneksi->prepare(
                    'UPDATE pembayaran
                     SET status = ?, catatan_petugas = ?
                     WHERE id_pembayaran = ?'
                );

                $stmt->bind_param(
                    'ssi',
                    $status,
                    $catatan,
                    $id
                );

                $stmt->execute();
                $stmt->close();

                header('Location: dashboard.php?tab=pembayaran');
                exit;
            }

            if ($action === 'ubah_status_user') {
                $id = (int) ($_POST['id_user'] ?? 0);
                $status = (string) ($_POST['status'] ?? '');

                if (!in_array($status, ['aktif', 'nonaktif'], true)) {
                    throw new RuntimeException(
                        'Status pengguna tidak valid.'
                    );
                }

                if ($id === (int) $_SESSION['id_user']) {
                    throw new RuntimeException(
                        'Tidak bisa menonaktifkan akun sendiri.'
                    );
                }

                $stmt = $koneksi->prepare(
                    'UPDATE users SET status = ? WHERE id_user = ?'
                );

                $stmt->bind_param('si', $status, $id);
                $stmt->execute();
                $stmt->close();

                header('Location: dashboard.php?tab=users');
                exit;
            }
        } catch (Throwable $ex) {
            $error = 'Aksi gagal: ' . $ex->getMessage();
        }
    }
}

/*
 * DATA DATABASE
 */
$totalKebaya = countTable($koneksi, 'kebaya');
$totalKategori = countTable($koneksi, 'kategori_kebaya');
$totalPenyewaan = countTable($koneksi, 'penyewaan');
$totalUsers = countTable($koneksi, 'users');

$pendingResult = $koneksi->query(
    "SELECT COUNT(*) AS total
     FROM pembayaran
     WHERE status = 'menunggu'"
);

$totalPending = $pendingResult
    ? (int) $pendingResult->fetch_assoc()['total']
    : 0;

$kategori = rows(
    $koneksi,
    'SELECT * FROM kategori_kebaya ORDER BY id_kategori DESC'
);

$kebaya = rows(
    $koneksi,
    'SELECT k.*, c.nama_kategori
     FROM kebaya k
     LEFT JOIN kategori_kebaya c
        ON c.id_kategori = k.id_kategori
     ORDER BY k.id_kebaya DESC'
);

$pembayaran = rows(
    $koneksi,
    'SELECT p.*, s.id_penyewaan
     FROM pembayaran p
     LEFT JOIN penyewaan s
        ON s.id_penyewaan = p.id_penyewaan
     ORDER BY p.id_pembayaran DESC'
);

$users = rows(
    $koneksi,
    'SELECT id_user, nama, username, role, no_hp, alamat, status, created_at
     FROM users
     ORDER BY id_user DESC'
);

$penyewaan = rows(
    $koneksi,
    'SELECT * FROM penyewaan ORDER BY id_penyewaan DESC'
);

$fitting = rows(
    $koneksi,
    'SELECT * FROM janji_fitting ORDER BY 1 DESC'
);

$pengembalian = rows(
    $koneksi,
    'SELECT * FROM pengembalian ORDER BY 1 DESC'
);

$log = rows(
    $koneksi,
    'SELECT l.*, u.nama, u.username
     FROM log_aktivitas l
     LEFT JOIN users u ON u.id_user = l.id_user
     ORDER BY l.waktu DESC
     LIMIT 200'
);

$titles = [
    'dashboard' => 'Dashboard Admin',
    'kebaya' => 'Data Kebaya',
    'kategori' => 'Kategori Kebaya',
    'pembayaran' => 'Verifikasi Pembayaran',
    'penyewaan' => 'Data Penyewaan',
    'users' => 'Kelola Pengguna',
    'fitting' => 'Janji Fitting',
    'pengembalian' => 'Data Pengembalian',
    'log' => 'Log Aktivitas'
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titles[$tab]) ?> | KebayaRent</title>

<style>
:root {
    --purple: #783b70;
    --dark: #332238;
    --bg: #f7f4f8;
    --line: #e8dfea;
    --muted: #786d7b;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    background: var(--bg);
    color: var(--dark);
    font-family: Arial, sans-serif;
}
a { color: inherit; text-decoration: none; }
.layout { display: flex; min-height: 100vh; }
.sidebar {
    width: 235px;
    flex-shrink: 0;
    background: var(--dark);
    color: white;
    padding: 24px 14px;
}
.brand {
    font-size: 23px;
    font-weight: 800;
    padding: 0 10px 22px;
}
.brand span { color: #e9b8df; }
.nav a {
    display: block;
    padding: 12px;
    margin: 4px 0;
    border-radius: 8px;
    color: #eee4f0;
    font-size: 14px;
}
.nav a:hover, .nav a.active { background: var(--purple); }
.main { flex: 1; min-width: 0; }
.topbar {
    background: white;
    border-bottom: 1px solid var(--line);
    padding: 20px 26px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
}
.topbar h1 { margin: 0 0 5px; font-size: 22px; }
.muted { color: var(--muted); font-size: 12px; }
.logout, .btn {
    display: inline-block;
    border: 0;
    padding: 10px 13px;
    border-radius: 8px;
    background: var(--purple);
    color: white;
    cursor: pointer;
    font-weight: 700;
    font-size: 12px;
}
.logout { background: #f6eaf4; color: var(--purple); }
.btn-red { background: #a63343; }
.btn-soft { background: #f6eaf4; color: var(--purple); }
.content { padding: 25px; }
.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}
.stat, .panel {
    background: white;
    border: 1px solid var(--line);
    border-radius: 13px;
    padding: 19px;
}
.stat-label { color: var(--muted); font-size: 13px; }
.stat-value { font-size: 28px; font-weight: 800; margin-top: 10px; }
.panel { margin-bottom: 20px; }
.panel h2 { font-size: 17px; margin: 0 0 16px; }
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td {
    padding: 12px 10px;
    text-align: left;
    border-bottom: 1px solid var(--line);
    vertical-align: middle;
}
th { color: var(--muted); background: #fcfafd; }
.thumb {
    width: 100px;
    height: 120px;
    object-fit: contain;
    background: white;
    border: 1px solid var(--line);
    border-radius: 8px;
    display: block;
}
.badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    background: #f0eaf2;
    color: var(--purple);
    font-size: 11px;
}
form { margin: 0; }
textarea, select, input {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--line);
    border-radius: 7px;
    margin: 4px 0 8px;
    font: inherit;
}
textarea { min-height: 55px; }
.notice {
    background: #fbe9eb;
    color: #a63343;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 15px;
}
.empty { padding: 20px; text-align: center; color: var(--muted); }
.actions { display: flex; flex-wrap: wrap; gap: 6px; }
.proof-link { display: inline-block; }
.proof-missing {
    color: #a63343;
    font-size: 12px;
    max-width: 190px;
    overflow-wrap: anywhere;
}
@media(max-width: 950px) {
    .stats { grid-template-columns: repeat(2, 1fr); }
}
@media(max-width: 680px) {
    .layout { display: block; }
    .sidebar { width: 100%; }
    .nav { display: grid; grid-template-columns: repeat(2, 1fr); }
    .content { padding: 14px; }
    .topbar { padding: 16px; }
    .stats { gap: 9px; }
}
</style>
</head>

<body>
<div class="layout">

<aside class="sidebar">
    <div class="brand">Kebaya<span>Rent</span></div>
    <nav class="nav">
        <?php foreach ($titles as $key => $title): ?>
            <a
                class="<?= $tab === $key ? 'active' : '' ?>"
                href="dashboard.php?tab=<?= e($key) ?>"
            ><?= e($title) ?></a>
        <?php endforeach; ?>
    </nav>
</aside>

<main class="main">

<header class="topbar">
    <div>
        <h1><?= e($titles[$tab]) ?></h1>
        <div class="muted">
            Login sebagai admin: <?= e($namaAdmin) ?>
        </div>
    </div>
    <a class="logout" href="../logout.php"
       onclick="return confirm('Yakin ingin logout?')">Logout</a>
</header>

<section class="content">

<?php if ($error !== ''): ?>
    <div class="notice"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($tab === 'dashboard'): ?>

    <div class="stats">
        <div class="stat">
            <div class="stat-label">Total Kebaya</div>
            <div class="stat-value"><?= $totalKebaya ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Kategori</div>
            <div class="stat-value"><?= $totalKategori ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Penyewaan</div>
            <div class="stat-value"><?= $totalPenyewaan ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Akun Pengguna</div>
            <div class="stat-value"><?= $totalUsers ?></div>
        </div>
    </div>

    <div class="panel">
        <h2>Pembayaran Menunggu</h2>
        <div class="stat-value"><?= $totalPending ?></div>
        <p class="muted">Pembayaran yang belum diperiksa admin.</p>
        <a class="btn" href="dashboard.php?tab=pembayaran">
            Periksa pembayaran
        </a>
    </div>

<?php elseif ($tab === 'pembayaran'): ?>

    <div class="panel">
        <h2>Daftar Pembayaran dan Bukti Transfer</h2>

        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>ID Penyewaan</th>
                <th>Metode</th>
                <th>Jumlah</th>
                <th>Bukti Pembayaran</th>
                <th>Status</th>
                <th>Tindakan</th>
            </tr>
            </thead>
            <tbody>

            <?php foreach ($pembayaran as $p): ?>
                <?php
                $bukti = trim(
                    (string) ($p['bukti_pembayaran'] ?? '')
                );
                $buktiSrc = imageUrl($bukti);
                ?>
                <tr>
                    <td><?= (int) $p['id_pembayaran'] ?></td>
                    <td><?= e($p['id_penyewaan'] ?? '-') ?></td>
                    <td><?= e($p['metode'] ?? '-') ?></td>
                    <td>
                        Rp <?= number_format(
                            (float) ($p['jumlah_bayar'] ?? 0),
                            0,
                            ',',
                            '.'
                        ) ?>
                    </td>
                    <td>
                        <?php if ($buktiSrc !== ''): ?>
                            <a
                                class="proof-link"
                                href="<?= e($buktiSrc) ?>"
                                target="_blank"
                                rel="noopener"
                            >
                                <img
                                    class="thumb"
                                    src="<?= e($buktiSrc) ?>"
                                    alt="Bukti pembayaran"
                                    loading="lazy"
                                >
                            </a>
                            <div class="muted">
                                Klik foto untuk memperbesar
                            </div>
                        <?php elseif ($bukti !== ''): ?>
                            <div class="proof-missing">
                                File belum ditemukan atau bukan gambar.
                                <br>
                                <small><?= e(basename($bukti)) ?></small>
                            </div>
                        <?php else: ?>
                            <span class="muted">
                                Belum ada bukti pembayaran
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge">
                            <?= e($p['status'] ?? '-') ?>
                        </span>
                    </td>
                    <td>
                        <?php if (($p['status'] ?? '') === 'menunggu'): ?>
                            <form method="post">
                                <input type="hidden" name="csrf"
                                       value="<?= e($csrf) ?>">
                                <input type="hidden" name="id_pembayaran"
                                       value="<?= (int) $p['id_pembayaran'] ?>">

                                <textarea name="catatan_petugas"
                                    placeholder="Catatan admin (opsional)"></textarea>

                                <div class="actions">
                                    <button class="btn"
                                            name="action"
                                            value="verifikasi">
                                        Verifikasi
                                    </button>
                                    <button class="btn btn-red"
                                            name="action"
                                            value="tolak"
                                            onclick="return confirm('Tolak pembayaran ini?')">
                                        Tolak
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <span class="muted">Sudah diproses</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$pembayaran): ?>
                <tr>
                    <td colspan="7" class="empty">
                        Belum ada pembayaran.
                    </td>
                </tr>
            <?php endif; ?>

            </tbody>
        </table>
        </div>
    </div>

<?php elseif ($tab === 'kebaya'): ?>

    <div class="panel">
        <h2>Data Kebaya</h2>
        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Foto</th>
                <th>Nama</th>
                <th>Kategori</th>
                <th>Harga</th>
                <th>Stok</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($kebaya as $item): ?>
                <?php $fotoSrc = imageUrl($item['foto'] ?? ''); ?>
                <tr>
                    <td><?= e($item['id_kebaya'] ?? '-') ?></td>
                    <td>
                        <?php if ($fotoSrc !== ''): ?>
                            <img class="thumb"
                                 src="<?= e($fotoSrc) ?>"
                                 alt="Foto kebaya">
                        <?php else: ?>
                            <span class="muted">Foto tidak ditemukan</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($item['nama_kebaya'] ?? '-') ?></td>
                    <td><?= e($item['nama_kategori'] ?? '-') ?></td>
                    <td>
                        Rp <?= number_format(
                            (float) ($item['harga_sewa'] ?? 0),
                            0,
                            ',',
                            '.'
                        ) ?>
                    </td>
                    <td><?= e($item['stok'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$kebaya): ?>
                <tr>
                    <td colspan="6" class="empty">Belum ada data kebaya.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

<?php elseif ($tab === 'kategori'): ?>

    <div class="panel">
        <h2>Daftar Kategori</h2>
        <div class="table-wrap">
        <table>
            <thead>
                <tr><th>ID</th><th>Nama Kategori</th></tr>
            </thead>
            <tbody>
            <?php foreach ($kategori as $k): ?>
                <tr>
                    <td><?= e($k['id_kategori'] ?? '-') ?></td>
                    <td><?= e($k['nama_kategori'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$kategori): ?>
                <tr>
                    <td colspan="2" class="empty">Belum ada kategori.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

<?php elseif ($tab === 'users'): ?>

    <div class="panel">
        <h2>Kelola Status Akun</h2>
        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>ID</th><th>Nama</th><th>Username</th>
                <th>Role</th><th>Status</th><th>Aksi</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= (int) $u['id_user'] ?></td>
                    <td><?= e($u['nama']) ?></td>
                    <td><?= e($u['username']) ?></td>
                    <td><?= e($u['role']) ?></td>
                    <td><?= e($u['status'] ?? '-') ?></td>
                    <td>
                        <?php if (
                            (int) $u['id_user'] !==
                            (int) $_SESSION['id_user']
                        ): ?>
                            <form method="post">
                                <input type="hidden" name="csrf"
                                       value="<?= e($csrf) ?>">
                                <input type="hidden" name="action"
                                       value="ubah_status_user">
                                <input type="hidden" name="id_user"
                                       value="<?= (int) $u['id_user'] ?>">

                                <select name="status">
                                    <option value="aktif"
                                        <?= $u['status'] === 'aktif'
                                            ? 'selected' : '' ?>>
                                        Aktif
                                    </option>
                                    <option value="nonaktif"
                                        <?= $u['status'] === 'nonaktif'
                                            ? 'selected' : '' ?>>
                                        Nonaktif
                                    </option>
                                </select>
                                <button class="btn btn-soft">Simpan</button>
                            </form>
                        <?php else: ?>
                            <span class="muted">Akun saat ini</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

<?php else: ?>

    <?php
    $datasets = [
        'penyewaan' => $penyewaan,
        'fitting' => $fitting,
        'pengembalian' => $pengembalian,
        'log' => $log
    ];

    $dataset = $datasets[$tab] ?? [];
    ?>

    <div class="panel">
        <h2><?= e($titles[$tab]) ?></h2>
        <div class="table-wrap">
        <table>
            <?php if ($dataset): ?>
                <thead>
                <tr>
                    <?php foreach (array_keys($dataset[0]) as $column): ?>
                        <th><?= e($column) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($dataset as $row): ?>
                    <tr>
                    <?php foreach ($row as $value): ?>
                        <td><?= e($value ?? '-') ?></td>
                    <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            <?php else: ?>
                <tbody>
                    <tr>
                        <td class="empty">Belum ada data.</td>
                    </tr>
                </tbody>
            <?php endif; ?>
        </table>
        </div>
    </div>

<?php endif; ?>

</section>
</main>
</div>
</body>
</html>
