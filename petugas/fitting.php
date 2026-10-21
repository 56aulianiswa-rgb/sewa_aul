<?php
session_start();
require_once __DIR__ . '/../koneksi.php';

/* =========================
   LOGIN & LOGOUT PETUGAS
========================= */
if (isset($_GET['aksi']) && $_GET['aksi'] === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: ../login.php#login');
    exit;
}

if (!isset($_SESSION['id_user']) || ($_SESSION['role'] ?? '') !== 'petugas') {
    header('Location: ../login.php#login');
    exit;
}

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
function redirectWithMessage(string $type, string $message): void {
    $_SESSION['flash_fitting'] = ['type' => $type, 'message' => $message];
    header('Location: fitting.php');
    exit;
}

$flash = $_SESSION['flash_fitting'] ?? null;
unset($_SESSION['flash_fitting']);
$error = '';
$editData = null;

/* =========================
   CEK TABEL FITTING
   Struktur yang diasumsikan:
   id_fitting, id_user, id_penyewaan, tanggal_fitting,
   jam_fitting, catatan, status, created_at
========================= */
$tableCheck = mysqli_query($koneksi, "SHOW TABLES LIKE 'janji_fitting'");
if (!$tableCheck || mysqli_num_rows($tableCheck) === 0) {
    $tableReady = false;
} else {
    $tableReady = true;
    $columns = [];
    $columnResult = mysqli_query($koneksi, "SHOW COLUMNS FROM janji_fitting");
    if ($columnResult) {
        while ($column = mysqli_fetch_assoc($columnResult)) {
            $columns[] = $column['Field'];
        }
    }
    $requiredColumns = ['id_fitting', 'id_user', 'id_penyewaan', 'tanggal_fitting', 'jam_fitting', 'catatan', 'status'];
    foreach ($requiredColumns as $columnName) {
        if (!in_array($columnName, $columns, true)) {
            $tableReady = false;
            break;
        }
    }
}

/* =========================
   PROSES TAMBAH / EDIT / HAPUS
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableReady) {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'simpan') {
        $idFitting = (int)($_POST['id_fitting'] ?? 0);
        $idUser = (int)($_POST['id_user'] ?? 0);
        $idPenyewaan = (int)($_POST['id_penyewaan'] ?? 0);
        $tanggal = trim($_POST['tanggal_fitting'] ?? '');
        $jam = trim($_POST['jam_fitting'] ?? '');
        $catatan = trim($_POST['catatan'] ?? '');
        $status = trim($_POST['status'] ?? 'menunggu');

        $allowedStatuses = ['menunggu', 'disetujui', 'ditolak', 'selesai', 'dibatalkan'];
        if ($idUser < 1 || $idPenyewaan < 1 || $tanggal === '' || $jam === '' || !in_array($status, $allowedStatuses, true)) {
            redirectWithMessage('danger', 'Mohon lengkapi data fitting dengan benar.');
        }

        // Pastikan penyewaan memang milik penyewa yang dipilih.
        $checkRental = mysqli_prepare($koneksi, "SELECT id_penyewaan FROM penyewaan WHERE id_penyewaan = ? AND id_user = ? LIMIT 1");
        mysqli_stmt_bind_param($checkRental, 'ii', $idPenyewaan, $idUser);
        mysqli_stmt_execute($checkRental);
        $rentalResult = mysqli_stmt_get_result($checkRental);
        $validRental = $rentalResult && mysqli_num_rows($rentalResult) > 0;
        mysqli_stmt_close($checkRental);

        if (!$validRental) {
            redirectWithMessage('danger', 'Penyewaan tidak ditemukan atau bukan milik penyewa tersebut.');
        }

        if ($idFitting > 0) {
            $stmt = mysqli_prepare($koneksi, "UPDATE janji_fitting SET id_user=?, id_penyewaan=?, tanggal_fitting=?, jam_fitting=?, catatan=?, status=? WHERE id_fitting=?");
            mysqli_stmt_bind_param($stmt, 'iissssi', $idUser, $idPenyewaan, $tanggal, $jam, $catatan, $status, $idFitting);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            redirectWithMessage($ok ? 'success' : 'danger', $ok ? 'Janji fitting berhasil diperbarui.' : 'Gagal memperbarui janji fitting.');
        } else {
            $stmt = mysqli_prepare($koneksi, "INSERT INTO janji_fitting (id_user, id_penyewaan, tanggal_fitting, jam_fitting, catatan, status) VALUES (?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'iissss', $idUser, $idPenyewaan, $tanggal, $jam, $catatan, $status);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            redirectWithMessage($ok ? 'success' : 'danger', $ok ? 'Janji fitting berhasil ditambahkan.' : 'Gagal menambahkan janji fitting. Pastikan struktur tabel sesuai.');
        }
    }

    if ($aksi === 'hapus') {
        $idFitting = (int)($_POST['id_fitting'] ?? 0);
        $stmt = mysqli_prepare($koneksi, "DELETE FROM janji_fitting WHERE id_fitting = ?");
        mysqli_stmt_bind_param($stmt, 'i', $idFitting);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        redirectWithMessage($ok ? 'success' : 'danger', $ok ? 'Janji fitting berhasil dihapus.' : 'Janji fitting gagal dihapus.');
    }
}

/* =========================
   DATA UNTUK FORM EDIT
========================= */
if ($tableReady && isset($_GET['edit'])) {
    $idEdit = (int)$_GET['edit'];
    $stmt = mysqli_prepare($koneksi, "SELECT * FROM janji_fitting WHERE id_fitting = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $idEdit);
    mysqli_stmt_execute($stmt);
    $resultEdit = mysqli_stmt_get_result($stmt);
    $editData = $resultEdit ? mysqli_fetch_assoc($resultEdit) : null;
    mysqli_stmt_close($stmt);
}

/* =========================
   DATA PENYEWA & PENYEWAAN
========================= */
$users = [];
$rentals = [];
$fittings = [];
$search = trim($_GET['q'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');

if ($tableReady) {
    $userResult = mysqli_query($koneksi, "SELECT id_user, nama, username, no_hp FROM users WHERE role='penyewa' ORDER BY nama ASC");
    if ($userResult) {
        while ($row = mysqli_fetch_assoc($userResult)) $users[] = $row;
    }

    $rentalResult = mysqli_query($koneksi, "SELECT p.id_penyewaan, p.kode_penyewaan, p.id_user, p.tanggal_mulai, p.tanggal_selesai, p.status, u.nama FROM penyewaan p JOIN users u ON p.id_user=u.id_user ORDER BY p.id_penyewaan DESC");
    if ($rentalResult) {
        while ($row = mysqli_fetch_assoc($rentalResult)) $rentals[] = $row;
    }

    $sql = "SELECT f.id_fitting, f.id_user, f.id_penyewaan, f.tanggal_fitting, f.jam_fitting, f.catatan, f.status, u.nama, u.no_hp, p.kode_penyewaan
            FROM janji_fitting f
            JOIN users u ON f.id_user=u.id_user
            JOIN penyewaan p ON f.id_penyewaan=p.id_penyewaan
            WHERE 1=1";
    $types = '';
    $params = [];
    if ($search !== '') {
        $sql .= " AND (u.nama LIKE ? OR u.no_hp LIKE ? OR p.kode_penyewaan LIKE ?)";
        $like = '%' . $search . '%';
        $types .= 'sss';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($filterStatus !== '' && in_array($filterStatus, ['menunggu','disetujui','ditolak','selesai','dibatalkan'], true)) {
        $sql .= " AND f.status = ?";
        $types .= 's';
        $params[] = $filterStatus;
    }
    $sql .= " ORDER BY f.tanggal_fitting DESC, f.jam_fitting DESC, f.id_fitting DESC";
    $stmt = mysqli_prepare($koneksi, $sql);
    if ($stmt) {
        if ($types !== '') {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $listResult = mysqli_stmt_get_result($stmt);
        if ($listResult) {
            while ($row = mysqli_fetch_assoc($listResult)) $fittings[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelola Fitting | KebayaRent</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root{--pink:#d96f9b;--pink-dark:#b84f7c;--pink-light:#fff0f6;--pink-pale:#fff8fb;--ink:#45313b;--muted:#8d7883}
        body{background:#fff8fb;color:var(--ink);font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
        .sidebar{background:linear-gradient(180deg,#f8dce8,#fcecf3);min-height:100vh;border-right:1px solid #f0d4e0}
        .brand{font-weight:800;color:#9c3f68;letter-spacing:.2px}
        .nav-link{color:#755665;border-radius:12px;padding:.75rem .9rem;margin:.15rem 0;font-weight:600}
        .nav-link:hover,.nav-link.active{background:#fff;color:#b84f7c}
        .main{padding:28px}
        .card-soft{border:1px solid #f2dce6;border-radius:20px;box-shadow:0 8px 26px rgba(148,76,108,.06);background:#fff}
        .btn-pink{background:var(--pink);border-color:var(--pink);color:#fff;font-weight:700}
        .btn-pink:hover{background:var(--pink-dark);border-color:var(--pink-dark);color:#fff}
        .text-pink{color:var(--pink-dark)}
        .form-control,.form-select{border-color:#ead5df;border-radius:11px;padding:.65rem .8rem}
        .form-control:focus,.form-select:focus{border-color:#df8db0;box-shadow:0 0 0 .2rem rgba(217,111,155,.14)}
        .table thead th{background:#fff1f6;color:#87506a;font-size:.82rem;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
        .table td{vertical-align:middle}
        .badge-status{border-radius:30px;padding:.45rem .7rem;font-weight:700}
        .status-menunggu{background:#fff0c7;color:#8b6500}.status-disetujui{background:#dff5e8;color:#20734a}
        .status-ditolak{background:#ffe0e3;color:#a72d42}.status-selesai{background:#e2e9ff;color:#3f56a5}.status-dibatalkan{background:#eee5ec;color:#745c6b}
        .stat-icon{width:46px;height:46px;border-radius:15px;background:#ffedf4;color:#c35f8a;display:grid;place-items:center;font-size:1.3rem}
        @media(max-width:767px){.sidebar{min-height:auto}.main{padding:16px}.side-nav{display:flex;gap:6px;overflow:auto}.side-nav .nav-link{white-space:nowrap}}
    </style>
</head>
<body>
<div class="container-fluid">
<div class="row">
    <aside class="col-md-3 col-xl-2 sidebar p-3">
        <div class="d-flex align-items-center gap-2 mb-4 px-2 pt-2">
            <span class="stat-icon"><i class="bi bi-flower1"></i></span>
            <div><div class="brand fs-5">KebayaRent</div><small class="text-secondary">Panel Petugas</small></div>
        </div>
        <nav class="nav flex-column side-nav">
            <a class="nav-link" href="dashboard.php"><i class="bi bi-grid me-2"></i>Dashboard</a>
            <a class="nav-link" href="kebaya.php"><i class="bi bi-bag-heart me-2"></i>Data Kebaya</a>
            <a class="nav-link" href="penyewaan.php"><i class="bi bi-calendar-check me-2"></i>Penyewaan</a>
            <a class="nav-link active" href="fitting.php"><i class="bi bi-calendar2-heart me-2"></i>Janji Fitting</a>
            <a class="nav-link" href="pengembalian.php"><i class="bi bi-arrow-return-left me-2"></i>Pengembalian</a>
            <a class="nav-link mt-3" href="fitting.php?aksi=logout" onclick="return confirm('Yakin ingin keluar?')"><i class="bi bi-box-arrow-right me-2"></i>Keluar</a>
        </nav>
        <div class="small text-secondary px-2 mt-4">Kelola jadwal fitting penyewa dengan mudah.</div>
    </aside>

    <main class="col-md-9 col-xl-10 main">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <div class="text-pink fw-semibold small mb-1">KELOLA LAYANAN</div>
                <h1 class="h3 fw-bold mb-1">Janji Fitting</h1>
                <div class="text-secondary">Atur jadwal fitting kebaya untuk penyewa.</div>
            </div>
            <div class="d-flex align-items-center gap-2 rounded-pill bg-white border px-3 py-2">
                <span class="stat-icon" style="width:34px;height:34px;border-radius:50%;font-size:1rem"><i class="bi bi-person-badge"></i></span>
                <div><div class="fw-semibold small"><?= e($_SESSION['nama'] ?? 'Petugas') ?></div><small class="text-secondary">Petugas</small></div>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show rounded-4" role="alert">
                <?= e($flash['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!$tableReady): ?>
            <div class="card-soft p-4">
                <div class="d-flex gap-3 align-items-start">
                    <span class="stat-icon"><i class="bi bi-database-exclamation"></i></span>
                    <div>
                        <h2 class="h5 fw-bold">Tabel janji_fitting belum tersedia atau kolomnya belum sesuai.</h2>
                        <p class="text-secondary mb-2">Jalankan SQL berikut di phpMyAdmin pada database <code>kebayarent</code>, lalu muat ulang halaman ini.</p>
                        <pre class="bg-light p-3 rounded-3 small text-wrap"><code>CREATE TABLE IF NOT EXISTS janji_fitting (
    id_fitting INT AUTO_INCREMENT PRIMARY KEY,
    id_user INT NOT NULL,
    id_penyewaan INT NOT NULL,
    tanggal_fitting DATE NOT NULL,
    jam_fitting TIME NOT NULL,
    catatan TEXT NULL,
    status ENUM('menunggu','disetujui','ditolak','selesai','dibatalkan') NOT NULL DEFAULT 'menunggu',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fitting_user FOREIGN KEY (id_user) REFERENCES users(id_user),
    CONSTRAINT fk_fitting_penyewaan FOREIGN KEY (id_penyewaan) REFERENCES penyewaan(id_penyewaan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</code></pre>
                        <button class="btn btn-pink" type="button" onclick="copySql()"><i class="bi bi-clipboard me-1"></i>Salin SQL</button>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-3"><div class="card-soft p-3 d-flex align-items-center gap-3"><div class="stat-icon"><i class="bi bi-calendar-week"></i></div><div><div class="text-secondary small">Total Janji</div><div class="h4 fw-bold mb-0"><?= count($fittings) ?></div></div></div></div>
                <div class="col-sm-6 col-xl-3"><div class="card-soft p-3 d-flex align-items-center gap-3"><div class="stat-icon"><i class="bi bi-hourglass-split"></i></div><div><div class="text-secondary small">Menunggu</div><div class="h4 fw-bold mb-0"><?= count(array_filter($fittings, fn($f) => $f['status'] === 'menunggu')) ?></div></div></div></div>
                <div class="col-sm-6 col-xl-3"><div class="card-soft p-3 d-flex align-items-center gap-3"><div class="stat-icon"><i class="bi bi-check2-circle"></i></div><div><div class="text-secondary small">Disetujui</div><div class="h4 fw-bold mb-0"><?= count(array_filter($fittings, fn($f) => $f['status'] === 'disetujui')) ?></div></div></div></div>
                <div class="col-sm-6 col-xl-3"><div class="card-soft p-3 d-flex align-items-center gap-3"><div class="stat-icon"><i class="bi bi-calendar-heart"></i></div><div><div class="text-secondary small">Selesai</div><div class="h4 fw-bold mb-0"><?= count(array_filter($fittings, fn($f) => $f['status'] === 'selesai')) ?></div></div></div></div>
            </div>

            <div class="card-soft p-4 mb-4" id="form-fitting">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div><h2 class="h5 fw-bold mb-1"><?= $editData ? 'Edit Janji Fitting' : 'Tambah Janji Fitting' ?></h2><p class="text-secondary small mb-0">Isi informasi penyewa dan jadwal fitting.</p></div>
                    <?php if ($editData): ?><a class="btn btn-outline-secondary btn-sm rounded-pill" href="fitting.php">Batal edit</a><?php endif; ?>
                </div>
                <?php if (count($users) === 0 || count($rentals) === 0): ?>
                    <div class="alert alert-warning mb-0">Belum ada data penyewa atau penyewaan. Tambahkan data penyewa dan transaksi penyewaan terlebih dahulu.</div>
                <?php else: ?>
                <form method="post" action="fitting.php">
                    <input type="hidden" name="aksi" value="simpan">
                    <input type="hidden" name="id_fitting" value="<?= e($editData['id_fitting'] ?? 0) ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Penyewa <span class="text-danger">*</span></label>
                            <select name="id_user" id="id_user" class="form-select" required>
                                <option value="">-- Pilih penyewa --</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int)$u['id_user'] ?>" <?= (int)($editData['id_user'] ?? 0) === (int)$u['id_user'] ? 'selected' : '' ?>><?= e($u['nama']) ?> (<?= e($u['username']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Kode Penyewaan <span class="text-danger">*</span></label>
                            <select name="id_penyewaan" id="id_penyewaan" class="form-select" required>
                                <option value="">-- Pilih kode penyewaan --</option>
                                <?php foreach ($rentals as $r): ?>
                                    <option value="<?= (int)$r['id_penyewaan'] ?>" data-user="<?= (int)$r['id_user'] ?>" <?= (int)($editData['id_penyewaan'] ?? 0) === (int)$r['id_penyewaan'] ? 'selected' : '' ?>><?= e($r['kode_penyewaan']) ?> — <?= e($r['nama']) ?> (<?= e($r['status']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Pilih transaksi milik penyewa yang sama.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tanggal Fitting <span class="text-danger">*</span></label>
                            <input type="date" name="tanggal_fitting" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= e($editData['tanggal_fitting'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Jam Fitting <span class="text-danger">*</span></label>
                            <input type="time" name="jam_fitting" class="form-control" value="<?= e(isset($editData['jam_fitting']) ? substr($editData['jam_fitting'],0,5) : '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach (['menunggu'=>'Menunggu','disetujui'=>'Disetujui','ditolak'=>'Ditolak','selesai'=>'Selesai','dibatalkan'=>'Dibatalkan'] as $key=>$label): ?>
                                    <option value="<?= e($key) ?>" <?= ($editData['status'] ?? 'menunggu') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Catatan</label>
                            <textarea name="catatan" class="form-control" rows="2" placeholder="Contoh: fitting ukuran lengan atau penyesuaian kebaya..."><?= e($editData['catatan'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-pink px-4"><i class="bi bi-save me-2"></i><?= $editData ? 'Simpan Perubahan' : 'Tambah Janji Fitting' ?></button>
                        </div>
                    </div>
                </form>
                <?php endif; ?>
            </div>

            <div class="card-soft p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                    <div><h2 class="h5 fw-bold mb-1">Daftar Janji Fitting</h2><p class="text-secondary small mb-0">Cari, periksa, dan kelola jadwal fitting.</p></div>
                    <form method="get" class="d-flex flex-wrap gap-2">
                        <input type="search" class="form-control" name="q" placeholder="Nama, HP, kode..." value="<?= e($search) ?>" style="min-width:190px">
                        <select name="status" class="form-select" style="min-width:145px">
                            <option value="">Semua status</option>
                            <?php foreach (['menunggu','disetujui','ditolak','selesai','dibatalkan'] as $s): ?><option value="<?= e($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-pink"><i class="bi bi-search me-1"></i>Cari</button>
                        <a href="fitting.php" class="btn btn-outline-secondary">Reset</a>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>No.</th><th>Penyewa</th><th>Penyewaan</th><th>Jadwal Fitting</th><th>Catatan</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
                        <tbody>
                        <?php if (!$fittings): ?>
                            <tr><td colspan="7" class="text-center py-5"><div class="stat-icon mx-auto mb-3"><i class="bi bi-calendar2-x"></i></div><div class="fw-semibold">Belum ada janji fitting</div><div class="text-secondary small">Tambahkan janji fitting melalui formulir di atas.</div></td></tr>
                        <?php else: foreach ($fittings as $i=>$f): ?>
                            <tr>
                                <td><?= $i+1 ?></td>
                                <td><div class="fw-semibold"><?= e($f['nama']) ?></div><small class="text-secondary"><?= e($f['no_hp'] ?: 'Nomor HP belum diisi') ?></small></td>
                                <td><span class="fw-semibold text-pink"><?= e($f['kode_penyewaan']) ?></span></td>
                                <td><div class="fw-semibold"><?= date('d M Y', strtotime($f['tanggal_fitting'])) ?></div><small class="text-secondary"><?= date('H:i', strtotime($f['jam_fitting'])) ?> WIB</small></td>
                                <td style="min-width:150px"><?= e($f['catatan'] ?: '—') ?></td>
                                <td><span class="badge-status status-<?= e($f['status']) ?>"><?= e(ucfirst($f['status'])) ?></span></td>
                                <td class="text-end text-nowrap">
                                    <a href="fitting.php?edit=<?= (int)$f['id_fitting'] ?>#form-fitting" class="btn btn-sm btn-outline-secondary rounded-pill" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus janji fitting ini?')">
                                        <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id_fitting" value="<?= (int)$f['id_fitting'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill" title="Hapus"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="small text-secondary mt-2">Menampilkan <?= count($fittings) ?> janji fitting sesuai filter.</div>
            </div>
        <?php endif; ?>
        <footer class="text-center text-secondary small py-4">© <?= date('Y') ?> KebayaRent · Dengan sentuhan pastel pink ♡</footer>
    </main>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copySql() {
    const sql = `CREATE TABLE IF NOT EXISTS janji_fitting (
    id_fitting INT AUTO_INCREMENT PRIMARY KEY,
    id_user INT NOT NULL,
    id_penyewaan INT NOT NULL,
    tanggal_fitting DATE NOT NULL,
    jam_fitting TIME NOT NULL,
    catatan TEXT NULL,
    status ENUM('menunggu','disetujui','ditolak','selesai','dibatalkan') NOT NULL DEFAULT 'menunggu',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fitting_user FOREIGN KEY (id_user) REFERENCES users(id_user),
    CONSTRAINT fk_fitting_penyewaan FOREIGN KEY (id_penyewaan) REFERENCES penyewaan(id_penyewaan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;`;
    navigator.clipboard.writeText(sql).then(() => alert('SQL berhasil disalin.')).catch(() => alert('Silakan salin SQL dari kotak secara manual.'));
}
const userSelect = document.getElementById('id_user');
const rentalSelect = document.getElementById('id_penyewaan');
function filterRentals() {
    if (!userSelect || !rentalSelect) return;
    const selectedUser = userSelect.value;
    Array.from(rentalSelect.options).forEach(option => {
        if (!option.value) { option.hidden = false; return; }
        option.hidden = selectedUser !== '' && option.dataset.user !== selectedUser;
    });
    const selectedOption = rentalSelect.options[rentalSelect.selectedIndex];
    if (selectedOption && selectedOption.value && selectedOption.hidden) rentalSelect.value = '';
}
if (userSelect && rentalSelect) {
    userSelect.addEventListener('change', filterRentals);
    filterRentals();
}
</script>
</body>
</html>
