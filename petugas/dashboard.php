
<?php
session_start();
require_once __DIR__ . '/../koneksi.php';

if (!isset($_SESSION['id_user']) || 
    !in_array($_SESSION['role'] ?? '', ['petugas', 'admin'], true)) {
    header('Location: ../login.php');
    exit;
}

mysqli_set_charset($koneksi, 'utf8mb4');

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tableExists($db, $table) {
    $table = mysqli_real_escape_string($db, $table);
    $result = mysqli_query($db, "SHOW TABLES LIKE '$table'");
    return $result && mysqli_num_rows($result) > 0;
}

function getColumns($db, $table) {
    $columns = [];
    if (!tableExists($db, $table)) {
        return $columns;
    }

    $result = mysqli_query($db, "SHOW COLUMNS FROM `$table`");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $columns[] = $row['Field'];
        }
    }
    return $columns;
}

function firstColumn($columns, $candidates) {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function getCount($db, $sql) {
    $result = mysqli_query($db, $sql);
    if (!$result) return 0;
    $row = mysqli_fetch_row($result);
    return (int)($row[0] ?? 0);
}

$pesan = '';
$error = '';

$kolomPembayaran = getColumns($koneksi, 'pembayaran');
$kolomFitting = getColumns($koneksi, 'janji_fitting');

/*
|--------------------------------------------------------------------------
| PROSES PEMBAYARAN
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['aksi_pembayaran'])) {

    $id = filter_input(INPUT_POST, 'id_pembayaran', FILTER_VALIDATE_INT);
    $aksi = $_POST['aksi_pembayaran'];

    if (!$id || !in_array($aksi, ['verifikasi', 'tolak'], true)) {
        $error = 'Permintaan pembayaran tidak valid.';
    } elseif (!in_array('status', $kolomPembayaran, true)) {
        $error = 'Kolom status pada tabel pembayaran tidak ditemukan.';
    } else {
        $statusBaru = $aksi === 'verifikasi' ? 'diverifikasi' : 'ditolak';
        $catatan = trim($_POST['catatan_petugas'] ?? '');

        $statusBaru = mysqli_real_escape_string($koneksi, $statusBaru);
        $catatan = mysqli_real_escape_string($koneksi, $catatan);

        $set = "status = '$statusBaru'";

        if (in_array('catatan_petugas', $kolomPembayaran, true)) {
            $set .= ", catatan_petugas = '$catatan'";
        }

        $result = mysqli_query(
            $koneksi,
            "UPDATE pembayaran SET $set WHERE id_pembayaran = $id"
        );

        if ($result && mysqli_affected_rows($koneksi) >= 0) {
            $pesan = 'Status pembayaran berhasil diperbarui.';
        } else {
            $error = 'Gagal memperbarui pembayaran.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| PROSES PERSETUJUAN FITTING
|--------------------------------------------------------------------------
| Nama kolom status dicari dari beberapa kemungkinan.
| Jika nama kolom tidak cocok, proses dihentikan dengan pesan yang jelas.
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['aksi_fitting'])) {

    $id = filter_input(INPUT_POST, 'id_fitting', FILTER_VALIDATE_INT);
    $aksi = $_POST['aksi_fitting'];

    $idKolom = firstColumn($kolomFitting, [
        'id_janji_fitting',
        'id_fitting',
        'id'
    ]);

    $statusKolom = firstColumn($kolomFitting, [
        'status',
        'status_fitting',
        'status_pengajuan'
    ]);

    if (!tableExists($koneksi, 'janji_fitting')) {
        $error = 'Tabel janji_fitting belum tersedia di database.';
    } elseif (!$id || !$idKolom || !$statusKolom ||
        !in_array($aksi, ['setujui', 'tolak'], true)) {
        $error = 'Struktur tabel fitting belum cocok. Periksa kolom tabel janji_fitting.';
    } else {
        $statusBaru = $aksi === 'setujui' ? 'disetujui' : 'ditolak';
        $statusBaru = mysqli_real_escape_string($koneksi, $statusBaru);

        $query = "UPDATE `janji_fitting`
                  SET `$statusKolom` = '$statusBaru'
                  WHERE `$idKolom` = $id";

        $result = mysqli_query($koneksi, $query);

        if ($result) {
            $pesan = 'Status pengajuan fitting berhasil diperbarui.';
        } else {
            $error = 'Gagal memperbarui fitting: ' . mysqli_error($koneksi);
        }
    }
}

/*
|--------------------------------------------------------------------------
| AMBIL DATA PEMBAYARAN
|--------------------------------------------------------------------------
*/
$dataPembayaran = [];
if (tableExists($koneksi, 'pembayaran')) {
    $queryPembayaran = "
        SELECT p.*, s.id_penyewaan
        FROM pembayaran p
        LEFT JOIN penyewaan s
          ON s.id_penyewaan = p.id_penyewaan
        ORDER BY p.id_pembayaran DESC
    ";

    $result = mysqli_query($koneksi, $queryPembayaran);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $dataPembayaran[] = $row;
        }
    }
}

/*
|--------------------------------------------------------------------------
| AMBIL DATA FITTING
|--------------------------------------------------------------------------
*/
$dataFitting = [];
if (tableExists($koneksi, 'janji_fitting')) {
    $result = mysqli_query($koneksi, "SELECT * FROM janji_fitting ORDER BY 1 DESC");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $dataFitting[] = $row;
        }
    }
}

$totalPembayaran = count($dataPembayaran);
$totalMenunggu = 0;
foreach ($dataPembayaran as $p) {
    if (($p['status'] ?? '') === 'menunggu') {
        $totalMenunggu++;
    }
}

$totalFitting = count($dataFitting);

function formatRupiah($angka) {
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}

function tampilStatus($status) {
    $status = (string)$status;
    if ($status === '') return 'Belum ditentukan';
    return ucfirst(str_replace('_', ' ', $status));
}

function lokasiBukti($path) {
    $path = trim((string)$path);
    if ($path === '') return '';

    // Hindari path traversal: hanya gunakan nama file.
    $namaFile = basename(str_replace('\\', '/', $path));

    $kandidat = [
        __DIR__ . '/../penyewa/bukti_pembayaran/' . $namaFile,
        __DIR__ . '/../bukti_pembayaran/' . $namaFile,
        __DIR__ . '/../' . ltrim($path, '/')
    ];

    foreach ($kandidat as $file) {
        if (is_file($file)) {
            return str_replace('\\', '/', substr($file, strlen(dirname(__DIR__)) + 1));
        }
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard Petugas - KebayaRent</title>
<style>
:root {
    --bg: #f7f2f5;
    --card: #ffffff;
    --text: #352832;
    --muted: #786a75;
    --primary: #9b5277;
    --primary-dark: #753655;
    --border: #eadde5;
    --green: #247747;
    --red: #b42332;
    --yellow: #986400;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: Arial, Helvetica, sans-serif;
}
header {
    background: var(--primary-dark);
    color: white;
    padding: 22px 5%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
}
header h1 { margin: 0 0 6px; font-size: 24px; }
header p { margin: 0; opacity: .85; }
a { color: var(--primary-dark); }
.logout {
    display: inline-block;
    color: white;
    border: 1px solid #ffffff88;
    padding: 10px 15px;
    border-radius: 8px;
    text-decoration: none;
}
main { width: min(1200px, 94%); margin: 26px auto; }
.notice {
    padding: 13px 16px;
    margin-bottom: 16px;
    border-radius: 9px;
    background: #e7f7ec;
    color: #155b32;
}
.notice.error { background: #ffebeb; color: #92222b; }
.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.stat, .panel {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 19px;
    box-shadow: 0 3px 14px #3a183008;
}
.stat small { color: var(--muted); }
.stat h2 { margin: 10px 0 0; font-size: 27px; }
.panel { margin-bottom: 24px; }
.panel h2 { margin-top: 0; font-size: 21px; }
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; min-width: 720px; }
th, td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    vertical-align: top;
}
th { background: #faf5f8; }
.badge {
    display: inline-block;
    border-radius: 20px;
    padding: 5px 9px;
    font-size: 12px;
    background: #f3e8ef;
}
.badge.menunggu { color: var(--yellow); background: #fff4cf; }
.badge.diverifikasi, .badge.disetujui { color: var(--green); background: #e2f6e9; }
.badge.ditolak { color: var(--red); background: #ffebeb; }
form { margin: 6px 0; }
input, textarea {
    width: 100%;
    padding: 9px;
    border: 1px solid var(--border);
    border-radius: 7px;
    font: inherit;
}
textarea { min-height: 55px; resize: vertical; }
button {
    cursor: pointer;
    border: 0;
    padding: 9px 12px;
    border-radius: 7px;
    font-weight: bold;
    margin: 3px 2px 3px 0;
}
.btn-ok { background: #dff4e6; color: #176638; }
.btn-no { background: #ffe4e4; color: #a11e2b; }
.proof {
    display: block;
    max-width: 160px;
    max-height: 140px;
    object-fit: contain;
    margin-top: 8px;
    border: 1px solid var(--border);
    border-radius: 6px;
}
.empty { color: var(--muted); padding: 15px 0; }
footer { text-align: center; color: var(--muted); padding: 20px; }
@media (max-width: 600px) {
    header h1 { font-size: 21px; }
    .stat h2 { font-size: 23px; }
}
</style>
</head>
<body>
<header>
    <div>
        <h1>KebayaRent — Dashboard Petugas</h1>
        <p>Halo, <?= e($_SESSION['nama'] ?? $_SESSION['username'] ?? 'Petugas') ?>.</p>
    </div>
    <a class="logout" href="../logout.php">Keluar</a>
</header>

<main>
    <?php if ($pesan !== ''): ?>
        <div class="notice"><?= e($pesan) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="notice error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="stats">
        <div class="stat">
            <small>Total pembayaran</small>
            <h2><?= $totalPembayaran ?></h2>
        </div>
        <div class="stat">
            <small>Pembayaran menunggu</small>
            <h2><?= $totalMenunggu ?></h2>
        </div>
        <div class="stat">
            <small>Total pengajuan fitting</small>
            <h2><?= $totalFitting ?></h2>
        </div>
    </section>

    <section class="panel">
        <h2>Verifikasi Pembayaran</h2>
        <?php if (!$dataPembayaran): ?>
            <p class="empty">Belum ada data pembayaran, atau tabel pembayaran belum tersedia.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Penyewaan</th>
                    <th>Metode</th>
                    <th>Jumlah</th>
                    <th>Bukti</th>
                    <th>Status</th>
                    <th>Tindakan petugas</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($dataPembayaran as $p): ?>
                <?php
                $status = (string)($p['status'] ?? 'menunggu');
                $bukti = lokasiBukti($p['bukti_pembayaran'] ?? '');
                ?>
                <tr>
                    <td>#<?= (int)$p['id_pembayaran'] ?></td>
                    <td>#<?= e($p['id_penyewaan'] ?? $p['id_penyewaan'] ?? '-') ?></td>
                    <td><?= e($p['metode'] ?? '-') ?></td>
                    <td><?= e(formatRupiah($p['jumlah_bayar'] ?? 0)) ?></td>
                    <td>
                        <?php if ($bukti !== ''): ?>
                            <a href="../<?= e($bukti) ?>" target="_blank" rel="noopener">Lihat bukti</a>
                            <a href="../<?= e($bukti) ?>" target="_blank" rel="noopener">
                                <img class="proof" src="../<?= e($bukti) ?>" alt="Bukti pembayaran">
                            </a>
                        <?php elseif (!empty($p['bukti_pembayaran'])): ?>
                            <span>File bukti tidak ditemukan</span>
                            <small><?= e(basename((string)$p['bukti_pembayaran'])) ?></small>
                        <?php else: ?>
                            <span>Belum ada bukti</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= e($status) ?>"><?= e(tampilStatus($status)) ?></span></td>
                    <td>
                        <?php if ($status === 'menunggu'): ?>
                            <form method="post">
                                <input type="hidden" name="id_pembayaran" value="<?= (int)$p['id_pembayaran'] ?>">
                                <label>Catatan petugas (opsional)</label>
                                <textarea name="catatan_petugas" placeholder="Catatan untuk penyewa"></textarea>
                                <button class="btn-ok" name="aksi_pembayaran" value="verifikasi">Setujui</button>
                                <button class="btn-no" name="aksi_pembayaran" value="tolak"
                                    onclick="return confirm('Tolak pembayaran ini?')">Tolak</button>
                            </form>
                        <?php else: ?>
                            <small>Sudah diproses</small>
                            <?php if (!empty($p['catatan_petugas'])): ?>
                                <p><?= e($p['catatan_petugas']) ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Persetujuan Pengajuan Fitting</h2>
        <?php if (!tableExists($koneksi, 'janji_fitting')): ?>
            <p class="empty">Tabel janji_fitting belum ditemukan di database.</p>
        <?php elseif (!$dataFitting): ?>
            <p class="empty">Belum ada pengajuan fitting.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Data pengajuan</th>
                    <th>Status</th>
                    <th>Persetujuan</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $idKolomFitting = firstColumn($kolomFitting, [
                'id_janji_fitting', 'id_fitting', 'id'
            ]);
            $statusKolomFitting = firstColumn($kolomFitting, [
                'status', 'status_fitting', 'status_pengajuan'
            ]);
            ?>
            <?php foreach ($dataFitting as $f): ?>
                <?php
                $idFitting = $idKolomFitting ? ($f[$idKolomFitting] ?? '') : '';
                $statusFitting = $statusKolomFitting
                    ? (string)($f[$statusKolomFitting] ?? 'menunggu')
                    : 'Kolom status belum dikenali';
                ?>
                <tr>
                    <td>
                        <?php foreach ($f as $kolom => $nilai): ?>
                            <div><strong><?= e(ucwords(str_replace('_', ' ', $kolom))) ?>:</strong>
                                <?= e($nilai) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <span class="badge <?= e($statusFitting) ?>">
                            <?= e(tampilStatus($statusFitting)) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($idFitting !== '' && $statusKolomFitting &&
                            in_array($statusFitting, ['menunggu', 'diajukan', 'pending'], true)): ?>
                            <form method="post">
                                <input type="hidden" name="id_fitting" value="<?= (int)$idFitting ?>">
                                <button class="btn-ok" name="aksi_fitting" value="setujui">Setujui fitting</button>
                                <button class="btn-no" name="aksi_fitting" value="tolak"
                                    onclick="return confirm('Tolak pengajuan fitting ini?')">Tolak</button>
                            </form>
                        <?php elseif (!$statusKolomFitting): ?>
                            <small>Kolom status belum dikenali.</small>
                        <?php else: ?>
                            <small>Sudah diproses atau status bukan menunggu.</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
</main>

<footer>KebayaRent &copy; <?= date('Y') ?></footer>
</body>
</html>
