<?php
declare(strict_types=1);
require_once __DIR__ . '/ValidatePemesanan.php';

/** Data papan alur: pemesanan dikelompokkan per tahap (urutan = ValidatePemesanan::FLOW). */
final class Workflow
{
    public static function board(array $db, string $q = ''): array
    {
        $term = V::lower(V::str($q));
        $kolom = [];
        foreach (ValidatePemesanan::FLOW as $s) $kolom[$s] = [];

        $rows = $db['pemesanan'];
        usort($rows, fn($a, $b) => strtotime($a['jadwal']) <=> strtotime($b['jadwal']));   // jadwal terdekat di atas

        foreach ($rows as $o) {
            if (!isset($kolom[$o['status']])) continue;
            if ($term !== '' && !str_contains(V::lower($o['nama']), $term) && !str_contains(V::lower($o['kode']), $term)) continue;
            $kolom[$o['status']][] = [
                'id' => $o['id'], 'kode' => $o['kode'], 'nama' => $o['nama'], 'layanan' => $o['layanan'],
                'jadwal' => $o['jadwal'], 'status' => $o['status'], 'bayar' => $o['bayar'],
                'status_berikutnya' => ValidatePemesanan::next($o['status']),
            ];
        }

        $columns = [];
        foreach ($kolom as $status => $items)
            $columns[] = ['status' => $status, 'total' => count($items), 'items' => $items];
        return ['columns' => $columns];
    }
}