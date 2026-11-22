<?php
declare(strict_types=1);
require_once __DIR__ . '/ValidatePemesanan.php';
require_once __DIR__ . '/TransaksiApi.php';

/** Data ringkasan untuk halaman Dashboard (GET /api/dashboard). */
final class Dashboard
{
    public const HARI_HEATMAP = 85;   // 17 kolom x 5 baris
    public const MAKS_JADWAL = 2;
    public const MAKS_TERBARU = 4;
    public const MAKS_VERIF = 5;
    public const MAKS_AKTIVITAS = 4;

    public static function data(array $db, array $user): array
    {
        $tz = new DateTimeZone('Asia/Jakarta');
        $now = new DateTimeImmutable('now', $tz);
        $today = $now->format('Y-m-d');
        $tgl = fn(string $iso): string => (new DateTimeImmutable($iso))->setTimezone($tz)->format('Y-m-d');

        $rows = $db['pemesanan'];
        $lay = [];
        foreach ($db['layanan'] as $l) $lay[$l['id']] = $l;

        /* ---- statistik ---- */
        $n = fn(callable $f): int => count(array_filter($rows, $f));
        $stats = [
            'total' => count($rows),
            'terjadwal_total' => $n(fn($o) => $o['status'] === 'Terjadwal'),
            'terjadwal_hari_ini' => $n(fn($o) => $o['status'] === 'Terjadwal' && $tgl($o['jadwal']) === $today),
            'baru_hari_ini' => $n(fn($o) => $tgl($o['dibuat']) === $today),
            'berjalan' => $n(fn($o) => in_array($o['status'], ['Sesi Foto', 'Proses Editing'], true)),
            'hasil_siap' => $n(fn($o) => $o['status'] === 'Hasil Siap'),
        ];

        /* ---- jadwal hari ini (layanan yang butuh jadwal, belum selesai, paling awal dulu) ---- */
        $jadwal = array_values(array_filter($rows, function ($o) use ($tgl, $today, $lay) {
            $l = $lay[$o['layanan_id']] ?? null;
            return $o['status'] !== 'Selesai' && $tgl($o['jadwal']) === $today && (!$l || $l['kebutuhan_jadwal']);
        }));
        usort($jadwal, fn($a, $b) => strtotime($a['jadwal']) <=> strtotime($b['jadwal']));
        $jadwal = array_map(fn($o) => array_intersect_key($o, array_flip(['id', 'kode', 'nama', 'layanan', 'jadwal', 'status'])),
            array_slice($jadwal, 0, self::MAKS_JADWAL));

        /* ---- heatmap: pelanggan berbeda yang ditangani pegawai login per hari ---- */
        $perHari = [];
        foreach ($rows as $o)
            foreach ($o['history'] ?? [] as $h)
                if (($h['aktor'] ?? '') === $user['nama']) $perHari[$tgl($h['waktu'])][$o['pelanggan_id']] = true;

        $hari = []; $unik = [];
        for ($d = self::HARI_HEATMAP - 1; $d >= 0; $d--) {
            $k = $now->modify("-$d days")->format('Y-m-d');
            $ids = array_keys($perHari[$k] ?? []);
            foreach ($ids as $id) $unik[$id] = true;
            $hari[] = ['tgl' => $k, 'jumlah' => count($ids)];
        }

        /* ---- pemesanan terbaru ---- */
        $baru = $rows;
        usort($baru, fn($a, $b) => strtotime($b['dibuat']) <=> strtotime($a['dibuat']));
        $baru = array_map(fn($o) => array_intersect_key($o, array_flip(['id', 'kode', 'nama', 'layanan', 'dibuat'])),
            array_slice($baru, 0, self::MAKS_TERBARU));

        /* ---- antrean verifikasi pembayaran (yang paling lama menunggu di atas) ---- */
        $verif = array_values(array_filter($rows, fn($o) => ($o['bayar'] ?? '') === 'Menunggu Verifikasi'));
        usort($verif, fn($a, $b) => strtotime($a['dibuat']) <=> strtotime($b['dibuat']));
        $verifTotal = count($verif);
        $verif = array_map(fn($o) => ['id' => $o['id'], 'kode' => $o['kode'], 'nama' => $o['nama'],
            'metode' => TransaksiApi::metodeOf($o)], array_slice($verif, 0, self::MAKS_VERIF));

        /* ---- aktivitas terbaru (riwayat status semua pemesanan) ---- */
        $akt = []; $seq = 0;
        foreach ($rows as $o)
            foreach ($o['history'] ?? [] as $h)
                $akt[] = ['kode' => $o['kode'], 'status' => $h['status'], 'waktu' => $h['waktu'], 'seq' => $seq++,
                    'oleh' => ($h['aktor'] ?? '') === 'Pelanggan/Sistem' ? 'Pelanggan / sistem' : ($h['aktor'] ?? '-') . ' / Pegawai'];
        usort($akt, fn($a, $b) => [strtotime($b['waktu']), $b['seq']] <=> [strtotime($a['waktu']), $a['seq']]);   // terbaru dulu
        $akt = array_map(fn($a) => array_diff_key($a, ['seq' => 1]), array_slice($akt, 0, self::MAKS_AKTIVITAS));

        return [
            'user' => Auth::publicUser($user),
            'stats' => $stats,
            'jadwal_hari_ini' => $jadwal,
            'heatmap' => ['hari' => $hari, 'pelanggan_unik' => count($unik)],
            'terbaru' => $baru,
            'verifikasi' => ['total' => $verifTotal, 'items' => $verif],
            'aktivitas' => $akt,
        ];
    }
}