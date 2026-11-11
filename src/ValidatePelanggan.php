<?php
declare(strict_types=1);
require_once __DIR__ . '/V.php';

/** Validasi backend untuk POST/PUT /api/pelanggan. Array error kosong = valid. */
final class ValidatePelanggan
{
    /** Rapikan spasi ganda pada nama. */
    public static function cleanNama(mixed $v): string
    {
        return preg_replace('/\s+/u', ' ', V::str($v)) ?? '';
    }

    /** Bentuk kanonik nomor: +62812... dianggap sama dengan 0812... */
    public static function canon(string $n): string
    {
        return preg_replace('/^\+62/', '0', $n) ?? $n;
    }

    public static function validate(mixed $b, callable $isNumberTaken): array
    {
        $e = [];
        $b = is_array($b) ? $b : [];

        $nama = self::cleanNama($b['nama'] ?? null);
        if ($nama === '') $e['nama'] = 'Nama pelanggan wajib diisi.';
        elseif (!preg_match('/^[\p{L}\s]+$/u', $nama)) $e['nama'] = 'Nama hanya boleh berisi huruf dan spasi.';
        elseif (V::len($nama) < 3 || V::len($nama) > 100) $e['nama'] = 'Nama harus 3-100 karakter.';

        $nomor = V::str($b['nomor'] ?? null);
        if ($nomor === '') $e['nomor'] = 'Nomor telepon wajib diisi.';
        elseif (!preg_match('/^\+?\d+$/', $nomor)) $e['nomor'] = 'Nomor hanya boleh berisi angka, tanpa huruf, spasi, atau simbol lain.';
        elseif (!preg_match('/^(\+62|08)/', $nomor)) $e['nomor'] = 'Nomor harus diawali +62 atau 08.';
        elseif (strlen(ltrim($nomor, '+')) < 10 || strlen(ltrim($nomor, '+')) > 15) $e['nomor'] = 'Nomor harus 10-15 digit.';
        elseif ($isNumberTaken($nomor)) $e['nomor'] = 'Nomor telepon sudah terdaftar.';

        return $e;
    }
}