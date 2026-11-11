<?php
declare(strict_types=1);

/** Helper kecil untuk validator (padanan isInt / str / get di versi JS). */
final class V
{
    public static function isInt(mixed $v): bool { return is_int($v); }

    /** String ter-trim; non-string => ''. */
    public static function str(mixed $v): string
    {
        return is_string($v) ? preg_replace('/^\s+|\s+$/u', '', $v) ?? '' : '';
    }

    public static function len(string $s): int { return mb_strlen($s, 'UTF-8'); }
    public static function lower(string $s): string { return mb_strtolower($s, 'UTF-8'); }

    /** Ambil $a[$k] bila $a array, selain itu null. */
    public static function get(mixed $a, string $k): mixed
    {
        return is_array($a) ? ($a[$k] ?? null) : null;
    }

    /** Padanan Array.isArray (JSON array = list). */
    public static function isList(mixed $a): bool
    {
        if (!is_array($a)) return false;
        $i = 0;
        foreach ($a as $k => $_) { if ($k !== $i++) return false; }
        return true;
    }
}
