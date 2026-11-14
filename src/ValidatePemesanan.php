<?php
declare(strict_types=1);
require_once __DIR__ . '/V.php';

/** Validasi + pembentukan data untuk POST /api/pemesanan dan PATCH .../status. */
final class ValidatePemesanan
{
    public const FLOW = ['Pemesanan Dibuat', 'Menunggu Pembayaran', 'Pembayaran Diverifikasi', 'Terjadwal',
                         'Sesi Foto', 'Proses Editing', 'Hasil Siap', 'Selesai'];
    public const BAYAR = ['Belum Dibayar', 'Menunggu Verifikasi', 'Lunas', 'Ditolak'];
    private const MENIT = ['Menit' => 1, 'Jam' => 60, 'Hari' => 1440, 'Minggu' => 10080];

    public static function next(string $s): ?string
    {
        $i = array_search($s, self::FLOW, true);
        return $i === false ? null : (self::FLOW[$i + 1] ?? null);
    }

    public static function toMenit(mixed $d): int
    {
        return is_array($d) ? (int) $d['nilai'] * (self::MENIT[$d['satuan']] ?? 1) : 0;
    }

    /** "2026-11-20T10:00" (dianggap WIB) atau ber-offset -> ISO 8601; null bila tidak valid. */
    public static function parseJadwal(string $s): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})?$/', $s)) return null;
        try { $d = new DateTimeImmutable($s, new DateTimeZone('Asia/Jakarta')); } catch (Exception) { return null; }
        return $d->format('Y-m-d') === substr($s, 0, 10) ? $d->format('c') : null;   // tolak 31 Februari, dst.
    }

    /** Catatan perubahan status (opsional). Aturan sama dengan DetailPemesanan.html. '' = valid. */
    public static function catatan(mixed $v): string
    {
        if ($v === null) return '';
        if (!is_string($v)) return 'Catatan harus berupa teks.';
        $t = V::str($v);
        if ($t === '') return '';
        if (V::len($t) > 255) return 'Catatan maksimal 255 karakter.';
        if (preg_match('/[<>]/', $t)) return 'Catatan tidak boleh mengandung HTML atau script.';
        if (preg_match('/[\r\n]/', $t)) return 'Catatan tidak boleh berisi baris baru.';
        if (preg_match('/[^\x00-\x7F]/', $t)) return 'Catatan hanya boleh berisi karakter ASCII (tanpa emoji).';
        if (!preg_match('#^[A-Za-z0-9 .,:;!?()\-_/\'"]+$#', $t)) return 'Catatan mengandung karakter yang tidak diizinkan.';
        return '';
    }

    private static function find(array $rows, mixed $id): ?array
    {
        if (!is_string($id)) return null;
        foreach ($rows as $r) if ($r['id'] === $id) return $r;
        return null;
    }

    /**
     * Validasi sekaligus membentuk data pemesanan (harga & nama di-snapshot dari layanan/add on).
     * Mengembalikan [errors, data]; data kosong bila ada error.
     */
    public static function check(mixed $b, array $db): array
    {
        $e = [];
        $b = is_array($b) ? $b : [];
        $tz = new DateTimeZone('Asia/Jakarta');

        $p = self::find($db['pelanggan'], $b['pelanggan_id'] ?? null);
        if (!$p) $e['pelanggan_id'] = 'Pelanggan tidak valid.';

        $l = self::find($db['layanan'], $b['layanan_id'] ?? null);
        if (!$l) $e['layanan_id'] = 'Layanan tidak valid.';
        elseif (!$l['aktif']) { $e['layanan_id'] = 'Layanan sedang tidak aktif.'; $l = null; }

        $harga = 0; $pil = null; $outPil = []; $items = []; $menit = 0;

        if ($l) {
            /* harga layanan */
            if ($l['jenis_harga'] === 'tunggal') {
                $harga = (int) $l['harga_tunggal'];
            } else {
                $pn = V::lower(V::str($b['pilihan_harga'] ?? null)); $f = null;
                foreach ($l['harga_pilihan'] as $g)
                    foreach ($g['pilihan'] as $x) if ($pn !== '' && V::lower($x['nama']) === $pn) $f = $x;
                if ($f) { $harga = (int) $f['harga']; $pil = $f['nama']; }
                else $e['pilihan_harga'] = 'Pilihan harga layanan tidak valid.';
            }

            /* output bertipe pilihan: {"nama output": "sub pilihan"} */
            $op = $b['output_pilihan'] ?? [];
            if (!is_array($op)) $e['output_pilihan'] = 'Output pilihan harus berupa objek.';
            else foreach ($l['output'] as $o) {
                if ($o['tipe'] !== 'pilihan') continue;
                $v = $op[$o['nama']] ?? null;
                if (!is_string($v) || !in_array($v, $o['sub_pilihan'], true))
                    $e['output_pilihan.' . $o['nama']] = 'Pilihan untuk "' . $o['nama'] . '" wajib dipilih.';
                else $outPil[] = ['output' => $o['nama'], 'pilihan' => $v];
            }

            /* add on: [{addon_id, pilihan?, qty?}] */
            $menit = self::toMenit($l['durasi']);
            $as = $b['addons'] ?? [];
            if (!is_array($as) || ($as && !V::isList($as))) {
                $e['addons'] = 'Add on harus berupa daftar.';
            } else {
                $seen = []; $durSeen = [];
                foreach ($as as $i => $a) {
                    $k = "addons[$i]";
                    $ad = self::find($db['addon'], V::get($a, 'addon_id'));
                    if (!$ad || $ad['layanan_id'] !== $l['id'] || !$ad['aktif']) { $e["$k.addon_id"] = 'Add on tidak valid untuk layanan ini.'; continue; }
                    if ($ad['jenis_harga'] === 'tunggal') {
                        $nm = $ad['nama']; $hg = (int) $ad['harga_tunggal']; $kt = $ad['keterangan_harga'];
                    } else {
                        $pn = V::lower(V::str(V::get($a, 'pilihan'))); $f = null;
                        foreach ($ad['pilihan_harga'] as $x) if ($pn !== '' && V::lower($x['nama']) === $pn) $f = $x;
                        if (!$f) { $e["$k.pilihan"] = 'Pilihan add on tidak valid.'; continue; }
                        $nm = $f['nama']; $hg = (int) $f['harga']; $kt = $f['keterangan'];
                    }
                    $q = V::get($a, 'qty') ?? 1;
                    if (!V::isInt($q) || $q < 1 || $q > 99) { $e["$k.qty"] = 'Jumlah harus integer 1-99.'; continue; }
                    if ($kt === 'non') $q = 1;                       // add on "non" tidak per satuan
                    $key = $ad['id'] . '|' . V::lower($nm);
                    if (isset($seen[$key])) { $e[$k] = 'Add on duplikat.'; continue; }
                    $seen[$key] = true;
                    $items[] = ['addon_id' => $ad['id'], 'nama' => $nm, 'harga' => $hg, 'keterangan' => $kt, 'qty' => $q];
                    if (!isset($durSeen[$ad['id']])) { $durSeen[$ad['id']] = true; $menit += self::toMenit($ad['durasi']); }
                }
            }
        }

        /* jadwal */
        $jd = null;
        $raw = $b['jadwal'] ?? null;
        $perlu = $l ? (bool) $l['kebutuhan_jadwal'] : true;
        if ($raw === null || $raw === '') {
            if ($perlu) $e['jadwal'] = 'Jadwal wajib diisi.';
            else $jd = (new DateTimeImmutable('now', $tz))->format('c');
        } elseif (!is_string($raw) || ($jd = self::parseJadwal($raw)) === null) {
            $e['jadwal'] = 'Format jadwal tidak valid (YYYY-MM-DDTHH:MM).';
        } elseif ($perlu && strtotime($jd) < time() - 300) {
            $e['jadwal'] = 'Jadwal tidak boleh di masa lalu.';
        }

        /* bentrok jadwal pada layanan yang sama (kecuali pemesanan yang sudah selesai) */
        if ($l && $l['kebutuhan_jadwal'] && $jd !== null && !isset($e['jadwal'])) {
            $mulai = strtotime($jd); $akhir = $mulai + $menit * 60;
            foreach ($db['pemesanan'] as $o) {
                if ($o['layanan_id'] !== $l['id'] || $o['status'] === 'Selesai') continue;
                $m = strtotime($o['jadwal']); $s = $m + $o['durasi_menit'] * 60;
                if ($mulai < $s && $m < $akhir) { $e['jadwal'] = 'Jadwal bentrok dengan pemesanan ' . $o['kode'] . '.'; break; }
            }
        }

        /* catatan khusus (opsional) */
        $ck = null;
        if (($b['catatan_khusus'] ?? null) !== null) {
            $t = V::str($b['catatan_khusus']);
            if (!is_string($b['catatan_khusus']) || V::len($t) > 500 || preg_match('/[<>]/', $t))
                $e['catatan_khusus'] = 'Catatan khusus maksimal 500 karakter dan tidak boleh mengandung < atau >.';
            elseif ($t !== '') $ck = $t;
        }

        if ($e) return [$e, []];

        $total = $harga;
        foreach ($items as $it) $total += $it['harga'] * $it['qty'];

        return [[], [
            'pelanggan_id' => $p['id'], 'nama' => $p['nama'], 'nomor' => $p['nomor'],
            'layanan_id' => $l['id'], 'layanan' => $l['nama'],
            'jadwal' => $jd, 'durasi_menit' => $menit, 'pegawai' => 'Belum Ditentukan',
            'catatan_khusus' => $ck,
            'output' => array_map(fn($o) => $o['nama'], $l['output']),
            'output_pilihan' => $outPil, 'pilihan_harga' => $pil,
            'harga_layanan' => $harga, 'addons' => $items, 'total' => $total,
        ]];
    }
}