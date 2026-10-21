<?php
session_start();
require_once __DIR__ . '/koneksi.php';

$error = '';
$nama = '';
$username = '';
$no_hp = '';
$alamat = '';

// Jika pengguna sudah login, arahkan sesuai role.
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

// Proses pendaftaran.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama = trim($_POST['nama'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $no_hp = trim($_POST['no_hp'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    $password = $_POST['password'] ?? '';
    $konfirmasi = $_POST['konfirmasi_password'] ?? '';

    // Validasi input.
    if (
        $nama === '' ||
        $username === '' ||
        $no_hp === '' ||
        $alamat === '' ||
        $password === '' ||
        $konfirmasi === ''
    ) {
        $error = 'Semua kolom wajib diisi.';
    } elseif (
        !preg_match("/^[\p{L}\s'.-]+$/u", $nama)
    ) {
        $error = 'Nama hanya boleh berisi huruf, spasi, titik, atau tanda hubung.';
    } elseif (strlen($username) < 4 || strlen($username) > 50) {
        $error = 'Username harus terdiri dari 4 sampai 50 karakter.';
    } elseif (
        !preg_match('/^[a-zA-Z0-9_.]+$/', $username)
    ) {
        $error = 'Username hanya boleh berisi huruf, angka, titik, dan garis bawah.';
    } elseif (strlen($no_hp) < 8 || strlen($no_hp) > 20) {
        $error = 'Nomor HP harus terdiri dari 8 sampai 20 karakter.';
    } elseif (!preg_match('/^[0-9+\-\s()]+$/', $no_hp)) {
        $error = 'Format nomor HP tidak valid.';
    } elseif (strlen($alamat) < 5 || strlen($alamat) > 1000) {
        $error = 'Alamat harus terdiri dari 5 sampai 1000 karakter.';
    } elseif (strlen($password) < 8) {
        $error = 'Password minimal 8 karakter.';
    } elseif ($password !== $konfirmasi) {
        $error = 'Konfirmasi password tidak sama.';
    } else {
        // Cek apakah username sudah digunakan.
        $stmt = mysqli_prepare(
            $koneksi,
            "SELECT id_user FROM users WHERE username = ? LIMIT 1"
        );

        if (!$stmt) {
            $error = 'Terjadi kesalahan saat memeriksa username.';
        } else {
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $sudahAda = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);

            if ($sudahAda) {
                $error = 'Username sudah digunakan. Silakan pilih username lain.';
            } else {
                // Simpan password dalam bentuk hash.
                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $role = 'penyewa';
                $status = 'aktif';

                $insert = mysqli_prepare(
                    $koneksi,
                    "INSERT INTO users
                        (nama, username, password, role, no_hp, alamat, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );

                if (!$insert) {
                    $error = 'Pendaftaran gagal diproses. Periksa struktur tabel users.';
                } else {
                    mysqli_stmt_bind_param(
                        $insert,
                        'sssssss',
                        $nama,
                        $username,
                        $passwordHash,
                        $role,
                        $no_hp,
                        $alamat,
                        $status
                    );

                    if (mysqli_stmt_execute($insert)) {
                        mysqli_stmt_close($insert);

                        header('Location: login.php?daftar=berhasil#login');
                        exit;
                    } else {
                        if (mysqli_stmt_errno($insert) === 1062) {
                            $error = 'Username sudah digunakan. Silakan pilih username lain.';
                        } else {
                            $error = 'Pendaftaran gagal disimpan. Silakan periksa database.';
                        }

                        mysqli_stmt_close($insert);
                    }
                }
            }
        }
    }
}

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Daftar Akun - KebayaRent</title>

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
            --text: #442b38;
            --muted: #826c78;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: flex;
            flex-direction: column;
            background:
                radial-gradient(circle at 10% 10%, #ffe0ed, transparent 32%),
                linear-gradient(135deg, #fff4f8, #fffafd);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }

        .navbar {
            background: rgba(255, 255, 255, .95);
            box-shadow: 0 3px 20px rgba(80, 30, 55, .06);
        }

        .brand {
            color: var(--pink-dark);
            font-size: 1.4rem;
            font-weight: 800;
            text-decoration: none;
        }

        .btn-pink {
            padding: 12px 20px;
            border: none;
            border-radius: 12px;
            background: var(--pink);
            color: #fff;
            font-weight: 700;
            transition: .2s;
        }

        .btn-pink:hover {
            background: var(--pink-dark);
            color: #fff;
        }

        main {
            flex: 1;
            display: flex;
            align-items: center;
            padding: 45px 12px;
        }

        .register-card {
            width: 100%;
            max-width: 570px;
            margin: auto;
            padding: 35px;
            border: 1px solid #f8e1eb;
            border-radius: 25px;
            background: #fff;
            box-shadow: 0 15px 45px rgba(100, 40, 70, .09);
        }

        .register-icon {
            display: flex;
            width: 65px;
            height: 65px;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            border-radius: 22px;
            background: var(--pink-light);
            color: var(--pink-dark);
            font-size: 2rem;
        }

        h1 {
            font-size: 1.8rem;
            font-weight: 800;
            text-align: center;
        }

        .subtitle {
            color: var(--muted);
            text-align: center;
        }

        .form-label {
            font-weight: 700;
        }

        .form-control {
            min-height: 46px;
            border-color: #eadbe3;
            border-radius: 10px;
        }

        textarea.form-control {
            min-height: 90px;
        }

        .form-control:focus {
            border-color: var(--pink);
            box-shadow: 0 0 0 .2rem rgba(217, 79, 135, .13);
        }

        .input-group .form-control {
            border-radius: 10px 0 0 10px;
        }

        .input-group .btn {
            border-color: #eadbe3;
            border-radius: 0 10px 10px 0;
        }

        .login-link {
            color: var(--pink-dark);
            font-weight: 700;
            text-decoration: none;
        }

        .login-link:hover {
            text-decoration: underline;
        }

        footer {
            padding: 20px 12px;
            color: var(--muted);
            text-align: center;
            font-size: .9rem;
        }

        @media (max-width: 576px) {
            .register-card {
                padding: 25px 20px;
            }

            h1 {
                font-size: 1.55rem;
            }
        }
    </style>
</head>

<body>

<nav class="navbar py-3">
    <div class="container">
        <a class="brand" href="login.php">
            <i class="bi bi-flower1 me-1"></i>KebayaRent
        </a>

        <a href="login.php#login" class="login-link">
            <i class="bi bi-box-arrow-in-right me-1"></i>Login
        </a>
    </div>
</nav>

<main>
    <div class="register-card">
        <div class="register-icon">
            <i class="bi bi-person-heart"></i>
        </div>

        <h1>Buat Akun Penyewa</h1>
        <p class="subtitle mb-4">
            Daftar sekarang dan temukan kebaya favoritmu. 💗
        </p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="daftar.php">
            <div class="mb-3">
                <label for="nama" class="form-label">Nama Lengkap</label>
                <input
                    type="text"
                    class="form-control"
                    id="nama"
                    name="nama"
                    value="<?= e($nama) ?>"
                    placeholder="Masukkan nama lengkap"
                    maxlength="100"
                    autocomplete="name"
                    required
                >
            </div>

            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input
                    type="text"
                    class="form-control"
                    id="username"
                    name="username"
                    value="<?= e($username) ?>"
                    placeholder="Minimal 4 karakter"
                    minlength="4"
                    maxlength="50"
                    pattern="[a-zA-Z0-9_.]+"
                    autocomplete="username"
                    required
                >
                <small class="text-muted">
                    Gunakan huruf, angka, titik, atau garis bawah.
                </small>
            </div>

            <div class="mb-3">
                <label for="no_hp" class="form-label">Nomor HP</label>
                <input
                    type="tel"
                    class="form-control"
                    id="no_hp"
                    name="no_hp"
                    value="<?= e($no_hp) ?>"
                    placeholder="Contoh: 081234567890"
                    maxlength="20"
                    autocomplete="tel"
                    required
                >
            </div>

            <div class="mb-3">
                <label for="alamat" class="form-label">Alamat</label>
                <textarea
                    class="form-control"
                    id="alamat"
                    name="alamat"
                    placeholder="Masukkan alamat lengkap"
                    minlength="5"
                    maxlength="1000"
                    autocomplete="street-address"
                    required
                ><?= e($alamat) ?></textarea>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <input
                        type="password"
                        class="form-control"
                        id="password"
                        name="password"
                        placeholder="Minimal 8 karakter"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                    <button
                        class="btn btn-outline-secondary"
                        type="button"
                        data-toggle-password="password"
                        aria-label="Tampilkan password"
                    >
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <small class="text-muted">
                    Gunakan password minimal 8 karakter.
                </small>
            </div>

            <div class="mb-4">
                <label
                    for="konfirmasi_password"
                    class="form-label"
                >
                    Konfirmasi Password
                </label>
                <div class="input-group">
                    <input
                        type="password"
                        class="form-control"
                        id="konfirmasi_password"
                        name="konfirmasi_password"
                        placeholder="Ulangi password"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                    <button
                        class="btn btn-outline-secondary"
                        type="button"
                        data-toggle-password="konfirmasi_password"
                        aria-label="Tampilkan konfirmasi password"
                    >
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <button
                type="submit"
                class="btn btn-pink w-100"
            >
                <i class="bi bi-person-plus me-2"></i>
                Daftar Sekarang
            </button>
        </form>

        <div class="text-center mt-4">
            <span class="text-muted">Sudah punya akun?</span>
            <a href="login.php#login" class="login-link ms-1">
                Login di sini
            </a>
        </div>

        <p class="text-center text-muted small mt-3 mb-0">
            Pendaftaran ini membuat akun penyewa baru.
        </p>
    </div>
</main>

<footer>
    &copy; <?= date('Y') ?> KebayaRent.
    <i class="bi bi-heart-fill" style="color:#d94f87;"></i>
    Tampil anggun di setiap momen.
</footer>

<script>
    document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
        button.addEventListener('click', function () {
            const inputId = button.getAttribute('data-toggle-password');
            const input = document.getElementById(inputId);
            const icon = button.querySelector('i');

            if (!input || !icon) return;

            const tampil = input.type === 'password';
            input.type = tampil ? 'text' : 'password';

            icon.className = tampil ? 'bi bi-eye-slash' : 'bi bi-eye';

            button.setAttribute(
                'aria-label',
                tampil ? 'Sembunyikan password' : 'Tampilkan password'
            );
        });
    });

    // Validasi konfirmasi password di browser.
    const form = document.querySelector('form');
    const password = document.getElementById('password');
    const konfirmasi = document.getElementById('konfirmasi_password');

    form.addEventListener('submit', function (event) {
        if (password.value !== konfirmasi.value) {
            event.preventDefault();
            konfirmasi.setCustomValidity('Konfirmasi password tidak sama.');
            konfirmasi.reportValidity();
        } else {
            konfirmasi.setCustomValidity('');
        }
    });

    konfirmasi.addEventListener('input', function () {
        konfirmasi.setCustomValidity('');
    });
</script>

</body>
</html>
