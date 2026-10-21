<?php
session_start();
require_once 'koneksi.php';

// Halaman ini hanya dapat diakses admin
if (
    !isset($_SESSION['id_user'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
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

// Tambah pengguna
if (isset($_POST['tambah'])) {
    $nama = trim($_POST['nama'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $no_hp = trim($_POST['no_hp'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    $status = $_POST['status'] ?? '';

    if ($nama === '' || $username === '' || $password === '') {
        $pesan = "Nama, username, dan password wajib diisi.";
        $jenisPesan = 'danger';
    } elseif (
        !in_array($role, ['admin', 'petugas', 'penyewa'], true) ||
        !in_array($status, ['aktif', 'nonaktif'], true)
    ) {
        $pesan = "Role atau status tidak valid.";
        $jenisPesan = 'danger';
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = mysqli_prepare(
            $koneksi,
            "INSERT INTO users
             (nama, username, password, role, no_hp, alamat, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        mysqli_stmt_bind_param(
            $stmt,
            "sssssss",
            $nama,
            $username,
            $passwordHash,
            $role,
            $no_hp,
            $alamat,
            $status
        );

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            header("Location: users.php?pesan=tambah");
            exit;
        } else {
            $pesan = mysqli_stmt_errno($stmt) == 1062
                ? "Username sudah digunakan."
                : "Gagal menambahkan pengguna.";

            $jenisPesan = 'danger';
            mysqli_stmt_close($stmt);
        }
    }
}

// Edit pengguna
if (isset($_POST['edit'])) {
    $id = (int) ($_POST['id_user'] ?? 0);
    $nama = trim($_POST['nama'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role = $_POST['role'] ?? '';
    $no_hp = trim($_POST['no_hp'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    $status = $_POST['status'] ?? '';
    $passwordBaru = $_POST['password'] ?? '';

    if (
        $id <= 0 ||
        $nama === '' ||
        $username === '' ||
        !in_array($role, ['admin', 'petugas', 'penyewa'], true) ||
        !in_array($status, ['aktif', 'nonaktif'], true)
    ) {
        $pesan = "Data pengguna tidak valid.";
        $jenisPesan = 'danger';
    } elseif (
        $id === (int) $_SESSION['id_user'] &&
        ($role !== 'admin' || $status !== 'aktif')
    ) {
        $pesan = "Kamu tidak dapat menonaktifkan atau mengubah role akun sendiri.";
        $jenisPesan = 'danger';
    } else {
        if ($passwordBaru !== '') {
            $passwordHash = password_hash(
                $passwordBaru,
                PASSWORD_DEFAULT
            );

            $stmt = mysqli_prepare(
                $koneksi,
                "UPDATE users
                 SET nama=?, username=?, password=?, role=?,
                     no_hp=?, alamat=?, status=?
                 WHERE id_user=?"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "sssssssi",
                $nama,
                $username,
                $passwordHash,
                $role,
                $no_hp,
                $alamat,
                $status,
                $id
            );
        } else {
            $stmt = mysqli_prepare(
                $koneksi,
                "UPDATE users
                 SET nama=?, username=?, role=?,
                     no_hp=?, alamat=?, status=?
                 WHERE id_user=?"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "ssssssi",
                $nama,
                $username,
                $role,
                $no_hp,
                $alamat,
                $status,
                $id
            );
        }

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            if ($id === (int) $_SESSION['id_user']) {
                $_SESSION['nama'] = $nama;
                $_SESSION['username'] = $username;
            }

            header("Location: users.php?pesan=edit");
            exit;
        } else {
            $pesan = mysqli_stmt_errno($stmt) == 1062
                ? "Username sudah digunakan."
                : "Gagal memperbarui pengguna.";

            $jenisPesan = 'danger';
            mysqli_stmt_close($stmt);
        }
    }
}

// Hapus pengguna
if (isset($_POST['hapus'])) {
    $id = (int) ($_POST['id_user'] ?? 0);

    if ($id <= 0) {
        $pesan = "Data pengguna tidak valid.";
        $jenisPesan = 'danger';
    } elseif ($id === (int) $_SESSION['id_user']) {
        $pesan = "Kamu tidak dapat menghapus akun yang sedang digunakan.";
        $jenisPesan = 'danger';
    } else {
        $stmt = mysqli_prepare(
            $koneksi,
            "DELETE FROM users WHERE id_user=?"
        );

        mysqli_stmt_bind_param($stmt, "i", $id);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            header("Location: users.php?pesan=hapus");
            exit;
        } else {
            $pesan = "Pengguna tidak dapat dihapus karena masih terhubung dengan data transaksi.";
            $jenisPesan = 'danger';
            mysqli_stmt_close($stmt);
        }
    }
}

// Pesan setelah operasi berhasil
if (isset($_GET['pesan'])) {
    $daftarPesan = [
        'tambah' => 'Pengguna berhasil ditambahkan.',
        'edit' => 'Data pengguna berhasil diperbarui.',
        'hapus' => 'Pengguna berhasil dihapus.'
    ];

    if (isset($daftarPesan[$_GET['pesan']])) {
        $pesan = $daftarPesan[$_GET['pesan']];
        $jenisPesan = 'success';
    }
}

// Ambil data pengguna untuk diedit
$dataEdit = null;

if (isset($_GET['edit'])) {
    $idEdit = (int) $_GET['edit'];

    if ($idEdit > 0) {
        $stmt = mysqli_prepare(
            $koneksi,
            "SELECT id_user, nama, username, role, no_hp,
                    alamat, status
             FROM users
             WHERE id_user=?"
        );

        mysqli_stmt_bind_param($stmt, "i", $idEdit);
        mysqli_stmt_execute($stmt);
        $hasilEdit = mysqli_stmt_get_result($stmt);
        $dataEdit = mysqli_fetch_assoc($hasilEdit);
        mysqli_stmt_close($stmt);

        if (!$dataEdit) {
            $pesan = "Data pengguna yang ingin diedit tidak ditemukan.";
            $jenisPesan = 'danger';
        }
    }
}

// Ambil daftar pengguna
$data = mysqli_query(
    $koneksi,
    "SELECT id_user, nama, username, role, no_hp,
            alamat, status, created_at
     FROM users
     ORDER BY id_user DESC"
);

// Statistik pengguna
$totalPengguna = 0;
$totalAdmin = 0;
$totalPetugas = 0;
$totalPenyewa = 0;
$totalAktif = 0;

if ($data) {
    $totalPengguna = mysqli_num_rows($data);

    while ($stat = mysqli_fetch_assoc($data)) {
        if ($stat['role'] === 'admin') {
            $totalAdmin++;
        } elseif ($stat['role'] === 'petugas') {
            $totalPetugas++;
        } elseif ($stat['role'] === 'penyewa') {
            $totalPenyewa++;
        }

        if ($stat['status'] === 'aktif') {
            $totalAktif++;
        }
    }

    // Kembalikan pointer hasil query ke baris pertama
    mysqli_data_seek($data, 0);
} elseif ($pesan === '') {
    $pesan = "Gagal mengambil data pengguna.";
    $jenisPesan = 'danger';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manajemen Pengguna - KebayaRent</title>

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
        }

        body {
            min-height: 100vh;
            background: #fff8fb;
            color: var(--ink);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(110deg, var(--pink-dark), var(--pink));
            box-shadow: 0 4px 18px rgba(166, 30, 89, .15);
        }

        .page-title {
            font-weight: 800;
        }

        .page-subtitle {
            color: var(--muted);
        }

        .stat-card,
        .content-card {
            background: #fff;
            border: 1px solid var(--pink-border);
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(166, 30, 89, .06);
        }

        .stat-card {
            height: 100%;
            padding: 20px;
        }

        .stat-icon {
            width: 46px;
            height: 46px;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            background: var(--pink-soft);
            color: var(--pink-dark);
            font-size: 1.35rem;
        }

        .stat-label {
            color: var(--muted);
            font-size: .88rem;
        }

        .stat-number {
            color: var(--pink-dark);
            font-size: 1.7rem;
            font-weight: 800;
        }

        .content-card {
            overflow: hidden;
        }

        .content-header {
            padding: 19px 22px;
            background: linear-gradient(120deg, #fff, #fff5f9);
            border-bottom: 1px solid var(--pink-border);
        }

        .form-label {
            color: #594254;
            font-weight: 650;
            font-size: .92rem;
        }

        .form-control,
        .form-select {
            border: 1px solid #ead5e0;
            border-radius: 10px;
            padding: 10px 12px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--pink);
            box-shadow: 0 0 0 .2rem rgba(217, 70, 131, .13);
        }

        .btn-pink {
            color: #fff;
            background: var(--pink);
            border-color: var(--pink);
        }

        .btn-pink:hover {
            color: #fff;
            background: var(--pink-dark);
            border-color: var(--pink-dark);
        }

        .btn-outline-pink {
            color: var(--pink-dark);
            border-color: var(--pink);
            background: #fff;
        }

        .btn-outline-pink:hover {
            color: var(--pink-dark);
            background: var(--pink-soft);
            border-color: var(--pink-dark);
        }

        .table {
            margin-bottom: 0;
            vertical-align: middle;
        }

        .table thead th {
            padding: 14px 16px;
            white-space: nowrap;
            background: var(--pink-soft);
            color: var(--pink-dark);
            border-bottom: 1px solid var(--pink-border);
            font-size: .8rem;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .table tbody td {
            padding: 14px 16px;
            border-color: #f8e7ef;
        }

        .table tbody tr:hover {
            background: #fff9fc;
        }

        .role-badge,
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 50px;
            font-size: .78rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .role-admin {
            background: #f0e7ff;
            color: #7040a1;
        }

        .role-petugas {
            background: #e5f1ff;
            color: #245e9a;
        }

        .role-penyewa {
            background: #ffeaf3;
            color: #ad356d;
        }

        .status-aktif {
            background: #e5f8ed;
            color: #187747;
        }

        .status-nonaktif {
            background: #f1edf1;
            color: #6c5b6b;
        }

        .alert {
            border: none;
            border-radius: 12px;
        }

        .empty-state {
            padding: 38px 20px !important;
            color: var(--muted);
            text-align: center;
        }

        .empty-state i {
            display: block;
            margin-bottom: 10px;
            color: var(--pink);
            font-size: 2.5rem;
        }

        .help-text {
            color: var(--muted);
            font-size: .82rem;
        }

        @media (max-width: 767px) {
            .page-heading {
                align-items: flex-start !important;
                flex-direction: column;
                gap: 12px;
            }

            .stat-card {
                padding: 16px;
            }

            .content-header {
                padding: 17px;
            }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark">
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

    <div class="d-flex justify-content-between align-items-center page-heading mb-4">
        <div>
            <div class="text-uppercase small fw-bold mb-2"
                 style="color:var(--pink);letter-spacing:1.5px;">
                <i class="bi bi-people me-1"></i>Kelola Akun
            </div>

            <h2 class="page-title mb-2">Manajemen Pengguna</h2>

            <p class="page-subtitle mb-0">
                Kelola akun admin, petugas, dan penyewa KebayaRent.
            </p>
        </div>

        <a href="index.php" class="btn btn-outline-pink rounded-pill px-3">
            <i class="bi bi-arrow-left me-1"></i>Kembali ke Dashboard
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
        <div class="col-6 col-lg-3">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="stat-icon"><i class="bi bi-people"></i></div>
                <div>
                    <div class="stat-label">Total Pengguna</div>
                    <div class="stat-number">
                        <?= number_format($totalPengguna, 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="stat-icon"><i class="bi bi-shield-lock"></i></div>
                <div>
                    <div class="stat-label">Admin</div>
                    <div class="stat-number">
                        <?= number_format($totalAdmin, 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="stat-icon"><i class="bi bi-person-badge"></i></div>
                <div>
                    <div class="stat-label">Petugas</div>
                    <div class="stat-number">
                        <?= number_format($totalPetugas, 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="stat-icon"><i class="bi bi-person-check"></i></div>
                <div>
                    <div class="stat-label">Akun Aktif</div>
                    <div class="stat-number">
                        <?= number_format($totalAktif, 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Form tambah/edit pengguna -->
    <section class="content-card mb-4">
        <div class="content-header">
            <h5 class="fw-bold mb-1">
                <i class="bi bi-<?= $dataEdit ? 'pencil-square' : 'person-plus' ?> me-2"
                   style="color:var(--pink);"></i>
                <?= $dataEdit ? 'Edit Pengguna' : 'Tambah Pengguna Baru' ?>
            </h5>

            <div class="small text-secondary">
                <?= $dataEdit
                    ? 'Perbarui informasi akun pengguna yang dipilih.'
                    : 'Isi formulir berikut untuk membuat akun baru.' ?>
            </div>
        </div>

        <div class="p-3 p-md-4">
            <form method="POST"
                  action="users.php<?= $dataEdit ? '?edit=' . (int) $dataEdit['id_user'] : '' ?>">

                <?php if ($dataEdit): ?>
                    <input type="hidden" name="id_user"
                           value="<?= (int) $dataEdit['id_user'] ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="nama" class="form-label">Nama Lengkap *</label>
                        <input
                            type="text"
                            id="nama"
                            name="nama"
                            class="form-control"
                            maxlength="100"
                            placeholder="Masukkan nama lengkap"
                            required
                            value="<?= e($dataEdit['nama'] ?? '') ?>"
                        >
                    </div>

                    <div class="col-md-6">
                        <label for="username" class="form-label">Username *</label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-control"
                            maxlength="50"
                            placeholder="Masukkan username"
                            required
                            value="<?= e($dataEdit['username'] ?? '') ?>"
                        >
                    </div>

                    <div class="col-md-6">
                        <label for="password" class="form-label">
                            Password <?= $dataEdit ? '(opsional)' : '*' ?>
                        </label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            <?= $dataEdit ? '' : 'required' ?>
                            autocomplete="new-password"
                            placeholder="<?= $dataEdit ? 'Kosongkan jika tidak diubah' : 'Buat password' ?>"
                        >
                        <?php if ($dataEdit): ?>
                            <div class="help-text mt-1">
                                Password lama tetap digunakan jika kolom ini dikosongkan.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label for="role" class="form-label">Role Pengguna *</label>
                        <select id="role" name="role" class="form-select" required>
                            <?php foreach (['admin', 'petugas', 'penyewa'] as $r): ?>
                                <option value="<?= e($r) ?>"
                                    <?= ($dataEdit['role'] ?? 'penyewa') === $r ? 'selected' : '' ?>>
                                    <?= e(ucfirst($r)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="no_hp" class="form-label">Nomor HP</label>
                        <input
                            type="text"
                            id="no_hp"
                            name="no_hp"
                            class="form-control"
                            maxlength="20"
                            placeholder="Contoh: 08xxxxxxxxxx"
                            value="<?= e($dataEdit['no_hp'] ?? '') ?>"
                        >
                    </div>

                    <div class="col-md-6">
                        <label for="status" class="form-label">Status Akun *</label>
                        <select id="status" name="status" class="form-select" required>
                            <option value="aktif"
                                <?= ($dataEdit['status'] ?? 'aktif') === 'aktif' ? 'selected' : '' ?>>
                                Aktif
                            </option>
                            <option value="nonaktif"
                                <?= ($dataEdit['status'] ?? 'aktif') === 'nonaktif' ? 'selected' : '' ?>>
                                Nonaktif
                            </option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label for="alamat" class="form-label">Alamat</label>
                        <textarea
                            id="alamat"
                            name="alamat"
                            class="form-control"
                            rows="3"
                            placeholder="Masukkan alamat pengguna"
                        ><?= e($dataEdit['alamat'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <?php if ($dataEdit): ?>
                        <button type="submit" name="edit" class="btn btn-pink rounded-pill px-4">
                            <i class="bi bi-check-circle me-1"></i>Simpan Perubahan
                        </button>

                        <a href="users.php" class="btn btn-light border rounded-pill px-4">
                            <i class="bi bi-x-circle me-1"></i>Batal
                        </a>
                    <?php else: ?>
                        <button type="submit" name="tambah" class="btn btn-pink rounded-pill px-4">
                            <i class="bi bi-person-plus me-1"></i>Tambah Pengguna
                        </button>

                        <button type="reset" class="btn btn-light border rounded-pill px-4">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <!-- Tabel daftar pengguna -->
    <section class="content-card">
        <div class="content-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="fw-bold mb-1">
                    <i class="bi bi-card-list me-2" style="color:var(--pink);"></i>
                    Daftar Pengguna
                </h5>
                <div class="small text-secondary">
                    Informasi akun yang terdaftar di sistem.
                </div>
            </div>

            <div class="input-group" style="max-width:300px;">
                <span class="input-group-text bg-white"
                      style="border-color:var(--pink-border);">
                    <i class="bi bi-search" style="color:var(--pink);"></i>
                </span>
                <input type="search" id="cariPengguna"
                       class="form-control"
                       placeholder="Cari pengguna..."
                       aria-label="Cari pengguna"
                       style="border-color:var(--pink-border);">
            </div>
        </div>

        <?php if (!$data): ?>
            <div class="empty-state">
                <i class="bi bi-exclamation-circle"></i>
                Gagal memuat daftar pengguna.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover" id="tabelPengguna">
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Nama Pengguna</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Nomor HP</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (mysqli_num_rows($data) > 0): ?>
                        <?php $no = 1; ?>
                        <?php while ($row = mysqli_fetch_assoc($data)): ?>
                            <tr>
                                <td><?= $no++ ?></td>

                                <td>
                                    <div class="fw-semibold"><?= e($row['nama']) ?></div>
                                    <div class="small text-secondary">
                                        ID: <?= (int) $row['id_user'] ?>
                                    </div>
                                </td>

                                <td><?= e($row['username']) ?></td>

                                <td>
                                    <span class="role-badge role-<?= e($row['role']) ?>">
                                        <?= e(ucfirst($row['role'])) ?>
                                    </span>
                                </td>

                                <td><?= e($row['no_hp'] ?: '-') ?></td>

                                <td>
                                    <span class="status-badge status-<?= e($row['status']) ?>">
                                        <i class="bi bi-<?= $row['status'] === 'aktif' ? 'check-circle' : 'slash-circle' ?> me-1"></i>
                                        <?= e(ucfirst($row['status'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="d-flex gap-2">
                                        <a
                                            href="users.php?edit=<?= (int) $row['id_user'] ?>"
                                            class="btn btn-sm btn-outline-pink rounded-pill px-3"
                                            title="Edit pengguna"
                                        >
                                            <i class="bi bi-pencil-square me-1"></i>Edit
                                        </a>

                                        <?php if ((int) $row['id_user'] !== (int) $_SESSION['id_user']): ?>
                                            <form method="POST"
                                                  onsubmit="return confirm('Yakin ingin menghapus pengguna ini? Data yang terhubung dengan transaksi mungkin tidak dapat dihapus.');">
                                                <input
                                                    type="hidden"
                                                    name="id_user"
                                                    value="<?= (int) $row['id_user'] ?>"
                                                >
                                                <button
                                                    type="submit"
                                                    name="hapus"
                                                    class="btn btn-sm btn-outline-danger rounded-pill px-3"
                                                    title="Hapus pengguna"
                                                >
                                                    <i class="bi bi-trash me-1"></i>Hapus
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="small text-secondary align-self-center">
                                                Akun kamu
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="empty-state">
                                <i class="bi bi-people"></i>
                                <div class="fw-bold">Belum Ada Pengguna</div>
                                <div class="small">Tambahkan pengguna melalui formulir di atas.</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <div id="hasilPencarianKosong" class="empty-state d-none">
                    <i class="bi bi-search"></i>
                    Tidak ada pengguna yang cocok dengan pencarian.
                </div>
            </div>

            <div class="px-4 py-3 small text-secondary"
                 style="background:#fffafd;border-top:1px solid var(--pink-border);">
                <i class="bi bi-info-circle me-1"></i>
                Total <?= number_format($totalPengguna, 0, ',', '.') ?> pengguna terdaftar,
                termasuk <?= number_format($totalPenyewa, 0, ',', '.') ?> penyewa.
            </div>
        <?php endif; ?>
    </section>

    <div class="text-center small text-secondary mt-4">
        <i class="bi bi-flower1 me-1" style="color:var(--pink);"></i>
        KebayaRent &mdash; Sistem Informasi Penyewaan Kebaya
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const inputCari = document.getElementById('cariPengguna');
    const tabel = document.getElementById('tabelPengguna');
    const pesanKosong = document.getElementById('hasilPencarianKosong');

    if (inputCari && tabel) {
        inputCari.addEventListener('input', function () {
            const kataKunci = this.value.toLowerCase().trim();
            const baris = tabel.querySelectorAll('tbody tr');
            let jumlahTampil = 0;

            baris.forEach(function (tr) {
                const cocok = tr.textContent.toLowerCase().includes(kataKunci);
                tr.style.display = cocok ? '' : 'none';

                if (cocok) {
                    jumlahTampil++;
                }
            });

            if (pesanKosong) {
                pesanKosong.classList.toggle(
                    'd-none',
                    jumlahTampil > 0 || baris.length === 0
                );
            }
        });
    }
</script>
</body>
</html>
