<?php
session_start();
require_once 'koneksi.php';

/* =========================
   CEK LOGIN
========================= */
if (
    !isset($_SESSION['id_user'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'petugas'], true)
) {
    header("Location: login.php");
    exit;
}

/* =========================
   FUNGSI BANTU
========================= */
function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah($angka) {
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}

/* =========================
   PROSES FOTO TANPA FOLDER
   Foto disimpan sebagai Base64
   di kolom foto database.
========================= */
function prosesFoto($field = 'foto') {
    if (!isset($_FILES[$field])) {
        return null;
    }

    if ($_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'Foto gagal diunggah. Silakan coba kembali.'
        );
    }

    // Maksimal 2 MB.
    if ($_FILES[$field]['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException(
            'Ukuran foto maksimal 2 MB.'
        );
    }

    $tmp = $_FILES[$field]['tmp_name'];

    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException(
            'File foto tidak valid.'
        );
    }

    // Validasi isi file sebagai gambar.
    $info = @getimagesize($tmp);

    if ($info === false) {
        throw new RuntimeException(
            'File harus berupa gambar yang valid.'
        );
    }

    $mime = $info['mime'] ?? '';

    $formatDiizinkan = [
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    if (!in_array($mime, $formatDiizinkan, true)) {
        throw new RuntimeException(
            'Format foto harus JPG, PNG, atau WebP.'
        );
    }

    $isi = file_get_contents($tmp);

    if ($isi === false) {
        throw new RuntimeException(
            'Foto tidak dapat dibaca.'
        );
    }

    return 'data:' . $mime . ';base64,' . base64_encode($isi);
}

$pesan = '';
$jenisPesan = 'success';
$dataEdit = null;

/* =========================
   TAMBAH KEBAYA
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah'])) {
    $id_kategori = (int)($_POST['id_kategori'] ?? 0);
    $nama = trim($_POST['nama_kebaya'] ?? '');
    $ukuran = trim($_POST['ukuran'] ?? '');
    $warna = trim($_POST['warna'] ?? '');
    $harga = (float)($_POST['harga_sewa'] ?? -1);
    $stok = (int)($_POST['stok'] ?? -1);
    $deskripsi = trim($_POST['deskripsi'] ?? '');

    $foto = null;
    $errorFoto = '';

    try {
        $foto = prosesFoto();
    } catch (RuntimeException $e) {
        $errorFoto = $e->getMessage();
    }

    if (
        $errorFoto !== '' ||
        $id_kategori <= 0 ||
        $nama === '' ||
        $harga < 0 ||
        $stok < 0
    ) {
        $pesan = $errorFoto !== ''
            ? $errorFoto
            : 'Periksa kembali data yang kamu masukkan.';

        $jenisPesan = 'danger';
    } else {
        $cekKategori = mysqli_prepare(
            $koneksi,
            "SELECT id_kategori
             FROM kategori_kebaya
             WHERE id_kategori = ?"
        );

        if (!$cekKategori) {
            $pesan = 'Gagal memeriksa kategori kebaya.';
            $jenisPesan = 'danger';
        } else {
            mysqli_stmt_bind_param(
                $cekKategori,
                'i',
                $id_kategori
            );

            mysqli_stmt_execute($cekKategori);
            $kategoriValid = mysqli_stmt_get_result($cekKategori);
            $adaKategori = mysqli_num_rows($kategoriValid) > 0;

            mysqli_stmt_close($cekKategori);

            if (!$adaKategori) {
                $pesan = 'Kategori yang dipilih tidak ditemukan.';
                $jenisPesan = 'danger';
            } else {
                $stmt = mysqli_prepare(
                    $koneksi,
                    "INSERT INTO kebaya
                    (
                        id_kategori,
                        nama_kebaya,
                        ukuran,
                        warna,
                        harga_sewa,
                        stok,
                        deskripsi,
                        foto,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'tersedia')"
                );

                if (!$stmt) {
                    $pesan = 'Gagal menyiapkan penyimpanan data kebaya.';
                    $jenisPesan = 'danger';
                } else {
                    mysqli_stmt_bind_param(
                        $stmt,
                        'isssdiss',
                        $id_kategori,
                        $nama,
                        $ukuran,
                        $warna,
                        $harga,
                        $stok,
                        $deskripsi,
                        $foto
                    );

                    if (mysqli_stmt_execute($stmt)) {
                        mysqli_stmt_close($stmt);

                        header('Location: kebaya.php?pesan=tambah');
                        exit;
                    }

                    mysqli_stmt_close($stmt);

                    $pesan = 'Gagal menambahkan kebaya. Silakan periksa data.';
                    $jenisPesan = 'danger';
                }
            }
        }
    }
}

/* =========================
   EDIT KEBAYA
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit'])) {
    $id = (int)($_POST['id_kebaya'] ?? 0);
    $id_kategori = (int)($_POST['id_kategori'] ?? 0);
    $nama = trim($_POST['nama_kebaya'] ?? '');
    $ukuran = trim($_POST['ukuran'] ?? '');
    $warna = trim($_POST['warna'] ?? '');
    $harga = (float)($_POST['harga_sewa'] ?? -1);
    $stok = (int)($_POST['stok'] ?? -1);
    $deskripsi = trim($_POST['deskripsi'] ?? '');
    $status = $_POST['status'] ?? '';

    $fotoBaru = null;
    $errorFoto = '';

    try {
        $fotoBaru = prosesFoto();
    } catch (RuntimeException $e) {
        $errorFoto = $e->getMessage();
    }

    if (
        $errorFoto !== '' ||
        $id <= 0 ||
        $id_kategori <= 0 ||
        $nama === '' ||
        $harga < 0 ||
        $stok < 0 ||
        !in_array($status, ['tersedia', 'nonaktif'], true)
    ) {
        $pesan = $errorFoto !== ''
            ? $errorFoto
            : 'Data tidak valid. Periksa kembali isian.';

        $jenisPesan = 'danger';
    } else {
        // Jika tidak memilih foto baru, pertahankan foto lama.
        if ($fotoBaru === null) {
            $stmtFoto = mysqli_prepare(
                $koneksi,
                "SELECT foto
                 FROM kebaya
                 WHERE id_kebaya = ?"
            );

            if (!$stmtFoto) {
                $pesan = 'Gagal memeriksa foto sebelumnya.';
                $jenisPesan = 'danger';
            } else {
                mysqli_stmt_bind_param(
                    $stmtFoto,
                    'i',
                    $id
                );

                mysqli_stmt_execute($stmtFoto);
                $hasilFoto = mysqli_stmt_get_result($stmtFoto);
                $fotoLama = mysqli_fetch_assoc($hasilFoto);

                mysqli_stmt_close($stmtFoto);

                if (!$fotoLama) {
                    $pesan = 'Data kebaya tidak ditemukan.';
                    $jenisPesan = 'danger';
                } else {
                    $fotoBaru = $fotoLama['foto'];
                }
            }
        }

        if ($pesan === '') {
            $stmt = mysqli_prepare(
                $koneksi,
                "UPDATE kebaya
                 SET id_kategori = ?,
                     nama_kebaya = ?,
                     ukuran = ?,
                     warna = ?,
                     harga_sewa = ?,
                     stok = ?,
                     deskripsi = ?,
                     status = ?,
                     foto = ?
                 WHERE id_kebaya = ?"
            );

            if (!$stmt) {
                $pesan = 'Gagal menyiapkan perubahan data kebaya.';
                $jenisPesan = 'danger';
            } else {
                mysqli_stmt_bind_param(
                    $stmt,
                    'isssdisssi',
                    $id_kategori,
                    $nama,
                    $ukuran,
                    $warna,
                    $harga,
                    $stok,
                    $deskripsi,
                    $status,
                    $fotoBaru,
                    $id
                );

                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);

                    header('Location: kebaya.php?pesan=edit');
                    exit;
                }

                mysqli_stmt_close($stmt);

                $pesan = 'Gagal memperbarui data kebaya.';
                $jenisPesan = 'danger';
            }
        }
    }
}

/* =========================
   HAPUS KEBAYA
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus'])) {
    $id = (int)($_POST['id_kebaya'] ?? 0);

    if ($id <= 0) {
        $pesan = 'Data kebaya tidak valid.';
        $jenisPesan = 'danger';
    } else {
        $stmt = mysqli_prepare(
            $koneksi,
            "DELETE FROM kebaya
             WHERE id_kebaya = ?"
        );

        if (!$stmt) {
            $pesan = 'Gagal menyiapkan penghapusan data.';
            $jenisPesan = 'danger';
        } else {
            mysqli_stmt_bind_param($stmt, 'i', $id);

            if (
                mysqli_stmt_execute($stmt) &&
                mysqli_stmt_affected_rows($stmt) > 0
            ) {
                mysqli_stmt_close($stmt);

                header('Location: kebaya.php?pesan=hapus');
                exit;
            }

            mysqli_stmt_close($stmt);

            $pesan = 'Kebaya tidak bisa dihapus atau datanya sudah digunakan dalam penyewaan.';
            $jenisPesan = 'danger';
        }
    }
}

/* =========================
   PESAN DARI URL
========================= */
if (isset($_GET['pesan']) && $pesan === '') {
    $daftarPesan = [
        'tambah' => 'Data kebaya berhasil ditambahkan.',
        'edit' => 'Data kebaya berhasil diperbarui.',
        'hapus' => 'Data kebaya berhasil dihapus.'
    ];

    if (isset($daftarPesan[$_GET['pesan']])) {
        $pesan = $daftarPesan[$_GET['pesan']];
        $jenisPesan = 'success';
    }
}

/* =========================
   DATA EDIT
========================= */
if (isset($_GET['edit'])) {
    $idEdit = (int)$_GET['edit'];

    if ($idEdit > 0) {
        $stmt = mysqli_prepare(
            $koneksi,
            "SELECT *
             FROM kebaya
             WHERE id_kebaya = ?"
        );

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $idEdit);
            mysqli_stmt_execute($stmt);

            $hasilEdit = mysqli_stmt_get_result($stmt);
            $dataEdit = mysqli_fetch_assoc($hasilEdit) ?: null;

            mysqli_stmt_close($stmt);
        }
    }
}

/* =========================
   KATEGORI
========================= */
$kategori = mysqli_query(
    $koneksi,
    "SELECT id_kategori, nama_kategori
     FROM kategori_kebaya
     ORDER BY nama_kategori ASC"
);

/* =========================
   PENCARIAN DAN FILTER
========================= */
$cari = trim($_GET['cari'] ?? '');
$filterStatus = $_GET['status'] ?? '';

if (!in_array($filterStatus, ['tersedia', 'nonaktif'], true)) {
    $filterStatus = '';
}

$sql = "SELECT k.*, c.nama_kategori
        FROM kebaya k
        LEFT JOIN kategori_kebaya c
          ON k.id_kategori = c.id_kategori
        WHERE 1=1";

$params = [];
$types = '';

if ($cari !== '') {
    $sql .= " AND (
        k.nama_kebaya LIKE ?
        OR c.nama_kategori LIKE ?
        OR k.warna LIKE ?
        OR k.ukuran LIKE ?
    )";

    $keyword = '%' . $cari . '%';

    $params = [
        $keyword,
        $keyword,
        $keyword,
        $keyword
    ];

    $types .= 'ssss';
}

if ($filterStatus !== '') {
    $sql .= " AND k.status = ?";
    $params[] = $filterStatus;
    $types .= 's';
}

$sql .= " ORDER BY k.id_kebaya DESC";

$stmtData = mysqli_prepare($koneksi, $sql);
$dataKebaya = false;

if ($stmtData) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmtData, $types, ...$params);
    }

    mysqli_stmt_execute($stmtData);
    $dataKebaya = mysqli_stmt_get_result($stmtData);
}

/* =========================
   RINGKASAN
========================= */
$ringkasan = mysqli_query(
    $koneksi,
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'tersedia'), 0) AS tersedia,
        COALESCE(SUM(status = 'nonaktif'), 0) AS nonaktif,
        COALESCE(SUM(stok), 0) AS total_stok
     FROM kebaya"
);

$stat = $ringkasan
    ? mysqli_fetch_assoc($ringkasan)
    : false;

$stat = $stat ?: [
    'total' => 0,
    'tersedia' => 0,
    'nonaktif' => 0,
    'total_stok' => 0
];

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Data Kebaya - KebayaRent</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
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
            background: #fff8fb;
            color: var(--ink);
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
            padding: 34px 0 74px;
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
            font-size: clamp(26px, 4vw, 34px);
            font-weight: 850;
            letter-spacing: -.8px;
        }

        .hero p {
            max-width: 680px;
            margin-bottom: 0;
            color: var(--muted);
            line-height: 1.8;
        }

        .main-wrap {
            position: relative;
            margin-top: -46px;
            padding-bottom: 45px;
        }

        .stat-card,
        .panel {
            height: 100%;
            background: #fff;
            border: 1px solid var(--pink-border);
            border-radius: 16px;
            box-shadow: 0 8px 25px #9123590b;
        }

        .stat-card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 19px;
        }

        .stat-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 48px;
            height: 48px;
            color: var(--pink);
            background: var(--pink-soft);
            border-radius: 14px;
            font-size: 21px;
        }

        .stat-label {
            margin-bottom: 5px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .stat-value {
            color: #542541;
            font-size: 23px;
            font-weight: 850;
            line-height: 1.2;
        }

        .panel {
            overflow: hidden;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 20px 23px;
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
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .panel-body {
            padding: 23px;
        }

        .form-label {
            margin-bottom: 7px;
            color: #63485c;
            font-size: 12px;
            font-weight: 750;
        }

        .form-control,
        .form-select {
            min-height: 43px;
            border: 1px solid #e9dce5;
            border-radius: 9px;
            font-size: 13px;
        }

        textarea.form-control {
            min-height: 95px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #e879a8;
            box-shadow: 0 0 0 3px #f472b624;
        }

        input[type="file"].form-control {
            padding: 9px;
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

        .btn-sm {
            border-radius: 8px;
            font-size: 12px;
            font-weight: 650;
        }

        .search-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
        }

        .search-wrap .form-control {
            min-width: 180px;
        }

        .table {
            vertical-align: middle;
        }

        .table thead th {
            padding: 14px 13px;
            color: #76556a;
            background: #fff4f9;
            border-bottom: 1px solid #f0dce7;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .35px;
            white-space: nowrap;
            text-transform: uppercase;
        }

        .table tbody td {
            padding: 13px;
            border-color: #f5eaf0;
            color: #654e60;
            font-size: 12px;
        }

        .table tbody tr:hover td {
            background: #fffafd;
        }

        .name-cell {
            min-width: 145px;
            color: #542541 !important;
            font-weight: 750;
        }

        .foto-kebaya {
            width: 58px;
            height: 68px;
            object-fit: cover;
            border-radius: 9px;
            border: 1px solid var(--pink-border);
            background: #fff8fb;
        }

        .foto-preview {
            width: 110px;
            height: 130px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid var(--pink-border);
        }

        .badge-status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 30px;
            font-size: 10px;
            font-weight: 800;
        }

        .status-tersedia {
            color: #16734a;
            background: #e5f8ee;
        }

        .status-nonaktif {
            color: #8b536e;
            background: #f8eaf1;
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
            font-size: 35px;
        }

        .footer {
            padding: 22px 15px 28px;
            color: #a18b9b;
            background: #fff;
            border-top: 1px solid #f4e4ec;
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 767px) {
            .hero {
                padding: 28px 0 65px;
            }

            .main-wrap {
                margin-top: -37px;
            }

            .panel-header,
            .panel-body {
                padding: 17px;
            }

            .stat-value {
                font-size: 20px;
            }

            .search-wrap {
                flex-direction: column;
            }

            .search-wrap .form-control,
            .search-wrap .form-select,
            .search-wrap .btn {
                width: 100%;
                max-width: none !important;
            }
        }
    </style>
</head>

<body>

<nav class="navbar navbar-expand navbar-custom py-3">
    <div class="container">
        <a href="index.php" class="navbar-brand">
            <i class="bi bi-flower1 me-2"></i>Kebaya<span>Rent</span>
        </a>

        <a href="index.php" class="btn btn-outline-light btn-sm px-3">
            <i class="bi bi-grid me-1"></i>Dashboard
        </a>
    </div>
</nav>

<section class="hero">
    <div class="container">
        <span class="eyebrow">
            <i class="bi bi-stars me-1"></i>Kelola koleksi kebaya
        </span>

        <h1>Data Kebaya</h1>

        <p>
            Kelola koleksi kebaya, foto, kategori, harga sewa, stok,
            dan status ketersediaan melalui satu halaman.
        </p>
    </div>
</section>

<main class="container main-wrap">

    <?php if ($pesan !== ''): ?>
        <div
            class="alert alert-<?= h($jenisPesan) ?> alert-dismissible fade show shadow-sm mb-4"
            role="alert">

            <i class="bi bi-info-circle-fill me-2"></i><?= h($pesan) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Tutup">
            </button>
        </div>
    <?php endif; ?>

    <!-- RINGKASAN -->
    <div class="row g-3 mb-4">

        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-grid-1x2"></i>
                </div>
                <div>
                    <div class="stat-label">Total Kebaya</div>
                    <div class="stat-value"><?= (int)$stat['total'] ?></div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-check-circle"></i>
                </div>
                <div>
                    <div class="stat-label">Tersedia</div>
                    <div class="stat-value"><?= (int)$stat['tersedia'] ?></div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-eye-slash"></i>
                </div>
                <div>
                    <div class="stat-label">Nonaktif</div>
                    <div class="stat-value"><?= (int)$stat['nonaktif'] ?></div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-box-seam"></i>
                </div>
                <div>
                    <div class="stat-label">Total Stok</div>
                    <div class="stat-value"><?= (int)$stat['total_stok'] ?></div>
                </div>
            </div>
        </div>

    </div>

    <!-- FORM TAMBAH DAN EDIT -->
    <section class="panel mb-4">

        <div class="panel-header">
            <div>
                <h2>
                    <i
                        class="bi bi-<?= $dataEdit ? 'pencil-square' : 'plus-circle' ?> me-2"
                        style="color:var(--pink)">
                    </i>

                    <?= $dataEdit ? 'Edit Data Kebaya' : 'Tambah Kebaya Baru' ?>
                </h2>

                <p>
                    <?= $dataEdit
                        ? 'Perbarui informasi dan foto kebaya yang dipilih.'
                        : 'Masukkan informasi dan foto kebaya yang akan ditambahkan.' ?>
                </p>
            </div>

            <?php if ($dataEdit): ?>
                <a href="kebaya.php" class="btn btn-soft btn-sm">
                    <i class="bi bi-x-lg me-1"></i>Batal
                </a>
            <?php endif; ?>
        </div>

        <div class="panel-body">

            <?php if (!$kategori || mysqli_num_rows($kategori) === 0): ?>

                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Belum ada kategori kebaya. Tambahkan kategori terlebih dahulu
                    melalui <a href="kategori.php">halaman kategori</a>.
                </div>

            <?php else: ?>

                <form
                    method="POST"
                    enctype="multipart/form-data"
                    action="kebaya.php<?= $dataEdit
                        ? '?edit=' . (int)$dataEdit['id_kebaya']
                        : '' ?>">

                    <?php if ($dataEdit): ?>
                        <input
                            type="hidden"
                            name="id_kebaya"
                            value="<?= (int)$dataEdit['id_kebaya'] ?>">
                    <?php endif; ?>

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label" for="id_kategori">
                                Kategori
                            </label>

                            <select
                                name="id_kategori"
                                id="id_kategori"
                                class="form-select"
                                required>

                                <option value="">-- Pilih kategori --</option>

                                <?php
                                mysqli_data_seek($kategori, 0);

                                while ($kat = mysqli_fetch_assoc($kategori)):
                                ?>
                                    <option
                                        value="<?= (int)$kat['id_kategori'] ?>"
                                        <?= (
                                            $dataEdit &&
                                            (int)$dataEdit['id_kategori'] ===
                                            (int)$kat['id_kategori']
                                        ) ? 'selected' : '' ?>>

                                        <?= h($kat['nama_kategori']) ?>
                                    </option>
                                <?php endwhile; ?>

                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="nama_kebaya">
                                Nama Kebaya
                            </label>

                            <input
                                type="text"
                                name="nama_kebaya"
                                id="nama_kebaya"
                                class="form-control"
                                maxlength="150"
                                required
                                placeholder="Contoh: Kebaya Encim Pink"
                                value="<?= h($dataEdit['nama_kebaya'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="ukuran">
                                Ukuran
                            </label>

                            <input
                                type="text"
                                name="ukuran"
                                id="ukuran"
                                class="form-control"
                                maxlength="50"
                                placeholder="Contoh: M, L, XL"
                                value="<?= h($dataEdit['ukuran'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="warna">
                                Warna
                            </label>

                            <input
                                type="text"
                                name="warna"
                                id="warna"
                                class="form-control"
                                maxlength="50"
                                placeholder="Contoh: Dusty Pink"
                                value="<?= h($dataEdit['warna'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="harga_sewa">
                                Harga Sewa (Rp)
                            </label>

                            <input
                                type="number"
                                name="harga_sewa"
                                id="harga_sewa"
                                class="form-control"
                                min="0"
                                step="0.01"
                                required
                                placeholder="Contoh: 150000"
                                value="<?= h($dataEdit['harga_sewa'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="stok">
                                Stok
                            </label>

                            <input
                                type="number"
                                name="stok"
                                id="stok"
                                class="form-control"
                                min="0"
                                required
                                placeholder="Jumlah stok"
                                value="<?= h($dataEdit['stok'] ?? '0') ?>">
                        </div>

                        <?php if ($dataEdit): ?>
                            <div class="col-md-4">
                                <label class="form-label" for="status">
                                    Status
                                </label>

                                <select
                                    name="status"
                                    id="status"
                                    class="form-select"
                                    required>

                                    <option
                                        value="tersedia"
                                        <?= $dataEdit['status'] === 'tersedia'
                                            ? 'selected' : '' ?>>
                                        Tersedia
                                    </option>

                                    <option
                                        value="nonaktif"
                                        <?= $dataEdit['status'] === 'nonaktif'
                                            ? 'selected' : '' ?>>
                                        Nonaktif
                                    </option>

                                </select>
                            </div>
                        <?php endif; ?>

                        <!-- INPUT FOTO -->
                        <div class="col-md-6">
                            <label class="form-label" for="foto">
                                <i class="bi bi-image me-1"></i>Foto Kebaya
                            </label>

                            <input
                                type="file"
                                name="foto"
                                id="foto"
                                class="form-control"
                                accept="image/jpeg,image/png,image/webp">

                            <small class="text-muted d-block mt-2">
                                Format JPG, PNG, atau WebP. Maksimal 2 MB.
                            </small>

                            <?php if ($dataEdit && !empty($dataEdit['foto'])): ?>
                                <div class="mt-3">
                                    <img
                                        src="<?= h($dataEdit['foto']) ?>"
                                        alt="Foto kebaya saat ini"
                                        class="foto-preview">

                                    <div class="small text-muted mt-1">
                                        Foto saat ini
                                    </div>

                                    <small class="text-muted">
                                        Pilih foto baru jika ingin menggantinya.
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="deskripsi">
                                Deskripsi
                            </label>

                            <textarea
                                name="deskripsi"
                                id="deskripsi"
                                class="form-control"
                                rows="3"
                                maxlength="2000"
                                placeholder="Tuliskan detail atau keterangan kebaya..."><?= h($dataEdit['deskripsi'] ?? '') ?></textarea>
                        </div>

                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-4">

                        <?php if ($dataEdit): ?>

                            <button
                                type="submit"
                                name="edit"
                                class="btn btn-pink px-4 py-2">

                                <i class="bi bi-check2-circle me-2"></i>
                                Simpan Perubahan
                            </button>

                            <a href="kebaya.php" class="btn btn-soft px-4 py-2">
                                Batal
                            </a>

                        <?php else: ?>

                            <button
                                type="submit"
                                name="tambah"
                                class="btn btn-pink px-4 py-2">

                                <i class="bi bi-plus-circle me-2"></i>
                                Tambah Kebaya
                            </button>

                            <button
                                type="reset"
                                class="btn btn-soft px-4 py-2">

                                <i class="bi bi-arrow-counterclockwise me-1"></i>
                                Reset
                            </button>

                        <?php endif; ?>

                    </div>

                </form>

            <?php endif; ?>

        </div>
    </section>

    <!-- DAFTAR KEBAYA -->
    <section class="panel">

        <div class="panel-header">
            <div>
                <h2>
                    <i class="bi bi-collection me-2" style="color:var(--pink)"></i>
                    Daftar Kebaya
                </h2>

                <p>Daftar koleksi dan foto yang tersimpan di database.</p>
            </div>

            <span class="badge rounded-pill text-bg-light border">
                <?= $dataKebaya ? mysqli_num_rows($dataKebaya) : 0 ?> data
            </span>
        </div>

        <!-- PENCARIAN -->
        <div class="panel-body border-bottom">
            <form method="GET" action="kebaya.php" class="search-wrap">

                <input
                    type="search"
                    name="cari"
                    class="form-control"
                    placeholder="Cari nama, kategori, warna..."
                    value="<?= h($cari) ?>">

                <select
                    name="status"
                    class="form-select"
                    style="max-width:190px">

                    <option value="">Semua status</option>

                    <option
                        value="tersedia"
                        <?= $filterStatus === 'tersedia' ? 'selected' : '' ?>>
                        Tersedia
                    </option>

                    <option
                        value="nonaktif"
                        <?= $filterStatus === 'nonaktif' ? 'selected' : '' ?>>
                        Nonaktif
                    </option>

                </select>

                <button type="submit" class="btn btn-pink px-3">
                    <i class="bi bi-search me-1"></i>Cari
                </button>

                <a href="kebaya.php" class="btn btn-soft px-3">
                    Reset
                </a>

            </form>
        </div>

        <!-- TABEL -->
        <div class="table-responsive">
            <table class="table table-hover mb-0">

                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Foto</th>
                        <th>Nama Kebaya</th>
                        <th>Kategori</th>
                        <th>Ukuran</th>
                        <th>Warna</th>
                        <th>Harga Sewa</th>
                        <th>Stok</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($dataKebaya && mysqli_num_rows($dataKebaya) > 0): ?>

                    <?php $no = 1; ?>

                    <?php while ($row = mysqli_fetch_assoc($dataKebaya)): ?>

                        <tr>
                            <td><?= $no++ ?></td>

                            <!-- FOTO KEBAYA -->
                            <td>
                                <?php if (!empty($row['foto'])): ?>

                                    <img
                                        src="<?= h($row['foto']) ?>"
                                        alt="Foto <?= h($row['nama_kebaya']) ?>"
                                        class="foto-kebaya"
                                        loading="lazy">

                                <?php else: ?>

                                    <div
                                        class="foto-kebaya d-flex align-items-center justify-content-center"
                                        title="Belum ada foto">

                                        <i class="bi bi-image text-secondary fs-4"></i>
                                    </div>

                                <?php endif; ?>
                            </td>

                            <td class="name-cell">
                                <i
                                    class="bi bi-flower2 me-1"
                                    style="color:#d94683">
                                </i>

                                <?= h($row['nama_kebaya']) ?>
                            </td>

                            <td>
                                <?= h($row['nama_kategori'] ?? '-') ?>
                            </td>

                            <td>
                                <?= h($row['ukuran'] ?: '-') ?>
                            </td>

                            <td>
                                <?= h($row['warna'] ?: '-') ?>
                            </td>

                            <td class="fw-bold">
                                <?= rupiah($row['harga_sewa']) ?>
                            </td>

                            <td>
                                <span class="badge rounded-pill text-bg-light border">
                                    <?= (int)$row['stok'] ?>
                                </span>
                            </td>

                            <td>
                                <?php if ($row['status'] === 'tersedia'): ?>

                                    <span class="badge-status status-tersedia">
                                        <i class="bi bi-check-circle me-1"></i>
                                        Tersedia
                                    </span>

                                <?php else: ?>

                                    <span class="badge-status status-nonaktif">
                                        <i class="bi bi-eye-slash me-1"></i>
                                        Nonaktif
                                    </span>

                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="d-flex gap-1">

                                    <a
                                        href="kebaya.php?edit=<?= (int)$row['id_kebaya'] ?>"
                                        class="btn btn-warning btn-sm"
                                        title="Edit kebaya">

                                        <i class="bi bi-pencil-square"></i>
                                    </a>

                                    <form
                                        method="POST"
                                        onsubmit="return confirm('Yakin ingin menghapus kebaya ini? Data yang sudah dipakai dalam penyewaan tidak dapat dihapus.');">

                                        <input
                                            type="hidden"
                                            name="id_kebaya"
                                            value="<?= (int)$row['id_kebaya'] ?>">

                                        <button
                                            type="submit"
                                            name="hapus"
                                            class="btn btn-danger btn-sm"
                                            title="Hapus kebaya">

                                            <i class="bi bi-trash3"></i>
                                        </button>

                                    </form>

                                </div>
                            </td>
                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="10">

                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>

                                <div class="fw-bold mb-1">
                                    Data kebaya belum ditemukan
                                </div>

                                <div>
                                    Coba ubah kata pencarian atau tambahkan kebaya baru.
                                </div>
                            </div>

                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </section>

</main>

<footer class="footer">
    © <?= date('Y') ?> KebayaRent · Kelola koleksi kebaya dengan mudah.
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
