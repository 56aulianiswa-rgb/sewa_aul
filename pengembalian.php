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

function e($nilai) {
    return htmlspecialchars((string)($nilai ?? ''), ENT_QUOTES, 'UTF-8');
}

$pesan = '';
$jenisPesan = 'info';

// Proses pengembalian
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idPenyewaan = (int)($_POST['id_penyewaan'] ?? 0);
    $tanggalKembali = trim($_POST['tanggal_kembali'] ?? '');
    $kondisi = $_POST['kondisi'] ?? '';
    $dendaInput = $_POST['denda'] ?? '';
    $catatan = trim($_POST['catatan'] ?? '');
    $idPetugas = (int)$_SESSION['id_user'];

    $denda = filter_var($dendaInput, FILTER_VALIDATE_FLOAT);

    $tanggalObj = DateTime::createFromFormat(
        '!Y-m-d',
        $tanggalKembali
    );

    $tanggalValid = $tanggalObj &&
        $tanggalObj->format('Y-m-d') === $tanggalKembali;

    if (
        $idPenyewaan <= 0 ||
        !$tanggalValid ||
        !in_array($kondisi, ['baik', 'rusak', 'hilang'], true) ||
        $denda === false ||
        $denda === null ||
        !is_finite((float)$denda) ||
        $denda < 0 ||
        $dendaInput === ''
    ) {
        $pesan = 'Data tidak valid. Periksa kembali formulir pengembalian.';
        $jenisPesan = 'danger';
    } else {
        mysqli_begin_transaction($koneksi);

        try {
            // Periksa status penyewaan dan kunci baris selama transaksi
            $stmt = mysqli_prepare(
                $koneksi,
                "SELECT status
                 FROM penyewaan
                 WHERE id_penyewaan = ?
                 FOR UPDATE"
            );

            if (!$stmt) {
                throw new Exception('Gagal memeriksa data penyewaan.');
            }

            mysqli_stmt_bind_param($stmt, 'i', $idPenyewaan);
            mysqli_stmt_execute($stmt);
            $hasil = mysqli_stmt_get_result($stmt);
            $sewa = mysqli_fetch_assoc($hasil);
            mysqli_stmt_close($stmt);

            if (
                !$sewa ||
                !in_array($sewa['status'], ['disetujui', 'dipinjam'], true)
            ) {
                throw new Exception(
                    'Penyewaan tidak ditemukan atau tidak siap dikembalikan.'
                );
            }

            // Simpan data pengembalian
            $stmt = mysqli_prepare(
                $koneksi,
                "INSERT INTO pengembalian
                    (id_penyewaan, tanggal_kembali, kondisi, denda, catatan, id_petugas)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            if (!$stmt) {
                throw new Exception('Gagal menyiapkan data pengembalian.');
            }

            mysqli_stmt_bind_param(
                $stmt,
                'issdsi',
                $idPenyewaan,
                $tanggalKembali,
                $kondisi,
                $denda,
                $catatan,
                $idPetugas
            );

            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                throw new Exception(
                    'Pengembalian gagal disimpan. Pastikan data belum pernah dicatat.'
                );
            }

            mysqli_stmt_close($stmt);

            // Perbarui status penyewaan
            $statusLama = $sewa['status'];
            $statusBaru = 'selesai';

            $stmt = mysqli_prepare(
                $koneksi,
                "UPDATE penyewaan
                 SET status = ?
                 WHERE id_penyewaan = ?"
            );

            if (!$stmt) {
                throw new Exception('Gagal menyiapkan perubahan status.');
            }

            mysqli_stmt_bind_param(
                $stmt,
                'si',
                $statusBaru,
                $idPenyewaan
            );

            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                throw new Exception('Gagal memperbarui status penyewaan.');
            }

            mysqli_stmt_close($stmt);

            // Catat riwayat perubahan status
            $stmt = mysqli_prepare(
                $koneksi,
                "INSERT INTO log_status_penyewaan
                    (id_penyewaan, status_lama, status_baru)
                 VALUES (?, ?, ?)"
            );

            if (!$stmt) {
                throw new Exception('Gagal menyiapkan riwayat status.');
            }

            mysqli_stmt_bind_param(
                $stmt,
                'iss',
                $idPenyewaan,
                $statusLama,
                $statusBaru
            );

            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                throw new Exception('Gagal mencatat riwayat status.');
            }

            mysqli_stmt_close($stmt);

            // Catat aktivitas petugas
            $aktivitas = 'Mencatat pengembalian penyewaan ID ' . $idPenyewaan;

            $stmt = mysqli_prepare(
                $koneksi,
                "INSERT INTO log_aktivitas (id_user, aktivitas)
                 VALUES (?, ?)"
            );

            if (!$stmt) {
                throw new Exception('Gagal menyiapkan log aktivitas.');
            }

            mysqli_stmt_bind_param(
                $stmt,
                'is',
                $idPetugas,
                $aktivitas
            );

            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                throw new Exception('Gagal mencatat aktivitas petugas.');
            }

            mysqli_stmt_close($stmt);

            mysqli_commit($koneksi);

            header('Location: pengembalian.php?hasil=berhasil');
            exit;

        } catch (Throwable $err) {
            mysqli_rollback($koneksi);

            // Pesan yang aman untuk ditampilkan
            $pesan = $err->getMessage();
            $jenisPesan = 'danger';

            // Catat detail teknis di log server, bukan di halaman
            error_log('Pengembalian KebayaRent: ' . $err->getMessage());
        }
    }
}

if (isset($_GET['hasil']) && $_GET['hasil'] === 'berhasil') {
    $pesan = 'Pengembalian berhasil dicatat dan status penyewaan telah diperbarui.';
    $jenisPesan = 'success';
}

// Data penyewaan yang belum dikembalikan
$dataSewa = mysqli_query(
    $koneksi,
    "SELECT p.id_penyewaan, p.kode_penyewaan,
            u.nama AS nama_penyewa
     FROM penyewaan p
     JOIN users u ON p.id_user = u.id_user
     LEFT JOIN pengembalian pg
        ON pg.id_penyewaan = p.id_penyewaan
     WHERE p.status IN ('disetujui', 'dipinjam')
       AND pg.id_pengembalian IS NULL
     ORDER BY p.id_penyewaan DESC"
);

// Riwayat pengembalian
$riwayat = mysqli_query(
    $koneksi,
    "SELECT pg.*, p.kode_penyewaan,
            u.nama AS nama_petugas
     FROM pengembalian pg
     JOIN penyewaan p ON pg.id_penyewaan = p.id_penyewaan
     LEFT JOIN users u ON pg.id_petugas = u.id_user
     ORDER BY pg.id_pengembalian DESC"
);

if (!$dataSewa || !$riwayat) {
    error_log('Gagal mengambil data halaman pengembalian: ' . mysqli_error($koneksi));
}

// Ringkasan pengembalian
$jumlahPengembalian = 0;
$totalDenda = 0;
$jumlahRusakHilang = 0;

$ringkasan = mysqli_query(
    $koneksi,
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(denda), 0) AS total_denda,
        COALESCE(SUM(CASE WHEN kondisi IN ('rusak', 'hilang')
                          THEN 1 ELSE 0 END), 0) AS rusak_hilang
     FROM pengembalian"
);

if ($ringkasan) {
    $dataRingkasan = mysqli_fetch_assoc($ringkasan);
    $jumlahPengembalian = (int)$dataRingkasan['total'];
    $totalDenda = (float)$dataRingkasan['total_denda'];
    $jumlahRusakHilang = (int)$dataRingkasan['rusak_hilang'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Pengembalian Kebaya - KebayaRent</title>

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
            font-weight: 800;
            color: var(--ink);
        }

        .page-subtitle {
            color: var(--muted);
        }

        .pink-icon {
            width: 48px;
            height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border-radius: 15px;
            background: var(--pink-soft);
            color: var(--pink-dark);
            font-size: 23px;
        }

        .stat-card,
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
            font-size: 25px;
            font-weight: 800;
            color: var(--pink-dark);
            overflow-wrap: anywhere;
        }

        .main-card {
            overflow: hidden;
            margin-bottom: 24px;
        }

        .card-heading {
            padding: 20px 22px;
            border-bottom: 1px solid var(--pink-border);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-heading h5 {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
        }

        .card-content {
            padding: 24px;
        }

        .form-label {
            color: var(--ink);
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .form-control,
        .form-select {
            min-height: 44px;
            border: 1px solid var(--pink-border);
            border-radius: 11px;
            color: var(--ink);
        }

        textarea.form-control {
            min-height: auto;
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
            padding: 11px 18px;
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
        }

        .btn-soft:hover {
            background: #fce0ec;
            color: var(--pink-dark);
        }

        .alert {
            border: none;
            border-radius: 13px;
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

        .condition-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .condition-baik {
            background: #e5f7ed;
            color: #187547;
        }

        .condition-rusak {
            background: #fff0d7;
            color: #9a5b00;
        }

        .condition-hilang {
            background: #ffe5e8;
            color: #b42336;
        }

        .money {
            font-weight: 700;
            color: var(--pink-dark);
            white-space: nowrap;
        }

        .empty-state {
            padding: 38px 20px !important;
            text-align: center;
            color: var(--muted);
        }

        .empty-state i {
            display: block;
            font-size: 36px;
            color: #e7a2bf;
            margin-bottom: 10px;
        }

        footer {
            text-align: center;
            color: var(--muted);
            font-size: 12px;
            padding: 10px 0 24px;
        }

        @media (max-width: 576px) {
            .card-content {
                padding: 17px;
            }

            .card-heading {
                padding: 17px;
            }

            .stat-card {
                padding: 16px;
            }

            .stat-number {
                font-size: 22px;
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
            <i class="bi bi-arrow-return-left"></i>
        </div>

        <div>
            <h2 class="page-title mb-1">Pengembalian Kebaya</h2>
            <p class="page-subtitle mb-0">
                Catat pengembalian dan pantau kondisi kebaya yang disewa.
            </p>
        </div>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-<?= e($jenisPesan) ?> alert-dismissible fade show mb-4"
             role="alert">
            <i class="bi bi-<?= $jenisPesan === 'success' ? 'check-circle-fill' : 'info-circle-fill' ?> me-2"></i>
            <?= e($pesan) ?>
            <button type="button" class="btn-close"
                    data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- Ringkasan -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="pink-icon">
                        <i class="bi bi-clipboard-check"></i>
                    </div>
                    <div>
                        <div class="stat-label">Total Pengembalian</div>
                        <div class="stat-number">
                            <?= number_format($jumlahPengembalian, 0, ',', '.') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="pink-icon">
                        <i class="bi bi-cash-coin"></i>
                    </div>
                    <div>
                        <div class="stat-label">Total Denda</div>
                        <div class="stat-number">
                            Rp <?= number_format($totalDenda, 0, ',', '.') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="pink-icon">
                        <i class="bi bi-exclamation-circle"></i>
                    </div>
                    <div>
                        <div class="stat-label">Kondisi Rusak / Hilang</div>
                        <div class="stat-number">
                            <?= number_format($jumlahRusakHilang, 0, ',', '.') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Form pengembalian -->
    <section class="main-card">
        <div class="card-heading">
            <div class="pink-icon">
                <i class="bi bi-pencil-square"></i>
            </div>
            <div>
                <h5>Catat Pengembalian</h5>
                <small class="page-subtitle">
                    Lengkapi data saat kebaya dikembalikan.
                </small>
            </div>
        </div>

        <div class="card-content">
            <?php if (!$dataSewa): ?>
                <div class="alert alert-danger mb-0">
                    Gagal mengambil data penyewaan. Silakan periksa database.
                </div>

            <?php elseif (mysqli_num_rows($dataSewa) === 0): ?>
                <div class="text-center py-4">
                    <div class="pink-icon mx-auto mb-3">
                        <i class="bi bi-check2-circle"></i>
                    </div>
                    <h6 class="fw-bold">Tidak ada pengembalian yang menunggu</h6>
                    <p class="page-subtitle mb-0">
                        Semua penyewaan yang memenuhi syarat sudah diproses
                        atau belum ada penyewaan yang siap dikembalikan.
                    </p>
                </div>

            <?php else: ?>
                <form method="POST" id="formPengembalian">
                    <div class="row g-3">

                        <div class="col-12">
                            <label for="id_penyewaan" class="form-label">
                                Penyewaan <span class="text-danger">*</span>
                            </label>

                            <select name="id_penyewaan" id="id_penyewaan"
                                    class="form-select" required>
                                <option value="">-- Pilih penyewaan --</option>

                                <?php while ($row = mysqli_fetch_assoc($dataSewa)): ?>
                                    <option value="<?= (int)$row['id_penyewaan'] ?>">
                                        <?= e($row['kode_penyewaan']) ?>
                                        — <?= e($row['nama_penyewa']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>

                            <div class="form-text">
                                Hanya penyewaan yang disetujui atau sedang dipinjam
                                dan belum tercatat pengembaliannya yang ditampilkan.
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="tanggal_kembali" class="form-label">
                                Tanggal Kembali <span class="text-danger">*</span>
                            </label>

                            <input type="date"
                                   name="tanggal_kembali"
                                   id="tanggal_kembali"
                                   class="form-control"
                                   value="<?= e($_POST['tanggal_kembali'] ?? date('Y-m-d')) ?>"
                                   required>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="kondisi" class="form-label">
                                Kondisi Kebaya <span class="text-danger">*</span>
                            </label>

                            <select name="kondisi" id="kondisi"
                                    class="form-select" required>
                                <option value="baik"
                                    <?= ($_POST['kondisi'] ?? 'baik') === 'baik' ? 'selected' : '' ?>>
                                    Baik
                                </option>

                                <option value="rusak"
                                    <?= ($_POST['kondisi'] ?? '') === 'rusak' ? 'selected' : '' ?>>
                                    Rusak
                                </option>

                                <option value="hilang"
                                    <?= ($_POST['kondisi'] ?? '') === 'hilang' ? 'selected' : '' ?>>
                                    Hilang
                                </option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label for="denda" class="form-label">
                                Denda (Rp) <span class="text-danger">*</span>
                            </label>

                            <input type="number"
                                   name="denda"
                                   id="denda"
                                   class="form-control"
                                   min="0"
                                   step="0.01"
                                   value="<?= e($_POST['denda'] ?? '0') ?>"
                                   required>

                            <div class="form-text">
                                Masukkan 0 jika tidak ada denda.
                            </div>
                        </div>

                        <div class="col-12">
                            <label for="catatan" class="form-label">
                                Catatan Pengembalian
                            </label>

                            <textarea name="catatan"
                                      id="catatan"
                                      class="form-control"
                                      rows="3"
                                      maxlength="2000"
                                      placeholder="Contoh: Kebaya dikembalikan lengkap dan dalam kondisi baik."><?= e($_POST['catatan'] ?? '') ?></textarea>
                        </div>

                        <div class="col-12 pt-2">
                            <button type="submit" class="btn btn-pink">
                                <i class="bi bi-check-circle me-2"></i>
                                Simpan Pengembalian
                            </button>

                            <a href="index.php" class="btn btn-soft ms-2">
                                <i class="bi bi-arrow-left me-1"></i>
                                Kembali
                            </a>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <!-- Riwayat pengembalian -->
    <section class="main-card">
        <div class="card-heading">
            <div class="pink-icon">
                <i class="bi bi-clock-history"></i>
            </div>

            <div>
                <h5>Riwayat Pengembalian</h5>
                <small class="page-subtitle">
                    Daftar pengembalian yang sudah dicatat.
                </small>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Penyewaan</th>
                        <th>Tanggal Kembali</th>
                        <th>Kondisi</th>
                        <th>Denda</th>
                        <th>Petugas</th>
                        <th>Catatan</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$riwayat): ?>
                    <tr>
                        <td colspan="7" class="empty-state">
                            Gagal memuat riwayat pengembalian.
                        </td>
                    </tr>

                <?php elseif (mysqli_num_rows($riwayat) > 0): ?>
                    <?php $no = 1; ?>

                    <?php while ($row = mysqli_fetch_assoc($riwayat)): ?>
                        <?php
                        $kelasKondisi = [
                            'baik' => 'condition-baik',
                            'rusak' => 'condition-rusak',
                            'hilang' => 'condition-hilang'
                        ][$row['kondisi']] ?? '';
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

                            <td class="text-nowrap">
                                <i class="bi bi-calendar3 me-1 text-secondary"></i>
                                <?= e($row['tanggal_kembali']) ?>
                            </td>

                            <td>
                                <span class="condition-badge <?= e($kelasKondisi) ?>">
                                    <?= e(ucfirst($row['kondisi'])) ?>
                                </span>
                            </td>

                            <td class="money">
                                Rp <?= number_format((float)$row['denda'], 0, ',', '.') ?>
                            </td>

                            <td><?= e($row['nama_petugas'] ?: '-') ?></td>

                            <td style="min-width: 180px; max-width: 280px;">
                                <?= e($row['catatan'] ?: '-') ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="7" class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <div class="fw-bold mb-1">
                                Belum ada riwayat pengembalian
                            </div>
                            <div>
                                Data akan tampil setelah pengembalian dicatat.
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer>
        <i class="bi bi-flower1 me-1"></i>
        KebayaRent &copy; <?= date('Y') ?> — Sistem Penyewaan Kebaya
    </footer>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.getElementById('formPengembalian')?.addEventListener('submit', function (event) {
    if (!confirm('Apakah kamu yakin ingin menyimpan data pengembalian ini?')) {
        event.preventDefault();
    }
});
</script>

</body>
</html>
