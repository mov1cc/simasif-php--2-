<?php
// php -S localhost:8000 -t public router.php
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (str_starts_with($path, '/api/')) { require __DIR__ . '/public/api/index.php'; return true; }
if ($path === '/') { header('Location: /Layanan.html'); return true; }

// Whitelist file statis (tidak peka huruf besar/kecil); yang lain WAJIB lewat gate
$statis = preg_match('#^/(js/[\w.-]+\.js|uploads/[\w.-]+\.(jpe?g|png|webp)|login\.html|lupapassword\.html)$#i', $path) === 1;
if ($statis && !str_contains($path, '..')) return false;

require __DIR__ . '/public/gate.php';
return true;