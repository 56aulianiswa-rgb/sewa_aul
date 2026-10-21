<?php
session_start();
require_once 'koneksi.php';

if (
    !isset($_SESSION['id_user'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'petugas'], true)
) {
    header("Location: login.php");
    exit;
}

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah($angka) {
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}

function tanggalIndo($tanggal) {
    if (!$tanggal) {
        return '-';
    }

    $waktu = strtotime($tanggal);
    return $waktu ? date('d-m-Y', $waktu) : '-';
}

$tanggal_awal = $_GET['awal'] ?? '';
$tanggal_akhir = $_GET['akhir'] ?? '';

$error = '';

/* Validasi tanggal filter */
if (
    ($tanggal_awal !== '' &&
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_awal)) ||
    ($tanggal_akhir !== '' &&
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_akhir))
) {
    $error = 'Format tanggal tidak valid.';
    $tanggal_awal = '';
    $tanggal_akhir = '';
}

if (
    $tanggal_awal !== '' &&
    $tanggal_akhir !== '' &&
    $tanggal_awal > $tanggal_akhir
) {
    $error = 'Tanggal awal tidak boleh melebihi tanggal akhir.';
    $tanggal_awal = '';
    $tanggal_akhir = '';
}

/* Filter laporan berdasarkan tanggal pesan */
$where = '';
$params = [];
$types = '';

if ($tanggal_awal !== '' && $tanggal_akhir !== '') {
    $where = ' WHERE DATE(p.tanggal_pesan) BETWEEN ? AND ?';
    $params = [$tanggal_awal, $tanggal_akhir];
    $types = 'ss';
} elseif ($tanggal_awal !== '') {
    $where = ' WHERE DATE(p.tanggal_pesan) >= ?';
    $params = [$tanggal_awal];
    $types = 's';
} elseif ($tanggal_akhir !== '') {
    $where = ' WHERE DATE(p.tanggal_pesan) <= ?';
    $params = [$tanggal_akhir];
    $types = 's';
}

$sql = "SELECT
            p.id_penyewaan,
            p.kode_penyewaan,
            u.nama AS nama_penyewa,
            p.tanggal_pesan,
            p.tanggal_mulai,
            p.tanggal_selesai,
            p.total,
            p.status
        FROM penyewaan p
        JOIN users u ON p.id_user = u.id_user
        $where
        ORDER BY p.tanggal_pesan DESC, p.id_penyewaan DESC";

$stmt = mysqli_prepare($koneksi, $sql);

if (!$stmt) {
    die('Laporan tidak dapat diproses. Periksa query database.');
}

if ($types !== '') {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$total_transaksi = 0;
$total_pendapatan = 0;
$jumlah_selesai = 0;
$data = [];

while ($row = mysqli_fetch_assoc($result)) {
    $data[] = $row;
    $total_transaksi++;

    if ($row['status'] === 'selesai') {
        $total_pendapatan += (float)$row['total'];
        $jumlah_selesai++;
    }
}

mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Laporan Penyewaan - KebayaRent</title>

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
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: var(--ink);
            background: #fff8fb;
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 14px;
        }

        .navbar-custom {
            background: linear-gradient(
                120deg,
                #9d174d,
                #c0266d 55%,
                #e65b99
            );
            box-shadow: 0 5px 20px #9d174d25;
        }

        .navbar-brand {
            color: #fff !important;
            font-size: 1.3rem;
            font-weight: 800;
        }

        .navbar-brand span {
            color: #ffd8e9;
        }

        .hero {
            padding: 35px 0 76px;
            background:
                radial-gradient(
                    circle at 90% 15%,
                    #f9c6df88,
                    transparent 35%
                ),
                linear-gradient(135deg, #fff0f6, #fff9fc);
            border-bottom: 1px solid #f9e6ef;
        }

        .eyebrow {
            display: inline-block;
            padding: 7px 12px;
            color: var(--pink-dark);
            background: #fff;
            border: 1px solid var(--pink-border);
            border-radius: 30px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .5px;
            text-transform: uppercase;
        }

        .hero h1 {
            margin: 15px 0 8px;
            color: #4a2140;
            font-size: clamp(27px, 4vw, 35px);
            font-weight: 850;
            letter-spacing: -.8px;
        }

        .hero p {
            max-width: 680px;
            margin: 0;
            color: var(--muted);
            line-height: 1.8;
        }

        .main-wrap {
            position: relative;
            margin-top: -47px;
            padding-bottom: 45px;
        }

        .panel {
            overflow: hidden;
            background: #fff;
            border: 1px solid var(--pink-border);
            border-radius: 17px;
            box-shadow: 0 8px 25px #9123590b;
        }

        .panel-header {
            padding: 21px 24px;
            background: linear-gradient(110deg, #fff0f6, #fffafd);
            border-bottom: 1px solid #f7e0eb;
        }

        .panel-header h2 {
            margin: 0;
            color: #542541;
            font-size: 16px;
            font-weight: 800;
        }

        .panel-header p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .panel-body {
            padding: 23px;
        }

        .filter-label {
            margin-bottom: 8px;
            color: #63485c;
            font-size: 12px;
            font-weight: 750;
        }

        .form-control {
            min-height: 44px;
            border: 1px solid #e9dce5;
            border-radius: 9px;
            font-size: 13px;
        }

        .form-control:focus {
            border-color: #e879a8;
            box-shadow: 0 0 0 3px #f472b624;
        }

        .btn-pink {
            color: #fff;
            background: linear-gradient(110deg, #be185d, #e6498b);
            border: 0;
            border-radius: 9px;
            font-weight: 700;
        }

        .btn-pink:hover {
            color: #fff;
            background: linear-gradient(110deg, #9d174d, #c0266d);
        }

        .btn-soft {
            color: var(--pink-dark);
            background: #fff;
            border: 1px solid #edc6d9;
            border-radius: 9px;
            font-weight: 700;
        }

        .btn-soft:hover {
            color: var(--pink-dark);
            background: var(--pink-soft);
        }

        .btn-print {
            color: #fff;
            background: #287d58;
            border: 1px solid #287d58;
            border-radius: 9px;
            font-weight: 700;
        }

        .btn-print:hover {
            color: #fff;
            background: #206545;
        }

        .stat-card {
            display: flex;
            align-items: center;
            gap: 15px;
            height: 100%;
            padding: 21px;
            background: #fff;
            border: 1px solid var(--pink-border);
            border-radius: 16px;
            box-shadow: 0 8px 25px #9123590b;
        }

        .stat-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 49px;
            height: 49px;
            color: var(--pink);
            background: var(--pink-soft);
            border-radius: 14px;
            font-size: 21px;
        }

        .stat-label {
            margin-bottom: 5px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 650;
        }

        .stat-value {
            color: #542541;
            font-size: clamp(18px, 2vw, 23px);
            font-weight: 850;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }

        .table {
            margin: 0;
            vertical-align: middle;
        }

        .table thead th {
            padding: 14px 13px;
            color: #76556a;
            background: #fff4f9;
            border-bottom: 1px solid #f0dce7;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .3px;
            white-space: nowrap;
            text-transform: uppercase;
        }

        .table tbody td {
            padding: 13px;
            color: #654e60;
            border-color: #f5eaf0;
            font-size: 12px;
            white-space: nowrap;
        }

        .table tbody tr:hover td {
            background: #fffafd;
        }

        .kode {
            color: var(--pink-dark);
            font-weight: 800;
        }

        .badge-status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 30px;
            font-size: 10px;
            font-weight: 800;
        }

        .status-menunggu {
            color: #8a6500;
            background: #fff4cf;
        }

        .status-disetujui {
            color: #225db2;
            background: #e6f0ff;
        }

        .status-ditolak,
        .status-dibatalkan {
            color: #a42b45;
            background: #ffe6eb;
        }

        .status-dipinjam {
            color: #7c43a1;
            background: #f2e5ff;
        }

        .status-selesai {
            color: #16734a;
            background: #e5f8ee;
        }

        .empty-state {
            padding: 45px 20px;
            color: var(--muted);
            text-align: center;
        }

        .empty-state i {
            display: block;
            margin-bottom: 12px;
            color: #e9a2c2;
            font-size: 36px;
        }

        .footer {
            padding: 22px 15px 28px;
            color: #a18b9b;
            background: #fff;
            border-top: 1px solid #f4e4ec;
            font-size: 12px;
            text-align: center;
        }

        .print-only {
            display: none;
        }

        @media (max-width: 767px) {
            .hero {
                padding: 28px 0 65px;
            }

            .main-wrap {
                margin-top: -38px;
            }

            .panel-header,
            .panel-body {
                padding: 17px;
            }

            .stat-card {
                align-items: flex-start;
                gap: 10px;
                padding: 14px;
            }

            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 18px;
            }

            .stat-value {
                font-size: 18px;
            }

            .filter-actions {
                display: grid;
                grid-template-columns: 1fr 1fr;
            }

            .filter-actions button,
            .filter-actions a {
                width: 100%;
            }
        }

        @media print {
            @page {
                size: landscape;
                margin: 12mm;
            }

            body {
                color: #000;
                background: #fff;
                font-family: Arial, sans-serif;
                font-size: 10px;
            }

            .navbar-custom,
            .hero,
            .filter-panel,
            .footer,
            .no-print {
                display: none !important;
            }

            .main-wrap {
                width: 100%;
                max-width: none;
                margin: 0;
                padding: 0;
            }

            .panel,
            .stat-card {
                border: 1px solid #ccc;
                box-shadow: none;
                break-inside: avoid;
            }

            .panel-header {
                padding: 10px;
                background: #fff !important;
            }

            .panel-body {
                padding: 10px;
            }

            .stat-card {
                padding: 10px;
            }

            .stat-icon {
                display: none;
            }

            .table thead th,
            .table tbody td {
                padding: 7px;
                color: #000;
                background: #fff !important;
                border: 1px solid #bbb;
                font-size: 9px;
            }

            .print-only {
                display: block;
            }

            .badge-status {
                padding: 0;
                color: #000 !important;
                background: #fff !important;
            }

            .report-title {
                margin-bottom: 14px;
                text-align: center;
            }

            .report-title h2 {
                font-size: 18px;
                font-weight: bold;
            }
        }
    </style>
</head>

<body>

<nav class="navbar navbar-expand navbar-custom py-3 no-print">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <i class="bi bi-flower1 me-2"></i>Kebaya<span>Rent</span>
        </a>

        <a href="index.php" class="btn btn-outline-light btn-sm px-3">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
    </div>
</nav>

<section class="hero no-print">
    <div class="container">
        <span class="eyebrow">
            <i class="bi bi-file-earmark-bar-graph me-1"></i>
            Laporan dan rekapitulasi
        </span>

        <h1>Laporan Penyewaan</h1>

        <p>
            Pantau transaksi, status penyewaan, dan total nilai penyewaan
            yang telah selesai dalam periode pilihanmu.
        </p>
    </div>
</section>

<main class="container main-wrap">

    <div class="print-only report-title">
        <h2>Laporan Penyewaan KebayaRent</h2>
        <p>
            Periode:
            <?= $tanggal_awal ? tanggalIndo($tanggal_awal) : 'Semua tanggal' ?>
            s.d.
            <?= $tanggal_akhir ? tanggalIndo($tanggal_akhir) : 'Semua tanggal' ?>
        </p>
        <p>Tanggal cetak: <?= date('d-m-Y') ?></p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger no-print">
            <i class="bi bi-exclamation-circle me-2"></i><?= h($error) ?>
        </div>
    <?php endif; ?>

    <section class="panel filter-panel mb-4">
        <div class="panel-header">
            <h2>
                <i class="bi bi-calendar3 me-2"
                   style="color:var(--pink)"></i>
                Filter Periode Laporan
            </h2>
            <p>Pilih rentang tanggal pesan untuk menyaring transaksi.</p>
        </div>

        <div class="panel-body">
            <form method="GET" action="laporan.php">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label filter-label" for="awal">
                            Tanggal awal
                        </label>
                        <input type="date" name="awal" id="awal"
                               class="form-control"
                               value="<?= h($tanggal_awal) ?>"
                               max="<?= h($tanggal_akhir ?: '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label filter-label" for="akhir">
                            Tanggal akhir
                        </label>
                        <input type="date" name="akhir" id="akhir"
                               class="form-control"
                               value="<?= h($tanggal_akhir) ?>"
                               min="<?= h($tanggal_awal ?: '') ?>">
                    </div>

                    <div class="col-md-4">
                        <div class="filter-actions d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-pink px-3 py-2">
                                <i class="bi bi-funnel me-1"></i>Filter
                            </button>

                            <a href="laporan.php" class="btn btn-soft px-3 py-2">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                            </a>

                            <button type="button" onclick="window.print()"
                                    class="btn btn-print px-3 py-2">
                                <i class="bi bi-printer me-1"></i>Cetak
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-receipt"></i>
                </div>
                <div>
                    <div class="stat-label">Total Transaksi</div>
                    <div class="stat-value"><?= $total_transaksi ?></div>
                    <div class="small text-muted mt-1">Sesuai periode filter</div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-check2-circle"></i>
                </div>
                <div>
                    <div class="stat-label">Penyewaan Selesai</div>
                    <div class="stat-value"><?= $jumlah_selesai ?></div>
                    <div class="small text-muted mt-1">Status selesai</div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-cash-coin"></i>
                </div>
                <div>
                    <div class="stat-label">Total Nilai Penyewaan Selesai</div>
                    <div class="stat-value"><?= rupiah($total_pendapatan) ?></div>
                    <div class="small text-muted mt-1">
                        Berdasarkan transaksi berstatus selesai
                    </div>
                </div>
            </div>
        </div>
    </div>

    <section class="panel">
        <div class="panel-header d-flex flex-wrap justify-content-between
                    align-items-center gap-2">
            <div>
                <h2>
                    <i class="bi bi-table me-2"
                       style="color:var(--pink)"></i>
                    Rincian Transaksi
                </h2>
                <p>Daftar penyewaan berdasarkan periode yang dipilih.</p>
            </div>

            <span class="badge rounded-pill text-bg-light border">
                <?= count($data) ?> transaksi
            </span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Penyewaan</th>
                        <th>Nama Penyewa</th>
                        <th>Tanggal Pesan</th>
                        <th>Tanggal Mulai</th>
                        <th>Tanggal Selesai</th>
                        <th>Total</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (count($data) > 0): ?>
                    <?php foreach ($data as $i => $row): ?>
                        <?php
                        $statusClass = 'status-' . $row['status'];
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>

                            <td class="kode">
                                <?= h($row['kode_penyewaan']) ?>
                            </td>

                            <td><?= h($row['nama_penyewa']) ?></td>

                            <td><?= tanggalIndo($row['tanggal_pesan']) ?></td>

                            <td><?= tanggalIndo($row['tanggal_mulai']) ?></td>

                            <td><?= tanggalIndo($row['tanggal_selesai']) ?></td>

                            <td class="fw-bold"><?= rupiah($row['total']) ?></td>

                            <td>
                                <span class="badge-status <?= h($statusClass) ?>">
                                    <?= h(ucfirst($row['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>
                                <div class="fw-bold mb-1">
                                    Belum ada data penyewaan
                                </div>
                                <div>
                                    Tidak ditemukan transaksi pada periode tersebut.
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="panel-body border-top">
            <div class="d-flex flex-wrap justify-content-between gap-2">
                <span class="text-muted small">
                    Total transaksi: <strong><?= $total_transaksi ?></strong>
                </span>
                <span class="text-muted small">
                    Nilai penyewaan selesai:
                    <strong><?= rupiah($total_pendapatan) ?></strong>
                </span>
            </div>
        </div>
    </section>
</main>

<footer class="footer no-print">
    © <?= date('Y') ?> KebayaRent · Laporan penyewaan.
</footer>

</body>
</html>
