<?php
declare(strict_types=1);
require_once __DIR__ . '/V.php';

/**
 * Validasi backend untuk POST/PUT /api/layanan (PHP native, tanpa dependensi).
 * Mengembalikan array error; kosong = valid.
 * $isNameTaken: fn(string $nama): bool  (cek case-insensitive dilakukan oleh pemanggil)
 */
final class ValidateService
{
    private const DUR_MAX = ['Menit' => 525600, 'Jam' => 8760, 'Hari' => 365];
    private const EST_UNITS = ['Menit', 'Jam', 'Hari', 'Minggu'];

    public static function validate(mixed $b, callable $isNameTaken): array
    {
        $e = [];
        $b = is_array($b) ? $b : [];

        $nama = V::str($b['nama'] ?? null);
        if ($nama === '') $e['nama'] = 'Nama layanan wajib diisi.';
        elseif (V::len($nama) < 3 || V::len($nama) > 100) $e['nama'] = 'Nama layanan harus 3-100 karakter.';
        elseif ($isNameTaken($nama)) $e['nama'] = 'Nama layanan sudah digunakan.';

        $jenis = $b['jenis_harga'] ?? null;
        if (!in_array($jenis, ['tunggal', 'pilihan'], true)) $e['jenis_harga'] = 'Jenis harga tidak valid.';

        if ($jenis === 'tunggal') {
            $h = $b['harga_tunggal'] ?? null;
            if (!V::isInt($h) || $h < 1) $e['harga_tunggal'] = 'Harga harus integer minimal 1.';
        }

        if ($jenis === 'pilihan') {
            $gs = $b['harga_pilihan'] ?? null;
            if (!V::isList($gs) || count($gs) !== 1) {
                $e['harga_pilihan'] = 'Harus ada tepat 1 kelompok harga.';
            } else {
                foreach ($gs as $i => $g) {
                    $k = "harga_pilihan[$i]";
                    if (V::str(V::get($g, 'nama')) === '') $e["$k.nama"] = 'Nama kelompok wajib diisi.';
                    $ps = V::get($g, 'pilihan');
                    if (!V::isList($ps) || !count($ps)) { $e["$k.pilihan"] = 'Minimal 1 pilihan.'; continue; }
                    $seen = [];
                    foreach ($ps as $j => $p) {
                        $kk = "$k.pilihan[$j]";
                        $n = V::lower(V::str(V::get($p, 'nama')));
                        if ($n === '') $e["$kk.nama"] = 'Nama pilihan wajib diisi.';
                        elseif (isset($seen[$n])) $e["$kk.nama"] = 'Nama pilihan duplikat dalam satu kelompok.';
                        $seen[$n] = true;
                        $h = V::get($p, 'harga');
                        if (!V::isInt($h) || $h < 1) $e["$kk.harga"] = 'Harga harus integer minimal 1.';
                    }
                }
            }
        }

        if (!in_array($b['keterangan_harga'] ?? null, ['layanan', 'foto'], true))
            $e['keterangan_harga'] = 'Keterangan harga tidak valid.';

        $d = $b['durasi'] ?? null;
        $dn = V::get($d, 'nilai'); $ds = V::get($d, 'satuan');
        if (!is_array($d) || !V::isInt($dn) || $dn < 1 || !is_string($ds)
            || !array_key_exists($ds, self::DUR_MAX) || $dn > self::DUR_MAX[$ds])
            $e['durasi'] = 'Durasi harus integer positif, maksimal 365 hari.';

        if (($b['estimasi_hasil'] ?? null) !== null) {
            $s = $b['estimasi_hasil'];
            $sn = V::get($s, 'nilai'); $su = V::get($s, 'satuan');
            if (!is_array($s) || !V::isInt($sn) || $sn < 1 || !in_array($su, self::EST_UNITS, true))
                $e['estimasi_hasil'] = 'Estimasi tidak valid.';
        }

        $mo = $b['maks_orang'] ?? null;
        if ($mo !== null && (!V::isInt($mo) || $mo < 1 || $mo > 999))
            $e['maks_orang'] = 'Maksimal orang harus integer 1-999 atau null.';

        if (($b['deskripsi'] ?? null) !== null) {
            $t = V::str($b['deskripsi']);
            if ($t === '' || V::len($t) > 1000) $e['deskripsi'] = 'Deskripsi tidak boleh kosong/spasi dan maksimal 1000 karakter.';
        }

        $out = $b['output'] ?? null;
        if (!V::isList($out) || !count($out)) {
            $e['output'] = 'Minimal 1 output.';
        } else {
            foreach ($out as $i => $o) {
                $k = "output[$i]";
                if (V::str(V::get($o, 'nama')) === '') $e["$k.nama"] = 'Nama output wajib diisi.';
                $tipe = V::get($o, 'tipe');
                if (!in_array($tipe, ['non', 'pilihan'], true)) $e["$k.tipe"] = 'Tipe output tidak valid.';
                if ($tipe === 'pilihan') {
                    $subs = V::get($o, 'sub_pilihan');
                    if (!V::isList($subs) || !count($subs)) { $e["$k.sub_pilihan"] = 'Minimal 1 sub pilihan.'; continue; }
                    $seen = [];
                    foreach ($subs as $j => $s) {
                        $n = V::lower(V::str($s));
                        if ($n === '') $e["$k.sub_pilihan[$j]"] = 'Nama sub pilihan wajib diisi.';
                        elseif (isset($seen[$n])) $e["$k.sub_pilihan[$j]"] = 'Nama sub pilihan duplikat.';
                        $seen[$n] = true;
                    }
                }
            }
        }

        if (!is_bool($b['kebutuhan_jadwal'] ?? null)) $e['kebutuhan_jadwal'] = 'Wajib memilih Ya/Tidak.';
        if (!is_bool($b['tampil_publik'] ?? null)) $e['tampil_publik'] = 'Wajib memilih Ya/Tidak.';
        return $e;
    }
}
