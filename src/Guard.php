<?php
declare(strict_types=1);
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';

if (!function_exists('findIdx')) {   // dipakai Auth::user()
    function findIdx(array $rows, string $id): ?int
    {
        foreach ($rows as $i => $r) if ($r['id'] === $id) return $i;
        return null;
    }
}

/** Auth guard: panggil SEBELUM ada file/view yang dibaca atau output yang dikirim. */
final class Guard
{
    public static function noStore(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Vary: Cookie');
    }

    /** Mengembalikan user yang login; jika belum login: 302 ke Login.html lalu exit (tanpa body). */
    public static function requireLogin(): array
    {
        self::noStore();
        $u = Auth::user(Db::read());
        if ($u === null) {
            http_response_code(302);
            header('Location: Login.html');
            exit;
        }
        return $u;
    }
}