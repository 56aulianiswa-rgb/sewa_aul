<?php
session_start();
require_once 'koneksi.php';

// Pertahankan autentikasi dashboard admin dan petugas.
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'petugas'], true)) {
    header("Location: ajukan.php");
    exit;
}

$nama = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Pengguna';

function hitung($koneksi, $sql) {
    $result = mysqli_query($koneksi, $sql);
    if (!$result) {
        return 0;
    }
    $row = mysqli_fetch_assoc($result);
    return (int)($row['jumlah'] ?? 0);
}

$total_kebaya = hitung(
    $koneksi,
    "SELECT COUNT(*) AS jumlah FROM kebaya WHERE status = 'tersedia'"
);
$total_penyewaan = hitung(
    $koneksi,
    "SELECT COUNT(*) AS jumlah FROM penyewaan"
);
$total_menunggu = hitung(
    $koneksi,
    "SELECT COUNT(*) AS jumlah FROM penyewaan WHERE status = 'menunggu'"
);
$total_selesai = hitung(
    $koneksi,
    "SELECT COUNT(*) AS jumlah FROM penyewaan WHERE status = 'selesai'"
);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | KebayaRent</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --pink: #a71955;
            --pink-dark: #78123d;
            --pink-soft: #fff0f5;
            --pink-line: #f4dce6;
            --ink: #302632;
            --muted: #857985;
            --surface: #ffffff;
            --page: #fff8fa;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--page);
            color: var(--ink);
            font-family: Inter, "Segoe UI", Arial, sans-serif;
        }

        .sidebar {
            width: 258px;
            position: fixed;
            inset: 0 auto 0 0;
            z-index: 1030;
            display: flex;
            flex-direction: column;
            padding: 25px 17px 18px;
            color: #fff;
            background: linear-gradient(175deg, #a71955 0%, #78123d 100%);
            overflow-y: auto;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 10px 28px;
            color: #fff;
            text-decoration: none;
        }

        .brand-mark {
            width: 43px;
            height: 43px;
            display: grid;
            place-items: center;
            border-radius: 14px;
            background: rgba(255,255,255,.17);
            font-size: 23px;
        }

        .brand-name { font-size: 21px; font-weight: 800; letter-spacing: -.5px; }
        .brand-caption { display: block; margin-top: 1px; font-size: 11px; color: #f9dbe7; letter-spacing: 1.4px; }

        .nav-label {
            padding: 10px 12px 8px;
            color: #f0c4d6;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .side-link {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 45px;
            margin: 3px 0;
            padding: 11px 13px;
            border-radius: 12px;
            color: #fbeaf1;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: background .2s, transform .2s;
        }

        .side-link i { width: 19px; font-size: 17px; text-align: center; }
        .side-link:hover, .side-link.active {
            color: #fff;
            background: rgba(255,255,255,.16);
        }
        .side-link:hover { transform: translateX(2px); }
        .side-link .badge { margin-left: auto; }

        .sidebar-bottom {
            margin-top: auto;
            padding: 18px 8px 0;
        }

        .profile-mini {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 13px 10px;
            border-top: 1px solid rgba(255,255,255,.18);
        }

        .avatar {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: #f9dce7;
            color: var(--pink-dark);
            font-size: 17px;
            font-weight: 800;
        }

        .profile-name { max-width: 135px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; font-weight: 700; }
        .profile-role { margin-top: 2px; color: #f5cbdc; font-size: 11px; text-transform: capitalize; }

        .main {
            min-height: 100vh;
            margin-left: 258px;
            padding: 28px clamp(18px, 3vw, 42px) 24px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            margin-bottom: 30px;
        }

        .eyebrow {
            margin-bottom: 7px;
            color: var(--pink);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 1.7px;
            text-transform: uppercase;
        }

        .page-title { margin: 0; font-size: clamp(25px, 3vw, 34px); font-weight: 800; letter-spacing: -1.1px; }
        .page-subtitle { margin: 8px 0 0; color: var(--muted); font-size: 14px; }

        .top-actions { display: flex; align-items: center; gap: 10px; }
        .today-chip {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 11px 14px;
            border: 1px solid var(--pink-line);
            border-radius: 12px;
            background: #fff;
            color: #6e5c69;
            font-size: 12px;
            white-space: nowrap;
        }

        .welcome-banner {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            min-height: 175px;
            margin-bottom: 25px;
            padding: 28px 32px;
            overflow: hidden;
            border-radius: 22px;
            color: #fff;
            background: linear-gradient(112deg, #a71955 0%, #c13a70 58%, #d86c98 100%);
            box-shadow: 0 12px 28px rgba(167,25,85,.13);
        }

        .welcome-banner::before, .welcome-banner::after {
            content: "";
            position: absolute;
            border: 1px solid rgba(255,255,255,.16);
            border-radius: 50%;
            pointer-events: none;
        }
        .welcome-banner::before { width: 260px; height: 260px; right: 8%; top: -150px; }
        .welcome-banner::after { width: 210px; height: 210px; right: -25px; bottom: -140px; }

        .welcome-copy { position: relative; z-index: 1; }
        .welcome-copy .small-label { color: #ffe0ec; font-size: 12px; font-weight: 700; letter-spacing: 1.3px; text-transform: uppercase; }
        .welcome-copy h2 { margin: 9px 0 8px; font-size: clamp(22px, 3vw, 29px); font-weight: 800; letter-spacing: -.6px; }
        .welcome-copy p { max-width: 520px; margin: 0; color: #ffe8f0; font-size: 13px; line-height: 1.7; }

        .welcome-art {
            position: relative;
            z-index: 1;
            width: 105px;
            height: 105px;
            flex: 0 0 105px;
            display: grid;
            place-items: center;
            border: 1px solid rgba(255,255,255,.28);
            border-radius: 28px;
            background: rgba(255,255,255,.13);
            font-size: 52px;
            transform: rotate(5deg);
        }

        .section-heading {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 12px;
            margin: 30px 0 16px;
        }
        .section-heading h2 { margin: 0; font-size: 18px; font-weight: 800; }
        .section-heading p { margin: 5px 0 0; color: var(--muted); font-size: 12px; }

        .stat-card {
            position: relative;
            height: 100%;
            min-height: 150px;
            padding: 22px;
            border: 1px solid var(--pink-line);
            border-radius: 18px;
            background: var(--surface);
            box-shadow: 0 5px 18px rgba(78, 24, 48, .035);
            transition: transform .2s, box-shadow .2s;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(78,24,48,.07); }

        .stat-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .stat-label { color: var(--muted); font-size: 12px; font-weight: 600; }
        .stat-icon {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 13px;
            font-size: 20px;
        }
        .icon-pink { color: #a71955; background: #ffedf4; }
        .icon-purple { color: #7752a5; background: #f1eaff; }
        .icon-orange { color: #b66a17; background: #fff2df; }
        .icon-green { color: #28815d; background: #e5f7ee; }
        .stat-number { margin-top: 13px; font-size: 32px; line-height: 1.1; font-weight: 800; letter-spacing: -1px; }
        .stat-foot { margin-top: 8px; color: #a095a0; font-size: 11px; }

        .content-card {
            height: 100%;
            padding: 22px;
            border: 1px solid var(--pink-line);
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 5px 18px rgba(78,24,48,.035);
        }

        .quick-link {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            height: 100%;
            min-height: 142px;
            padding: 18px;
            border: 1px solid #f3e4eb;
            border-radius: 15px;
            background: #fff;
            color: inherit;
            text-decoration: none;
            transition: border-color .2s, transform .2s, box-shadow .2s;
        }
        .quick-link:hover {
            border-color: #e7a7c1;
            transform: translateY(-3px);
            box-shadow: 0 10px 22px rgba(94,20,51,.06);
            color: inherit;
        }

        .quick-icon {
            width: 45px;
            height: 45px;
            flex: 0 0 45px;
            display: grid;
            place-items: center;
            border-radius: 14px;
            background: var(--pink-soft);
            color: var(--pink);
            font-size: 21px;
        }
        .quick-link h3 { margin: 2px 0 7px; font-size: 14px; font-weight: 800; }
        .quick-link p { margin: 0; color: var(--muted); font-size: 12px; line-height: 1.65; }
        .quick-arrow { margin-left: auto; color: #b7a6b0; }

        .pending-panel {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 18px;
            border: 1px solid #f3d5a7;
            border-radius: 15px;
            background: #fffaf1;
        }
        .pending-icon {
            width: 48px;
            height: 48px;
            flex: 0 0 48px;
            display: grid;
            place-items: center;
            border-radius: 15px;
            background: #ffedca;
            color: #a96510;
            font-size: 23px;
        }
        .pending-panel h3 { margin: 0 0 4px; font-size: 14px; font-weight: 800; }
        .pending-panel p { margin: 0; color: #8d7a61; font-size: 12px; }
        .btn-pink {
            border: 0;
            border-radius: 11px;
            padding: 10px 15px;
            background: var(--pink);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
        }
        .btn-pink:hover { background: var(--pink-dark); color: #fff; }
        .footer { padding: 28px 0 4px; color: #a1949e; text-align: center; font-size: 11px; }

        .mobile-bar { display: none; }

        @media (max-width: 1199px) {
            .sidebar { width: 230px; }
            .main { margin-left: 230px; }
            .side-link { font-size: 12px; }
            .today-chip { display: none; }
        }

        @media (max-width: 991.98px) {
            .sidebar {
                position: static;
                width: 100%;
                padding: 13px 18px;
                overflow: visible;
            }
            .brand { padding: 0; }
            .sidebar .nav-label, .sidebar nav, .sidebar-bottom { display: none; }
            .mobile-bar { display: block; }
            .mobile-menu {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 7px;
                margin-top: 14px;
            }
            .mobile-menu .side-link {
                justify-content: center;
                gap: 7px;
                margin: 0;
                padding: 10px 6px;
                border: 1px solid rgba(255,255,255,.14);
                font-size: 11px;
            }
            .mobile-menu .side-link i { width: auto; }
            .main { margin-left: 0; padding: 25px 20px; }
        }

        @media (max-width: 575.98px) {
            .main { padding: 22px 14px; }
            .topbar { align-items: flex-start; margin-bottom: 22px; }
            .page-title { font-size: 26px; }
            .page-subtitle { font-size: 12px; line-height: 1.6; }
            .welcome-banner { min-height: 155px; padding: 22px 20px; border-radius: 18px; }
            .welcome-art { width: 65px; height: 65px; flex-basis: 65px; border-radius: 20px; font-size: 33px; }
            .welcome-copy h2 { font-size: 22px; }
            .welcome-copy p { font-size: 12px; }
            .stat-card { min-height: 135px; padding: 17px; }
            .stat-number { font-size: 28px; }
            .content-card { padding: 16px; }
            .quick-link { min-height: 120px; padding: 14px; gap: 10px; }
            .quick-icon { width: 38px; height: 38px; flex-basis: 38px; font-size: 18px; }
            .pending-panel { align-items: flex-start; flex-wrap: wrap; }
            .pending-panel .btn-pink { width: 100%; }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <a class="brand" href="index.php">
        <span class="brand-mark"><i class="bi bi-flower1"></i></span>
        <span>
            <span class="brand-name">KebayaRent</span>
            <span class="brand-caption">RENTAL MANAGEMENT</span>
        </span>
    </a>

    <div class="nav-label">Menu utama</div>
    <nav aria-label="Navigasi utama">
        <a class="side-link active" href="index.php"><i class="bi bi-grid-1x2-fill"></i>Dashboard</a>
        <a class="side-link" href="kebaya.php"><i class="bi bi-person-standing-dress"></i>Data Kebaya</a>
        <?php if ($role === 'admin'): ?>
            <a class="side-link" href="kategori.php"><i class="bi bi-tags"></i>Kategori</a>
        <?php endif; ?>
        <a class="side-link" href="penyewaan.php"><i class="bi bi-receipt"></i>Penyewaan</a>
        <a class="side-link" href="persetujuan.php">
            <i class="bi bi-clipboard-check"></i>Persetujuan
            <?php if ($total_menunggu > 0): ?>
                <span class="badge rounded-pill bg-warning text-dark"><?= $total_menunggu ?></span>
            <?php endif; ?>
        </a>
        <a class="side-link" href="pengembalian.php"><i class="bi bi-arrow-return-left"></i>Pengembalian</a>

        <?php if ($role === 'admin'): ?>
            <div class="nav-label mt-3">Administrasi</div>
            <a class="side-link" href="users.php"><i class="bi bi-people"></i>Data Pengguna</a>
            <a class="side-link" href="laporan.php"><i class="bi bi-bar-chart-line"></i>Laporan</a>
            <a class="side-link" href="log_aktivitas.php"><i class="bi bi-clock-history"></i>Log Aktivitas</a>
        <?php endif; ?>
    </nav>

    <div class="mobile-bar">
        <div class="mobile-menu">
            <a class="side-link active" href="index.php"><i class="bi bi-grid-1x2-fill"></i>Dashboard</a>
            <a class="side-link" href="kebaya.php"><i class="bi bi-person-standing-dress"></i>Kebaya</a>
            <a class="side-link" href="penyewaan.php"><i class="bi bi-receipt"></i>Penyewaan</a>
            <a class="side-link" href="persetujuan.php"><i class="bi bi-clipboard-check"></i>Persetujuan</a>
            <a class="side-link" href="pengembalian.php"><i class="bi bi-arrow-return-left"></i>Kembali</a>
            <?php if ($role === 'admin'): ?>
                <a class="side-link" href="kategori.php"><i class="bi bi-tags"></i>Kategori</a>
                <a class="side-link" href="users.php"><i class="bi bi-people"></i>Pengguna</a>
                <a class="side-link" href="laporan.php"><i class="bi bi-bar-chart-line"></i>Laporan</a>
                <a class="side-link" href="log_aktivitas.php"><i class="bi bi-clock-history"></i>Log</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="sidebar-bottom">
        <div class="profile-mini">
            <div class="avatar"><?= strtoupper(substr($nama, 0, 1)) ?></div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="profile-name"><?= htmlspecialchars($nama, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="profile-role"><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <a href="logout.php" class="text-white fs-5" title="Logout" aria-label="Logout">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</aside>

<main class="main">
    <header class="topbar">
        <div>
            <div class="eyebrow">Panel pengelola</div>
            <h1 class="page-title">Dashboard</h1>
            <p class="page-subtitle">Pantau koleksi kebaya dan aktivitas penyewaanmu di sini.</p>
        </div>
        <div class="top-actions">
            <div class="today-chip"><i class="bi bi-calendar3"></i><?= date('d M Y') ?></div>
            <a href="logout.php" class="btn btn-outline-danger btn-sm d-lg-none">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </header>

    <section class="welcome-banner">
        <div class="welcome-copy">
            <div class="small-label">Selamat datang kembali</div>
            <h2>Halo, <?= htmlspecialchars($nama, ENT_QUOTES, 'UTF-8') ?>! <span aria-hidden="true">✿</span></h2>
            <p>Selamat bekerja di KebayaRent. Kelola koleksi, periksa pengajuan, dan pastikan pengalaman penyewaan berjalan lancar.</p>
        </div>
        <div class="welcome-art" aria-hidden="true"><i class="bi bi-flower2"></i></div>
    </section>

    <div class="section-heading">
        <div>
            <h2>Ringkasan Sistem</h2>
            <p>Gambaran singkat data penyewaan saat ini.</p>
        </div>
    </div>

    <section class="row g-3">
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Kebaya tersedia</span>
                    <span class="stat-icon icon-pink"><i class="bi bi-person-standing-dress"></i></span>
                </div>
                <div class="stat-number"><?= $total_kebaya ?></div>
                <div class="stat-foot">Koleksi berstatus tersedia</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Total penyewaan</span>
                    <span class="stat-icon icon-purple"><i class="bi bi-receipt-cutoff"></i></span>
                </div>
                <div class="stat-number"><?= $total_penyewaan ?></div>
                <div class="stat-foot">Seluruh transaksi tercatat</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Menunggu persetujuan</span>
                    <span class="stat-icon icon-orange"><i class="bi bi-hourglass-split"></i></span>
                </div>
                <div class="stat-number"><?= $total_menunggu ?></div>
                <div class="stat-foot">Pengajuan perlu diperiksa</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Penyewaan selesai</span>
                    <span class="stat-icon icon-green"><i class="bi bi-check2-circle"></i></span>
                </div>
                <div class="stat-number"><?= $total_selesai ?></div>
                <div class="stat-foot">Transaksi berstatus selesai</div>
            </div>
        </div>
    </section>

    <div class="section-heading">
        <div>
            <h2>Akses Cepat</h2>
            <p>Pilih menu untuk melanjutkan pekerjaan.</p>
        </div>
    </div>

    <section class="row g-3">
        <div class="col-md-6 col-xl-4">
            <a class="quick-link" href="kebaya.php">
                <span class="quick-icon"><i class="bi bi-person-standing-dress"></i></span>
                <span>
                    <h3>Data Kebaya</h3>
                    <p>Kelola koleksi, harga sewa, stok, dan foto kebaya.</p>
                </span>
                <i class="bi bi-arrow-up-right quick-arrow"></i>
            </a>
        </div>
        <div class="col-md-6 col-xl-4">
            <a class="quick-link" href="penyewaan.php">
                <span class="quick-icon"><i class="bi bi-receipt"></i></span>
                <span>
                    <h3>Data Penyewaan</h3>
                    <p>Lihat dan telusuri transaksi penyewaan yang tercatat.</p>
                </span>
                <i class="bi bi-arrow-up-right quick-arrow"></i>
            </a>
        </div>
        <div class="col-md-6 col-xl-4">
            <a class="quick-link" href="persetujuan.php">
                <span class="quick-icon"><i class="bi bi-clipboard-check"></i></span>
                <span>
                    <h3>Persetujuan</h3>
                    <p>Periksa pengajuan penyewa dan tentukan statusnya.</p>
                </span>
                <i class="bi bi-arrow-up-right quick-arrow"></i>
            </a>
        </div>
        <div class="col-md-6 col-xl-4">
            <a class="quick-link" href="pengembalian.php">
                <span class="quick-icon"><i class="bi bi-arrow-return-left"></i></span>
                <span>
                    <h3>Pengembalian</h3>
                    <p>Catat pengembalian kebaya, kondisi, dan denda.</p>
                </span>
                <i class="bi bi-arrow-up-right quick-arrow"></i>
            </a>
        </div>

        <?php if ($role === 'admin'): ?>
            <div class="col-md-6 col-xl-4">
                <a class="quick-link" href="kategori.php">
                    <span class="quick-icon"><i class="bi bi-tags"></i></span>
                    <span>
                        <h3>Kategori Kebaya</h3>
                        <p>Atur kategori untuk menjaga koleksi tetap tertata.</p>
                    </span>
                    <i class="bi bi-arrow-up-right quick-arrow"></i>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a class="quick-link" href="users.php">
                    <span class="quick-icon"><i class="bi bi-people"></i></span>
                    <span>
                        <h3>Data Pengguna</h3>
                        <p>Kelola akun dan akses pengguna sistem.</p>
                    </span>
                    <i class="bi bi-arrow-up-right quick-arrow"></i>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a class="quick-link" href="laporan.php">
                    <span class="quick-icon"><i class="bi bi-bar-chart-line"></i></span>
                    <span>
                        <h3>Laporan</h3>
                        <p>Lihat ringkasan dan rekap transaksi penyewaan.</p>
                    </span>
                    <i class="bi bi-arrow-up-right quick-arrow"></i>
                </a>
            </div>
            <div class="col-md-6 col-xl-4">
                <a class="quick-link" href="log_aktivitas.php">
                    <span class="quick-icon"><i class="bi bi-clock-history"></i></span>
                    <span>
                        <h3>Log Aktivitas</h3>
                        <p>Pantau aktivitas yang tercatat di dalam sistem.</p>
                    </span>
                    <i class="bi bi-arrow-up-right quick-arrow"></i>
                </a>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($total_menunggu > 0): ?>
        <div class="section-heading">
            <div>
                <h2>Perlu Perhatian</h2>
                <p>Ada pengajuan yang belum diproses.</p>
            </div>
        </div>
        <section class="pending-panel">
            <div class="pending-icon"><i class="bi bi-bell"></i></div>
            <div class="flex-grow-1">
                <h3><?= $total_menunggu ?> pengajuan menunggu persetujuan</h3>
                <p>Periksa detail pengajuan agar proses penyewaan bisa dilanjutkan.</p>
            </div>
            <a href="persetujuan.php" class="btn btn-pink">Periksa Pengajuan <i class="bi bi-arrow-right ms-1"></i></a>
        </section>
    <?php endif; ?>

    <footer class="footer">
        &copy; <?= date('Y') ?> KebayaRent · Sistem Informasi Penyewaan Kebaya
    </footer>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
