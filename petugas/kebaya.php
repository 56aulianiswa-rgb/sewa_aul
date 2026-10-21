<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['id_user']) || ($_SESSION['role'] ?? '') !== 'petugas') {
    header('Location: ../login.php#login');
    exit;
}

require_once __DIR__ . '/../koneksi.php';
mysqli_report(MYSQLI_REPORT_OFF);

function e($v)
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function flashKebaya($pesan, $jenis = 'success')
{
    $_SESSION['flash_kebaya'] = ['pesan' => $pesan, 'jenis' => $jenis];
    header('Location: kebaya.php');
    exit;
}

/* Logout tanpa file logout.php */
if (isset($_GET['aksi']) && $_GET['aksi'] === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ../login.php#login');
    exit;
}

$flash = null;

/* Proses tambah/edit/hapus */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'simpan') {
        $id = filter_input(INPUT_POST, 'id_kebaya', FILTER_VALIDATE_INT);
        $idKategori = filter_input(INPUT_POST, 'id_kategori', FILTER_VALIDATE_INT);
        $nama = trim($_POST['nama_kebaya'] ?? '');
        $ukuran = trim($_POST['ukuran'] ?? '');
        $warna = trim($_POST['warna'] ?? '');
        $harga = filter_input(INPUT_POST, 'harga_sewa', FILTER_VALIDATE_FLOAT);
        $stok = filter_input(INPUT_POST, 'stok', FILTER_VALIDATE_INT);
        $deskripsi = trim($_POST['deskripsi'] ?? '');
        $status = $_POST['status'] ?? 'tersedia';
        $fotoLama = trim($_POST['foto_lama'] ?? '');
        $foto = $fotoLama;

        if (
            !$idKategori || $nama === '' || $ukuran === '' || $warna === '' ||
            $harga === false || $harga === null || $harga < 0 ||
            $stok === false || $stok === null || $stok < 0 ||
            !in_array($status, ['tersedia', 'nonaktif'], true)
        ) {
            flashKebaya('Periksa kembali data kebaya. Semua kolom wajib harus valid.', 'danger');
        }

        /* Upload foto opsional */
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
                flashKebaya('Upload foto gagal. Coba pilih foto lain.', 'danger');
            }
            if ($_FILES['foto']['size'] > 3 * 1024 * 1024) {
                flashKebaya('Ukuran foto maksimal 3 MB.', 'danger');
            }
            $tmp = $_FILES['foto']['tmp_name'];
            $info = @getimagesize($tmp);
            if ($info === false) flashKebaya('File harus berupa gambar yang valid.', 'danger');

            $mime = $info['mime'] ?? '';
            $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extMap[$mime])) flashKebaya('Format foto yang diizinkan: JPG, PNG, atau WEBP.', 'danger');

            $folderUpload = __DIR__ . '/../uploads';
            if (!is_dir($folderUpload) && !mkdir($folderUpload, 0755, true)) {
                flashKebaya('Folder uploads tidak dapat dibuat.', 'danger');
            }
            $namaFile = 'kebaya_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime];
            if (!move_uploaded_file($tmp, $folderUpload . DIRECTORY_SEPARATOR . $namaFile)) {
                flashKebaya('Foto tidak berhasil disimpan ke folder uploads.', 'danger');
            }
            $foto = $namaFile;
        }

        if ($id) {
            $stmt = mysqli_prepare($koneksi, "UPDATE kebaya SET id_kategori=?, nama_kebaya=?, ukuran=?, warna=?, harga_sewa=?, stok=?, deskripsi=?, foto=?, status=? WHERE id_kebaya=?");
            if (!$stmt) flashKebaya('Query edit kebaya gagal disiapkan.', 'danger');
            mysqli_stmt_bind_param($stmt, 'isssdisssi', $idKategori, $nama, $ukuran, $warna, $harga, $stok, $deskripsi, $foto, $status, $id);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            if (!$ok) flashKebaya('Data kebaya gagal diperbarui. Periksa struktur tabel dan data kategori.', 'danger');
            flashKebaya('Data kebaya berhasil diperbarui.');
        } else {
            $stmt = mysqli_prepare($koneksi, "INSERT INTO kebaya (id_kategori, nama_kebaya, ukuran, warna, harga_sewa, stok, deskripsi, foto, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) flashKebaya('Query tambah kebaya gagal disiapkan.', 'danger');
            mysqli_stmt_bind_param($stmt, 'isssdisss', $idKategori, $nama, $ukuran, $warna, $harga, $stok, $deskripsi, $foto, $status);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            if (!$ok) flashKebaya('Data kebaya gagal ditambahkan. Periksa struktur tabel dan kategori.', 'danger');
            flashKebaya('Kebaya baru berhasil ditambahkan.');
        }
    }

    if ($aksi === 'hapus') {
        $id = filter_input(INPUT_POST, 'id_kebaya', FILTER_VALIDATE_INT);
        if (!$id) flashKebaya('ID kebaya tidak valid.', 'danger');

        /* Jika sudah dipakai di transaksi, nonaktifkan agar histori tidak rusak */
        $stmtCek = mysqli_prepare($koneksi, "SELECT COUNT(*) AS jumlah FROM detail_penyewaan WHERE id_kebaya=?");
        if (!$stmtCek) flashKebaya('Gagal memeriksa histori penyewaan.', 'danger');
        mysqli_stmt_bind_param($stmtCek, 'i', $id);
        mysqli_stmt_execute($stmtCek);
        $cek = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCek));
        mysqli_stmt_close($stmtCek);

        if ((int)($cek['jumlah'] ?? 0) > 0) {
            $stmt = mysqli_prepare($koneksi, "UPDATE kebaya SET status='nonaktif' WHERE id_kebaya=?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            flashKebaya($ok ? 'Kebaya sudah digunakan dalam transaksi, jadi dinonaktifkan agar histori tetap aman.' : 'Kebaya gagal dinonaktifkan.', $ok ? 'warning' : 'danger');
        }

        $stmt = mysqli_prepare($koneksi, "DELETE FROM kebaya WHERE id_kebaya=?");
        if (!$stmt) flashKebaya('Query hapus kebaya gagal disiapkan.', 'danger');
        mysqli_stmt_bind_param($stmt, 'i', $id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        flashKebaya($ok ? 'Data kebaya berhasil dihapus.' : 'Data gagal dihapus. Jika sudah dipakai transaksi, nonaktifkan saja.', $ok ? 'success' : 'danger');
    }
}

$flash = $_SESSION['flash_kebaya'] ?? null;
unset($_SESSION['flash_kebaya']);

$q = trim($_GET['q'] ?? '');
$filterStatus = $_GET['status'] ?? '';
$filterKategori = filter_input(INPUT_GET, 'kategori', FILTER_VALIDATE_INT);

$kategori = [];
$resKat = mysqli_query($koneksi, "SELECT id_kategori, nama_kategori FROM kategori_kebaya ORDER BY nama_kategori");
if ($resKat) while ($r = mysqli_fetch_assoc($resKat)) $kategori[] = $r;

$sql = "SELECT k.id_kebaya, k.id_kategori, k.nama_kebaya, k.ukuran, k.warna, k.harga_sewa, k.stok, k.deskripsi, k.foto, k.status, kk.nama_kategori
        FROM kebaya k LEFT JOIN kategori_kebaya kk ON k.id_kategori=kk.id_kategori WHERE 1=1";
$types = '';
$params = [];
if ($q !== '') {
    $sql .= ' AND (k.nama_kebaya LIKE ? OR k.warna LIKE ? OR k.ukuran LIKE ? OR kk.nama_kategori LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($filterStatus, ['tersedia', 'nonaktif'], true)) {
    $sql .= ' AND k.status=?';
    $types .= 's';
    $params[] = $filterStatus;
}
if ($filterKategori) {
    $sql .= ' AND k.id_kategori=?';
    $types .= 'i';
    $params[] = $filterKategori;
}
$sql .= ' ORDER BY k.id_kebaya DESC';
$items = [];
$stmt = mysqli_prepare($koneksi, $sql);
if ($stmt) {
    if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) while ($r = mysqli_fetch_assoc($res)) $items[] = $r;
    mysqli_stmt_close($stmt);
}

function urlFoto($foto)
{
    $foto = trim((string)$foto);
    if ($foto === '') return '';
    if (preg_match('~^https?://~i', $foto)) return $foto;
    return '../uploads/' . rawurlencode(basename($foto));
}
$namaPetugas = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Petugas';
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Kebaya | KebayaRent</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --pink: #d95f91;
            --dark: #b94476;
            --light: #fff0f6;
            --ink: #392b34;
            --muted: #897984;
            --line: #f1e4eb
        }

        body {
            margin: 0;
            background: #fff9fc;
            color: var(--ink);
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif
        }

        .sidebar {
            width: 250px;
            min-height: 100vh;
            position: fixed;
            inset: 0 auto 0 0;
            background: #fff;
            border-right: 1px solid var(--line);
            padding: 26px 17px;
            z-index: 5
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 0 10px 28px;
            color: var(--dark);
            font-size: 1.2rem;
            font-weight: 800;
            text-decoration: none
        }

        .brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: #fff;
            background: linear-gradient(135deg, #ec8db5, #c94e84);
            font-size: 1.25rem
        }

        .nav-caption {
            color: #b5a4ae;
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .12em;
            padding: 10px 13px;
            text-transform: uppercase
        }

        .side-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 13px;
            border-radius: 12px;
            margin: 4px 0;
            color: #786572;
            text-decoration: none;
            font-weight: 600;
            font-size: .93rem
        }

        .side-link i {
            font-size: 1.1rem;
            width: 22px;
            text-align: center
        }

        .side-link:hover,
        .side-link.active {
            color: var(--dark);
            background: var(--light)
        }

        .main {
            margin-left: 250px;
            padding: 30px
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 25px
        }

        .eyebrow {
            color: var(--dark);
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            font-weight: 800
        }

        h1 {
            font-size: clamp(1.5rem, 2.5vw, 2rem);
            font-weight: 800;
            margin: 5px 0
        }

        .subtext {
            color: var(--muted);
            margin: 0
        }

        .user-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            border: 1px solid var(--line);
            border-radius: 15px;
            padding: 9px 13px
        }

        .avatar {
            width: 39px;
            height: 39px;
            display: grid;
            place-items: center;
            border-radius: 13px;
            color: var(--dark);
            background: var(--light);
            font-size: 1.2rem
        }

        .panel {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 5px 20px #68354f09
        }

        .panel-head {
            padding: 21px 22px;
            border-bottom: 1px solid var(--line)
        }

        .form-control,
        .form-select {
            border-color: #eadce4;
            border-radius: 10px;
            padding: .65rem .8rem
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #df8bb0;
            box-shadow: 0 0 0 .2rem #fbe4ee
        }

        .btn-pink {
            background: var(--pink);
            border-color: var(--pink);
            color: #fff;
            border-radius: 10px;
            font-weight: 700
        }

        .btn-pink:hover {
            background: var(--dark);
            border-color: var(--dark);
            color: #fff
        }

        .table-wrap {
            overflow-x: auto
        }

        .table {
            margin: 0;
            min-width: 950px
        }

        .table thead th {
            background: #fff9fc;
            color: #9a8792;
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            border-bottom: 1px solid var(--line);
            padding: 14px 15px;
            white-space: nowrap
        }

        .table tbody td {
            padding: 13px 15px;
            border-color: #f7edf2;
            vertical-align: middle;
            font-size: .87rem
        }

        .thumb {
            width: 58px;
            height: 65px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #fff4f8
        }

        .no-photo {
            width: 58px;
            height: 65px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            background: var(--light);
            color: var(--dark);
            font-size: 1.4rem
        }

        .code {
            color: var(--dark);
            font-weight: 800
        }

        .pill {
            display: inline-block;
            border-radius: 30px;
            padding: 6px 10px;
            font-size: .75rem;
            font-weight: 800
        }

        .empty {
            padding: 40px 20px;
            text-align: center;
            color: var(--muted)
        }

        @media(max-width:991px) {
            .sidebar {
                position: static;
                width: 100%;
                min-height: auto;
                padding: 12px 15px;
                border-right: 0;
                border-bottom: 1px solid var(--line)
            }

            .brand {
                padding: 0 5px 10px
            }

            .side-nav {
                display: flex;
                gap: 5px;
                overflow-x: auto
            }

            .nav-caption {
                display: none
            }

            .side-link {
                white-space: nowrap;
                padding: 9px 11px
            }

            .main {
                margin-left: 0;
                padding: 22px 15px
            }
        }

        @media(max-width:575px) {
            .topbar {
                align-items: flex-start;
                flex-direction: column
            }

            .user-chip {
                width: 100%
            }
        }
    </style>
</head>

<body>
    <aside class="sidebar">
        <a class="brand" href="dashboard.php"><span class="brand-icon"><i class="bi bi-flower1"></i></span><span>KebayaRent<small class="d-block text-secondary fw-normal" style="font-size:.72rem">Panel Petugas</small></span></a>
        <div class="nav-caption">Menu utama</div>
        <nav class="side-nav">
            <a class="side-link" href="dashboard.php"><i class="bi bi-grid-1x2-fill"></i> Dashboard</a>
            <a class="side-link" href="penyewaan.php"><i class="bi bi-calendar2-check"></i> Data Penyewaan</a>
            <a class="side-link" href="pengembalian.php"><i class="bi bi-arrow-return-left"></i> Pengembalian</a>
            <a class="side-link active" href="kebaya.php"><i class="bi bi-flower2"></i> Data Kebaya</a>
            <a class="side-link" href="fitting.php"><i class="bi bi-calendar-heart"></i> Jadwal Fitting</a>
        </nav>
        <div class="nav-caption mt-4">Akun</div><a class="side-link text-danger" href="kebaya.php?aksi=logout" onclick="return confirm('Yakin ingin keluar?')"><i class="bi bi-box-arrow-right"></i> Keluar</a>
    </aside>
    <main class="main">
        <header class="topbar">
            <div>
                <div class="eyebrow">Manajemen katalog</div>
                <h1>Data Kebaya</h1>
                <p class="subtext">Kelola nama, kategori, ukuran, stok, harga sewa, dan foto kebaya.</p>
            </div>
            <div class="user-chip">
                <div class="avatar"><i class="bi bi-person-badge"></i></div>
                <div>
                    <div class="fw-bold"><?= e($namaPetugas) ?></div><small class="text-secondary">Petugas</small>
                </div>
            </div>
        </header>

        <?php if ($flash): ?><div class="alert alert-<?= e($flash['jenis']) ?> alert-dismissible fade show" role="alert"><?= e($flash['pesan']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

        <section class="panel mb-4">
            <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h2 class="h5 fw-bold mb-1">Tambah Kebaya</h2><small class="text-secondary">Isi formulir untuk menambahkan item baru ke katalog.</small>
                </div><button class="btn btn-pink px-3" type="button" data-bs-toggle="collapse" data-bs-target="#formTambah"><i class="bi bi-plus-lg me-1"></i> Form Tambah</button>
            </div>
            <div class="collapse show" id="formTambah">
                <div class="p-3 p-lg-4">
                    <form method="post" enctype="multipart/form-data" class="row g-3">
                        <input type="hidden" name="aksi" value="simpan">
                        <div class="col-12 col-md-6"><label class="form-label fw-semibold" for="nama_kebaya">Nama kebaya</label><input class="form-control" id="nama_kebaya" name="nama_kebaya" maxlength="150" required></div>
                        <div class="col-12 col-md-6"><label class="form-label fw-semibold" for="id_kategori">Kategori</label><select class="form-select" id="id_kategori" name="id_kategori" required>
                                <option value="">-- Pilih kategori --</option><?php foreach ($kategori as $k): ?><option value="<?= (int)$k['id_kategori'] ?>"><?= e($k['nama_kategori']) ?></option><?php endforeach; ?>
                            </select><?php if (!$kategori): ?><small class="text-danger">Belum ada kategori. Tambahkan kategori terlebih dahulu ke tabel kategori_kebaya.</small><?php endif; ?></div>
                        <div class="col-6 col-md-3"><label class="form-label fw-semibold" for="ukuran">Ukuran</label><input class="form-control" id="ukuran" name="ukuran" placeholder="S, M, L, XL" required></div>
                        <div class="col-6 col-md-3"><label class="form-label fw-semibold" for="warna">Warna</label><input class="form-control" id="warna" name="warna" required></div>
                        <div class="col-6 col-md-3"><label class="form-label fw-semibold" for="harga_sewa">Harga sewa (Rp)</label><input class="form-control" type="number" id="harga_sewa" name="harga_sewa" min="0" step="1000" required></div>
                        <div class="col-6 col-md-3"><label class="form-label fw-semibold" for="stok">Stok</label><input class="form-control" type="number" id="stok" name="stok" min="0" value="1" required></div>
                        <div class="col-12 col-md-6"><label class="form-label fw-semibold" for="foto">Foto (JPG/PNG/WEBP, maks. 3 MB)</label><input class="form-control" type="file" id="foto" name="foto" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"></div>
                        <div class="col-12 col-md-6"><label class="form-label fw-semibold" for="status">Status</label><select class="form-select" id="status" name="status">
                                <option value="tersedia">Tersedia</option>
                                <option value="nonaktif">Nonaktif</option>
                            </select></div>
                        <div class="col-12"><label class="form-label fw-semibold" for="deskripsi">Deskripsi</label><textarea class="form-control" id="deskripsi" name="deskripsi" rows="2" placeholder="Deskripsi kebaya (opsional)"></textarea></div>
                        <div class="col-12"><button class="btn btn-pink px-4" type="submit"><i class="bi bi-save me-1"></i> Simpan Kebaya</button></div>
                    </form>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2 class="h5 fw-bold mb-1">Daftar Kebaya</h2><small class="text-secondary">Cari dan kelola item yang sudah tersimpan.</small>
            </div>
            <div class="p-3 p-lg-4 border-bottom" style="border-color:var(--line)!important">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-12 col-md-5"><label class="form-label small text-secondary" for="q">Pencarian</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Nama, warna, ukuran, kategori"></div>
                    <div class="col-12 col-md-3"><label class="form-label small text-secondary" for="kategoriFilter">Kategori</label><select class="form-select" id="kategoriFilter" name="kategori">
                            <option value="">Semua kategori</option><?php foreach ($kategori as $k): ?><option value="<?= (int)$k['id_kategori'] ?>" <?= (int)$filterKategori === (int)$k['id_kategori'] ? 'selected' : '' ?>><?= e($k['nama_kategori']) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-12 col-md-2"><label class="form-label small text-secondary" for="statusFilter">Status</label><select class="form-select" id="statusFilter" name="status">
                            <option value="">Semua</option>
                            <option value="tersedia" <?= $filterStatus === 'tersedia' ? 'selected' : '' ?>>Tersedia</option>
                            <option value="nonaktif" <?= $filterStatus === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                        </select></div>
                    <div class="col-12 col-md-2 d-flex gap-2"><button class="btn btn-pink flex-grow-1" type="submit"><i class="bi bi-search"></i> Cari</button><a class="btn btn-outline-secondary" href="kebaya.php" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a></div>
                </form>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Foto</th>
                            <th>Nama kebaya</th>
                            <th>Kategori</th>
                            <th>Ukuran</th>
                            <th>Warna</th>
                            <th>Harga sewa</th>
                            <th>Stok</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($items): foreach ($items as $item): ?>
                                <tr>
                                    <td><?php $fotoUrl = urlFoto($item['foto'] ?? '');
                                        if ($fotoUrl): ?><img class="thumb" src="<?= e($fotoUrl) ?>" alt="<?= e($item['nama_kebaya']) ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='grid'"><span class="no-photo" style="display:none"><i class="bi bi-image"></i></span><?php else: ?><span class="no-photo"><i class="bi bi-image"></i></span><?php endif; ?></td>
                                    <td><strong><?= e($item['nama_kebaya']) ?></strong><?php if (!empty($item['deskripsi'])): ?><div class="small text-secondary"><?= e(mb_strimwidth($item['deskripsi'], 0, 65, '…', 'UTF-8')) ?></div><?php endif; ?></td>
                                    <td><?= e($item['nama_kategori'] ?? '-') ?></td>
                                    <td><?= e($item['ukuran']) ?></td>
                                    <td><?= e($item['warna']) ?></td>
                                    <td class="fw-bold">Rp<?= number_format((float)$item['harga_sewa'], 0, ',', '.') ?></td>
                                    <td><?= (int)$item['stok'] ?></td>
                                    <td><?php if ($item['status'] === 'tersedia'): ?><span class="pill bg-success-subtle text-success-emphasis">Tersedia</span><?php else: ?><span class="pill bg-secondary-subtle text-secondary-emphasis">Nonaktif</span><?php endif; ?></td>
                                    <td>
                                        <div class="d-flex gap-2"><button class="btn btn-sm btn-outline-secondary rounded-3" data-bs-toggle="modal" data-bs-target="#edit<?= (int)$item['id_kebaya'] ?>"><i class="bi bi-pencil-square"></i> Edit</button>
                                            <form method="post" onsubmit="return confirm('Hapus kebaya ini? Jika sudah digunakan dalam transaksi, item akan dinonaktifkan saja.')"><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id_kebaya" value="<?= (int)$item['id_kebaya'] ?>"><button class="btn btn-sm btn-outline-danger rounded-3" type="submit"><i class="bi bi-trash"></i></button></form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach;
                        else: ?><tr>
                                <td colspan="9">
                                    <div class="empty"><i class="bi bi-flower2 fs-2 d-block mb-2"></i><strong>Data kebaya belum ditemukan</strong>
                                        <div class="small mt-1">Tambahkan kebaya atau ubah filter pencarian.</div>
                                    </div>
                                </td>
                            </tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php foreach ($items as $item): ?>
            <div class="modal fade" id="edit<?= (int)$item['id_kebaya'] ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content border-0 rounded-4">
                        <form method="post" enctype="multipart/form-data">
                            <div class="modal-header border-0 pb-0">
                                <div>
                                    <h2 class="modal-title fs-5 fw-bold">Edit Kebaya</h2>
                                    <p class="small text-secondary mb-0"><?= e($item['nama_kebaya']) ?></p>
                                </div><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body"><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id_kebaya" value="<?= (int)$item['id_kebaya'] ?>"><input type="hidden" name="foto_lama" value="<?= e($item['foto'] ?? '') ?>">
                                <div class="row g-3">
                                    <div class="col-md-6"><label class="form-label fw-semibold">Nama kebaya</label><input class="form-control" name="nama_kebaya" value="<?= e($item['nama_kebaya']) ?>" required></div>
                                    <div class="col-md-6"><label class="form-label fw-semibold">Kategori</label><select class="form-select" name="id_kategori" required><?php foreach ($kategori as $k): ?><option value="<?= (int)$k['id_kategori'] ?>" <?= (int)$item['id_kategori'] === (int)$k['id_kategori'] ? 'selected' : '' ?>><?= e($k['nama_kategori']) ?></option><?php endforeach; ?></select></div>
                                    <div class="col-sm-3"><label class="form-label fw-semibold">Ukuran</label><input class="form-control" name="ukuran" value="<?= e($item['ukuran']) ?>" required></div>
                                    <div class="col-sm-3"><label class="form-label fw-semibold">Warna</label><input class="form-control" name="warna" value="<?= e($item['warna']) ?>" required></div>
                                    <div class="col-sm-3"><label class="form-label fw-semibold">Harga sewa (Rp)</label><input class="form-control" type="number" min="0" step="1000" name="harga_sewa" value="<?= e($item['harga_sewa']) ?>" required></div>
                                    <div class="col-sm-3"><label class="form-label fw-semibold">Stok</label><input class="form-control" type="number" min="0" name="stok" value="<?= (int)$item['stok'] ?>" required></div>
                                    <div class="col-md-6"><label class="form-label fw-semibold">Ganti foto (opsional)</label><input class="form-control" type="file" name="foto" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><?php if (!empty($item['foto'])): ?><small class="text-secondary">Foto saat ini: <?= e($item['foto']) ?></small><?php endif; ?></div>
                                    <div class="col-md-6"><label class="form-label fw-semibold">Status</label><select class="form-select" name="status">
                                            <option value="tersedia" <?= $item['status'] === 'tersedia' ? 'selected' : '' ?>>Tersedia</option>
                                            <option value="nonaktif" <?= $item['status'] === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                                        </select></div>
                                    <div class="col-12"><label class="form-label fw-semibold">Deskripsi</label><textarea class="form-control" name="deskripsi" rows="3"><?= e($item['deskripsi'] ?? '') ?></textarea></div>
                                </div>
                            </div>
                            <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-light rounded-3" data-bs-dismiss="modal">Batal</button><button class="btn btn-pink px-4" type="submit"><i class="bi bi-save me-1"></i> Simpan Perubahan</button></div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <footer class="text-center text-secondary small mt-5 pb-2">© <?= date('Y') ?> KebayaRent · Panel Petugas</footer>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>