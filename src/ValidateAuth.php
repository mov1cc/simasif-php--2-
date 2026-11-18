<?php
declare(strict_types=1);
require_once __DIR__ . '/V.php';

/** Validasi backend untuk /api/auth/login. Array error kosong = valid. */
final class ValidateAuth
{
    public const PW_MIN = 8;
    public const PW_MAX = 72;   // batas bcrypt (byte)

    /** Email kanonik: ter-trim dan huruf kecil. */
    public static function email(mixed $v): string
    {
        return V::lower(V::str($v));
    }

    public static function emailError(string $e): string
    {
        if ($e === '') return 'Email wajib diisi.';
        if (strlen($e) > 254 || !filter_var($e, FILTER_VALIDATE_EMAIL))
            return 'Format email belum benar, contoh: nama@studio.com.';
        return '';
    }

    public static function passwordError(string $p): string
    {
        if ($p === '') return 'Password baru wajib diisi.';
        if (strlen($p) < self::PW_MIN) return 'Password minimal ' . self::PW_MIN . ' karakter.';
        if (strlen($p) > self::PW_MAX) return 'Password maksimal ' . self::PW_MAX . ' karakter.';
        if (!preg_match('/\p{L}/u', $p) || !preg_match('/\d/', $p)) return 'Password harus mengandung huruf dan angka.';
        return '';
    }

    public static function login(mixed $b): array
    {
        $e = [];
        $b = is_array($b) ? $b : [];
        if ($m = self::emailError(self::email($b['email'] ?? null))) $e['email'] = $m;
        $p = $b['password'] ?? null;
        if (!is_string($p) || $p === '') $e['password'] = 'Password wajib diisi.';
        elseif (strlen($p) > 128) $e['password'] = 'Password terlalu panjang.';
        return $e;
    }
}