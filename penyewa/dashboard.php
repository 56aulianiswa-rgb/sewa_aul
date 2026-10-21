<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_GET['aksi']) && $_GET['aksi'] === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: /sewa_aul/login.php');
    exit;
}

require_once __DIR__ . '/../koneksi.php';
mysqli_report(MYSQLI_REPORT_OFF);
if (!isset($_SESSION['id_user']) || ($_SESSION['role'] ?? '') !== 'penyewa') {
    header('Location: /sewa_aul/login.php'); exit;
}
$id_user = (int)$_SESSION['id_user'];
$nama = $_SESSION['nama'] ?? 'Penyewa';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
if (!isset($_SESSION['keranjang']) || !is_array($_SESSION['keranjang'])) $_SESSION['keranjang'] = [];

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function rupiah($v) { return 'Rp ' . number_format((float)$v, 0, ',', '.'); }
function redirectKe($url) { header('Location: ' . $url); exit; }
function validTanggal($tanggal) {
    if (!is_string($tanggal) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) return false;
    [$y,$m,$d] = array_map('intval', explode('-', $tanggal));
    return checkdate($m, $d, $y);
}

/**
 * Foto kebaya dari admin bisa berupa data URI Base64 (data:image/jpeg;base64,...)
 * atau berupa URL/path file. Jangan mengubah data URI menjadi path uploads.
 */
function urlFoto($foto) {
    $foto = trim((string)$foto);
    if ($foto === '') return '';
    if (preg_match('~^data:image/(jpeg|jpg|png|webp);base64,[a-z0-9+/=\r\n]+$~i', $foto)) return $foto;
    if (preg_match('~^https?://~i', $foto)) return $foto;
    $foto = str_replace('\\', '/', $foto);
    if (str_starts_with($foto, '//') || preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $foto)) return '';
    $foto = ltrim($foto, '/');
    if (str_contains($foto, '/')) return '../' . $foto;
    return '../uploads/' . rawurlencode(basename($foto));
}

$menu = $_GET['menu'] ?? 'dashboard';
$menuValid = ['dashboard','katalog','keranjang','pesanan','pembayaran','fitting','profil'];
if (!in_array($menu, $menuValid, true)) $menu = 'dashboard';
$pesan = ''; $jenisPesan = 'success';
if (isset($_GET['success'])) {
    $pesan = match ($_GET['success']) {
        'checkout' => 'Pesanan berhasil dibuat dan menunggu persetujuan admin. Pembayaran belum dibuka.',
        'bukti' => 'Bukti pembayaran berhasil diunggah dan menunggu verifikasi.',
        'profil' => 'Profil berhasil diperbarui.',
        'fitting' => 'Jadwal fitting berhasil diajukan.',
        default => ''
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!is_string($csrf) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        $pesan = 'Sesi formulir tidak valid. Silakan muat ulang halaman.'; $jenisPesan = 'danger';
    } else {
        $aksi = $_POST['aksi'] ?? '';
        if ($aksi === 'tambah_keranjang') {
            $id = (int)($_POST['id_kebaya'] ?? 0); $jumlah = max(1, (int)($_POST['jumlah'] ?? 1));
            $stmt = mysqli_prepare($koneksi, "SELECT stok FROM kebaya WHERE id_kebaya=? AND status='tersedia'");
            mysqli_stmt_bind_param($stmt, 'i', $id); mysqli_stmt_execute($stmt); $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)); mysqli_stmt_close($stmt);
            if (!$row || (int)$row['stok'] < 1) { $pesan='Kebaya tidak tersedia.'; $jenisPesan='danger'; }
            else { $_SESSION['keranjang'][$id] = min((int)($_SESSION['keranjang'][$id] ?? 0) + $jumlah, (int)$row['stok']); redirectKe('dashboard.php?menu=keranjang'); }
        } elseif ($aksi === 'ubah_keranjang') {
            foreach (($_POST['jumlah'] ?? []) as $id => $qty) {
                $id=(int)$id; $qty=(int)$qty;
                if ($qty<=0) { unset($_SESSION['keranjang'][$id]); continue; }
                $stmt=mysqli_prepare($koneksi,"SELECT stok FROM kebaya WHERE id_kebaya=? AND status='tersedia'");
                mysqli_stmt_bind_param($stmt,'i',$id); mysqli_stmt_execute($stmt); $row=mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)); mysqli_stmt_close($stmt);
                if (!$row) unset($_SESSION['keranjang'][$id]); else $_SESSION['keranjang'][$id]=min($qty,(int)$row['stok']);
            }
            redirectKe('dashboard.php?menu=keranjang');
        } elseif ($aksi === 'hapus_keranjang') {
            unset($_SESSION['keranjang'][(int)($_POST['id_kebaya'] ?? 0)]); redirectKe('dashboard.php?menu=keranjang');
        } elseif ($aksi === 'checkout') {
            $mulai=$_POST['tanggal_mulai'] ?? ''; $selesai=$_POST['tanggal_selesai'] ?? ''; $catatan=trim($_POST['catatan'] ?? '');
            if (empty($_SESSION['keranjang'])) { $pesan='Keranjang masih kosong.'; $jenisPesan='danger'; }
            elseif (!validTanggal($mulai)||!validTanggal($selesai)||$mulai<date('Y-m-d')||$selesai<$mulai) { $pesan='Tanggal sewa tidak valid.'; $jenisPesan='danger'; }
            else {
                $items=[]; $total=0; $stokCukup=true;
                foreach ($_SESSION['keranjang'] as $id=>$qty) {
                    $id=(int)$id; $qty=(int)$qty;
                    $stmt=mysqli_prepare($koneksi,"SELECT id_kebaya,nama_kebaya,harga_sewa,stok FROM kebaya WHERE id_kebaya=? AND status='tersedia'");
                    mysqli_stmt_bind_param($stmt,'i',$id); mysqli_stmt_execute($stmt); $r=mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)); mysqli_stmt_close($stmt);
                    if (!$r || $qty<1 || (int)$r['stok']<$qty) { $stokCukup=false; break; }
                    $r['jumlah']=$qty; $items[]=$r; $total+=(float)$r['harga_sewa']*$qty;
                }
                if (!$stokCukup || !$items) { $pesan='Stok kebaya berubah. Periksa kembali keranjang.'; $jenisPesan='danger'; }
                else {
                    mysqli_begin_transaction($koneksi);
                    try {
                        $kode='KR'.date('YmdHis').random_int(100,999);
                        $stmt=mysqli_prepare($koneksi,"INSERT INTO penyewaan (kode_penyewaan,id_user,tanggal_pesan,tanggal_mulai,tanggal_selesai,total,status,catatan) VALUES (?, ?, NOW(), ?, ?, ?, 'menunggu', ?)");
                        mysqli_stmt_bind_param($stmt,'sissds',$kode,$id_user,$mulai,$selesai,$total,$catatan);
                        if (!mysqli_stmt_execute($stmt)) throw new Exception('Pesanan gagal disimpan.');
                        $idPenyewaan=mysqli_insert_id($koneksi); mysqli_stmt_close($stmt);
                        $detail=mysqli_prepare($koneksi,"INSERT INTO detail_penyewaan (id_penyewaan,id_kebaya,jumlah,harga) VALUES (?,?,?,?)");
                        foreach ($items as $item) {
                            $idBarang=(int)$item['id_kebaya']; $qty=(int)$item['jumlah']; $harga=(float)$item['harga_sewa'];
                            mysqli_stmt_bind_param($detail,'iiid',$idPenyewaan,$idBarang,$qty,$harga);
                            if (!mysqli_stmt_execute($detail)) throw new Exception('Detail pesanan gagal disimpan.');
                        }
                        mysqli_stmt_close($detail); mysqli_commit($koneksi); $_SESSION['keranjang']=[];
                        redirectKe('dashboard.php?menu=pembayaran&success=checkout');
                    } catch (Throwable $ex) { mysqli_rollback($koneksi); $pesan='Checkout gagal. Periksa struktur tabel penyewaan dan detail_penyewaan.'; $jenisPesan='danger'; }
                }
            }
        } elseif ($aksi === 'unggah_bukti') {
            $idPenyewaan = (int)($_POST['id_penyewaan'] ?? 0);
            $tahap = $_POST['tahap'] ?? '';
            $metode = $_POST['metode'] ?? '';
            $metodeValid = ['transfer_bank', 'e_wallet'];
            if (!in_array($tahap, ['dp', 'pelunasan'], true) || !in_array($metode, $metodeValid, true)) {
                $pesan = 'Tahap pembayaran atau metode tidak valid.'; $jenisPesan = 'danger';
            } else {
                $stmt = mysqli_prepare($koneksi, "SELECT id_penyewaan,total,status FROM penyewaan WHERE id_penyewaan=? AND id_user=?");
                mysqli_stmt_bind_param($stmt, 'ii', $idPenyewaan, $id_user); mysqli_stmt_execute($stmt);
                $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)); mysqli_stmt_close($stmt);
                $fit = false;
                if ($order) {
                    $cekFit = mysqli_prepare($koneksi, "SELECT id_fitting FROM janji_fitting WHERE id_penyewaan=? AND status='disetujui' LIMIT 1");
                    if ($cekFit) { mysqli_stmt_bind_param($cekFit, 'i', $idPenyewaan); mysqli_stmt_execute($cekFit); $fit = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($cekFit)); mysqli_stmt_close($cekFit); }
                }
                $dp = null; $pelunasan = null;
                if ($order) {
                    $q = mysqli_prepare($koneksi, "SELECT tahap,status FROM pembayaran_tahap WHERE id_penyewaan=?");
                    mysqli_stmt_bind_param($q, 'i', $idPenyewaan); mysqli_stmt_execute($q); $res = mysqli_stmt_get_result($q);
                    while ($pr = mysqli_fetch_assoc($res)) { if ($pr['tahap']==='dp') $dp=$pr['status']; if ($pr['tahap']==='pelunasan') $pelunasan=$pr['status']; }
                    mysqli_stmt_close($q);
                }
                $allowed = $order && $order['status'] !== 'ditolak' && $order['status'] !== 'menunggu' && $fit;
                if ($tahap === 'pelunasan' && $dp !== 'diverifikasi') $allowed = false;
                if ($tahap === 'dp' && $dp === 'diverifikasi') $allowed = false;
                if ($tahap === 'pelunasan' && $pelunasan === 'diverifikasi') $allowed = false;
                if (!$allowed) { $pesan = 'Pembayaran belum bisa dilakukan. Pesanan harus disetujui, fitting dikonfirmasi, dan DP diverifikasi sebelum pelunasan.'; $jenisPesan = 'danger'; }
                elseif (!isset($_FILES['bukti']) || $_FILES['bukti']['error'] !== UPLOAD_ERR_OK) { $pesan='Pilih file bukti pembayaran terlebih dahulu.'; $jenisPesan='danger'; }
                elseif ($_FILES['bukti']['size'] > 3*1024*1024) { $pesan='Ukuran bukti pembayaran maksimal 3 MB.'; $jenisPesan='danger'; }
                else {
                    $file=$_FILES['bukti']; $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($file['tmp_name']);
                    $jenisFile=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                    if (!isset($jenisFile[$mime])) { $pesan='Bukti harus berupa gambar JPG, PNG, atau WEBP.'; $jenisPesan='danger'; }
                    else {
                        $folder=__DIR__.'/bukti_pembayaran'; if (!is_dir($folder)) mkdir($folder,0755,true);
                        $namaFile=bin2hex(random_bytes(16)).'.'.$jenisFile[$mime]; $tujuan=$folder.'/'.$namaFile; $pathSimpan='bukti_pembayaran/'.$namaFile;
                        $jumlahBayar = $tahap === 'dp' ? round((float)$order['total'] * 0.50, 2) : max(0, (float)$order['total'] - round((float)$order['total'] * 0.50, 2));
                        $cek=mysqli_prepare($koneksi,"SELECT id_pembayaran_tahap,status FROM pembayaran_tahap WHERE id_penyewaan=? AND tahap=?");
                        mysqli_stmt_bind_param($cek,'is',$idPenyewaan,$tahap); mysqli_stmt_execute($cek); $lama=mysqli_fetch_assoc(mysqli_stmt_get_result($cek)); mysqli_stmt_close($cek);
                        if ($lama && $lama['status']==='diverifikasi') { $pesan='Tahap pembayaran ini sudah diverifikasi.'; $jenisPesan='danger'; }
                        elseif (move_uploaded_file($file['tmp_name'],$tujuan)) {
                            if ($lama) {
                                $stmt=mysqli_prepare($koneksi,"UPDATE pembayaran_tahap SET metode=?,jumlah_bayar=?,bukti_pembayaran=?,status='menunggu',tanggal_bayar=NOW(),catatan_petugas=NULL WHERE id_pembayaran_tahap=?");
                                mysqli_stmt_bind_param($stmt,'sdsi',$metode,$jumlahBayar,$pathSimpan,$lama['id_pembayaran_tahap']);
                            } else {
                                $stmt=mysqli_prepare($koneksi,"INSERT INTO pembayaran_tahap (id_penyewaan,tahap,metode,jumlah_bayar,bukti_pembayaran,status,tanggal_bayar) VALUES (?,?,?,?,?,'menunggu',NOW())");
                                mysqli_stmt_bind_param($stmt,'issds',$idPenyewaan,$tahap,$metode,$jumlahBayar,$pathSimpan);
                            }
                            if (mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); redirectKe('dashboard.php?menu=pembayaran&success=bukti'); }
                            mysqli_stmt_close($stmt); @unlink($tujuan); $pesan='Bukti terunggah, tetapi data pembayaran gagal disimpan.'; $jenisPesan='danger';
                        } else { $pesan='File gagal diunggah. Periksa izin folder.'; $jenisPesan='danger'; }
                    }
                }
            }
        } elseif ($aksi === 'profil') {
            $namaBaru=trim($_POST['nama'] ?? ''); $noHp=trim($_POST['no_hp'] ?? ''); $alamat=trim($_POST['alamat'] ?? '');
            if ($namaBaru==='' || strlen($namaBaru)>100) { $pesan='Nama wajib diisi dan maksimal 100 karakter.'; $jenisPesan='danger'; }
            else {
                $stmt=mysqli_prepare($koneksi,"UPDATE users SET nama=?,no_hp=?,alamat=? WHERE id_user=?"); mysqli_stmt_bind_param($stmt,'sssi',$namaBaru,$noHp,$alamat,$id_user);
                if (mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); $_SESSION['nama']=$namaBaru; redirectKe('dashboard.php?menu=profil&success=profil'); }
                mysqli_stmt_close($stmt); $pesan='Profil gagal diperbarui.'; $jenisPesan='danger';
            }
        } elseif ($aksi === 'fitting') {
            $idPenyewaan = (int)($_POST['id_penyewaan'] ?? 0);
            $tanggal=$_POST['tanggal_fitting'] ?? ''; $jam=$_POST['jam_fitting'] ?? ''; $catatan=trim($_POST['catatan'] ?? '');
            $cekOrder = mysqli_prepare($koneksi, "SELECT id_penyewaan FROM penyewaan WHERE id_penyewaan=? AND id_user=? AND status='disetujui'");
            mysqli_stmt_bind_param($cekOrder, 'ii', $idPenyewaan, $id_user); mysqli_stmt_execute($cekOrder); $orderFit = mysqli_fetch_assoc(mysqli_stmt_get_result($cekOrder)); mysqli_stmt_close($cekOrder);
            if (!$orderFit || !validTanggal($tanggal) || $tanggal<date('Y-m-d') || !preg_match('/^\d{2}:\d{2}$/',$jam)) { $pesan='Pilih pesanan yang sudah disetujui serta tanggal dan jam fitting yang valid.'; $jenisPesan='danger'; }
            else {
                $cekAda = mysqli_prepare($koneksi, "SELECT id_fitting FROM janji_fitting WHERE id_penyewaan=? LIMIT 1");
                mysqli_stmt_bind_param($cekAda, 'i', $idPenyewaan); mysqli_stmt_execute($cekAda); $adaFit = mysqli_fetch_assoc(mysqli_stmt_get_result($cekAda)); mysqli_stmt_close($cekAda);
                if ($adaFit) { $pesan='Pesanan ini sudah memiliki pengajuan fitting.'; $jenisPesan='danger'; }
                else {
                    $stmt=mysqli_prepare($koneksi,"INSERT INTO janji_fitting (id_user,id_penyewaan,tanggal_fitting,jam_fitting,catatan,status) VALUES (?,?,?,?,?,'menunggu')");
                    if ($stmt) { mysqli_stmt_bind_param($stmt,'iisss',$id_user,$idPenyewaan,$tanggal,$jam,$catatan); if (mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); redirectKe('dashboard.php?menu=fitting&success=fitting'); } mysqli_stmt_close($stmt); }
                    $pesan='Gagal menyimpan fitting. Periksa struktur tabel janji_fitting.'; $jenisPesan='danger';
                }
            }
        }
    }
}

// Katalog dari data admin. Kolom foto dibaca langsung dari tabel kebaya.
$katalog=[];
$sql=mysqli_query($koneksi,"SELECT k.*,kat.nama_kategori FROM kebaya k LEFT JOIN kategori_kebaya kat ON k.id_kategori=kat.id_kategori WHERE k.status='tersedia' ORDER BY k.id_kebaya DESC");
if ($sql) while ($row=mysqli_fetch_assoc($sql)) $katalog[]=$row;

$itemsKeranjang=[]; $totalKeranjang=0;
foreach ($_SESSION['keranjang'] as $id=>$qty) {
    $id=(int)$id; $stmt=mysqli_prepare($koneksi,"SELECT id_kebaya,nama_kebaya,harga_sewa,stok,foto FROM kebaya WHERE id_kebaya=? AND status='tersedia'");
    mysqli_stmt_bind_param($stmt,'i',$id); mysqli_stmt_execute($stmt); $row=mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)); mysqli_stmt_close($stmt);
    if (!$row) { unset($_SESSION['keranjang'][$id]); continue; }
    $row['jumlah']=(int)$qty; $row['subtotal']=(float)$row['harga_sewa']*(int)$qty; $totalKeranjang+=$row['subtotal']; $itemsKeranjang[]=$row;
}
$stmt=mysqli_prepare($koneksi,"SELECT nama,username,no_hp,alamat FROM users WHERE id_user=?"); mysqli_stmt_bind_param($stmt,'i',$id_user); mysqli_stmt_execute($stmt); $profil=mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: []; mysqli_stmt_close($stmt);
$pesanan=[];
$stmt=mysqli_prepare($koneksi,"SELECT s.*,
 (SELECT pt.status FROM pembayaran_tahap pt WHERE pt.id_penyewaan=s.id_penyewaan AND pt.tahap='dp' LIMIT 1) AS status_dp,
 (SELECT pt.jumlah_bayar FROM pembayaran_tahap pt WHERE pt.id_penyewaan=s.id_penyewaan AND pt.tahap='dp' LIMIT 1) AS jumlah_dp,
 (SELECT pt.bukti_pembayaran FROM pembayaran_tahap pt WHERE pt.id_penyewaan=s.id_penyewaan AND pt.tahap='dp' LIMIT 1) AS bukti_dp,
 (SELECT pt.catatan_petugas FROM pembayaran_tahap pt WHERE pt.id_penyewaan=s.id_penyewaan AND pt.tahap='dp' LIMIT 1) AS catatan_dp,
 (SELECT pt.status FROM pembayaran_tahap pt WHERE pt.id_penyewaan=s.id_penyewaan AND pt.tahap='pelunasan' LIMIT 1) AS status_pelunasan,
 (SELECT pt.jumlah_bayar FROM pembayaran_tahap pt WHERE pt.id_penyewaan=s.id_penyewaan AND pt.tahap='pelunasan' LIMIT 1) AS jumlah_pelunasan,
 (SELECT pt.bukti_pembayaran FROM pembayaran_tahap pt WHERE pt.id_penyewaan=s.id_penyewaan AND pt.tahap='pelunasan' LIMIT 1) AS bukti_pelunasan,
 (SELECT jf.status FROM janji_fitting jf WHERE jf.id_penyewaan=s.id_penyewaan ORDER BY jf.id_fitting DESC LIMIT 1) AS status_fitting,
 (SELECT jf.tanggal_fitting FROM janji_fitting jf WHERE jf.id_penyewaan=s.id_penyewaan ORDER BY jf.id_fitting DESC LIMIT 1) AS tanggal_fitting,
 (SELECT jf.jam_fitting FROM janji_fitting jf WHERE jf.id_penyewaan=s.id_penyewaan ORDER BY jf.id_fitting DESC LIMIT 1) AS jam_fitting
 FROM penyewaan s WHERE s.id_user=? ORDER BY s.id_penyewaan DESC");
mysqli_stmt_bind_param($stmt,'i',$id_user); mysqli_stmt_execute($stmt); $hasil=mysqli_stmt_get_result($stmt); while ($row=mysqli_fetch_assoc($hasil)) $pesanan[]=$row; mysqli_stmt_close($stmt);
$fitting=[]; $stmt=mysqli_prepare($koneksi,"SELECT jf.id_fitting,jf.id_penyewaan,jf.tanggal_fitting,jf.jam_fitting,jf.catatan,jf.status,s.kode_penyewaan FROM janji_fitting jf LEFT JOIN penyewaan s ON s.id_penyewaan=jf.id_penyewaan WHERE jf.id_user=? ORDER BY jf.tanggal_fitting DESC,jf.jam_fitting DESC");
if ($stmt) { mysqli_stmt_bind_param($stmt,'i',$id_user); mysqli_stmt_execute($stmt); $hasil=mysqli_stmt_get_result($stmt); while ($row=mysqli_fetch_assoc($hasil)) $fitting[]=$row; mysqli_stmt_close($stmt); }
$pesananFit = array_values(array_filter($pesanan, fn($p) => $p['status']==='disetujui' && empty($p['status_fitting'])));
$totalPesanan=count($pesanan);
$judul=['dashboard'=>'Dashboard','katalog'=>'Katalog Kebaya','keranjang'=>'Keranjang & Checkout','pesanan'=>'Pesanan Saya','pembayaran'=>'Pembayaran','fitting'=>'Jadwal Fitting','profil'=>'Profil Saya'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($judul[$menu]) ?> - KebayaRent</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{--pink:#d86b98;--dark:#ad416f;--light:#fff0f6;--border:#f4d1e0;--text:#503b46}
body{background:#fff8fb;color:var(--text);font-family:Arial,sans-serif}.navbar{background:#fff;border-bottom:1px solid var(--border)}
.brand,.page-title{color:var(--dark);font-weight:800}.brand{font-size:1.35rem}.nav-link{color:#715260;border-radius:10px;margin:2px}.nav-link.active,.nav-link:hover{background:var(--light);color:var(--dark)}
.panel,.stat-card,.kebaya-card{background:#fff;border:1px solid var(--border);border-radius:18px;box-shadow:0 5px 18px #8d49610a}.stat-card{padding:22px;height:100%}.stat-icon{width:48px;height:48px;border-radius:14px;display:grid;place-items:center;background:var(--light);color:var(--dark);font-size:1.4rem}
.btn-pink{background:var(--pink);color:#fff;border:0;border-radius:10px;padding:10px 16px}.btn-pink:hover{background:var(--dark);color:#fff}.btn-outline-pink{border:1px solid var(--pink);color:var(--dark);border-radius:10px}.btn-outline-pink:hover{background:var(--light);color:var(--dark)}
.form-control,.form-select{border-radius:10px}.form-control:focus,.form-select:focus{border-color:var(--pink);box-shadow:0 0 0 .2rem #d86b9820}.kebaya-card{overflow:hidden;height:100%}.kebaya-photo{display:block;width:100%;height:240px;object-fit:cover;background:var(--light)}.photo-placeholder{height:240px;background:var(--light);display:grid;place-items:center;color:var(--pink);font-size:3rem}.table thead th{background:var(--light);color:var(--dark)}.badge-status{background:var(--light);color:var(--dark)}footer{color:#997b8a}
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg sticky-top"><div class="container">
<a class="navbar-brand brand" href="dashboard.php"><i class="bi bi-flower1"></i> KebayaRent</a>
<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu"><span class="navbar-toggler-icon"></span></button>
<div class="collapse navbar-collapse" id="navMenu"><div class="navbar-nav ms-auto align-items-lg-center">
<?php foreach ($judul as $key=>$label): ?><a class="nav-link <?= $menu===$key?'active':'' ?>" href="dashboard.php?menu=<?= e($key) ?>"><?= e($label) ?><?php if($key==='keranjang' && count($_SESSION['keranjang'])): ?> <span class="badge text-bg-danger"><?= count($_SESSION['keranjang']) ?></span><?php endif; ?></a><?php endforeach; ?>
<a class="btn btn-outline-pink btn-sm ms-lg-2" href="dashboard.php?aksi=logout"><i class="bi bi-box-arrow-right"></i> Keluar</a>
</div></div></div></nav>
<main class="container py-4 py-lg-5">
<div class="mb-4"><p class="mb-1 text-secondary">Halo, <?= e($nama) ?> 🌷</p><h2 class="page-title"><?= e($judul[$menu]) ?></h2></div>
<?php if($pesan): ?><div class="alert alert-<?= e($jenisPesan) ?> alert-dismissible fade show"><?= e($pesan) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if($menu==='dashboard'): ?>
<div class="panel p-4 p-lg-5 mb-4"><div class="row align-items-center g-4"><div class="col-lg-8"><span class="badge rounded-pill badge-status mb-3">Selamat datang di KebayaRent</span><h3 class="fw-bold">Temukan kebaya istimewa untuk hari spesialmu.</h3><p class="text-secondary">Lihat koleksi kebaya, buat pesanan, dan lakukan pembayaran dalam satu dashboard.</p><a href="dashboard.php?menu=katalog" class="btn btn-pink">Lihat Katalog</a> <a href="dashboard.php?menu=pesanan" class="btn btn-outline-pink ms-2">Pesanan Saya</a></div><div class="col-lg-4 text-center"><i class="bi bi-flower2" style="font-size:8rem;color:#e7a2bf"></i></div></div></div>
<div class="row g-3 mb-4"><div class="col-md-4"><div class="stat-card"><div class="stat-icon mb-3"><i class="bi bi-bag-heart"></i></div><p class="text-secondary mb-1">Total Pesanan</p><h3><?= $totalPesanan ?></h3></div></div><div class="col-md-4"><div class="stat-card"><div class="stat-icon mb-3"><i class="bi bi-stars"></i></div><p class="text-secondary mb-1">Kebaya Tersedia</p><h3><?= count($katalog) ?></h3></div></div><div class="col-md-4"><div class="stat-card"><div class="stat-icon mb-3"><i class="bi bi-cart-heart"></i></div><p class="text-secondary mb-1">Jenis di Keranjang</p><h3><?= count($_SESSION['keranjang']) ?></h3></div></div></div>
<h4 class="fw-bold mb-3">Koleksi Kebaya</h4><div class="row g-3">
<?php foreach(array_slice($katalog,0,3) as $k): ?><div class="col-md-4"><div class="kebaya-card"><?php $foto=urlFoto($k['foto']??''); ?><?php if($foto): ?><img class="kebaya-photo" src="<?= e($foto) ?>" alt="<?= e($k['nama_kebaya']) ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='grid'"><div class="photo-placeholder" style="display:none"><i class="bi bi-flower1"></i></div><?php else: ?><div class="photo-placeholder"><i class="bi bi-flower1"></i></div><?php endif; ?><div class="p-3"><h5 class="fw-bold"><?= e($k['nama_kebaya']) ?></h5><p class="text-secondary"><?= e($k['warna']) ?> · Ukuran <?= e($k['ukuran']) ?></p><strong style="color:var(--dark)"><?= rupiah($k['harga_sewa']) ?></strong><a class="btn btn-pink w-100 mt-3" href="dashboard.php?menu=katalog">Lihat Detail</a></div></div></div><?php endforeach; ?>
<?php if(!$katalog): ?><div class="col-12"><div class="panel p-4">Belum ada kebaya tersedia.</div></div><?php endif; ?></div>
<?php elseif($menu==='katalog'): ?>
<div class="row g-3"><?php foreach($katalog as $k): ?><div class="col-sm-6 col-lg-4"><div class="kebaya-card"><?php $foto=urlFoto($k['foto']??''); ?><?php if($foto): ?><img class="kebaya-photo" src="<?= e($foto) ?>" alt="<?= e($k['nama_kebaya']) ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='grid'"><div class="photo-placeholder" style="display:none"><i class="bi bi-flower1"></i></div><?php else: ?><div class="photo-placeholder"><i class="bi bi-flower1"></i></div><?php endif; ?><div class="p-3"><h5 class="fw-bold"><?= e($k['nama_kebaya']) ?></h5><p class="small text-secondary mb-2"><?= e($k['nama_kategori']??'Tanpa kategori') ?></p><p class="mb-1">Warna: <?= e($k['warna']) ?></p><p class="mb-1">Ukuran: <?= e($k['ukuran']) ?></p><p class="mb-2">Stok: <?= (int)$k['stok'] ?></p><strong class="fs-5" style="color:var(--dark)"><?= rupiah($k['harga_sewa']) ?></strong><?php if(!empty($k['deskripsi'])): ?><p class="small text-secondary mt-2"><?= e($k['deskripsi']) ?></p><?php endif; ?><?php if((int)$k['stok']>0): ?><form method="post" class="mt-3"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="aksi" value="tambah_keranjang"><input type="hidden" name="id_kebaya" value="<?= (int)$k['id_kebaya'] ?>"><label class="form-label small">Jumlah</label><input class="form-control mb-2" type="number" name="jumlah" value="1" min="1" max="<?= (int)$k['stok'] ?>" required><button class="btn btn-pink w-100" type="submit"><i class="bi bi-cart-plus"></i> Tambah ke Keranjang</button></form><?php else: ?><button class="btn btn-secondary w-100 mt-3" disabled>Stok Habis</button><?php endif; ?></div></div></div><?php endforeach; ?><?php if(!$katalog): ?><div class="col-12"><div class="panel p-4">Belum ada kebaya tersedia.</div></div><?php endif; ?></div>
<?php elseif($menu==='keranjang'): ?>
<div class="panel p-3 p-lg-4"><h5 class="fw-bold mb-3">Isi Keranjang</h5><?php if(!$itemsKeranjang): ?><p class="text-secondary">Keranjang masih kosong.</p><a href="dashboard.php?menu=katalog" class="btn btn-pink">Lihat Katalog</a><?php else: ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="aksi" value="ubah_keranjang"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Kebaya</th><th>Harga</th><th>Jumlah</th><th>Subtotal</th><th>Hapus</th></tr></thead><tbody><?php foreach($itemsKeranjang as $item): ?><tr><td><?= e($item['nama_kebaya']) ?></td><td><?= rupiah($item['harga_sewa']) ?></td><td style="min-width:95px"><input class="form-control" type="number" name="jumlah[<?= (int)$item['id_kebaya'] ?>]" value="<?= (int)$item['jumlah'] ?>" min="0" max="<?= (int)$item['stok'] ?>"></td><td><?= rupiah($item['subtotal']) ?></td><td><button class="btn btn-sm btn-outline-danger" type="submit" formaction="dashboard.php?menu=keranjang" formmethod="post" name="aksi" value="hapus_keranjang" onclick="this.form.insertAdjacentHTML('beforeend','<input type=&quot;hidden&quot; name=&quot;id_kebaya&quot; value=&quot;<?= (int)$item['id_kebaya'] ?>&quot;>');">Hapus</button></td></tr><?php endforeach; ?></tbody></table></div><button class="btn btn-outline-pink" type="submit">Perbarui Keranjang</button></form><hr><div class="text-end"><p class="text-secondary mb-1">Total sementara</p><h4 class="fw-bold" style="color:var(--dark)"><?= rupiah($totalKeranjang) ?></h4></div><h5 class="fw-bold mt-4">Checkout</h5><p class="small text-secondary">Total dihitung dari harga sewa dikali jumlah barang. Belum dikalikan jumlah hari.</p><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="aksi" value="checkout"><div class="col-md-6"><label class="form-label">Tanggal Mulai</label><input type="date" name="tanggal_mulai" min="<?= date('Y-m-d') ?>" class="form-control" required></div><div class="col-md-6"><label class="form-label">Tanggal Selesai</label><input type="date" name="tanggal_selesai" min="<?= date('Y-m-d') ?>" class="form-control" required></div><div class="col-12"><label class="form-label">Catatan</label><textarea name="catatan" class="form-control" rows="3"></textarea></div><div class="col-12"><button class="btn btn-pink" type="submit">Buat Pesanan</button></div></form><?php endif; ?></div>
<?php elseif ($menu==='pesanan' || $menu==='pembayaran'): ?>
<div class="panel p-3 p-lg-4">
<?php if (!$pesanan): ?><p class="text-secondary">Belum ada pesanan.</p><a href="dashboard.php?menu=katalog" class="btn btn-pink">Pilih Kebaya</a>
<?php else: ?>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Kode & Tanggal</th><th>Periode Sewa</th><th>Total</th><th>Status Pesanan</th><th>DP</th><th>Pelunasan</th></tr></thead><tbody>
<?php foreach ($pesanan as $p): $dpAmount=round((float)$p['total']*.5,2); $sisa=max(0,(float)$p['total']-$dpAmount); ?>
<tr><td><strong><?= e($p['kode_penyewaan']) ?></strong><div class="small text-secondary"><?= e($p['tanggal_pesan']) ?></div></td><td><?= e($p['tanggal_mulai']) ?><br>s.d. <?= e($p['tanggal_selesai']) ?></td><td><?= rupiah($p['total']) ?></td><td><span class="badge rounded-pill badge-status"><?= e(ucwords(str_replace('_',' ',$p['status']))) ?></span><div class="small text-secondary mt-1">Fitting: <?= e($p['status_fitting'] ?: 'belum diajukan') ?></div></td>
<td><?php if (($p['status_dp']??'')==='diverifikasi'): ?><span class="badge text-bg-success">DP terverifikasi</span><?php elseif (($p['status_dp']??'')==='menunggu'): ?><span class="badge text-bg-warning">Menunggu verifikasi</span><?php elseif (($p['status_dp']??'')==='ditolak'): ?><span class="badge text-bg-danger">DP ditolak</span><?php else: ?><span class="text-secondary small">Belum dibayar</span><?php endif; ?></td>
<td><?php if (($p['status_pelunasan']??'')==='diverifikasi'): ?><span class="badge text-bg-success">Lunas</span><?php elseif (($p['status_pelunasan']??'')==='menunggu'): ?><span class="badge text-bg-warning">Menunggu verifikasi</span><?php elseif (($p['status_pelunasan']??'')==='ditolak'): ?><span class="badge text-bg-danger">Ditolak</span><?php else: ?><span class="text-secondary small">Belum lunas</span><?php endif; ?></td></tr>
<?php if ($menu==='pembayaran'): ?><tr id="bayar<?= (int)$p['id_penyewaan'] ?>"><td colspan="6"><div class="p-3 rounded-3" style="background:var(--light)"><h6 class="fw-bold">Pembayaran <?= e($p['kode_penyewaan']) ?></h6><p class="mb-1">Total sewa: <strong><?= rupiah($p['total']) ?></strong></p><p class="mb-1">DP 50%: <strong><?= rupiah($dpAmount) ?></strong></p><p class="mb-3">Sisa pelunasan: <strong><?= rupiah($sisa) ?></strong></p>
<?php if ($p['status']==='menunggu'): ?><div class="alert alert-info mb-0">Pesanan menunggu persetujuan admin. Pembayaran belum tersedia.</div>
<?php elseif ($p['status']==='ditolak'): ?><div class="alert alert-danger mb-0">Pesanan ditolak admin.</div>
<?php elseif (($p['status_fitting']??'')!=='disetujui'): ?><div class="alert alert-info mb-0">Pembayaran dibuka setelah admin menyetujui jadwal fitting.</div>
<?php elseif (($p['status_dp']??'')!=='diverifikasi'): ?>
<?php if (($p['status_dp']??'')==='menunggu'): ?><div class="alert alert-warning">Bukti DP sedang menunggu verifikasi admin.</div><?php endif; ?>
<?php if (($p['status_dp']??'')==='ditolak'): ?><div class="alert alert-danger">Bukti DP ditolak. Silakan unggah ulang. <?= e($p['catatan_dp']??'') ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="row g-3"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="aksi" value="unggah_bukti"><input type="hidden" name="id_penyewaan" value="<?= (int)$p['id_penyewaan'] ?>"><input type="hidden" name="tahap" value="dp"><div class="col-md-3"><label class="form-label">Tahap</label><input class="form-control" value="DP 50%" disabled></div><div class="col-md-3"><label class="form-label">Metode</label><select name="metode" class="form-select" required><option value="transfer_bank">Transfer Bank</option><option value="e_wallet">E-Wallet</option></select></div><div class="col-md-4"><label class="form-label">Bukti transfer (maks. 3 MB)</label><input type="file" name="bukti" class="form-control" accept=".jpg,.jpeg,.png,.webp" required></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-pink w-100" type="submit">Kirim DP</button></div></form>
<?php elseif (($p['status_pelunasan']??'')!=='diverifikasi'): ?>
<?php if (($p['status_pelunasan']??'')==='menunggu'): ?><div class="alert alert-warning">Bukti pelunasan sedang menunggu verifikasi admin.</div><?php endif; ?><?php if (($p['status_pelunasan']??'')==='ditolak'): ?><div class="alert alert-danger">Bukti pelunasan ditolak. Silakan unggah ulang.</div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="row g-3"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="aksi" value="unggah_bukti"><input type="hidden" name="id_penyewaan" value="<?= (int)$p['id_penyewaan'] ?>"><input type="hidden" name="tahap" value="pelunasan"><div class="col-md-3"><label class="form-label">Tahap</label><input class="form-control" value="Pelunasan" disabled></div><div class="col-md-3"><label class="form-label">Metode</label><select name="metode" class="form-select" required><option value="transfer_bank">Transfer Bank</option><option value="e_wallet">E-Wallet</option></select></div><div class="col-md-4"><label class="form-label">Bukti transfer (maks. 3 MB)</label><input type="file" name="bukti" class="form-control" accept=".jpg,.jpeg,.png,.webp" required></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-pink w-100" type="submit">Kirim Pelunasan</button></div></form>
<?php else: ?><div class="alert alert-success mb-0">Pembayaran lunas. Tunggu admin mengonfirmasi pengambilan kebaya.</div><?php endif; ?>
</div></td></tr><?php endif; ?>
<?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php if ($menu==='pembayaran'): ?><div class="panel p-4 mt-4"><h5 class="fw-bold">Informasi Rekening Pembayaran</h5><p class="text-secondary mb-0">SEABANK = 809897865675454 · DANA = 098098979789 · MANDIRI = 9872892928 · BCA = 0890989789797 · BNI = 6786767567.</p><small class="text-secondary">Pastikan rekening tujuan benar sebelum transfer.</small></div><?php endif; ?>
<?php elseif ($menu==='fitting'): ?>
<div class="row g-4"><div class="col-lg-5"><div class="panel p-4"><h5 class="fw-bold mb-3">Ajukan Jadwal Fitting</h5>
<?php if (!$pesananFit): ?><div class="alert alert-info">Fitting dapat diajukan setelah pesanan disetujui admin dan belum memiliki jadwal fitting.</div><?php else: ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="aksi" value="fitting"><div class="mb-3"><label class="form-label">Pesanan</label><select name="id_penyewaan" class="form-select" required><option value="">Pilih pesanan</option><?php foreach ($pesananFit as $pf): ?><option value="<?= (int)$pf['id_penyewaan'] ?>"><?= e($pf['kode_penyewaan']) ?> — <?= e($pf['tanggal_mulai']) ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">Tanggal</label><input type="date" name="tanggal_fitting" min="<?= date('Y-m-d') ?>" class="form-control" required></div><div class="mb-3"><label class="form-label">Jam</label><input type="time" name="jam_fitting" class="form-control" required></div><div class="mb-3"><label class="form-label">Catatan</label><textarea name="catatan" class="form-control" rows="3"></textarea></div><button class="btn btn-pink" type="submit">Ajukan Fitting</button></form><?php endif; ?></div></div>
<div class="col-lg-7"><div class="panel p-4"><h5 class="fw-bold mb-3">Riwayat Fitting</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Pesanan</th><th>Tanggal</th><th>Jam</th><th>Catatan</th><th>Status</th></tr></thead><tbody><?php foreach ($fitting as $f): ?><tr><td><?= e($f['kode_penyewaan'] ?? '-') ?></td><td><?= e($f['tanggal_fitting']) ?></td><td><?= e(substr($f['jam_fitting'],0,5)) ?></td><td><?= e($f['catatan']) ?></td><td><span class="badge rounded-pill badge-status"><?= e(ucwords(str_replace('_',' ',$f['status']))) ?></span></td></tr><?php endforeach; ?><?php if (!$fitting): ?><tr><td colspan="5" class="text-secondary text-center">Belum ada jadwal.</td></tr><?php endif; ?></tbody></table></div></div></div></div>
<?php elseif($menu==='profil'): ?>
<div class="row justify-content-center"><div class="col-lg-8"><div class="panel p-4 p-lg-5"><h5 class="fw-bold mb-4">Informasi Akun</h5><form method="post"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="aksi" value="profil"><div class="mb-3"><label class="form-label">Nama Lengkap</label><input class="form-control" name="nama" maxlength="100" value="<?= e($profil['nama']??'') ?>" required></div><div class="mb-3"><label class="form-label">Username</label><input class="form-control" value="<?= e($profil['username']??'') ?>" disabled></div><div class="mb-3"><label class="form-label">Nomor HP</label><input class="form-control" name="no_hp" value="<?= e($profil['no_hp']??'') ?>"></div><div class="mb-3"><label class="form-label">Alamat</label><textarea class="form-control" name="alamat" rows="4"><?= e($profil['alamat']??'') ?></textarea></div><button class="btn btn-pink" type="submit">Simpan Perubahan</button></form></div></div></div>
<?php endif; ?>
</main>
<footer class="container text-center py-4 small">© <?= date('Y') ?> KebayaRent · Sewa kebaya dengan mudah 🌸</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
