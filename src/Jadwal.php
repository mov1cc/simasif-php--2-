<?php
declare(strict_types=1);
require_once __DIR__ . '/ValidatePemesanan.php';

/** Data untuk halaman Jadwal: pemesanan yang memakai slot waktu, per rentang tanggal. */
final class Jadwal
{
    public const BUKA = 8, TUTUP = 18;      // jam operasional (WIB)
    public const MAKS_HARI = 62;            // batas rentang satu request

    /** Validasi query; mengembalikan array error (kosong = valid). */
    public static function validate(string $dari, string $sampai): array
    {
        $e = [];
        foreach (['dari' => $dari, 'sampai' => $sampai] as $k => $v) {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))
                $e[$k] = "Parameter $k wajib berformat YYYY-MM-DD yang valid.";
        }
        if ($e) return $e;
        if ($dari > $sampai) return ['sampai' => 'Tanggal sampai tidak boleh sebelum tanggal dari.'];
        $selisih = (new DateTimeImmutable($dari))->diff(new DateTimeImmutable($sampai))->days;
        if ($selisih > self::MAKS_HARI) return ['sampai' => 'Rentang maksimal ' . self::MAKS_HARI . ' hari.'];
        return [];
    }

    private static function jam(int $menit): string
    {
        return sprintf('%02d:%02d', intdiv($menit, 60) % 24, $menit % 60);
    }

    /** Pemesanan (layanan yang butuh jadwal) pada rentang tanggal, urut waktu mulai. */
    public static function rows(array $db, string $dari, string $sampai): array
    {
        $tz = new DateTimeZone('Asia/Jakarta');
        $lay = [];
        foreach ($db['layanan'] as $l) $lay[$l['id']] = $l;

        $rows = [];
        foreach ($db['pemesanan'] as $o) {
            $l = $lay[$o['layanan_id']] ?? null;
            if ($l && !$l['kebutuhan_jadwal']) continue;       // layanan tanpa jadwal tidak masuk kalender

            $d = (new DateTimeImmutable($o['jadwal']))->setTimezone($tz);
            $tgl = $d->format('Y-m-d');
            if ($tgl < $dari || $tgl > $sampai) continue;

            $mulai = (int) $d->format('G') * 60 + (int) $d->format('i');
            $dur = (int) $o['durasi_menit'];
            $rows[] = [
                'id' => $o['id'], 'kode' => $o['kode'], 'nama' => $o['nama'], 'nomor' => $o['nomor'],
                'layanan' => $o['layanan'], 'tgl' => $tgl,
                'jam' => self::jam($mulai), 'akhir' => self::jam($mulai + $dur),
                'mulai' => $mulai, 'selesai' => $mulai + $dur, 'durasi' => $dur,
                'status' => $o['status'], 'bayar' => $o['bayar'],
            ];
        }
        usort($rows, fn($a, $b) => [$a['tgl'], $a['mulai']] <=> [$b['tgl'], $b['mulai']]);
        return $rows;
    }

    /** Konfigurasi kalender; min_durasi = durasi layanan tersingkat (dasar penentuan "hari penuh"). */
    public static function config(array $db): array
    {
        $min = null;
        foreach ($db['layanan'] as $l) {
            if (!$l['aktif'] || !$l['kebutuhan_jadwal']) continue;
            $m = ValidatePemesanan::toMenit($l['durasi']);
            if ($m > 0 && ($min === null || $m < $min)) $min = $m;
        }
        return ['buka' => self::BUKA, 'tutup' => self::TUTUP, 'min_durasi' => $min ?? 15];
    }
}