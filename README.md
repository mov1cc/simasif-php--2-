# SIMASIF - Layanan (frontend HTML/Tailwind + backend PHP native)

Backend: PHP 8.1+ murni, tanpa framework dan tanpa Composer. Penyimpanan: berkas JSON (data/db.json) dengan flock.

## Menjalankan
Server bawaan PHP (paling mudah):

    php -S localhost:8000 -t public router.php

Buka http://localhost:8000

Apache / XAMPP / Laragon: arahkan document root ke folder `public/` (mod_rewrite aktif, AllowOverride All).
Pastikan folder `data/` dan `public/uploads/` bisa ditulis oleh PHP.

## Struktur
- public/*.html            halaman (Layanan, FormTambahLayanan, FormAddon, Galeri)
- public/api/index.php     front controller semua endpoint /api/*
- src/ValidateService.php  validator layanan (port dari ValidateService.js)
- src/ValidateAddOn.php    validator add on (port dari Validateaddon.js)
- src/Db.php               penyimpanan JSON + kunci berkas
- router.php               router untuk `php -S`

## Endpoint
GET/POST /api/layanan; GET/PUT/PATCH/DELETE /api/layanan/{id}
GET/POST /api/add-on?layanan_id=; PUT /api/add-on/order; GET/PUT/PATCH/DELETE /api/add-on/{id}
GET/POST /api/layanan/{id}/gallery; PUT .../gallery/order; PUT/DELETE .../gallery/{gid}; PUT .../gallery/{gid}/image

Validasi gagal = HTTP 422 `{ "errors": { "kolom": "pesan" } }`.

## Catatan
- Untuk produksi: tambahkan autentikasi dan ganti Db.php dengan MySQL/PostgreSQL (PDO).
- Folder uploads dilindungi .htaccess agar skrip tidak bisa dieksekusi.
