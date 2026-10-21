```php
<?php
session_start();
require_once 'koneksi.php';

$error = '';
$pesan = '';
$daftar = false;

$nama = trim($_POST['nama'] ?? ($_SESSION['nama_tamu'] ?? ''));
$no_hp = trim($_POST['no_hp'] ?? ($_SESSION['hp_tamu'] ?? ''));

function rupiah($angka) {
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_kebaya = (int)($_POST['id_kebaya'] ?? 0);
    $jumlah = (int)($_POST['jumlah'] ?? 0);
    $tanggal_mulai = $_POST['tanggal_mulai'] ?? '';
    $tanggal_selesai = $_POST['tanggal_selesai'] ?? '';
    $catatan = trim($_POST['catatan'] ?? '');

    if ($nama === '' || $no_hp === '') {
        $error = 'Nama dan nomor HP wajib diisi.';
    } elseif (
        $id_kebaya <= 0 || $jumlah <= 0 ||
        $tanggal_mulai === '' || $tanggal_selesai === ''
    ) {
        $error = 'Lengkapi semua data penyewaan.';
    } elseif (
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_mulai) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_selesai) ||
        $tanggal_mulai < date('Y-m-d') ||
        $tanggal_selesai < $tanggal_mulai
    ) {
        $error = 'Tanggal penyewaan tidak valid.';
    } else {
        mysqli_begin_transaction($koneksi);

        try {
            $id_user = (int)($_SESSION['id_user_tamu'] ?? 0);

            if ($id_user > 0) {
                $stmt = mysqli_prepare(
                    $koneksi,
                    "SELECT id_user FROM users
                     WHERE id_user = ? AND role = 'penyewa'"
                );
                mysqli_stmt_bind_param($stmt, 'i', $id_user);
                mysqli_stmt_execute($stmt);
                $user_tamu = mysqli_fetch_assoc(
                    mysqli_stmt_get_result($stmt)
                );
                mysqli_stmt_close($stmt);

                if (!$user_tamu) {
                    $id_user = 0;
                }
            }

            if ($id_user === 0) {
                $username_tamu = 'tamu_' . bin2hex(random_bytes(8));
                $password_tamu = password_hash(
                    bin2hex(random_bytes(24)),
                    PASSWORD_DEFAULT
                );
                $role_tamu = 'penyewa';
                $status_tamu = 'nonaktif';
                $alamat_tamu = '';

                $stmt = mysqli_prepare(
                    $koneksi,
                    "INSERT INTO users
                    (nama, username, password, role, no_hp, alamat, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?)"
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    'sssssss',
                    $nama,
                    $username_tamu,
                    $password_tamu,
                    $role_tamu,
                    $no_hp,
                    $alamat_tamu,
                    $status_tamu
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception('Gagal menyimpan data penyewa.');
                }

                $id_user = mysqli_insert_id($koneksi);
                mysqli_stmt_close($stmt);
            } else {
                $stmt = mysqli_prepare(
                    $koneksi,
                    "UPDATE users SET nama = ?, no_hp = ?
                     WHERE id_user = ? AND role = 'penyewa'"
                );

                mysqli_stmt_bind_param(
                    $stmt,
                    'ssi',
                    $nama,
                    $no_hp,
                    $id_user
                );

                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception('Gagal memperbarui data penyewa.');
                }

                mysqli_stmt_close($stmt);
            }

            $stmt = mysqli_prepare(
                $koneksi,
                "SELECT nama_kebaya, harga_sewa, stok
                 FROM kebaya
                 WHERE id_kebaya = ? AND status = 'tersedia'
                 FOR UPDATE"
            );
            mysqli_stmt_bind_param($stmt, 'i', $id_kebaya);
            mysqli_stmt_execute($stmt);
            $kebaya = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if (!$kebaya) {
                throw new Exception('Kebaya tidak tersedia.');
            }

            if ($jumlah > (int)$kebaya['stok']) {
                throw new Exception('Jumlah melebihi stok kebaya.');
            }

            $tgl1 = DateTime::createFromFormat('!Y-m-d', $tanggal_mulai);
            $tgl2 = DateTime::createFromFormat('!Y-m-d', $tanggal_selesai);

            if (
                !$tgl1 || !$tgl2 ||
                $tgl1->format('Y-m-d') !== $tanggal_mulai ||
                $tgl2->format('Y-m-d') !== $tanggal_selesai
            ) {
                throw new Exception('Tanggal penyewaan tidak valid.');
            }

            $hari = (int)$tgl1->diff($tgl2)->days + 1;
            $harga = (float)$kebaya['harga_sewa'];
            $total = $harga * $jumlah * $hari;
            $kode = 'KR' . date('YmdHis') . random_int(100, 999);

            $stmt = mysqli_prepare(
                $koneksi,
                "INSERT INTO penyewaan
                (kode_penyewaan, id_user, tanggal_pesan,
                 tanggal_mulai, tanggal_selesai, total, status, catatan)
                VALUES (?, ?, CURDATE(), ?, ?, ?, 'menunggu', ?)"
            );

            mysqli_stmt_bind_param(
                $stmt,
                'sissds',
                $kode,
                $id_user,
                $tanggal_mulai,
                $tanggal_selesai,
                $total,
                $catatan
            );

            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception('Gagal menyimpan pengajuan.');
            }

            $id_penyewaan = mysqli_insert_id($koneksi);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare(
                $koneksi,
                "INSERT INTO detail_penyewaan
                (id_penyewaan, id_kebaya, jumlah, harga)
                VALUES (?, ?, ?, ?)"
            );

            mysqli_stmt_bind_param(
                $stmt,
                'iiid',
                $id_penyewaan,
                $id_kebaya,
                $jumlah,
                $harga
            );

            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception('Gagal menyimpan detail penyewaan.');
            }
            mysqli_stmt_close($stmt);

            $aktivitas = 'Pengajuan tamu: ' . $kode;
            $stmt = mysqli_prepare(
                $koneksi,
                "INSERT INTO log_aktivitas (id_user, aktivitas)
                 VALUES (?, ?)"
            );
            mysqli_stmt_bind_param($stmt, 'is', $id_user, $aktivitas);

            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception('Gagal mencatat aktivitas.');
            }
            mysqli_stmt_close($stmt);

            mysqli_commit($koneksi);

            $_SESSION['id_user_tamu'] = $id_user;
            $_SESSION['nama_tamu'] = $nama;
            $_SESSION['hp_tamu'] = $no_hp;

            header('Location: ajukan.php?berhasil=' . urlencode($kode));
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($koneksi);
            $error = $e->getMessage();
        }
    }
}

if (isset($_GET['berhasil'])) {
    $kode_hasil = preg_replace(
        '/[^A-Za-z0-9]/',
        '',
        $_GET['berhasil']
    );

    if ($kode_hasil !== '') {
        $pesan = 'Pengajuan berhasil dikirim. Kode penyewaan: ' .
                 $kode_hasil .
                 '. Status menunggu persetujuan petugas.';
    }
}

$daftar = mysqli_query(
    $koneksi,
    "SELECT k.id_kebaya, k.nama_kebaya, k.ukuran, k.warna,
            k.harga_sewa, k.stok, kat.nama_kategori
     FROM kebaya k
     LEFT JOIN kategori_kebaya kat
       ON k.id_kategori = kat.id_kategori
     WHERE k.status = 'tersedia' AND k.stok > 0
     ORDER BY k.nama_kebaya ASC"
);

if ($daftar === false) {
    $error = 'Data kebaya gagal dimuat. Periksa koneksi dan struktur database.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ajukan Penyewaan - KebayaRent</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

<style>
:root {
    --pink: #d94683;
    --pink-dark: #a61e59;
    --pink-soft: #fff0f6;
    --pink-border: #f4d4e3;
    --ink: #382638;
    --muted: #897486;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    color: var(--ink);
    background: #fff8fb;
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 14px;
}

.topbar {
    background: linear-gradient(120deg, #9d174d, #c0266d 55%, #e65b99);
    box-shadow: 0 5px 20px #9d174d25;
}

.brand {
    color: #fff;
    text-decoration: none;
    font-size: 1.35rem;
    font-weight: 800;
    letter-spacing: -.5px;
}

.brand span { color: #ffd8e9; }

.hero {
    padding: 38px 0 75px;
    background:
        radial-gradient(circle at 90% 15%, #f9c6df88, transparent 35%),
        linear-gradient(135deg, #fff0f6, #fff9fc);
    border-bottom: 1px solid #f9e6ef;
}

.eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 13px;
    color: var(--pink-dark);
    background: white;
    border: 1px solid var(--pink-border);
    border-radius: 30px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .5px;
    text-transform: uppercase;
}

.hero h1 {
    margin: 16px 0 10px;
    color: #4a2140;
    font-size: clamp(26px, 4vw, 36px);
    font-weight: 800;
    letter-spacing: -1px;
}

.hero p {
    max-width: 650px;
    color: var(--muted);
    font-size: 15px;
    line-height: 1.8;
}

.main-wrap {
    position: relative;
    margin-top: -48px;
    padding-bottom: 48px;
}

.form-card {
    overflow: hidden;
    background: white;
    border: 1px solid var(--pink-border);
    border-radius: 20px;
    box-shadow: 0 12px 35px #91235914;
}

.card-head {
    padding: 23px 28px;
    background: linear-gradient(110deg, #fff0f6, #fffafd);
    border-bottom: 1px solid #f7e0eb;
}

.card-head h2 {
    margin: 0;
    color: #542541;
    font-size: 17px;
    font-weight: 800;
}

.card-head h2 i { color: var(--pink); }

.card-head p {
    margin: 7px 0 0;
    color: var(--muted);
    font-size: 13px;
}

.form-content { padding: 28px; }

.section-title {
    display: flex;
    align-items: center;
    gap: 11px;
    margin: 0 0 20px;
    color: #542541;
    font-size: 15px;
    font-weight: 800;
}

.section-icon {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    color: var(--pink);
    background: var(--pink-soft);
    border: 1px solid #f8dce9;
    border-radius: 12px;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    color: #63485c;
    font-size: 13px;
    font-weight: 700;
}

.form-control, .form-select {
    width: 100%;
    min-height: 46px;
    padding: 11px 13px;
    color: var(--ink);
    background-color: #fff;
    border: 1px solid #e9dce5;
    border-radius: 10px;
    font-size: 13px;
}

.form-control:focus, .form-select:focus {
    border-color: #e879a8;
    box-shadow: 0 0 0 3px #f472b624;
}

textarea.form-control {
    min-height: 105px;
    resize: vertical;
}

hr {
    border-color: #f4e2eb !important;
    opacity: 1;
}

.tip {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.7;
}

.price-box {
    padding: 20px;
    color: #79506a;
    background: linear-gradient(120deg, #fff0f6, #fffafd);
    border: 1px solid #f1c9dc;
    border-radius: 15px;
}

.price-total {
    margin-top: 7px;
    color: var(--pink-dark);
    font-size: 24px;
    font-weight: 850;
    letter-spacing: -.5px;
}

.btn-pink {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 12px 20px;
    color: #fff;
    background: linear-gradient(110deg, #be185d, #e6498b);
    border: 0;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 750;
    transition: .2s;
}

.btn-pink:hover {
    color: #fff;
    background: linear-gradient(110deg, #9d174d, #c0266d);
    box-shadow: 0 6px 15px #be185d30;
    transform: translateY(-1px);
}

.btn-light-pink {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 11px 18px;
    color: var(--pink-dark);
    background: #fff;
    border: 1px solid #edc6d9;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
}

.btn-light-pink:hover {
    color: var(--pink-dark);
    background: var(--pink-soft);
}

.alert {
    padding: 17px 20px;
    border-radius: 13px;
    font-size: 13px;
    line-height: 1.8;
}

.footer {
    padding: 22px 15px 28px;
    color: #a18b9b;
    background: white;
    border-top: 1px solid #f4e4ec;
    font-size: 12px;
    text-align: center;
}

@media (max-width: 767px) {
    .hero { padding: 29px 0 65px; }
    .hero h1 { font-size: 27px; }
    .hero p { font-size: 13px; }
    .main-wrap { margin-top: -38px; }
    .card-head, .form-content { padding: 20px; }
    .price-total { font-size: 21px; }
}

@media (max-width: 575px) {
    .brand { font-size: 1.12rem; }
    .topbar .btn { padding: 7px 9px; font-size: 11px; }
    .hero h1 { font-size: 24px; }
    .actions { display: flex; flex-direction: column; }
    .actions .btn { width: 100%; min-height: 45px; }
}
</style>
</head>

<body>
<nav class="topbar py-3">
    <div class="container d-flex align-items-center justify-content-between gap-3">
        <a class="brand" href="index.php">
            <i class="bi bi-flower1 me-2"></i>Kebaya<span>Rent</span>
        </a>
        <a href="login.php" class="btn btn-sm btn-outline-light rounded-pill px-3">
            <i class="bi bi-shield-lock me-1"></i>Login Admin/Petugas
        </a>
    </div>
</nav>

<section class="hero">
    <div class="container">
        <span class="eyebrow">
            <i class="bi bi-heart-fill"></i>Sewa kebaya jadi mudah
        </span>
        <h1>Ajukan Penyewaan Kebaya</h1>
        <p class="mb-0">
            Isi identitas dan detail penyewaan. Kamu tidak perlu membuat akun
            atau login untuk mengajukan penyewaan.
        </p>
    </div>
</section>

<main class="container main-wrap">

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-success shadow-sm mb-4">
            <div class="fw-bold">
                <i class="bi bi-check-circle-fill me-2"></i>Pengajuan berhasil!
            </div>
            <div class="mt-1"><?= htmlspecialchars($pesan) ?></div>
            <div class="small mt-2">
                Simpan kode penyewaan untuk referensi saat menghubungi petugas.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger shadow-sm mb-4">
            <i class="bi bi-exclamation-circle-fill me-2"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <section class="form-card">
        <div class="card-head">
            <h2><i class="bi bi-clipboard2-heart me-2"></i>Formulir Penyewaan</h2>
            <p>Lengkapi data di bawah dengan benar sebelum mengirim pengajuan.</p>
        </div>

        <div class="form-content">
        <?php if ($daftar !== false && mysqli_num_rows($daftar) > 0): ?>

            <form method="POST" id="formSewa">
                <h3 class="section-title">
                    <span class="section-icon"><i class="bi bi-person"></i></span>
                    Identitas Penyewa
                </h3>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="nama">Nama lengkap</label>
                        <input type="text" name="nama" id="nama"
                               class="form-control" maxlength="100"
                               autocomplete="name"
                               value="<?= htmlspecialchars($nama) ?>" required>
                    </div>
                    <div class="col-md-6 mb-4">
                        <label class="form-label" for="no_hp">Nomor HP aktif</label>
                        <input type="tel" name="no_hp" id="no_hp"
                               class="form-control" maxlength="20"
                               autocomplete="tel"
                               value="<?= htmlspecialchars($no_hp) ?>" required>
                    </div>
                </div>

                <hr class="my-4">

                <h3 class="section-title">
                    <span class="section-icon"><i class="bi bi-bag-heart"></i></span>
                    Detail Penyewaan
                </h3>

                <div class="mb-3">
                    <label class="form-label" for="kebaya">Pilih kebaya</label>
                    <select name="id_kebaya" id="kebaya" class="form-select" required>
                        <option value="">-- Pilih kebaya yang tersedia --</option>
                        <?php while ($row = mysqli_fetch_assoc($daftar)): ?>
                            <option
                                value="<?= (int)$row['id_kebaya'] ?>"
                                data-harga="<?= (float)$row['harga_sewa'] ?>"
                                data-stok="<?= (int)$row['stok'] ?>">
                                <?= htmlspecialchars($row['nama_kebaya']) ?>
                                — <?= htmlspecialchars($row['nama_kategori'] ?? '-') ?>
                                | Ukuran <?= htmlspecialchars($row['ukuran'] ?? '-') ?>
                                | <?= htmlspecialchars($row['warna'] ?? '-') ?>
                                | <?= rupiah($row['harga_sewa']) ?>/hari
                                | Stok <?= (int)$row['stok'] ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <div class="tip mt-2">
                        <i class="bi bi-info-circle me-1"></i>
                        Harga yang ditampilkan adalah harga sewa per hari.
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="jumlah">Jumlah kebaya</label>
                        <input type="number" name="jumlah" id="jumlah"
                               class="form-control" min="1" value="1" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="mulai">Tanggal mulai</label>
                        <input type="date" name="tanggal_mulai" id="mulai"
                               class="form-control" min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="selesai">Tanggal selesai</label>
                        <input type="date" name="tanggal_selesai" id="selesai"
                               class="form-control" min="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label" for="catatan">
                        Catatan <span class="fw-normal text-muted">(opsional)</span>
                    </label>
                    <textarea name="catatan" id="catatan" class="form-control"
                              rows="3" maxlength="1000"
                              placeholder="Informasi tambahan untuk petugas..."></textarea>
                </div>

                <div class="price-box mb-4">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-calculator fs-5"></i>
                        <span class="fw-bold">Ringkasan biaya</span>
                    </div>
                    <div class="price-total" id="total">Perkiraan total: Rp 0</div>
                    <div class="tip mt-1">
                        Harga per hari × jumlah kebaya × lama sewa, termasuk
                        tanggal mulai dan tanggal selesai.
                    </div>
                </div>

                <div class="actions d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-pink">
                        <i class="bi bi-send-fill me-2"></i>Kirim Pengajuan
                    </button>
                    <a href="index.php" class="btn btn-light-pink">
                        <i class="bi bi-arrow-left me-1"></i>Kembali
                    </a>
                </div>
            </form>

        <?php elseif ($daftar !== false): ?>

            <div class="text-center py-5">
                <div class="section-icon mx-auto mb-3"
                     style="width:60px;height:60px;font-size:1.4rem">
                    <i class="bi bi-calendar-x"></i>
                </div>
                <h3 class="h5 fw-bold">Belum ada kebaya tersedia</h3>
                <p class="text-muted mb-0">
                    Saat ini belum ada kebaya yang dapat diajukan.
                    Silakan coba lagi nanti.
                </p>
            </div>

        <?php else: ?>

            <div class="text-center py-4">
                <h3 class="h5 fw-bold">Daftar kebaya belum dapat dimuat</h3>
                <p class="text-muted mb-0">
                    Periksa koneksi database dan struktur tabel.
                </p>
            </div>

        <?php endif; ?>
        </div>
    </section>
</main>

<footer class="footer">
    © <?= date('Y') ?> KebayaRent · Penyewaan kebaya dengan mudah dan nyaman.
</footer>

<script>
const kebaya = document.getElementById('kebaya');
const jumlah = document.getElementById('jumlah');
const mulai = document.getElementById('mulai');
const selesai = document.getElementById('selesai');
const total = document.getElementById('total');

function hitungTotal() {
    if (!kebaya || !jumlah || !mulai || !selesai || !total) return;

    const opsi = kebaya.options[kebaya.selectedIndex];
    const harga = Number(opsi?.dataset.harga || 0);
    const stok = Number(opsi?.dataset.stok || 0);

    jumlah.max = stok || 1;

    if (stok > 0 && Number(jumlah.value) > stok) {
        jumlah.value = stok;
    }

    const banyak = Math.max(0, Number(jumlah.value || 0));
    let hari = 0;

    if (mulai.value && selesai.value) {
        const a = new Date(mulai.value + 'T00:00:00');
        const b = new Date(selesai.value + 'T00:00:00');
        hari = Math.floor((b - a) / 86400000) + 1;
    }

    const nilai = harga * banyak * Math.max(0, hari);
    total.textContent = 'Perkiraan total: Rp ' +
        nilai.toLocaleString('id-ID');

    selesai.min = mulai.value || '<?= date('Y-m-d') ?>';

    if (selesai.value && mulai.value && selesai.value < mulai.value) {
        selesai.value = mulai.value;
    }
}

if (kebaya) kebaya.addEventListener('change', hitungTotal);
if (jumlah) jumlah.addEventListener('input', hitungTotal);
if (mulai) mulai.addEventListener('change', hitungTotal);
if (selesai) selesai.addEventListener('change', hitungTotal);

hitungTotal();
</script>
</body>
</html>
