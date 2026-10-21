<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();
require_once __DIR__ . '/koneksi.php';

$error = '';
$koleksiKebaya = [];

// Arahkan pengguna yang sudah login ke dashboard sesuai role.
if (isset($_SESSION['id_user'], $_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: admin/dashboard.php');
            exit;
        case 'petugas':
            header('Location: petugas/dashboard.php');
            exit;
        case 'penyewa':
            header('Location: penyewa/dashboard.php');
            exit;
    }
}

// Proses login admin, petugas, dan penyewa.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        $stmt = mysqli_prepare(
            $koneksi,
            "SELECT id_user, nama, username, password, role, status
             FROM users
             WHERE username = ?
             LIMIT 1"
        );

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);

            if (
                $user &&
                password_verify($password, $user['password']) &&
                $user['status'] === 'aktif' &&
                in_array(
                    $user['role'],
                    ['admin', 'petugas', 'penyewa'],
                    true
                )
            ) {
                session_regenerate_id(true);

                $_SESSION['id_user'] = $user['id_user'];
                $_SESSION['nama'] = $user['nama'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                // Catat aktivitas login jika tabel tersedia.
                $aktivitas = 'Login sebagai ' . $user['role'];

                $log = @mysqli_prepare(
                    $koneksi,
                    "INSERT INTO log_aktivitas
                     (id_user, aktivitas, waktu)
                     VALUES (?, ?, NOW())"
                );

                if ($log) {
                    mysqli_stmt_bind_param(
                        $log,
                        'is',
                        $user['id_user'],
                        $aktivitas
                    );
                    @mysqli_stmt_execute($log);
                    mysqli_stmt_close($log);
                }

                switch ($user['role']) {
                    case 'admin':
                        header('Location: admin/dashboard.php');
                        exit;
                    case 'petugas':
                        header('Location: petugas/dashboard.php');
                        exit;
                    case 'penyewa':
                        header('Location: penyewa/dashboard.php');
                        exit;
                }
            } else {
                $error = 'Username atau password salah, atau akun tidak aktif.';
            }
        } else {
            $error = 'Login gagal diproses. Periksa struktur tabel users.';
        }
    }
}

// Ambil koleksi kebaya. Jika query gagal, halaman tetap ditampilkan.
$sqlKoleksi = "
    SELECT id_kebaya, nama_kebaya, warna, ukuran, harga_sewa, foto
    FROM kebaya
    WHERE status = 'tersedia'
      AND foto IS NOT NULL
      AND foto != ''
    ORDER BY id_kebaya DESC
    LIMIT 6
";

$resultKoleksi = @mysqli_query($koneksi, $sqlKoleksi);

if ($resultKoleksi) {
    while ($row = mysqli_fetch_assoc($resultKoleksi)) {
        $koleksiKebaya[] = $row;
    }
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>KebayaRent - Sewa Kebaya Elegan</title>

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
            --pink: #d94f87;
            --pink-dark: #b9346b;
            --pink-light: #fff0f6;
            --pink-soft: #ffe0ec;
            --text: #442b38;
            --muted: #826c78;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 85px;
        }

        body {
            margin: 0;
            background: #fffafd;
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }

        .navbar {
            background: rgba(255, 255, 255, .96);
            box-shadow: 0 3px 20px rgba(80, 30, 55, .07);
        }

        .navbar-brand {
            color: var(--pink-dark) !important;
            font-size: 1.45rem;
            font-weight: 800;
        }

        .nav-link {
            color: var(--text);
            font-weight: 600;
        }

        .nav-link:hover {
            color: var(--pink);
        }

        .btn-pink {
            display: inline-block;
            padding: 12px 23px;
            border: 0;
            border-radius: 12px;
            background: var(--pink);
            color: #fff;
            font-weight: 700;
            text-decoration: none;
            transition: .2s;
        }

        .btn-pink:hover {
            background: var(--pink-dark);
            color: #fff;
            transform: translateY(-1px);
        }

        .btn-outline-pink {
            padding: 10px 20px;
            border: 1px solid var(--pink);
            border-radius: 12px;
            color: var(--pink-dark);
            font-weight: 700;
            text-decoration: none;
        }

        .btn-outline-pink:hover {
            background: var(--pink-light);
            color: var(--pink-dark);
        }

        .hero {
            position: relative;
            overflow: hidden;
            padding: 90px 0;
            background:
                radial-gradient(circle at 90% 15%, #ffd6e7 0, transparent 30%),
                linear-gradient(135deg, #fff1f7, #fffafd 65%);
        }

        .hero-label {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 30px;
            background: #fff;
            color: var(--pink-dark);
            font-size: .9rem;
            font-weight: 700;
        }

        .hero h1 {
            margin-top: 22px;
            font-size: clamp(2.4rem, 5vw, 4.2rem);
            font-weight: 800;
            line-height: 1.15;
        }

        .hero h1 span {
            color: var(--pink-dark);
        }

        .hero p {
            max-width: 560px;
            color: var(--muted);
            font-size: 1.08rem;
            line-height: 1.8;
        }

        .hero-art {
            display: flex;
            min-height: 340px;
            align-items: center;
            justify-content: center;
            border-radius: 40% 40% 24px 24px;
            background: linear-gradient(145deg, #ffd9e8, #fff5f9);
            font-size: 8rem;
        }

        section {
            padding: 75px 0;
        }

        .section-title {
            margin-bottom: 12px;
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            font-weight: 800;
        }

        .section-subtitle {
            margin-bottom: 35px;
            color: var(--muted);
        }

        .feature-card,
        .kebaya-card {
            height: 100%;
            overflow: hidden;
            border: 0;
            border-radius: 20px;
            background: #fff;
            box-shadow: 0 7px 28px rgba(100, 40, 70, .07);
        }

        .feature-card {
            padding: 26px;
        }

        .feature-icon {
            display: flex;
            width: 55px;
            height: 55px;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
            border-radius: 17px;
            background: var(--pink-light);
            color: var(--pink-dark);
            font-size: 1.6rem;
        }

        .kebaya-photo {
            display: flex;
            height: 230px;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: var(--pink-light);
            color: var(--pink-dark);
            font-size: 4rem;
        }

        .kebaya-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .price {
            color: var(--pink-dark);
            font-size: 1.05rem;
            font-weight: 800;
        }

        .login-section {
            background: linear-gradient(135deg, #fff0f6, #fffafd);
        }

        .login-card {
            width: 100%;
            max-width: 470px;
            margin: auto;
            padding: 35px;
            border: 1px solid #f8e1eb;
            border-radius: 25px;
            background: #fff;
            box-shadow: 0 12px 45px rgba(100, 40, 70, .10);
        }

        .login-symbol {
            display: flex;
            width: 65px;
            height: 65px;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            border-radius: 22px;
            background: var(--pink-light);
            color: var(--pink-dark);
            font-size: 2rem;
        }

        .login-card h3 {
            text-align: center;
            font-weight: 800;
        }

        .login-card .form-label {
            font-weight: 700;
        }

        .login-card .form-control,
        .login-card .input-group-text {
            min-height: 48px;
            border-color: #eadbe3;
        }

        .login-card .form-control:focus {
            border-color: var(--pink);
            box-shadow: 0 0 0 .2rem rgba(217, 79, 135, .13);
        }

        .login-card .input-group-text {
            border-right: 0;
        }

        .login-card .input-group .form-control {
            border-left: 0;
        }

        .login-card .input-group .btn {
            border-color: #eadbe3;
        }

        .about-box {
            padding: 35px;
            border-radius: 25px;
            background: #fff;
            box-shadow: 0 7px 28px rgba(100, 40, 70, .06);
        }

        .contact-section {
            background: #fff;
        }

        footer {
            padding: 25px 0;
            background: #442b38;
            color: #fff;
            text-align: center;
        }

        footer p {
            margin: 0;
            color: #f7dce8;
        }

        @media (max-width: 767px) {
            .hero {
                padding: 55px 0;
            }

            .hero-art {
                min-height: 230px;
                margin-top: 25px;
                font-size: 6rem;
            }

            .login-card {
                padding: 25px 20px;
            }

            section {
                padding: 55px 0;
            }
        }
    </style>
</head>

<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg sticky-top">
    <div class="container">
        <a class="navbar-brand" href="login.php">
            <i class="bi bi-flower1 me-1"></i>KebayaRent
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#navbarKebaya"
            aria-controls="navbarKebaya"
            aria-expanded="false"
            aria-label="Buka menu"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarKebaya">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <li class="nav-item">
                    <a class="nav-link" href="#beranda">Beranda</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#tentang">Tentang</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#koleksi">Koleksi</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#kontak">Kontak</a>
                </li>
                <li class="nav-item">
                    <a class="btn btn-pink ms-lg-2" href="#login">
                        <i class="bi bi-person-circle me-1"></i>Login
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- BERANDA -->
<header class="hero" id="beranda">
    <div class="container">
        <div class="row align-items-center g-4">
            <div class="col-lg-7">
                <span class="hero-label">
                    <i class="bi bi-stars me-1"></i>
                    Tampil anggun di setiap momen
                </span>

                <h1>
                    Keanggunan Tradisi,
                    <span>Untuk Momen Istimewa.</span>
                </h1>

                <p class="mt-3">
                    Temukan kebaya cantik untuk wisuda, pernikahan,
                    acara keluarga, dan berbagai momen spesialmu.
                    Pilih koleksi favoritmu dengan mudah bersama KebayaRent.
                </p>

                <div class="d-flex flex-wrap gap-3 mt-4">
                    <a href="#koleksi" class="btn-pink">
                        <i class="bi bi-heart me-1"></i>Lihat Koleksi
                    </a>
                    <a href="#login" class="btn-outline-pink">
                        Mulai Menyewa
                        <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="hero-art" aria-label="Ilustrasi kebaya">
                    <i class="bi bi-flower2"></i>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- TENTANG -->
<section id="tentang">
    <div class="container">
        <div class="text-center">
            <h2 class="section-title">Mengapa KebayaRent?</h2>
            <p class="section-subtitle">
                Pengalaman menyewa kebaya yang praktis dan menyenangkan.
            </p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-flower3"></i>
                    </div>
                    <h5 class="fw-bold">Koleksi Menawan</h5>
                    <p class="text-muted mb-0">
                        Pilihan kebaya dengan beragam warna dan ukuran
                        untuk menemani acara spesialmu.
                    </p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-calendar-heart"></i>
                    </div>
                    <h5 class="fw-bold">Pemesanan Praktis</h5>
                    <p class="text-muted mb-0">
                        Lihat katalog dan ajukan penyewaan melalui
                        akun penyewa milikmu.
                    </p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-chat-heart"></i>
                    </div>
                    <h5 class="fw-bold">Informasi Jelas</h5>
                    <p class="text-muted mb-0">
                        Periksa harga, ukuran, warna, dan informasi
                        ketersediaan sebelum memesan.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- KOLEKSI -->
<section id="koleksi" style="background:#fff4f8;">
    <div class="container">
        <div class="text-center">
            <h2 class="section-title">Koleksi Kebaya</h2>
            <p class="section-subtitle">
                Temukan pilihan kebaya untuk momen berkesanmu.
            </p>
        </div>

        <div class="row g-4">
            <?php if (!empty($koleksiKebaya)): ?>
                <?php foreach ($koleksiKebaya as $kebaya): ?>
                    <div class="col-sm-6 col-lg-4">
                        <div class="kebaya-card">
                            <div class="kebaya-photo">
                                <?php
                                $foto = trim((string) ($kebaya['foto'] ?? ''));
                                $fotoSrc = '';

                                // Mendukung foto berbentuk Base64 atau nama file/path.
                                if (preg_match(
                                    '/^data:image\/(jpeg|jpg|png|gif|webp);base64,/i',
                                    $foto
                                )) {
                                    $fotoSrc = $foto;
                                } elseif (
                                    $foto !== '' &&
                                    !preg_match('/^\s*javascript:/i', $foto) &&
                                    !preg_match('/^data:/i', $foto)
                                ) {
                                    $fotoSrc = $foto;
                                }
                                ?>

                                <?php if ($fotoSrc !== ''): ?>
                                    <img
                                        src="<?= e($fotoSrc) ?>"
                                        alt="<?= e($kebaya['nama_kebaya']) ?>"
                                        loading="lazy"
                                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                                    >
                                    <div style="display:none;width:100%;height:100%;align-items:center;justify-content:center;">
                                        <i class="bi bi-flower2"></i>
                                    </div>
                                <?php else: ?>
                                    <i class="bi bi-flower2"></i>
                                <?php endif; ?>
                            </div>

                            <div class="p-4">
                                <h5 class="fw-bold mb-2">
                                    <?= e($kebaya['nama_kebaya']) ?>
                                </h5>

                                <p class="text-muted mb-2">
                                    <i class="bi bi-palette me-1"></i>
                                    <?= e($kebaya['warna'] ?: 'Warna belum diisi') ?>
                                    <span class="mx-1">•</span>
                                    <i class="bi bi-rulers me-1"></i>
                                    <?= e($kebaya['ukuran'] ?: 'Ukuran belum diisi') ?>
                                </p>

                                <p class="price mb-3">
                                    Rp <?= number_format(
                                        (float) $kebaya['harga_sewa'],
                                        0,
                                        ',',
                                        '.'
                                    ) ?> <small>/ sewa</small>
                                </p>

                                <a href="#login" class="btn btn-pink w-100">
                                    <i class="bi bi-bag-heart me-1"></i>
                                    Login untuk Menyewa
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="bi bi-images fs-1" style="color:#d94f87;"></i>
                        <h5 class="fw-bold mt-3">Koleksi sedang disiapkan</h5>
                        <p class="text-muted">
                            Koleksi kebaya belum tersedia untuk ditampilkan.
                            Silakan kembali lagi nanti.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="text-center mt-4">
            <a href="#login" class="btn-outline-pink">
                Login untuk melihat lebih banyak
                <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
    </div>
</section>

<!-- LOGIN -->
<section class="login-section" id="login">
    <div class="container">
        <div class="text-center mb-4">
            <h2 class="section-title">Selamat Datang di KebayaRent</h2>
            <p class="section-subtitle mb-0">
                Masuk untuk melanjutkan ke akunmu.
            </p>
        </div>

        <div class="login-card">
            <div class="login-symbol">
                <i class="bi bi-person-heart"></i>
            </div>

            <h3>Login KebayaRent</h3>
            <p class="text-muted text-center mb-4">
                Admin, petugas, dan penyewa login di sini.
            </p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-circle me-2"></i>
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <?php if (
                isset($_GET['daftar']) &&
                $_GET['daftar'] === 'berhasil'
            ): ?>
                <div class="alert alert-success" role="alert">
                    <i class="bi bi-check-circle me-2"></i>
                    Akun berhasil dibuat. Silakan login!
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php#login">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white">
                            <i class="bi bi-person text-secondary"></i>
                        </span>
                        <input
                            type="text"
                            name="username"
                            id="username"
                            class="form-control"
                            placeholder="Masukkan username"
                            autocomplete="username"
                            maxlength="100"
                            required
                        >
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white">
                            <i class="bi bi-lock text-secondary"></i>
                        </span>
                        <input
                            type="password"
                            name="password"
                            id="password"
                            class="form-control"
                            placeholder="Masukkan password"
                            autocomplete="current-password"
                            required
                        >
                        <button
                            class="btn btn-outline-secondary"
                            type="button"
                            id="togglePassword"
                            aria-label="Tampilkan password"
                        >
                            <i class="bi bi-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                </div>

                <button
                    type="submit"
                    name="login"
                    value="1"
                    class="btn btn-pink w-100"
                >
                    <i class="bi bi-box-arrow-in-right me-2"></i>
                    Login
                </button>
            </form>

            <div class="text-center mt-4">
                <p class="text-muted mb-1">Belum punya akun penyewa?</p>
                <a
                    href="daftar.php"
                    class="fw-bold text-decoration-none"
                    style="color:var(--pink-dark);"
                >
                    <i class="bi bi-person-plus me-1"></i>
                    Daftar akun baru
                </a>
            </div>

            <div class="text-center mt-3">
                <small class="text-muted">
                    Login akan diarahkan sesuai jenis akunmu.
                </small>
            </div>
        </div>
    </div>
</section>

<!-- KONTAK -->
<section class="contact-section" id="kontak">
    <div class="container">
        <div class="about-box text-center">
            <div class="feature-icon mx-auto">
                <i class="bi bi-chat-dots"></i>
            </div>

            <h2 class="section-title">Ada yang ingin ditanyakan?</h2>
            <p class="text-muted mb-0">
                Terima kasih sudah mengunjungi KebayaRent.
                Silakan login untuk mulai menjelajahi layanan penyewaan.
            </p>

            <a href="#login" class="btn-pink mt-4">
                <i class="bi bi-person-circle me-1"></i>
                Login Sekarang
            </a>
        </div>
    </div>
</section>

<!-- FOOTER -->
<footer>
    <div class="container">
        <p>
            &copy; <?= date('Y') ?> KebayaRent.
            Dibuat dengan <i class="bi bi-heart-fill" style="color:#ff9fc5;"></i>
            untuk pecinta kebaya.
        </p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const togglePasswordIcon = document.getElementById('togglePasswordIcon');

    if (togglePassword && passwordInput && togglePasswordIcon) {
        togglePassword.addEventListener('click', function () {
            const isPassword = passwordInput.type === 'password';

            passwordInput.type = isPassword ? 'text' : 'password';
            togglePasswordIcon.className = isPassword
                ? 'bi bi-eye-slash'
                : 'bi bi-eye';

            togglePassword.setAttribute(
                'aria-label',
                isPassword ? 'Sembunyikan password' : 'Tampilkan password'
            );
        });
    }
</script>

</body>
</html>
