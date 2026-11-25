<?php
declare(strict_types=1);

/**
 * Endpoint publik (tanpa login) untuk HalamanPelanggan:
 *   GET /api/publik/layanan        daftar layanan yang ditampilkan ke publik
 *   GET /api/publik/layanan/{id}   detail satu layanan (harga pilihan, add on, galeri)
 * Memakai fungsi global index.php: out(), findIdx(), coverOf(), gIdx().
 * Hanya field yang aman dikirim; data internal (sesi, dibuat, aktif, dll.) tidak ikut.
 */
final class PublikApi
{
    public static function handle(string $m, string $p): void
    {
        if ($m !== 'GET') return;
        if ($p === '/api/publik/layanan') self::index();
        if (preg_match('#^/api/publik/layanan/([\w-]+)$#', $p, $r)) self::show($r[1]);
    }

    private static function tampil(array $l): bool
    {
        return !empty($l['tampil_publik']) && !empty($l['aktif']);
    }

    private static function harga(array $l): array
    {
        if ($l['jenis_harga'] === 'tunggal') {
            return ['harga' => (int) $l['harga_tunggal'], 'mulai_dari' => false];
        }
        $all = [];
        foreach ($l['harga_pilihan'] as $g) foreach ($g['pilihan'] as $x) $all[] = (int) $x['harga'];
        return ['harga' => $all ? min($all) : 0, 'mulai_dari' => true];
    }

    private static function card(array $db, array $l): array
    {
        return [
            'id' => $l['id'],
            'nama' => $l['nama'],
            'cover' => coverOf($db, $l['id']),
            'jenis_harga' => $l['jenis_harga'],
            'keterangan_harga' => $l['keterangan_harga'],
            'durasi' => $l['durasi'],
            'estimasi_hasil' => $l['estimasi_hasil'] ?? null,
            'maks_orang' => $l['maks_orang'] ?? null,
            'output' => array_map(fn($o) => $o['nama'], $l['output']),
        ] + self::harga($l);
    }

    private static function index(): never
    {
        $db = Db::read();
        $rows = array_values(array_filter($db['layanan'], [self::class, 'tampil']));
        usort($rows, fn($a, $b) => strcmp((string) $a['dibuat'], (string) $b['dibuat']));   // terlama di atas
        header('Cache-Control: public, max-age=30');
        out(200, ['data' => array_map(fn($l) => self::card($db, $l), $rows)]);
    }

    private static function show(string $id): never
{
    $db = Db::read();
    $i = findIdx($db['layanan'], $id);
    if ($i === null || !self::tampil($db['layanan'][$i])) out(404, ['error' => 'Layanan tidak ditemukan.']);
    $l = $db['layanan'][$i];

    $addons = array_values(array_filter($db['addon'], fn($a) => $a['layanan_id'] === $id && !empty($a['aktif'])));
    usort($addons, fn($a, $b) => $a['urutan'] <=> $b['urutan']);

    header('Cache-Control: public, max-age=30');
    out(200, ['data' => self::card($db, $l) + [
        'deskripsi' => $l['deskripsi'] ?? null,
        'kebutuhan_jadwal' => (bool) ($l['kebutuhan_jadwal'] ?? false),
        'harga_tunggal' => $l['harga_tunggal'],
        'harga_pilihan' => $l['harga_pilihan'],
        'output_detail' => $l['output'],
        'addons' => array_map(fn($a) => [
            'id' => $a['id'],
            'nama' => $a['nama'], 'jenis_harga' => $a['jenis_harga'], 'harga_tunggal' => $a['harga_tunggal'],
            'keterangan_harga' => $a['keterangan_harga'], 'pilihan_harga' => $a['pilihan_harga'],
            'deskripsi' => $a['deskripsi'], 'durasi' => $a['durasi'],
        ], $addons),
        'galeri' => array_map(fn($k) => ['url' => $db['gallery'][$k]['url'], 'caption' => $db['gallery'][$k]['caption']], gIdx($db, $id)),
    ]]);
}
}