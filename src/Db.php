<?php
declare(strict_types=1);

/**
 * Penyimpanan JSON berkas tunggal dengan flock (aman untuk request bersamaan).
 * Untuk produksi, ganti dengan MySQL/PostgreSQL via PDO; antarmuka read()/write() cukup diganti.
 */
final class Db
{
    private const EMPTY = ['layanan' => [], 'addon' => [], 'gallery' => [], 'pelanggan' => []];
    
    private static function file(): string { return dirname(__DIR__) . '/data/db.json'; }

    private static function decode(string $raw): array
    {
        $d = $raw === '' ? [] : json_decode($raw, true);
        return array_merge(self::EMPTY, is_array($d) ? $d : []);
    }

    public static function read(): array
    {
        $fh = fopen(self::file(), 'c+');
        flock($fh, LOCK_SH);
        $db = self::decode((string) stream_get_contents($fh));
        flock($fh, LOCK_UN); fclose($fh);
        return $db;
    }

    /**
     * Jalankan $fn(array &$db): array{0:int,1:array} di bawah kunci eksklusif.
     * Data disimpan hanya bila status < 400. Mengembalikan [status, body].
     */
    public static function write(callable $fn): array
    {
        $fh = fopen(self::file(), 'c+');
        flock($fh, LOCK_EX);
        try {
            $db = self::decode((string) stream_get_contents($fh));
            [$code, $body] = $fn($db);
            if ($code < 400) {
                ftruncate($fh, 0); rewind($fh);
                fwrite($fh, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                fflush($fh);
            }
            return [$code, $body];
        } finally {
            flock($fh, LOCK_UN); fclose($fh);
        }
    }
}
