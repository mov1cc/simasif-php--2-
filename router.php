<?php
// Router untuk server bawaan PHP:  php -S localhost:8000 -t public router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/')) { require __DIR__ . '/public/api/index.php'; return true; }
if ($path === '/') { header('Location: /Layanan.html'); return true; }
return false;   // selain itu: layani file statis apa adanya
