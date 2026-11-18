<?php
declare(strict_types=1);
/** Penjaga halaman: hanya menyajikan *.html bila sudah login, selain itu dialihkan ke Login.html. */
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/Auth.php';

if (!function_exists('findIdx')) {
    function findIdx(array $rows, string $id): ?int
    {
        foreach ($rows as $i => $r) if ($r['id'] === $id) return $i;
        return null;
    }
}

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$name = basename($path);
$file = __DIR__ . '/' . $name;

header('Cache-Control: no-store');   // cegah halaman muncul dari cache lewat tombol Back setelah logout

if (!preg_match('/^[A-Za-z0-9_-]+\.html$/', $name) || !is_file($file)) {
    http_response_code(404);
    echo 'Halaman tidak ditemukan.';
    exit;
}

if (Auth::user(Db::read()) === null) {
    header('Location: Login.html');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
readfile($file);