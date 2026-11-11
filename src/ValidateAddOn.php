<?php
declare(strict_types=1);
require_once __DIR__ . '/V.php';

/**
 * Validasi backend untuk POST/PUT /api/add-on (PHP native, tanpa dependensi).
 * Mengembalikan array error; kosong = valid.
 */
final class ValidateAddOn
{
    private const DUR_MAX = ['Menit' => 525600, 'Jam' => 8760, 'Hari' => 365];
    private const KET = ['non', 'foto', 'pcs'];

    public static function validate(mixed $b, callable $isNameTaken): array
    {
        $e = [];
        $b = is_array($b) ? $b : [];

        $nama = V::str($b['nama'] ?? null);
        if ($nama === '') $e['nama'] = 'Nama add on wajib diisi.';
        elseif (V::len($nama) < 3 || V::len($nama) > 100) $e['nama'] = 'Nama add on harus 3-100 karakter.';
        elseif ($isNameTaken($nama)) $e['nama'] = 'Nama add on sudah digunakan.';

        $jenis = $b['jenis_harga'] ?? null;
        if (!in_array($jenis, ['tunggal', 'pilihan'], true)) $e['jenis_harga'] = 'Jenis harga tidak valid.';

        if ($jenis === 'tunggal') {
            $h = $b['harga_tunggal'] ?? null;
            if (!V::isInt($h) || $h < 1) $e['harga_tunggal'] = 'Harga harus integer minimal 1.';
            if (!in_array($b['keterangan_harga'] ?? null, self::KET, true)) $e['keterangan_harga'] = 'Keterangan harga tidak valid.';
        }

        if ($jenis === 'pilihan') {
            $ps = $b['pilihan_harga'] ?? null;
            if (!V::isList($ps) || !count($ps)) {
                $e['pilihan_harga'] = 'Minimal 1 pilihan harga.';
            } else {
                $seen = [];
                foreach ($ps as $i => $p) {
                    $k = "pilihan_harga[$i]";
                    $n = V::lower(V::str(V::get($p, 'nama')));
                    if ($n === '') $e["$k.nama"] = 'Nama pilihan wajib diisi.';
                    elseif (isset($seen[$n])) $e["$k.nama"] = 'Nama pilihan duplikat.';
                    $seen[$n] = true;
                    $h = V::get($p, 'harga');
                    if (!V::isInt($h) || $h < 1) $e["$k.harga"] = 'Harga harus integer minimal 1.';
                    if (!in_array(V::get($p, 'keterangan'), self::KET, true)) $e["$k.keterangan"] = 'Keterangan tidak valid.';
                }
            }
        }

        if (($b['deskripsi'] ?? null) !== null) {
            $t = V::str($b['deskripsi']);
            if ($t === '' || V::len($t) > 1000) $e['deskripsi'] = 'Deskripsi tidak boleh kosong/spasi dan maksimal 1000 karakter.';
        }

        if (($b['durasi'] ?? null) !== null) {
            $d = $b['durasi'];
            $dn = V::get($d, 'nilai'); $ds = V::get($d, 'satuan');
            if (!is_array($d) || !V::isInt($dn) || $dn < 1 || !is_string($ds)
                || !array_key_exists($ds, self::DUR_MAX) || $dn > self::DUR_MAX[$ds])
                $e['durasi'] = 'Durasi harus integer positif, maksimal 365 hari.';
        }
        return $e;
    }
}
