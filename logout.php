
<?php
session_start();
require_once 'koneksi.php';

$id_user = $_SESSION['id_user'] ?? null;

if ($id_user !== null) {
    $aktivitas = "Logout dari sistem";

    $stmt = mysqli_prepare(
        $koneksi,
        "INSERT INTO log_aktivitas (id_user, aktivitas)
         VALUES (?, ?)"
    );

    mysqli_stmt_bind_param(
        $stmt,
        "is",
        $id_user,
        $aktivitas
    );

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

// Kembali ke halaman login
header("Location: login.php");
exit;
?>
