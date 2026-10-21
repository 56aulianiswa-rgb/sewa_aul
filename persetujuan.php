<?php
session_start();
require_once 'koneksi.php';

// Hanya admin dan petugas yang dapat mengakses halaman ini
if (
    !isset($_SESSION['id_user'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'petugas'], true)
) {
    header("Location: login.php");
    exit;
}

function e($nilai)
{
    return htmlspecialchars((string) $nilai, ENT_QUOTES, 'UTF-8');
}

$pesan = '';
$jenisPesan = 'info';

// Proses persetujuan atau penolakan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id_penyewaan'] ?? 0);
    $aksi = $_POST['aksi'] ?? '';

    if ($id > 0 && in_array($aksi, ['disetujui', 'ditolak'], true)) {
        mysqli_begin_transaction($koneksi);

        try {
            // Kunci data agar pesanan tidak diproses bersamaan
            $stmt = mysqli_prepare(
                $koneksi,
                "SELECT status FROM penyewaan
                 WHERE id_penyewaan = ? FOR UPDATE"
            );
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            $hasil = mysqli_stmt_get_result($stmt);
            $pesanan = mysqli_fetch_assoc($hasil);
            mysqli_stmt_close($stmt);

            if (!$pesanan || $pesanan['status'] !== 'menunggu') {
                throw new Exception(
                    "Pesanan tidak ditemukan atau sudah diproses."
                );
            }

            // Perbarui status pesanan
            $stmt = mysqli_prepare(
                $koneksi,
                "UPDATE penyewaan
                 SET status = ?
                 WHERE id_penyewaan = ? AND status = 'menunggu'"
            );
            mysqli_stmt_bind_param($stmt, "si", $aksi, $id);
            mysqli_stmt_execute($stmt);

            if (mysqli_stmt_affected_rows($stmt) !== 1) {
                mysqli_stmt_close($stmt);
                throw new Exception("Status pesanan gagal diperbarui.");
            }
            mysqli_stmt_close($stmt);

            // Catat perubahan status
            $statusLama = 'menunggu';
            $stmt = mysqli_prepare(
                $koneksi,
                "INSERT INTO log_status_penyewaan
                 (id_penyewaan, status_lama, status_baru)
                 VALUES (?, ?, ?)"
            );
            mysqli_stmt_bind_param(
                $stmt,
                "iss",
                $id,
                $statusLama,
                $aksi
            );
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Catat aktivitas admin atau petugas
            $idUser = (int) $_SESSION['id_user'];
            $aktivitas = "Mengubah status penyewaan ID $id menjadi $aksi";

            $stmt = mysqli_prepare(
                $koneksi,
                "INSERT INTO log_aktivitas (id_user, aktivitas)
                 VALUES (?, ?)"
            );
            mysqli_stmt_bind_param(
                $stmt,
                "is",
                $idUser,
                $aktivitas
            );
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            mysqli_commit($koneksi);

            header("Location: persetujuan.php?hasil=berhasil");
            exit;
        } catch (Throwable $err) {
            mysqli_rollback($koneksi);

            // Tampilkan pesan validasi yang aman untuk pengguna
            $pesan = $err instanceof mysqli_sql_exception
                ? "Terjadi kesalahan saat menyimpan perubahan. Silakan coba lagi."
                : $err->getMessage();

            $jenisPesan = 'danger';
        }
    } else {
        $pesan = "Permintaan tidak valid.";
        $jenisPesan = 'danger';
    }
}

// Pesan setelah proses berhasil
if (isset($_GET['hasil']) && $_GET['hasil'] === 'berhasil') {
    $pesan = "Status penyewaan berhasil diperbarui.";
    $jenisPesan = 'success';
}

// Ambil daftar pesanan yang menunggu persetujuan
$data = mysqli_query(
    $koneksi,
    "SELECT
        p.id_penyewaan,
        p.kode_penyewaan,
        p.tanggal_pesan,
        p.tanggal_mulai,
        p.tanggal_selesai,
        p.total,
        p.status,
        u.nama AS nama_penyewa
     FROM penyewaan p
     JOIN users u ON p.id_user = u.id_user
     WHERE p.status = 'menunggu'
     ORDER BY p.id_penyewaan DESC"
);

if (!$data && $pesan === '') {
    $pesan = "Gagal mengambil data penyewaan. Silakan muat ulang halaman.";
    $jenisPesan = 'danger';
}

$totalMenunggu = $data ? mysqli_num_rows($data) : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Persetujuan Penyewaan - KebayaRent</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

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
            min-height: 100vh;
            background: #fff8fb;
            color: var(--ink);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(
                110deg,
                var(--pink-dark),
                var(--pink)
            );
            box-shadow: 0 4px 18px rgba(166, 30, 89, .15);
        }

        .navbar-brand {
            letter-spacing: .3px;
        }

        .page-title {
            font-weight: 800;
            color: var(--ink);
        }

        .page-subtitle {
            color: var(--muted);
        }

        .stat-card,
        .content-card {
            background: var(--white);
            border: 1px solid var(--pink-border);
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(166, 30, 89, .06);
        }

        .stat-card {
            padding: 22px;
            height: 100%;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 15px;
            background: var(--pink-soft);
            color: var(--pink-dark);
            font-size: 1.4rem;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--pink-dark);
        }

        .stat-label {
            color: var(--muted);
            font-size: .9rem;
        }

        .content-card {
            overflow: hidden;
        }

        .content-header {
            padding: 20px 22px;
            border-bottom: 1px solid var(--pink-border);
            background: linear-gradient(120deg, #fff, #fff5f9);
        }

        .table {
            vertical-align: middle;
            margin-bottom: 0;
        }

        .table thead th {
            background: var(--pink-soft);
            color: var(--pink-dark);
            border-bottom: 1px solid var(--pink-border);
            padding: 15px 16px;
            font-size: .82rem;
            text-transform: uppercase;
            letter-spacing: .35px;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 15px 16px;
            border-color: #f8e7ef;
        }

        .table tbody tr:hover {
            background: #fff9fc;
        }

        .code-label {
            color: var(--pink-dark);
            font-weight: 750;
        }

        .btn-pink {
            background: var(--pink);
            border-color: var(--pink);
            color: #fff;
        }

        .btn-pink:hover {
            background: var(--pink-dark);
            border-color: var(--pink-dark);
            color: #fff;
        }

        .btn-outline-pink {
            border: 1px solid var(--pink);
            color: var(--pink-dark);
            background: #fff;
        }

        .btn-outline-pink:hover {
            background: var(--pink-soft);
            border-color: var(--pink-dark);
            color: var(--pink-dark);
        }

        .btn-approve {
            background: #e8f8ef;
            border: 1px solid #b8e8cb;
            color: #187747;
        }

        .btn-approve:hover {
            background: #c9f0d9;
            color: #125d37;
        }

        .btn-reject {
            background: #fff0f0;
            border: 1px solid #f5caca;
            color: #b42335;
        }

        .btn-reject:hover {
            background: #ffdddd;
            color: #8e1727;
        }

        .alert {
            border: none;
            border-radius: 12px;
        }

        .empty-state {
            padding: 48px 20px !important;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            display: block;
            font-size: 2.7rem;
            color: var(--pink);
            margin-bottom: 12px;
        }

        .period {
            white-space: nowrap;
            font-size: .9rem;
        }

        .table-footer {
            padding: 15px 22px;
            background: #fffafd;
            border-top: 1px solid var(--pink-border);
            color: var(--muted);
            font-size: .88rem;
        }

        @media (max-width: 767px) {
            .page-heading {
                align-items: flex-start !important;
                flex-direction: column;
                gap: 12px;
            }

            .stat-card {
                padding: 17px;
            }

            .content-header {
                padding: 17px;
            }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container py-1">
        <a href="index.php" class="navbar-brand fw-bold">
            <i class="bi bi-flower1 me-2"></i>KebayaRent
        </a>

        <a href="index.php" class="btn btn-outline-light btn-sm">
            <i class="bi bi-grid me-1"></i>Dashboard
        </a>
    </div>
</nav>

<main class="container py-4 py-lg-5">

    <div class="d-flex justify-content-between page-heading align-items-center mb-4">
        <div>
            <div class="text-uppercase small fw-bold mb-2"
                 style="color: var(--pink); letter-spacing: 1.5px;">
                <i class="bi bi-check2-circle me-1"></i>Kelola Penyewaan
            </div>

            <h2 class="page-title mb-2">Persetujuan Penyewaan</h2>

            <p class="page-subtitle mb-0">
                Periksa permintaan penyewaan dan tentukan statusnya.
            </p>
        </div>

        <a href="penyewaan.php" class="btn btn-outline-pink rounded-pill px-3">
            <i class="bi bi-list-ul me-1"></i> Semua Penyewaan
        </a>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-<?= e($jenisPesan) ?> alert-dismissible fade show mb-4"
             role="alert">
            <i class="bi bi-<?= $jenisPesan === 'success' ? 'check-circle-fill' : ($jenisPesan === 'danger' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?> me-2"></i>
            <?= e($pesan) ?>
            <button type="button" class="btn-close"
                    data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6 col-lg-4">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="stat-icon">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div>
                    <div class="stat-label">Menunggu Persetujuan</div>
                    <div class="stat-number">
                        <?= number_format($totalMenunggu, 0, ',', '.') ?>
                    </div>
                    <div class="small text-secondary">Permintaan perlu ditinjau</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-lg-4">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="stat-icon">
                    <i class="bi bi-calendar-check"></i>
                </div>
                <div>
                    <div class="stat-label">Perlu Tindakan</div>
                    <div class="fw-bold fs-5 mt-1">Tinjau Pesanan</div>
                    <div class="small text-secondary">Setujui atau tolak permintaan</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6 col-lg-4">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="stat-icon">
                    <i class="bi bi-shield-check"></i>
                </div>
                <div>
                    <div class="stat-label">Akses Halaman</div>
                    <div class="fw-bold fs-5 mt-1">Admin & Petugas</div>
                    <div class="small text-secondary">Pengelolaan pesanan</div>
                </div>
            </div>
        </div>
    </div>

    <section class="content-card">
        <div class="content-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="fw-bold mb-1">
                    <i class="bi bi-inbox me-2" style="color: var(--pink);"></i>
                    Daftar Permintaan
                </h5>
                <div class="small text-secondary">
                    Pesanan dengan status menunggu persetujuan.
                </div>
            </div>

            <span class="badge rounded-pill px-3 py-2"
                  style="background: var(--pink-soft); color: var(--pink-dark);">
                <?= number_format($totalMenunggu, 0, ',', '.') ?> permintaan
            </span>
        </div>

        <?php if (!$data): ?>
            <div class="p-4 text-center">
                <i class="bi bi-exclamation-circle fs-2 text-danger"></i>
                <p class="mt-2 mb-0">Data penyewaan gagal dimuat.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Kode Penyewaan</th>
                            <th>Nama Penyewa</th>
                            <th>Tanggal Pesan</th>
                            <th>Periode Sewa</th>
                            <th>Total Biaya</th>
                            <th class="text-center">Tindakan</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if ($totalMenunggu > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($data)): ?>
                            <tr>
                                <td>
                                    <span class="code-label">
                                        <?= e($row['kode_penyewaan']) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="fw-semibold">
                                        <?= e($row['nama_penyewa']) ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="text-secondary small">
                                        <i class="bi bi-calendar3 me-1"></i>
                                        <?= e($row['tanggal_pesan']) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="period">
                                        <?= e($row['tanggal_mulai']) ?>
                                    </div>
                                    <div class="small text-secondary">
                                        sampai <?= e($row['tanggal_selesai']) ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="fw-bold">
                                        Rp <?= number_format((float) $row['total'], 0, ',', '.') ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                                        <form method="POST"
                                              onsubmit="return confirm('Yakin menyetujui pesanan ini?');">
                                            <input type="hidden"
                                                   name="id_penyewaan"
                                                   value="<?= (int) $row['id_penyewaan'] ?>">

                                            <button type="submit"
                                                    name="aksi"
                                                    value="disetujui"
                                                    class="btn btn-approve btn-sm rounded-pill px-3">
                                                <i class="bi bi-check-lg me-1"></i>Setujui
                                            </button>
                                        </form>

                                        <form method="POST"
                                              onsubmit="return confirm('Yakin menolak pesanan ini?');">
                                            <input type="hidden"
                                                   name="id_penyewaan"
                                                   value="<?= (int) $row['id_penyewaan'] ?>">

                                            <button type="submit"
                                                    name="aksi"
                                                    value="ditolak"
                                                    class="btn btn-reject btn-sm rounded-pill px-3">
                                                <i class="bi bi-x-lg me-1"></i>Tolak
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-state">
                                <i class="bi bi-inbox"></i>
                                <div class="fw-bold mb-1">
                                    Tidak Ada Permintaan Menunggu
                                </div>
                                <div class="small">
                                    Semua permintaan sudah diproses atau belum ada pesanan baru.
                                </div>
                                <a href="penyewaan.php"
                                   class="btn btn-pink btn-sm rounded-pill mt-3 px-3">
                                    <i class="bi bi-list-ul me-1"></i>
                                    Lihat Semua Penyewaan
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>
                    <i class="bi bi-info-circle me-1"></i>
                    Perubahan status akan tercatat di log sistem.
                </span>
                <a href="penyewaan.php"
                   class="text-decoration-none fw-semibold"
                   style="color: var(--pink-dark);">
                    Lihat riwayat penyewaan
                    <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        <?php endif; ?>
    </section>

    <div class="text-center small text-secondary mt-4">
        <i class="bi bi-flower1 me-1" style="color: var(--pink);"></i>
        KebayaRent &mdash; Sistem Informasi Penyewaan Kebaya
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
