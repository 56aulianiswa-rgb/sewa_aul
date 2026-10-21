<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Pastikan hanya petugas yang dapat membuka halaman ini */
if (!isset($_SESSION['id_user']) || ($_SESSION['role'] ?? '') !== 'petugas') {
    header('Location: ../login.php#login');
    exit;
}

require_once __DIR__ . '/../koneksi.php';
mysqli_report(MYSQLI_REPORT_OFF);

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirectDenganPesan($pesan, $jenis = 'success') {
    $_SESSION['flash_penyewaan'] = ['pesan' => $pesan, 'jenis' => $jenis];
    header('Location: penyewaan.php');
    exit;
}

/* Logout tanpa file logout.php */
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

$allowedStatuses = ['menunggu', 'disetujui', 'ditolak', 'dipinjam', 'selesai', 'dibatalkan'];

/* Proses perubahan status dengan POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'ubah_status') {
    $idPenyewaan = filter_input(INPUT_POST, 'id_penyewaan', FILTER_VALIDATE_INT);
    $statusBaru = $_POST['status'] ?? '';
    $catatan = trim($_POST['catatan'] ?? '');

    if (!$idPenyewaan || !in_array($statusBaru, $allowedStatuses, true)) {
        redirectDenganPesan('Data perubahan status tidak valid.', 'danger');
    }

    mysqli_begin_transaction($koneksi);
    try {
        $stmt = mysqli_prepare($koneksi, 'SELECT status FROM penyewaan WHERE id_penyewaan = ? FOR UPDATE');
        if (!$stmt) {
            throw new Exception('Gagal menyiapkan query penyewaan.');
        }
        mysqli_stmt_bind_param($stmt, 'i', $idPenyewaan);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $dataLama = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if (!$dataLama) {
            throw new Exception('Data penyewaan tidak ditemukan.');
        }

        $statusLama = $dataLama['status'];

        /* Cegah status yang sama diproses berulang */
        if ($statusLama === $statusBaru) {
            mysqli_commit($koneksi);
            redirectDenganPesan('Status penyewaan tidak berubah.', 'info');
        }

        $stmt = mysqli_prepare($koneksi, 'UPDATE penyewaan SET status = ?, catatan = CASE WHEN ? = "" THEN catatan ELSE ? END WHERE id_penyewaan = ?');
        if (!$stmt) {
            throw new Exception('Gagal menyiapkan perubahan status.');
        }
        mysqli_stmt_bind_param($stmt, 'sssi', $statusBaru, $catatan, $catatan, $idPenyewaan);
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Status penyewaan gagal diperbarui.');
        }
        mysqli_stmt_close($stmt);

        /* Catat histori status bila tabel tersedia */
        $idPetugas = (int)$_SESSION['id_user'];
        $stmtLog = mysqli_prepare($koneksi, 'INSERT INTO log_status_penyewaan (id_penyewaan, status_lama, status_baru) VALUES (?, ?, ?)');
        if ($stmtLog) {
            mysqli_stmt_bind_param($stmtLog, 'iss', $idPenyewaan, $statusLama, $statusBaru);
            mysqli_stmt_execute($stmtLog);
            mysqli_stmt_close($stmtLog);
        }

        /* Catat aktivitas bila tabel tersedia */
        $aktivitas = 'Mengubah status penyewaan ID ' . $idPenyewaan . ' dari ' . $statusLama . ' menjadi ' . $statusBaru;
        $stmtAktivitas = mysqli_prepare($koneksi, 'INSERT INTO log_aktivitas (id_user, aktivitas) VALUES (?, ?)');
        if ($stmtAktivitas) {
            mysqli_stmt_bind_param($stmtAktivitas, 'is', $idPetugas, $aktivitas);
            mysqli_stmt_execute($stmtAktivitas);
            mysqli_stmt_close($stmtAktivitas);
        }

        mysqli_commit($koneksi);
        redirectDenganPesan('Status penyewaan berhasil diperbarui.', 'success');
    } catch (Throwable $err) {
        mysqli_rollback($koneksi);
        redirectDenganPesan('Perubahan gagal. Pastikan struktur tabel sesuai dengan database KebayaRent.', 'danger');
    }
}

$flash = $_SESSION['flash_penyewaan'] ?? null;
unset($_SESSION['flash_penyewaan']);

$q = trim($_GET['q'] ?? '');
$filterStatus = $_GET['status'] ?? '';
$tanggalAwal = $_GET['dari'] ?? '';
$tanggalAkhir = $_GET['sampai'] ?? '';

$sql = "SELECT p.id_penyewaan, p.kode_penyewaan, p.tanggal_pesan, p.tanggal_mulai,
               p.tanggal_selesai, p.total, p.status, p.catatan, u.nama, u.no_hp
        FROM penyewaan p
        LEFT JOIN users u ON p.id_user = u.id_user
        WHERE 1=1";
$types = '';
$params = [];

if ($q !== '') {
    $sql .= ' AND (p.kode_penyewaan LIKE ? OR u.nama LIKE ? OR u.no_hp LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}
if (in_array($filterStatus, $allowedStatuses, true)) {
    $sql .= ' AND p.status = ?';
    $types .= 's';
    $params[] = $filterStatus;
}
if ($tanggalAwal !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalAwal)) {
    $sql .= ' AND p.tanggal_pesan >= ?';
    $types .= 's';
    $params[] = $tanggalAwal;
}
if ($tanggalAkhir !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalAkhir)) {
    $sql .= ' AND p.tanggal_pesan <= ?';
    $types .= 's';
    $params[] = $tanggalAkhir;
}
$sql .= ' ORDER BY p.id_penyewaan DESC';

$stmt = mysqli_prepare($koneksi, $sql);
$rows = [];
if ($stmt) {
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

$namaPetugas = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Petugas';

function badgeStatus($status) {
    $classes = [
        'menunggu' => 'bg-warning-subtle text-warning-emphasis',
        'disetujui' => 'bg-primary-subtle text-primary-emphasis',
        'ditolak' => 'bg-danger-subtle text-danger-emphasis',
        'dipinjam' => 'bg-info-subtle text-info-emphasis',
        'selesai' => 'bg-success-subtle text-success-emphasis',
        'dibatalkan' => 'bg-secondary-subtle text-secondary-emphasis'
    ];
    return $classes[$status] ?? 'bg-light text-dark';
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Data Penyewaan | KebayaRent</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{--pink:#d95f91;--pink-dark:#b94476;--pink-light:#fff0f6;--ink:#392b34;--muted:#897984;--line:#f1e4eb}
body{margin:0;background:#fff9fc;color:var(--ink);font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
.sidebar{width:250px;min-height:100vh;position:fixed;inset:0 auto 0 0;background:#fff;border-right:1px solid var(--line);padding:26px 17px;z-index:5}
.brand{display:flex;align-items:center;gap:11px;padding:0 10px 28px;color:var(--pink-dark);font-size:1.2rem;font-weight:800;text-decoration:none}
.brand-icon{width:42px;height:42px;border-radius:14px;display:grid;place-items:center;color:#fff;background:linear-gradient(135deg,#ec8db5,#c94e84);font-size:1.25rem}
.nav-caption{color:#b5a4ae;font-size:.72rem;font-weight:800;letter-spacing:.12em;padding:10px 13px;text-transform:uppercase}
.side-link{display:flex;align-items:center;gap:12px;padding:12px 13px;border-radius:12px;margin:4px 0;color:#786572;text-decoration:none;font-weight:600;font-size:.93rem}
.side-link i{font-size:1.1rem;width:22px;text-align:center}
.side-link:hover,.side-link.active{color:var(--pink-dark);background:var(--pink-light)}
.main{margin-left:250px;padding:30px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:25px}
.eyebrow{color:var(--pink-dark);font-size:.78rem;text-transform:uppercase;letter-spacing:.12em;font-weight:800}
h1{font-size:clamp(1.5rem,2.5vw,2rem);font-weight:800;margin:5px 0}
.subtext{color:var(--muted);margin:0}
.user-chip{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid var(--line);border-radius:15px;padding:9px 13px}
.avatar{width:39px;height:39px;display:grid;place-items:center;border-radius:13px;color:var(--pink-dark);background:var(--pink-light);font-size:1.2rem}
.panel{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 5px 20px rgba(104,53,79,.035)}
.panel-head{padding:21px 22px;border-bottom:1px solid var(--line)}
.form-control,.form-select{border-color:#eadce4;border-radius:10px;padding:.65rem .8rem}
.form-control:focus,.form-select:focus{border-color:#df8bb0;box-shadow:0 0 0 .2rem #fbe4ee}
.btn-pink{background:var(--pink);border-color:var(--pink);color:#fff;border-radius:10px;font-weight:700}
.btn-pink:hover{background:var(--pink-dark);border-color:var(--pink-dark);color:#fff}
.table-wrap{overflow-x:auto}
.table{margin:0;min-width:1000px}
.table thead th{background:#fff9fc;color:#9a8792;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--line);padding:14px 15px;white-space:nowrap}
.table tbody td{padding:15px;border-color:#f7edf2;vertical-align:middle;font-size:.87rem}
.code{color:var(--pink-dark);font-weight:800}
.pill{display:inline-block;border-radius:30px;padding:6px 10px;font-size:.75rem;font-weight:800;white-space:nowrap}
.mini-label{font-size:.76rem;color:var(--muted);margin-bottom:5px}
.empty{padding:42px 20px;text-align:center;color:var(--muted)}
@media(max-width:991px){.sidebar{position:static;width:100%;min-height:auto;padding:12px 15px;border-right:0;border-bottom:1px solid var(--line)}.brand{padding:0 5px 10px}.side-nav{display:flex;gap:5px;overflow-x:auto}.nav-caption{display:none}.side-link{white-space:nowrap;padding:9px 11px}.main{margin-left:0;padding:22px 15px}}
@media(max-width:575px){.topbar{align-items:flex-start;flex-direction:column}.user-chip{width:100%}}
</style>
</head>
<body>
<aside class="sidebar">
<a class="brand" href="dashboard.php"><span class="brand-icon"><i class="bi bi-flower1"></i></span><span>KebayaRent<small class="d-block text-secondary fw-normal" style="font-size:.72rem">Panel Petugas</small></span></a>
<div class="nav-caption">Menu utama</div>
<nav class="side-nav">
<a class="side-link" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i> Dashboard</a>
<a class="side-link active" href="penyewaan.php"><i class="bi bi-calendar2-check"></i> Data Penyewaan</a>
<a class="side-link" href="pengembalian.php"><i class="bi bi-arrow-return-left"></i> Pengembalian</a>
<a class="side-link" href="kebaya.php"><i class="bi bi-flower2"></i> Data Kebaya</a>
<a class="side-link" href="fitting.php"><i class="bi bi-calendar-heart"></i> Jadwal Fitting</a>
</nav>
<div class="nav-caption mt-4">Akun</div>
<a class="side-link text-danger" href="penyewaan.php?aksi=logout" onclick="return confirm('Yakin ingin keluar dari akun?')"><i class="bi bi-box-arrow-right"></i> Keluar</a>
</aside>

<main class="main">
<header class="topbar">
<div><div class="eyebrow">Manajemen transaksi</div><h1>Data Penyewaan</h1><p class="subtext">Cari transaksi dan perbarui status penyewaan kebaya.</p></div>
<div class="user-chip"><div class="avatar"><i class="bi bi-person-badge"></i></div><div><div class="fw-bold"><?= e($namaPetugas) ?></div><small class="text-secondary">Petugas</small></div></div>
</header>

<?php if ($flash): ?>
<div class="alert alert-<?= e($flash['jenis']) ?> alert-dismissible fade show" role="alert">
<?= e($flash['pesan']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
<?php endif; ?>

<section class="panel mb-4">
<div class="panel-head"><h2 class="h5 fw-bold mb-1">Filter Penyewaan</h2><small class="text-secondary">Gunakan pencarian atau filter status/tanggal.</small></div>
<div class="p-3 p-lg-4">
<form method="get" class="row g-3 align-items-end">
<div class="col-12 col-md-6 col-xl-3"><label class="mini-label" for="q">Cari transaksi</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Kode, nama, atau nomor HP"></div>
<div class="col-12 col-md-6 col-xl-2"><label class="mini-label" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">Semua status</option><?php foreach ($allowedStatuses as $s): ?><option value="<?= e($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
<div class="col-6 col-xl-2"><label class="mini-label" for="dari">Tanggal pesan dari</label><input class="form-control" type="date" id="dari" name="dari" value="<?= e($tanggalAwal) ?>"></div>
<div class="col-6 col-xl-2"><label class="mini-label" for="sampai">Tanggal pesan sampai</label><input class="form-control" type="date" id="sampai" name="sampai" value="<?= e($tanggalAkhir) ?>"></div>
<div class="col-12 col-xl-3 d-flex gap-2"><button class="btn btn-pink flex-grow-1" type="submit"><i class="bi bi-search me-1"></i> Cari</button><a class="btn btn-outline-secondary" href="penyewaan.php">Reset</a></div>
</form>
</div>
</section>

<section class="panel">
<div class="panel-head d-flex justify-content-between align-items-center gap-3 flex-wrap"><div><h2 class="h5 fw-bold mb-1">Daftar Transaksi</h2><small class="text-secondary">Ditemukan <?= count($rows) ?> transaksi</small></div><span class="badge rounded-pill text-bg-light"><?= date('d/m/Y') ?></span></div>
<div class="table-wrap">
<table class="table">
<thead><tr><th>Kode Penyewaan</th><th>Penyewa</th><th>Kontak</th><th>Tanggal Pesan</th><th>Periode Sewa</th><th>Total</th><th>Status</th><th>Ubah Status</th></tr></thead>
<tbody>
<?php if ($rows): foreach ($rows as $row): ?>
<tr>
<td><span class="code"><?= e($row['kode_penyewaan']) ?></span><div class="small text-secondary">ID #<?= (int)$row['id_penyewaan'] ?></div></td>
<td class="fw-semibold"><?= e($row['nama'] ?? 'Pengguna') ?></td>
<td><?= e($row['no_hp'] ?? '-') ?></td>
<td><?= !empty($row['tanggal_pesan']) ? date('d/m/Y', strtotime($row['tanggal_pesan'])) : '-' ?></td>
<td><?= !empty($row['tanggal_mulai']) ? date('d/m/Y', strtotime($row['tanggal_mulai'])) : '-' ?><div class="small text-secondary">s.d. <?= !empty($row['tanggal_selesai']) ? date('d/m/Y', strtotime($row['tanggal_selesai'])) : '-' ?></div></td>
<td class="fw-bold">Rp<?= number_format((float)$row['total'], 0, ',', '.') ?></td>
<td><span class="pill <?= e(badgeStatus($row['status'])) ?>"><?= e(ucfirst($row['status'])) ?></span></td>
<td>
<button type="button" class="btn btn-sm btn-outline-secondary rounded-3" data-bs-toggle="modal" data-bs-target="#modalStatus<?= (int)$row['id_penyewaan'] ?>"><i class="bi bi-pencil-square me-1"></i> Ubah</button>
</td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8"><div class="empty"><i class="bi bi-calendar2-x fs-2 d-block mb-2"></i><strong>Data penyewaan tidak ditemukan</strong><div class="small mt-1">Coba ubah kata kunci atau filter yang digunakan.</div></div></td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</section>

<?php foreach ($rows as $row): ?>
<div class="modal fade" id="modalStatus<?= (int)$row['id_penyewaan'] ?>" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 rounded-4">
<form method="post">
<div class="modal-header border-0 pb-0"><div><h2 class="modal-title fs-5 fw-bold">Ubah Status Penyewaan</h2><p class="text-secondary small mb-0"><?= e($row['kode_penyewaan']) ?> · <?= e($row['nama'] ?? 'Pengguna') ?></p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
<div class="modal-body">
<input type="hidden" name="aksi" value="ubah_status"><input type="hidden" name="id_penyewaan" value="<?= (int)$row['id_penyewaan'] ?>">
<label class="form-label fw-semibold" for="status<?= (int)$row['id_penyewaan'] ?>">Status baru</label>
<select class="form-select mb-3" id="status<?= (int)$row['id_penyewaan'] ?>" name="status" required>
<?php foreach ($allowedStatuses as $s): ?><option value="<?= e($s) ?>" <?= $row['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
</select>
<label class="form-label fw-semibold" for="catatan<?= (int)$row['id_penyewaan'] ?>">Catatan (opsional)</label>
<textarea class="form-control" id="catatan<?= (int)$row['id_penyewaan'] ?>" name="catatan" rows="3" placeholder="Tambahkan catatan jika diperlukan"><?= e($row['catatan'] ?? '') ?></textarea>
<div class="form-text">Pastikan status sesuai dengan kondisi transaksi sebenarnya.</div>
</div>
<div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-light rounded-3" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-pink px-3"><i class="bi bi-check2 me-1"></i> Simpan Status</button></div>
</form>
</div></div>
</div>
<?php endforeach; ?>

<footer class="text-center text-secondary small mt-5 pb-2">© <?= date('Y') ?> KebayaRent · Panel Petugas</footer>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
