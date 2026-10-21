<?php
require_once 'koneksi.php';

$akun = [
    ['aularls', 'admin', 'aularls'],
    ['wawa', 'petugas', 'wawa']
];

foreach ($akun as $data) {
    $username = $data[0];
    $role = $data[1];
    $passwordBaru = $data[2];

    $hash = password_hash($passwordBaru, PASSWORD_DEFAULT);

    $stmt = mysqli_prepare(
        $koneksi,
        "UPDATE users
         SET password = ?, status = 'aktif'
         WHERE username = ? AND role = ?"
    );

    mysqli_stmt_bind_param(
        $stmt,
        'sss',
        $hash,
        $username,
        $role
    );

    mysqli_stmt_execute($stmt);

    echo htmlspecialchars($username) . ': ';
    echo mysqli_stmt_affected_rows($stmt) >= 0
        ? 'proses selesai'
        : 'gagal diperbarui';

    echo '<br>';

    mysqli_stmt_close($stmt);
}
?>
