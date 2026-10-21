<?php
session_start();
require_once 'koneksi.php';

// Hanya admin yang boleh membuka halaman ini
if (!isset($_SESSION['id_user'], $_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die("Akses ditolak. Halaman ini hanya untuk admin.");
}

$cari = trim($_GET['cari'] ?? '');

$sql = "SELECT l.id_log, l.aktivitas, l.waktu,
               u.nama, u.username
        FROM log_aktivitas l
        LEFT JOIN users u ON l.id_user = u.id_user";

if ($cari !== '') {
    $sql .= " WHERE l.aktivitas LIKE ?
              OR u.nama LIKE ?
              OR u.username LIKE ?";
}

$sql .= " ORDER BY l.waktu DESC, l.id_log DESC";

$stmt = mysqli_prepare($koneksi, $sql);

if (!$stmt) {
    die("Terjadi kesalahan saat menyiapkan data aktivitas.");
}

if ($cari !== '') {
    $kata = '%' . $cari . '%';
    mysqli_stmt_bind_param($stmt, "sss", $kata, $kata, $kata);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Ringkasan jumlah aktivitas
$queryTotal = mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total FROM log_aktivitas"
);

$totalAktivitas = (int) mysqli_fetch_assoc($queryTotal)['total'];

// Aktivitas hari ini
$queryHariIni = mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total
     FROM log_aktivitas
     WHERE DATE(waktu) = CURDATE()"
);

$aktivitasHariIni = (int) mysqli_fetch_assoc($queryHariIni)['total'];

// Jumlah hasil pencarian
$totalHasil = mysqli_num_rows($result);

function e($nilai) {
    return htmlspecialchars((string) ($nilai ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log Aktivitas - KebayaRent</title>

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

        .btn-outline-light {
            border-radius: 10px;
            font-weight: 600;
        }

        .page-title {
            font-weight: 800;
            color: var(--ink);
        }

        .page-subtitle {
            color: var(--muted);
        }

        .pink-icon {
            display: inline-flex;
            width: 48px;
            height: 48px;
            align-items: center;
            justify-content: center;
            border-radius: 15px;
            background: var(--pink-soft);
            color: var(--pink-dark);
            font-size: 23px;
        }

        .stat-card,
        .main-card,
        .search-card {
            background: var(--white);
            border: 1px solid var(--pink-border);
            border-radius: 18px;
            box-shadow: 0 6px 24px rgba(166, 30, 89, .055);
        }

        .stat-card {
            padding: 22px;
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
            font-size: 29px;
            font-weight: 800;
            line-height: 1.2;
        }

        .search-card {
            padding: 20px;
        }

        .form-control {
            min-height: 44px;
            border-color: var(--pink-border);
            border-radius: 11px;
        }

        .form-control:focus {
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

        .btn-reset {
            background: var(--pink-soft);
            border: 1px solid var(--pink-border);
            color: var(--pink-dark);
            border-radius: 11px;
            font-weight: 700;
            padding: 10px 17px;
        }

        .btn-reset:hover {
            background: #fce0ec;
            color: var(--pink-dark);
        }

        .main-card {
            overflow: hidden;
        }

        .card-heading {
            padding: 20px 22px;
            border-bottom: 1px solid var(--pink-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .card-heading h5 {
            font-weight: 800;
            margin: 0;
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
            letter-spacing: .4px;
            white-space: nowrap;
            padding: 15px 16px;
            border-bottom: 1px solid var(--pink-border);
        }

        .table tbody td {
            padding: 15px 16px;
            border-color: #f8e8ef;
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background: #fff9fc;
        }

        .number-cell {
            color: var(--muted);
            font-weight: 700;
            width: 65px;
        }

        .user-name {
            font-weight: 700;
            color: var(--ink);
        }

        .username {
            display: block;
            margin-top: 3px;
            color: var(--muted);
            font-size: 12px;
        }

        .activity-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: var(--pink-soft);
            color: var(--pink-dark);
        }

        .activity-content {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 200px;
        }

        .time-text {
            color: #6f5c6b;
            font-size: 13px;
            white-space: nowrap;
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
            color: var(--muted);
            font-size: 12px;
            text-align: center;
            padding: 25px 0 10px;
        }

        @media (max-width: 576px) {
            .stat-card {
                padding: 17px;
            }

            .stat-number {
                font-size: 25px;
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
        <a class="navbar-brand" href="dashboard.php">
            <i class="bi bi-flower1 me-2"></i>KebayaRent
        </a>

        <a href="dashboard.php" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i>
            Dashboard
        </a>
    </div>
</nav>

<main class="container py-4 py-md-5">

    <div class="mb-4">
        <div class="d-flex align-items-center gap-3 mb-2">
            <div class="pink-icon">
                <i class="bi bi-clock-history"></i>
            </div>
            <div>
                <h2 class="page-title mb-1">Log Aktivitas</h2>
                <p class="page-subtitle mb-0">
                    Pantau riwayat aktivitas pengguna di sistem KebayaRent.
                </p>
            </div>
        </div>
    </div>

    <!-- Kartu ringkasan -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="pink-icon">
                        <i class="bi bi-journal-text"></i>
                    </div>
                    <div>
                        <div class="stat-label">Total Seluruh Aktivitas</div>
                        <div class="stat-number">
                            <?= number_format($totalAktivitas, 0, ',', '.') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="pink-icon">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div>
                        <div class="stat-label">Aktivitas Hari Ini</div>
                        <div class="stat-number">
                            <?= number_format($aktivitasHariIni, 0, ',', '.') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Form pencarian -->
    <div class="search-card mb-4">
        <form method="GET" action="log_aktivitas.php">
            <label for="cari" class="form-label fw-bold">
                <i class="bi bi-search me-1"></i>
                Cari Riwayat Aktivitas
            </label>

            <div class="row g-2">
                <div class="col-12 col-md">
                    <input
                        type="text"
                        name="cari"
                        id="cari"
                        class="form-control"
                        placeholder="Cari aktivitas, nama, atau username..."
                        value="<?= e($cari) ?>"
                    >
                </div>

                <div class="col-6 col-md-auto">
                    <button type="submit" class="btn btn-pink w-100">
                        <i class="bi bi-search me-1"></i>Cari
                    </button>
                </div>

                <div class="col-6 col-md-auto">
                    <a href="log_aktivitas.php" class="btn btn-reset w-100">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Tabel aktivitas -->
    <div class="main-card">
        <div class="card-heading">
            <h5>
                <i class="bi bi-list-ul me-2" style="color: var(--pink);"></i>
                Riwayat Aktivitas
            </h5>

            <span class="result-badge">
                <?= number_format($totalHasil, 0, ',', '.') ?> data ditemukan
            </span>
        </div>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Pengguna</th>
                        <th>Aktivitas</th>
                        <th>Waktu</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($totalHasil > 0): ?>
                    <?php $no = 1; ?>

                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                        <tr>
                            <td class="number-cell">
                                <?= $no++ ?>
                            </td>

                            <td>
                                <div class="user-name">
                                    <?= e($row['nama'] ?: 'Pengguna tidak tersedia') ?>
                                </div>

                                <?php if (!empty($row['username'])): ?>
                                    <span class="username">
                                        @<?= e($row['username']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="activity-content">
                                    <span class="activity-icon">
                                        <i class="bi bi-activity"></i>
                                    </span>
                                    <span><?= e($row['aktivitas']) ?></span>
                                </div>
                            </td>

                            <td>
                                <span class="time-text">
                                    <i class="bi bi-calendar3 me-1"></i>
                                    <?= e($row['waktu'] ?: '-') ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="4" class="empty-state">
                            <i class="bi bi-inbox"></i>

                            <div class="fw-bold mb-1">
                                <?= $cari !== ''
                                    ? 'Aktivitas tidak ditemukan'
                                    : 'Belum ada aktivitas' ?>
                            </div>

                            <div>
                                <?= $cari !== ''
                                    ? 'Coba gunakan kata kunci pencarian yang berbeda.'
                                    : 'Riwayat aktivitas akan muncul di sini setelah tercatat.' ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
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
mysqli_stmt_close($stmt);
?>
