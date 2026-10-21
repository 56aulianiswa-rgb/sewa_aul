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
function flashRedirect($pesan, $jenis = 'success')
{
    $_SESSION['flash_pengembalian'] = ['pesan' => $pesan, 'jenis' => $jenis];
    header('Location: pengembalian.php');
    exit;
}

/* Logout langsung tanpa file logout.php */
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

/* Simpan pengembalian */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan_pengembalian') {
    $idPenyewaan = filter_input(INPUT_POST, 'id_penyewaan', FILTER_VALIDATE_INT);
    $tanggal = trim($_POST['tanggal_kembali'] ?? date('Y-m-d'));
    $kondisi = $_POST['kondisi'] ?? 'baik';
    $denda = filter_input(INPUT_POST, 'denda', FILTER_VALIDATE_FLOAT);
    $catatan = trim($_POST['catatan'] ?? '');
    $idPetugas = (int)$_SESSION['id_user'];

    if (
        !$idPenyewaan || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) ||
        !in_array($kondisi, ['baik', 'rusak', 'hilang'], true) ||
        $denda === false || $denda === null || $denda < 0
    ) {
        flashRedirect('Data tidak valid. Periksa kembali formulir.', 'danger');
    }

    mysqli_begin_transaction($koneksi);
    try {
        $stmt = mysqli_prepare($koneksi, "SELECT status FROM penyewaan WHERE id_penyewaan = ? FOR UPDATE");
        if (!$stmt) throw new Exception('Gagal membaca data penyewaan.');
        mysqli_stmt_bind_param($stmt, 'i', $idPenyewaan);
        mysqli_stmt_execute($stmt);
        $penyewaan = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$penyewaan) throw new Exception('Data penyewaan tidak ditemukan.');
        if (!in_array($penyewaan['status'], ['dipinjam', 'disetujui'], true)) {
            throw new Exception('Pengembalian hanya bisa dicatat untuk status disetujui atau dipinjam.');
        }

        $cek = mysqli_prepare($koneksi, "SELECT id_pengembalian FROM pengembalian WHERE id_penyewaan = ?");
        if (!$cek) throw new Exception('Gagal memeriksa data pengembalian.');
        mysqli_stmt_bind_param($cek, 'i', $idPenyewaan);
        mysqli_stmt_execute($cek);
        $sudahAda = mysqli_fetch_assoc(mysqli_stmt_get_result($cek));
        mysqli_stmt_close($cek);
        if ($sudahAda) throw new Exception('Pengembalian untuk penyewaan ini sudah dicatat.');

        $stmt = mysqli_prepare($koneksi, "INSERT INTO pengembalian (id_penyewaan, tanggal_kembali, kondisi, denda, catatan, id_petugas) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) throw new Exception('Gagal menyiapkan penyimpanan pengembalian.');
        mysqli_stmt_bind_param($stmt, 'issdsi', $idPenyewaan, $tanggal, $kondisi, $denda, $catatan, $idPetugas);
        if (!mysqli_stmt_execute($stmt)) throw new Exception('Data pengembalian gagal disimpan.');
        mysqli_stmt_close($stmt);

        $statusLama = $penyewaan['status'];
        $statusBaru = 'selesai';
        $stmt = mysqli_prepare($koneksi, "UPDATE penyewaan SET status = 'selesai' WHERE id_penyewaan = ?");
        if (!$stmt) throw new Exception('Gagal mengubah status penyewaan.');
        mysqli_stmt_bind_param($stmt, 'i', $idPenyewaan);
        if (!mysqli_stmt_execute($stmt)) throw new Exception('Status penyewaan gagal diperbarui.');
        mysqli_stmt_close($stmt);

        $log = mysqli_prepare($koneksi, "INSERT INTO log_status_penyewaan (id_penyewaan, status_lama, status_baru) VALUES (?, ?, ?)");
        if ($log) {
            mysqli_stmt_bind_param($log, 'iss', $idPenyewaan, $statusLama, $statusBaru);
            mysqli_stmt_execute($log);
            mysqli_stmt_close($log);
        }
        $aktivitas = "Mencatat pengembalian penyewaan ID " . $idPenyewaan . " dengan kondisi " . $kondisi;
        $logAkt = mysqli_prepare($koneksi, "INSERT INTO log_aktivitas (id_user, aktivitas) VALUES (?, ?)");
        if ($logAkt) {
            mysqli_stmt_bind_param($logAkt, 'is', $idPetugas, $aktivitas);
            mysqli_stmt_execute($logAkt);
            mysqli_stmt_close($logAkt);
        }
        mysqli_commit($koneksi);
        flashRedirect('Pengembalian berhasil dicatat. Status penyewaan menjadi selesai.');
    } catch (Throwable $ex) {
        mysqli_rollback($koneksi);
        flashRedirect($ex->getMessage(), 'danger');
    }
}

$flash = $_SESSION['flash_pengembalian'] ?? null;
unset($_SESSION['flash_pengembalian']);
$q = trim($_GET['q'] ?? '');
$filterKondisi = $_GET['kondisi'] ?? '';

$sql = "SELECT pg.id_pengembalian, pg.id_penyewaan, pg.tanggal_kembali, pg.kondisi, pg.denda, pg.catatan,
               p.kode_penyewaan, p.status AS status_penyewaan, u.nama, u.no_hp
        FROM pengembalian pg
        INNER JOIN penyewaan p ON pg.id_penyewaan = p.id_penyewaan
        LEFT JOIN users u ON p.id_user = u.id_user WHERE 1=1";
$types = '';
$params = [];
if ($q !== '') {
    $sql .= ' AND (p.kode_penyewaan LIKE ? OR u.nama LIKE ? OR u.no_hp LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}
if (in_array($filterKondisi, ['baik', 'rusak', 'hilang'], true)) {
    $sql .= ' AND pg.kondisi = ?';
    $types .= 's';
    $params[] = $filterKondisi;
}
$sql .= ' ORDER BY pg.id_pengembalian DESC';
$riwayat = [];
$stmt = mysqli_prepare($koneksi, $sql);
if ($stmt) {
    if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) while ($r = mysqli_fetch_assoc($res)) $riwayat[] = $r;
    mysqli_stmt_close($stmt);
}

$opsi = [];
$sqlOpsi = "SELECT p.id_penyewaan, p.kode_penyewaan, p.status, u.nama
            FROM penyewaan p
            LEFT JOIN users u ON p.id_user = u.id_user
            LEFT JOIN pengembalian pg ON pg.id_penyewaan = p.id_penyewaan
            WHERE p.status IN ('dipinjam','disetujui') AND pg.id_pengembalian IS NULL
            ORDER BY p.id_penyewaan DESC";
$resOpsi = mysqli_query($koneksi, $sqlOpsi);
if ($resOpsi) while ($r = mysqli_fetch_assoc($resOpsi)) $opsi[] = $r;

$totalDenda = 0;
foreach ($riwayat as $r) $totalDenda += (float)$r['denda'];
$namaPetugas = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Petugas';
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pengembalian | KebayaRent</title>
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
            color: white;
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
            min-width: 900px
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
            padding: 15px;
            border-color: #f7edf2;
            vertical-align: middle;
            font-size: .87rem
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

        .metric {
            padding: 19px 21px;
            background: white;
            border: 1px solid var(--line);
            border-radius: 16px
        }

        .metric small {
            color: var(--muted)
        }

        .metric strong {
            display: block;
            font-size: 1.6rem;
            margin-top: 4px
        }

        .empty {
            padding: 38px 20px;
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
            <a class="side-link active" href="pengembalian.php"><i class="bi bi-arrow-return-left"></i> Pengembalian</a>
            <a class="side-link" href="kebaya.php"><i class="bi bi-flower2"></i> Data Kebaya</a>
            <a class="side-link" href="fitting.php"><i class="bi bi-calendar-heart"></i> Jadwal Fitting</a>
        </nav>
        <div class="nav-caption mt-4">Akun</div><a class="side-link text-danger" href="pengembalian.php?aksi=logout" onclick="return confirm('Yakin ingin keluar?')"><i class="bi bi-box-arrow-right"></i> Keluar</a>
    </aside>
    <main class="main">
        <header class="topbar">
            <div>
                <div class="eyebrow">Kelola transaksi sewa</div>
                <h1>Pengembalian Kebaya</h1>
                <p class="subtext">Catat kondisi kebaya saat dikembalikan dan kelola riwayat pengembalian.</p>
            </div>
            <div class="user-chip">
                <div class="avatar"><i class="bi bi-person-badge"></i></div>
                <div>
                    <div class="fw-bold"><?= e($namaPetugas) ?></div><small class="text-secondary">Petugas</small>
                </div>
            </div>
        </header>
        <?php if ($flash): ?><div class="alert alert-<?= e($flash['jenis']) ?> alert-dismissible fade show" role="alert"><?= e($flash['pesan']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
                <div class="metric"><small><i class="bi bi-clipboard-check me-1"></i> Pengembalian sesuai filter</small><strong><?= number_format(count($riwayat), 0, ',', '.') ?></strong></div>
            </div>
            <div class="col-12 col-md-6">
                <div class="metric"><small><i class="bi bi-cash-coin me-1"></i> Total denda sesuai filter</small><strong>Rp<?= number_format($totalDenda, 0, ',', '.') ?></strong></div>
            </div>
        </div>
        <section class="panel mb-4">
            <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h2 class="h5 fw-bold mb-1">Catat Pengembalian</h2><small class="text-secondary">Pilih penyewaan yang belum memiliki catatan pengembalian.</small>
                </div><span class="badge rounded-pill text-bg-light"><?= count($opsi) ?> menunggu pengembalian</span>
            </div>
            <div class="p-3 p-lg-4">
                <?php if ($opsi): ?><form method="post" class="row g-3">
                        <input type="hidden" name="aksi" value="simpan_pengembalian">
                        <div class="col-12 col-lg-6"><label for="id_penyewaan" class="form-label fw-semibold">Penyewaan</label><select class="form-select" id="id_penyewaan" name="id_penyewaan" required>
                                <option value="">-- Pilih penyewaan --</option><?php foreach ($opsi as $op): ?><option value="<?= (int)$op['id_penyewaan'] ?>"><?= e($op['kode_penyewaan']) ?> — <?= e($op['nama'] ?? 'Pengguna') ?> (<?= e(ucfirst($op['status'])) ?>)</option><?php endforeach; ?>
                            </select></div>
                        <div class="col-12 col-sm-6 col-lg-3"><label for="tanggal_kembali" class="form-label fw-semibold">Tanggal kembali</label><input class="form-control" type="date" id="tanggal_kembali" name="tanggal_kembali" value="<?= date('Y-m-d') ?>" required></div>
                        <div class="col-12 col-sm-6 col-lg-3"><label for="kondisi" class="form-label fw-semibold">Kondisi kebaya</label><select class="form-select" id="kondisi" name="kondisi" required>
                                <option value="baik">Baik</option>
                                <option value="rusak">Rusak</option>
                                <option value="hilang">Hilang</option>
                            </select></div>
                        <div class="col-12 col-md-4"><label for="denda" class="form-label fw-semibold">Denda (Rp)</label><input class="form-control" type="number" id="denda" name="denda" min="0" step="1000" value="0" required>
                            <div class="form-text">Isi 0 jika tidak ada denda.</div>
                        </div>
                        <div class="col-12 col-md-8"><label for="catatan" class="form-label fw-semibold">Catatan</label><input class="form-control" type="text" id="catatan" name="catatan" maxlength="1000" placeholder="Contoh: kebaya kembali dalam kondisi baik"></div>
                        <div class="col-12"><button class="btn btn-pink px-4" type="submit" onclick="return confirm('Simpan pengembalian dan tandai penyewaan selesai?')"><i class="bi bi-check2-circle me-1"></i> Simpan Pengembalian</button></div>
                    </form><?php else: ?><div class="empty"><i class="bi bi-check-circle fs-2 d-block mb-2"></i><strong>Tidak ada penyewaan yang menunggu pengembalian.</strong>
                        <div class="small mt-1">Penyewaan harus berstatus disetujui atau dipinjam dan belum pernah dicatat pengembaliannya.</div>
                    </div><?php endif; ?>
            </div>
        </section>
        <section class="panel">
            <div class="panel-head">
                <h2 class="h5 fw-bold mb-1">Riwayat Pengembalian</h2><small class="text-secondary">Cari berdasarkan kode penyewaan, nama, atau nomor HP.</small>
            </div>
            <div class="p-3 p-lg-4 border-bottom" style="border-color:var(--line)!important">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-12 col-md-7"><label for="q" class="form-label small text-secondary">Pencarian</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Kode penyewaan, nama, atau nomor HP"></div>
                    <div class="col-12 col-md-3"><label for="kondisiFilter" class="form-label small text-secondary">Kondisi</label><select class="form-select" id="kondisiFilter" name="kondisi">
                            <option value="">Semua kondisi</option>
                            <option value="baik" <?= $filterKondisi === 'baik' ? 'selected' : '' ?>>Baik</option>
                            <option value="rusak" <?= $filterKondisi === 'rusak' ? 'selected' : '' ?>>Rusak</option>
                            <option value="hilang" <?= $filterKondisi === 'hilang' ? 'selected' : '' ?>>Hilang</option>
                        </select></div>
                    <div class="col-12 col-md-2 d-flex gap-2"><button class="btn btn-pink flex-grow-1" type="submit"><i class="bi bi-search"></i> Cari</button><a class="btn btn-outline-secondary" href="pengembalian.php" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a></div>
                </form>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kode penyewaan</th>
                            <th>Nama penyewa</th>
                            <th>Tanggal kembali</th>
                            <th>Kondisi</th>
                            <th>Denda</th>
                            <th>Catatan</th>
                            <th>Status sewa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($riwayat): foreach ($riwayat as $r): ?>
                                <tr>
                                    <td><span class="code"><?= e($r['kode_penyewaan']) ?></span></td>
                                    <td><?= e($r['nama'] ?? 'Pengguna') ?><div class="small text-secondary"><?= e($r['no_hp'] ?? '-') ?></div>
                                    </td>
                                    <td><?= !empty($r['tanggal_kembali']) ? date('d/m/Y', strtotime($r['tanggal_kembali'])) : '-' ?></td>
                                    <td><?php $warna = ['baik' => 'bg-success-subtle text-success-emphasis', 'rusak' => 'bg-warning-subtle text-warning-emphasis', 'hilang' => 'bg-danger-subtle text-danger-emphasis']; ?><span class="pill <?= $warna[$r['kondisi']] ?? 'bg-light text-dark' ?>"><?= e(ucfirst($r['kondisi'])) ?></span></td>
                                    <td class="fw-bold">Rp<?= number_format((float)$r['denda'], 0, ',', '.') ?></td>
                                    <td><?= e($r['catatan'] ?: '-') ?></td>
                                    <td><span class="pill bg-success-subtle text-success-emphasis"><?= e(ucfirst($r['status_penyewaan'])) ?></span></td>
                                </tr>
                            <?php endforeach;
                        else: ?><tr>
                                <td colspan="7">
                                    <div class="empty"><i class="bi bi-inbox fs-2 d-block mb-2"></i><strong>Belum ada riwayat pengembalian</strong>
                                        <div class="small mt-1">Data yang sesuai pencarian akan tampil di sini.</div>
                                    </div>
                                </td>
                            </tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <footer class="text-center text-secondary small mt-5 pb-2">© <?= date('Y') ?> KebayaRent · Panel Petugas</footer>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>