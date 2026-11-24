<?php
declare(strict_types=1);
/** Penyaji halaman: auth dicek dulu, baru file dicari dan dikirim. */
require_once dirname(__DIR__) . '/src/Guard.php';

const HALAMAN_PUBLIK = ['login.html', 'lupapassword.html', 'halamanpelanggan.html'];

$name = basename((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$valid = preg_match('/^[A-Za-z0-9_-]+\.html$/', $name) === 1;

if ($valid && in_array(strtolower($name), HALAMAN_PUBLIK, true)) {
    Guard::noStore();
    $file = __DIR__ . '/' . $name;                       // halaman publik
} else {
    Guard::requireLogin();                               // 1) cek auth, belum login => redirect + exit
    $file = dirname(__DIR__) . '/pages/' . $name;        // 2) baru cari file
}

if (!$valid || !is_file($file)) {
    http_response_code(404);
    echo 'Halaman tidak ditemukan.';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
readfile($file);