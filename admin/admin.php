<?php
session_start();
require_once 'koneksi.php';

$pesan = '';
$jenisPesan = 'info';
$akunDibuat = false;

$nama = 'Administrator';
$username = 'admin';
$password = password_hash('admin123', PASSWORD_DEFAULT);
$role = 'admin';
$status = 'aktif';

// Cek apakah username admin sudah digunakan.
$cek = mysqli_prepare(
    $koneksi,
    "SELECT id_user FROM users WHERE username = ? LIMIT 1"
);

if (!$cek) {
    $pesan = 'Terjadi kesalahan saat memeriksa akun.';
    $jenisPesan = 'danger';
} else {
    mysqli_stmt_bind_param($cek, 's', $username);
    mysqli_stmt_execute($cek);
    $hasil = mysqli_stmt_get_result($cek);

    if (mysqli_num_rows($hasil) > 0) {
        $pesan = 'Username admin sudah terdaftar. Gunakan akun yang sudah ada untuk masuk.';
        $jenisPesan = 'warning';
    } else {
        $stmt = mysqli_prepare(
            $koneksi,
            "INSERT INTO users (nama, username, password, role, status)
             VALUES (?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            $pesan = 'Terjadi kesalahan saat menyiapkan pembuatan akun.';
            $jenisPesan = 'danger';
        } else {
            mysqli_stmt_bind_param(
                $stmt,
                'sssss',
                $nama,
                $username,
                $password,
                $role,
                $status
            );

            if (mysqli_stmt_execute($stmt)) {
                $pesan = 'Akun admin berhasil dibuat.';
                $jenisPesan = 'success';
                $akunDibuat = true;
            } else {
                $pesan = 'Akun gagal dibuat. Silakan periksa koneksi dan struktur database.';
                $jenisPesan = 'danger';
            }

            mysqli_stmt_close($stmt);
        }
    }

    mysqli_stmt_close($cek);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buat Akun Admin | KebayaRent</title>

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
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            display: flex;
            flex-direction: column;
            background:
                radial-gradient(circle at 8% 8%, rgba(236, 153, 185, .18), transparent 28%),
                radial-gradient(circle at 92% 90%, rgba(167, 25, 85, .10), transparent 28%),
                #fff8fa;
            color: var(--ink);
            font-family: Inter, "Segoe UI", Arial, sans-serif;
        }

        .navbar {
            background: linear-gradient(110deg, var(--pink), var(--pink-dark));
            box-shadow: 0 4px 18px rgba(120, 18, 61, .12);
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 11px;
            color: #fff;
            text-decoration: none;
            font-size: 21px;
            font-weight: 800;
            letter-spacing: -.5px;
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            display: grid;
            place-items: center;
            border-radius: 13px;
            background: rgba(255,255,255,.16);
            font-size: 22px;
        }

        .page-wrap {
            flex: 1;
            display: grid;
            place-items: center;
            padding: 42px 16px;
        }

        .account-card {
            width: 100%;
            max-width: 520px;
            overflow: hidden;
            border: 1px solid var(--pink-line);
            border-radius: 23px;
            background: #fff;
            box-shadow: 0 18px 55px rgba(95, 24, 52, .09);
        }

        .card-top {
            padding: 30px 32px 25px;
            text-align: center;
            color: #fff;
            background: linear-gradient(120deg, #a71955, #ce5a88);
        }

        .hero-icon {
            width: 72px;
            height: 72px;
            display: grid;
            place-items: center;
            margin: 0 auto 17px;
            border: 1px solid rgba(255,255,255,.25);
            border-radius: 22px;
            background: rgba(255,255,255,.15);
            font-size: 34px;
        }

        .card-top h1 {
            margin: 0;
            font-size: 25px;
            font-weight: 800;
            letter-spacing: -.6px;
        }

        .card-top p {
            margin: 9px 0 0;
            color: #ffe7f0;
            font-size: 13px;
            line-height: 1.6;
        }

        .card-body { padding: 28px 32px 32px; }

        .status-alert {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            padding: 14px 15px;
            border-radius: 13px;
            font-size: 13px;
            line-height: 1.6;
        }

        .status-alert i { font-size: 18px; }

        .info-row {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 14px;
            border: 1px solid #f3e5eb;
            border-radius: 14px;
            background: #fffafd;
        }

        .info-icon {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: var(--pink-soft);
            color: var(--pink);
            font-size: 19px;
        }

        .info-label {
            margin-bottom: 3px;
            color: var(--muted);
            font-size: 11px;
        }

        .info-value {
            color: var(--ink);
            font-size: 14px;
            font-weight: 750;
            overflow-wrap: anywhere;
        }

        .security-note {
            margin-top: 18px;
            padding: 12px 13px;
            border-radius: 12px;
            background: #f8f5f7;
            color: #756975;
            font-size: 11px;
            line-height: 1.65;
        }

        .btn-pink {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px 16px;
            border: 0;
            border-radius: 12px;
            background: var(--pink);
            color: #fff;
            font-size: 13px;
            font-weight: 750;
            text-decoration: none;
            transition: background .2s, transform .2s;
        }

        .btn-pink:hover {
            background: var(--pink-dark);
            color: #fff;
            transform: translateY(-1px);
        }

        .btn-outline-pink {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            margin-top: 10px;
            padding: 11px 16px;
            border: 1px solid #e8c4d3;
            border-radius: 12px;
            background: #fff;
            color: var(--pink);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .btn-outline-pink:hover {
            background: var(--pink-soft);
            color: var(--pink-dark);
        }

        .footer {
            padding: 18px 12px 22px;
            color: #a1949e;
            text-align: center;
            font-size: 11px;
        }

        @media (max-width: 575px) {
            .page-wrap { padding: 25px 13px; }
            .card-top { padding: 26px 20px 23px; }
            .card-top h1 { font-size: 22px; }
            .card-body { padding: 22px 20px 25px; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark">
        <div class="container">
            <a class="brand" href="login.php">
                <span class="brand-icon"><i class="bi bi-flower1"></i></span>
                <span>KebayaRent</span>
            </a>
            <a href="login.php" class="btn btn-sm btn-light rounded-pill px-3">
                <i class="bi bi-box-arrow-in-right me-1"></i>Login
            </a>
        </div>
    </nav>

    <main class="page-wrap">
        <section class="account-card">
            <div class="card-top">
                <div class="hero-icon">
                    <i class="bi bi-shield-lock"></i>
                </div>
                <h1>Pembuatan Akun Admin</h1>
                <p>Pengaturan akun administrator untuk sistem KebayaRent.</p>
            </div>

            <div class="card-body">
                <div class="status-alert alert-<?= htmlspecialchars($jenisPesan, ENT_QUOTES, 'UTF-8') ?> mb-4" role="status">
                    <?php if ($jenisPesan === 'success'): ?>
                        <i class="bi bi-check-circle-fill"></i>
                    <?php elseif ($jenisPesan === 'warning'): ?>
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php else: ?>
                        <i class="bi bi-info-circle-fill"></i>
                    <?php endif; ?>
                    <div><?= htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8') ?></div>
                </div>

                <?php if ($akunDibuat): ?>
                    <div class="info-row mb-3">
                        <div class="info-icon"><i class="bi bi-person-badge"></i></div>
                        <div>
                            <div class="info-label">Nama akun</div>
                            <div class="info-value">Administrator</div>
                        </div>
                    </div>

                    <div class="info-row mb-3">
                        <div class="info-icon"><i class="bi bi-person"></i></div>
                        <div>
                            <div class="info-label">Username</div>
                            <div class="info-value">admin</div>
                        </div>
                    </div>

                    <div class="info-row mb-3">
                        <div class="info-icon"><i class="bi bi-key"></i></div>
                        <div>
                            <div class="info-label">Password awal</div>
                            <div class="info-value">admin123</div>
                        </div>
                    </div>

                    <div class="security-note">
                        <i class="bi bi-shield-check me-1"></i>
                        Password disimpan dalam bentuk hash pada database. Setelah berhasil masuk, ganti password awal dengan password yang kuat.
                    </div>
                <?php else: ?>
                    <div class="info-row mb-3">
                        <div class="info-icon"><i class="bi bi-person-badge"></i></div>
                        <div>
                            <div class="info-label">Username yang diperiksa</div>
                            <div class="info-value">admin</div>
                        </div>
                    </div>

                    <div class="security-note">
                        <i class="bi bi-info-circle me-1"></i>
                        Halaman ini tidak mengubah atau mereset password akun yang sudah terdaftar.
                    </div>
                <?php endif; ?>

                <a href="login.php" class="btn-pink mt-4">
                    <i class="bi bi-box-arrow-in-right"></i>
                    Buka Halaman Login
                </a>
                <a href="index.php" class="btn-outline-pink">
                    <i class="bi bi-grid-1x2"></i>
                    Kembali ke Dashboard
                </a>
            </div>
        </section>
    </main>

    <footer class="footer">
        &copy; <?= date('Y') ?> KebayaRent · Sistem Informasi Penyewaan Kebaya
    </footer>
</body>
</html>
