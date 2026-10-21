<?php
session_start();
require_once 'koneksi.php';

// Hak akses admin dan petugas
if (
    !isset($_SESSION['id_user'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'petugas'], true)
) {
    header("Location: login.php");
    exit;
}

function e($nilai) {
    return htmlspecialchars((string)($nilai ?? ''), ENT_QUOTES, 'UTF-8');
}

// Pencarian dan filter
$cari = trim($_GET['cari'] ?? '');
$filterStatus = $_GET['status'] ?? '';

$statusValid = [
    'menunggu',
    'disetujui',
    'ditolak',
    'dipinjam',
    'selesai',
    'dibatalkan'
];

if (!in_array($filterStatus, $statusValid, true)) {
    $filterStatus = '';
}

$sql = "SELECT p.*, u.nama AS nama_penyewa
        FROM penyewaan p
        JOIN users u ON p.id_user = u.id_user
        WHERE 1=1";

$types = '';
$params = [];

if ($cari !== '') {
    $sql .= " AND (
        p.kode_penyewaan LIKE ?
        OR u.nama LIKE ?
        OR p.catatan LIKE ?
    )";

    $kata = '%' . $cari . '%';
    $types .= 'sss';
    $params[] = $kata;
    $params[] = $kata;
    $params[] = $kata;
}

if ($filterStatus !== '') {
    $sql .= " AND p.status = ?";
    $types .= 's';
    $params[] = $filterStatus;
}

$sql .= " ORDER BY p.id_penyewaan DESC";

$stmt = mysqli_prepare($koneksi, $sql);
$data = false;

if ($stmt) {
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    if (mysqli_stmt_execute($stmt)) {
        $data = mysqli_stmt_get_result($stmt);
    }
}

// Ringkasan seluruh data penyewaan
$ringkasan = mysqli_query(
    $koneksi,
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN status = 'menunggu' THEN 1 ELSE 0 END), 0) AS menunggu,
        COALESCE(SUM(CASE WHEN status = 'dipinjam' THEN 1 ELSE 0 END), 0) AS dipinjam,
        COALESCE(SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END), 0) AS selesai
     FROM penyewaan"
);

$stat = [
    'total' => 0,
    'menunggu' => 0,
    'dipinjam' => 0,
    'selesai' => 0
];

if ($ringkasan) {
    $stat = mysqli_fetch_assoc($ringkasan);
}

$warnaStatus = [
    'menunggu' => 'status-menunggu',
    'disetujui' => 'status-disetujui',
    'ditolak' => 'status-ditolak',
    'dipinjam' => 'status-dipinjam',
    'selesai' => 'status-selesai',
    'dibatalkan' => 'status-dibatalkan'
];

$jumlahHasil = $data ? mysqli_num_rows($data) : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Data Penyewaan - KebayaRent</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <style>
        :root {
            --pink: #d94683;
            --pink-dark: #a61e59;
            --pink-soft: #fff0f6;
            --pink-border: #f4d4e3;
            --ink: #382638;
            --muted: #897486;
            --white: #ffffff;
        }

        body {
            background: #fff8fb;
            color: var(--ink);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        .navbar {
            background: linear-gradient(135deg, #a61e59, #d94683);
            box-shadow: 0 4px 18px rgba(166, 30, 89, .15);
        }

        .navbar-brand {
            font-weight: 800;
            letter-spacing: .3px;
        }

        .page-title {
            color: var(--ink);
            font-weight: 800;
        }

        .page-subtitle {
            color: var(--muted);
        }

        .pink-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 48px;
            height: 48px;
            border-radius: 15px;
            background: var(--pink-soft);
            color: var(--pink-dark);
            font-size: 23px;
        }

        .stat-card,
        .search-card,
        .main-card {
            background: var(--white);
            border: 1px solid var(--pink-border);
            border-radius: 18px;
            box-shadow: 0 6px 24px rgba(166, 30, 89, .055);
        }

        .stat-card {
            padding: 20px;
            height: 100%;
        }

        .stat-label {
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 7px;
        }

        .stat-number {
            color: var(--pink-dark);
            font-size: 27px;
            font-weight: 800;
        }

        .search-card {
            padding: 20px;
        }

        .form-control,
        .form-select {
            min-height: 44px;
            border: 1px solid var(--pink-border);
            border-radius: 11px;
            color: var(--ink);
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--pink);
            box-shadow: 0 0 0 .2rem rgba(217, 70, 131, .12);
        }

        .btn-pink {
            background: var(--pink);
            border: 1px solid var(--pink);
            color: white;
            border-radius: 11px;
            font-weight: 700;
            padding: 10px 17px;
        }

        .btn-pink:hover {
            background: var(--pink-dark);
            border-color: var(--pink-dark);
            color: white;
        }

        .btn-soft {
            background: var(--pink-soft);
            border: 1px solid var(--pink-border);
            color: var(--pink-dark);
            border-radius: 11px;
            font-weight: 700;
            padding: 10px 17px;
        }

        .btn-soft:hover {
            background: #fce0ec;
            color: var(--pink-dark);
        }

        .main-card {
            overflow: hidden;
        }

        .card-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 20px 22px;
            border-bottom: 1px solid var(--pink-border);
        }

        .card-heading h5 {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
        }

        .result-badge {
            display: inline-block;
            padding: 6px 11px;
            background: var(--pink-soft);
            color: var(--pink-dark);
            border-radius: 30px;
            font-size: 12px;
            font-weight: 700;
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: #fff0f6;
            color: var(--pink-dark);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .35px;
            white-space: nowrap;
            padding: 15px 16px;
            border-bottom: 1px solid var(--pink-border);
        }

        .table tbody td {
            padding: 14px 16px;
            border-color: #f8e8ef;
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background: #fff9fc;
        }

        .code-badge {
            display: inline-block;
            padding: 6px 9px;
            border-radius: 8px;
            background: var(--pink-soft);
            color: var(--pink-dark);
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        .user-name {
            font-weight: 700;
            min-width: 130px;
        }

        .date-text {
            color: #6f5c6b;
            font-size: 13px;
            white-space: nowrap;
        }

        .money {
            color: var(--pink-dark);
            font-weight: 800;
            white-space: nowrap;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-menunggu {
            background: #fff0cf;
            color: #8a5a00;
        }

        .status-disetujui {
            background: #dff3ff;
            color: #075985;
        }

        .status-ditolak {
            background: #ffe5e8;
            color: #b42336;
        }

        .status-dipinjam {
            background: #e9e5ff;
            color: #5338a4;
        }

        .status-selesai {
            background: #e0f6e9;
            color: #187547;
        }

        .status-dibatalkan {
            background: #ece8ed;
            color: #655565;
        }

        .empty-state {
            padding: 45px 20px !important;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            display: block;
            font-size: 38px;
            color: #e7a2bf;
            margin-bottom: 10px;
        }

        footer {
            text-align: center;
            color: var(--muted);
            font-size: 12px;
            padding: 25px 0 10px;
        }

        @media (max-width: 576px) {
            .stat-card {
                padding: 16px;
            }

            .stat-number {
                font-size: 23px;
            }

            .search-card {
                padding: 15px;
            }

            .card-heading {
                padding: 16px;
            }
        }
    </style>
</head>

<body>

<nav class="navbar navbar-dark">
    <div class="container py-1">
        <a href="index.php" class="navbar-brand">
            <i class="bi bi-flower1 me-2"></i>KebayaRent
        </a>

        <a href="index.php" class="btn btn-outline-light btn-sm">
            <i class="bi bi-grid me-1"></i>Dashboard
        </a>
    </div>
</nav>

<main class="container py-4 py-md-5">

    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="pink-icon">
            <i class="bi bi-receipt"></i>
        </div>

        <div>
            <h2 class="page-title mb-1">Data Penyewaan</h2>
            <p class="page-subtitle mb-0">
                Pantau transaksi dan status penyewaan kebaya.
            </p>
        </div>
    </div>

    <!-- Ringkasan -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="pink-icon">
                        <i class="bi bi-journal-text"></i>
                    </div>
                    <div>
                        <div class="stat-label">Total Penyewaan</div>
                        <div class="stat-number">
                            <?= number_format((int)$stat['total'], 0, ',', '.') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="pink-icon">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                    <div>
                        <div class="stat-label">Menunggu</div>
                        <div class="stat-number">
                            <?= number_format((int)$stat['menunggu'], 0, ',', '.') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="pink-icon">
                        <i class="bi bi-bag-check"></i>
                    </div>
                    <div>
                        <div class="stat-label">Sedang Dipinjam</div>
                        <div class="stat-number">
                            <?= number_format((int)$stat['dipinjam'], 0, ',', '.') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="pink-icon">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div>
                        <div class="stat-label">Selesai</div>
                        <div class="stat-number">
                            <?= number_format((int)$stat['selesai'], 0, ',', '.') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pencarian dan filter -->
    <div class="search-card mb-4">
        <form method="GET" action="penyewaan.php">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-6">
                    <label for="cari" class="form-label fw-bold">
                        <i class="bi bi-search me-1"></i>Cari Penyewaan
                    </label>

                    <input type="text"
                           name="cari"
                           id="cari"
                           class="form-control"
                           placeholder="Kode, nama penyewa, atau catatan..."
                           value="<?= e($cari) ?>">
                </div>

                <div class="col-12 col-md-3">
                    <label for="status" class="form-label fw-bold">
                        Filter Status
                    </label>

                    <select name="status" id="status" class="form-select">
                        <option value="">Semua Status</option>
                        <option value="menunggu" <?= $filterStatus === 'menunggu' ? 'selected' : '' ?>>Menunggu</option>
                        <option value="disetujui" <?= $filterStatus === 'disetujui' ? 'selected' : '' ?>>Disetujui</option>
                        <option value="ditolak" <?= $filterStatus === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
                        <option value="dipinjam" <?= $filterStatus === 'dipinjam' ? 'selected' : '' ?>>Dipinjam</option>
                        <option value="selesai" <?= $filterStatus === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                        <option value="dibatalkan" <?= $filterStatus === 'dibatalkan' ? 'selected' : '' ?>>Dibatalkan</option>
                    </select>
                </div>

                <div class="col-6 col-md-3">
                    <button type="submit" class="btn btn-pink w-100">
                        <i class="bi bi-search me-1"></i>Terapkan
                    </button>
                </div>

                <div class="col-6 d-md-none">
                    <a href="penyewaan.php" class="btn btn-soft w-100">
                        Reset
                    </a>
                </div>

                <div class="col-12 d-none d-md-block">
                    <a href="penyewaan.php" class="btn btn-soft btn-sm">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        Reset Filter
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Tabel penyewaan -->
    <section class="main-card">
        <div class="card-heading">
            <h5>
                <i class="bi bi-list-ul me-2" style="color: var(--pink);"></i>
                Daftar Penyewaan
            </h5>

            <span class="result-badge">
                <?= number_format($jumlahHasil, 0, ',', '.') ?> data ditemukan
            </span>
        </div>

        <?php if (!$data): ?>
            <div class="alert alert-danger m-3">
                Data gagal dimuat. Periksa koneksi dan struktur tabel database.
            </div>

        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Kode</th>
                            <th>Nama Penyewa</th>
                            <th>Tanggal Pesan</th>
                            <th>Tanggal Mulai</th>
                            <th>Tanggal Selesai</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if ($jumlahHasil > 0): ?>
                        <?php $no = 1; ?>

                        <?php while ($row = mysqli_fetch_assoc($data)): ?>
                            <?php
                            $status = $row['status'];
                            $kelasStatus = $warnaStatus[$status] ?? 'status-dibatalkan';
                            ?>

                            <tr>
                                <td class="text-muted fw-semibold">
                                    <?= $no++ ?>
                                </td>

                                <td>
                                    <span class="code-badge">
                                        <?= e($row['kode_penyewaan']) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="user-name">
                                        <?= e($row['nama_penyewa']) ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="date-text">
                                        <?= e($row['tanggal_pesan']) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="date-text">
                                        <?= e($row['tanggal_mulai']) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="date-text">
                                        <?= e($row['tanggal_selesai']) ?>
                                    </span>
                                </td>

                                <td class="money">
                                    Rp <?= number_format((float)$row['total'], 0, ',', '.') ?>
                                </td>

                                <td>
                                    <span class="status-badge <?= e($kelasStatus) ?>">
                                        <?= e(ucfirst($status)) ?>
                                    </span>
                                </td>

                                <td style="min-width: 160px; max-width: 260px;">
                                    <?= e($row['catatan'] ?: '-') ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="empty-state">
                                <i class="bi bi-inbox"></i>

                                <div class="fw-bold mb-1">
                                    <?= ($cari !== '' || $filterStatus !== '')
                                        ? 'Data penyewaan tidak ditemukan'
                                        : 'Belum ada data penyewaan' ?>
                                </div>

                                <div>
                                    <?= ($cari !== '' || $filterStatus !== '')
                                        ? 'Coba ubah kata kunci atau filter status.'
                                        : 'Data penyewaan akan muncul di halaman ini.' ?>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <div class="mt-3">
        <a href="index.php" class="btn btn-soft">
            <i class="bi bi-arrow-left me-1"></i>Kembali ke Dashboard
        </a>
    </div>

    <footer>
        <i class="bi bi-flower1 me-1"></i>
        KebayaRent &copy; <?= date('Y') ?> — Sistem Penyewaan Kebaya
    </footer>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
if ($stmt) {
    mysqli_stmt_close($stmt);
}
?>
