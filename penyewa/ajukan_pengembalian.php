
<?php
session_start();
require_once __DIR__ . '/../koneksi.php';

if (
    !isset($_SESSION['id_user']) ||
    ($_SESSION['role'] ?? '') !== 'penyewa'
) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$idUser = (int) $_SESSION['id_user'];
$idPenyewaan = filter_input(INPUT_POST, 'id_penyewaan', FILTER_VALIDATE_INT);
$catatan = trim($_POST['catatan'] ?? '');

if (!$idPenyewaan || strlen($catatan) > 2000) {
    exit('Data pengajuan tidak valid.');
}

try {
    $koneksi->begin_transaction();

    // Pastikan pesanan milik penyewa yang sedang login.
    $stmt = $koneksi->prepare(
        "SELECT id_penyewaan, status
         FROM penyewaan
         WHERE id_penyewaan = ? AND id_user = ?
         FOR UPDATE"
    );
    $stmt->bind_param('ii', $idPenyewaan, $idUser);
    $stmt->execute();
    $pesanan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pesanan) {
        throw new Exception('Pesanan tidak ditemukan.');
    }

    if (!in_array($pesanan['status'], ['dipinjam', 'selesai'], true)) {
        throw new Exception(
            'Pengajuan pengembalian hanya dapat dilakukan untuk pesanan yang sudah dipinjam.'
        );
    }

    // Wajib lunas dan pembayaran telah diverifikasi.
    $stmt = $koneksi->prepare(
        "SELECT COUNT(*) AS jumlah
         FROM pembayaran_tahap
         WHERE id_penyewaan = ?
           AND status <> 'diverifikasi'"
    );
    $stmt->bind_param('i', $idPenyewaan);
    $stmt->execute();
    $belumTerverifikasi = (int) $stmt->get_result()->fetch_assoc()['jumlah'];
    $stmt->close();

    $stmt = $koneksi->prepare(
        "SELECT COUNT(*) AS jumlah
         FROM pembayaran_tahap
         WHERE id_penyewaan = ? AND tahap = 'pelunasan'
           AND status = 'diverifikasi'"
    );
    $stmt->bind_param('i', $idPenyewaan);
    $stmt->execute();
    $lunas = (int) $stmt->get_result()->fetch_assoc()['jumlah'];
    $stmt->close();

    if ($belumTerverifikasi > 0 || $lunas < 1) {
        throw new Exception(
            'Pembayaran harus lunas dan diverifikasi sebelum mengajukan pengembalian.'
        );
    }

    // Tabel pengembalian hanya mengizinkan satu baris per pesanan.
    $stmt = $koneksi->prepare(
        "SELECT id_pengembalian, status
         FROM pengembalian
         WHERE id_penyewaan = ?
         FOR UPDATE"
    );
    $stmt->bind_param('i', $idPenyewaan);
    $stmt->execute();
    $pengembalian = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($pengembalian) {
        throw new Exception(
            'Pengajuan pengembalian untuk pesanan ini sudah tercatat.'
        );
    }

    $stmt = $koneksi->prepare(
        "INSERT INTO pengembalian
         (id_penyewaan, tanggal_kembali, catatan, status, tanggal_pengajuan)
         VALUES (?, NULL, ?, 'menunggu', NOW())"
    );
    $stmt->bind_param('is', $idPenyewaan, $catatan);
    $stmt->execute();
    $stmt->close();

    $koneksi->commit();

    $_SESSION['pesan'] = 'Pengajuan pengembalian berhasil dikirim.';
    header('Location: dashboard.php');
    exit;

} catch (Throwable $e) {
    $koneksi->rollback();
    $_SESSION['pesan_error'] = $e->getMessage();
    header('Location: dashboard.php');
    exit;
}
