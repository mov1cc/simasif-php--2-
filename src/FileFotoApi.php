<?php
declare(strict_types=1);
require_once __DIR__ . '/V.php';

/**
 * Endpoint File Fotografi (dipanggil dari public/api/index.php):
 *   GET    /api/pemesanan/{id}/file                       info pemesanan + daftar hasil & referensi
 *   POST   /api/pemesanan/{id}/file?jenis=hasil|ref[&ket=] unggah gambar (body = biner gambar)
 *   PUT    /api/pemesanan/{id}/file/order                 {jenis?, ids:[]} simpan urutan
 *   PUT    /api/pemesanan/{id}/file/{fid}                 {caption} ubah keterangan (hanya hasil)
 *   DELETE /api/pemesanan/{id}/file/{fid}                 hapus (hanya hasil)
 *   GET    /api/pemesanan/{id}/file/{fid}/berkas[?unduh=1] isi gambar (wajib login)
 * Memakai fungsi/konstanta global index.php: out(), readJson(), readBody(), ctype(), qs(),
 * findIdx(), newId(), imageOk(), IMG, MAX_BYTES.
 *
 * Berkas disimpan di data/foto/ (tidak publik) dan hanya bisa diambil lewat endpoint ini.
 * Data: $db['file_foto'] = [{id, pemesanan_id, jenis, file, caption, ket, urutan, dibuat, pegawai}]
 */
final class FileFotoApi
{
    private const JENIS = ['hasil', 'ref'];
    private const MAKS = ['hasil' => 30, 'ref' => 10];                        // maks. gambar per pemesanan
    private const BISA_UBAH = ['Sesi Foto', 'Proses Editing', 'Hasil Siap'];  // tahap yang boleh mengubah file hasil

    public static function handle(string $m, string $p): void
    {
        if (!preg_match('#^/api/pemesanan/([\w-]+)/file(?:/(order)|/([\w-]+)(/berkas)?)?$#', $p, $r)) return;
        $pid = $r[1];
        $order = ($r[2] ?? '') === 'order';
        $fid = $r[3] ?? null;
        $berkas = !empty($r[4] ?? '');

        if ($order) { if ($m === 'PUT') self::urutan($pid); return; }
        if ($fid === null) {
            if ($m === 'GET') self::index($pid);
            if ($m === 'POST') self::upload($pid);
            return;
        }
        if ($berkas) { if ($m === 'GET') self::berkas($pid, $fid); return; }
        if ($m === 'PUT') self::ubah($pid, $fid);
        if ($m === 'DELETE') self::hapus($pid, $fid);
    }

    /* ---------- helper ---------- */
    private static function dir(): string { return dirname(__DIR__) . '/data/foto'; }

    private static function boleh(array $o): bool { return in_array($o['status'], self::BISA_UBAH, true); }

    private static function row(string $pid, array $g): array
    {
        return ['id' => $g['id'], 'jenis' => $g['jenis'], 'url' => "/api/pemesanan/$pid/file/{$g['id']}/berkas",
                'caption' => $g['caption'], 'ket' => $g['ket'], 'urutan' => $g['urutan']];
    }

    /** Item satu pemesanan + jenis, terurut. */
    private static function items(array $db, string $pid, string $jenis): array
    {
        $rows = array_values(array_filter($db['file_foto'] ?? [],
            fn($g) => $g['pemesanan_id'] === $pid && $g['jenis'] === $jenis));
        usort($rows, fn($a, $b) => $a['urutan'] <=> $b['urutan']);
        return $rows;
    }

    /** Rapikan urutan menjadi 0..n-1. */
    private static function norm(array &$db, string $pid, string $jenis): void
    {
        $pos = []; $n = 0;
        foreach (self::items($db, $pid, $jenis) as $g) $pos[$g['id']] = $n++;
        foreach ($db['file_foto'] as $k => $g) if (isset($pos[$g['id']])) $db['file_foto'][$k]['urutan'] = $pos[$g['id']];
    }

    private static function simpan(string $type, string $buf): string
    {
        $d = self::dir();
        if (!is_dir($d) && !@mkdir($d, 0750, true) && !is_dir($d)) throw new RuntimeException('Gagal menyiapkan folder foto.', 500);
        $f = newId('ff_') . '.' . IMG[$type];
        if (file_put_contents($d . '/' . $f, $buf, LOCK_EX) === false) throw new RuntimeException('Gagal menyimpan file.', 500);
        return $f;
    }

    private static function hapusFile(?string $f): void
    {
        if ($f) @unlink(self::dir() . '/' . basename($f));
    }

    private static function aktor(): string
    {
        return Auth::user(Db::read())['nama'] ?? 'Anonimus';
    }

    /* ---------- baca ---------- */
    private static function index(string $pid): never
    {
        $db = Db::read();
        $i = findIdx($db['pemesanan'], $pid);
        if ($i === null) out(404, ['error' => 'Pemesanan tidak ditemukan.']);
        $o = $db['pemesanan'][$i];
        out(200, ['data' => [
            'pemesanan' => ['id' => $o['id'], 'kode' => $o['kode'], 'nama' => $o['nama'], 'layanan' => $o['layanan'], 'status' => $o['status']],
            'bisa_ubah' => self::boleh($o),
            'maks' => self::MAKS,
            'hasil' => array_map(fn($g) => self::row($pid, $g), self::items($db, $pid, 'hasil')),
            'referensi' => array_map(fn($g) => self::row($pid, $g), self::items($db, $pid, 'ref')),
        ]]);
    }

    /** Gambar hanya bisa dilihat/diunduh lewat endpoint ini (wajib login, sudah dijaga router). */
    private static function berkas(string $pid, string $fid): never
    {
        $db = Db::read();
        $g = null;
        foreach ($db['file_foto'] ?? [] as $x) if ($x['id'] === $fid && $x['pemesanan_id'] === $pid) { $g = $x; break; }
        $path = $g ? self::dir() . '/' . basename($g['file']) : '';
        $info = ($path !== '' && is_file($path)) ? @getimagesize($path) : false;
        if (!$info) out(404, ['error' => 'File tidak ditemukan.']);

        header('Content-Type: ' . $info['mime']);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        if (qs('unduh') === '1') {
            $i = findIdx($db['pemesanan'], $pid);
            $kode = preg_replace('/[^A-Za-z0-9_-]/', '', $i === null ? 'foto' : $db['pemesanan'][$i]['kode']);
            header('Content-Disposition: attachment; filename="' . $kode . '-' . $g['jenis'] . '-' . ($g['urutan'] + 1) . '.' . (IMG[$info['mime']] ?? 'jpg') . '"');
        }
        readfile($path);
        exit;
    }

    /* ---------- ubah ---------- */
    private static function upload(string $pid): never
    {
        $jenis = qs('jenis') ?: 'hasil';
        if (!in_array($jenis, self::JENIS, true)) out(422, ['errors' => ['jenis' => 'Jenis harus hasil atau ref.']]);

        $ket = null;
        if ($jenis === 'ref' && qs('ket') !== '') {
            $t = V::str(qs('ket'));
            if (V::len($t) > 500 || preg_match('/[<>]/', $t))
                out(422, ['errors' => ['ket' => 'Keterangan maksimal 500 karakter dan tidak boleh mengandung < atau >.']]);
            $ket = $t !== '' ? $t : null;
        }

        $type = ctype();
        if (!isset(IMG[$type])) out(415, ['error' => 'Hanya JPG/PNG/WebP.']);
        try { $buf = readBody(MAX_BYTES); } catch (RuntimeException $e) { out(413, ['error' => 'Ukuran melebihi 5 MB.']); }
        if (!imageOk($type, $buf)) out(415, ['error' => 'Isi file bukan gambar valid.']);
        $aktor = self::aktor();

        [$c, $body] = Db::write(function (array &$db) use ($pid, $jenis, $ket, $type, $buf, $aktor) {
            $i = findIdx($db['pemesanan'], $pid);
            if ($i === null) return [404, ['error' => 'Pemesanan tidak ditemukan.']];
            if ($jenis === 'hasil' && !self::boleh($db['pemesanan'][$i]))
                return [409, ['error' => 'File hasil hanya bisa diunggah saat tahap Sesi Foto sampai Hasil Siap.']];

            $n = count(self::items($db, $pid, $jenis));
            if ($n >= self::MAKS[$jenis]) return [422, ['error' => 'Maksimal ' . self::MAKS[$jenis] . ' gambar.']];

            $g = ['id' => newId('f'), 'pemesanan_id' => $pid, 'jenis' => $jenis, 'file' => self::simpan($type, $buf),
                  'caption' => '', 'ket' => $ket, 'urutan' => $n, 'dibuat' => nowIso(), 'pegawai' => $aktor];
            $db['file_foto'][] = $g;
            return [201, ['ok' => true, 'data' => self::row($pid, $g)]];
        });
        out($c, $body);
    }

    private static function ubah(string $pid, string $fid): never
    {
        $b = readJson();
        $b = is_array($b) ? $b : [];
        $cap = $b['caption'] ?? null;
        if ($cap !== null && (!is_string($cap) || V::len(V::str($cap)) > 120 || preg_match('/[<>]/', $cap)))
            out(422, ['errors' => ['caption' => 'Keterangan maksimal 120 karakter dan tidak boleh mengandung < atau >.']]);

        [$c, $body] = Db::write(function (array &$db) use ($pid, $fid, $cap) {
            $pi = findIdx($db['pemesanan'], $pid);
            $gi = findIdx($db['file_foto'], $fid);
            if ($pi === null || $gi === null || $db['file_foto'][$gi]['pemesanan_id'] !== $pid) return [404, ['error' => 'File tidak ditemukan.']];
            if ($db['file_foto'][$gi]['jenis'] !== 'hasil') return [403, ['error' => 'File referensi dari pelanggan tidak bisa diubah.']];
            if (!self::boleh($db['pemesanan'][$pi])) return [409, ['error' => 'File hasil tidak bisa diubah pada tahap pemesanan ini.']];

            if ($cap !== null) $db['file_foto'][$gi]['caption'] = V::str($cap);
            return [200, ['ok' => true, 'data' => self::row($pid, $db['file_foto'][$gi])]];
        });
        out($c, $body);
    }

    private static function hapus(string $pid, string $fid): never
    {
        $file = null;
        [$c, $body] = Db::write(function (array &$db) use ($pid, $fid, &$file) {
            $pi = findIdx($db['pemesanan'], $pid);
            $gi = findIdx($db['file_foto'], $fid);
            if ($pi === null || $gi === null || $db['file_foto'][$gi]['pemesanan_id'] !== $pid) return [404, ['error' => 'File tidak ditemukan.']];
            $g = $db['file_foto'][$gi];
            if ($g['jenis'] !== 'hasil') return [403, ['error' => 'File referensi dari pelanggan tidak bisa dihapus.']];
            if (!self::boleh($db['pemesanan'][$pi])) return [409, ['error' => 'File hasil tidak bisa dihapus pada tahap pemesanan ini.']];

            $file = $g['file'];
            array_splice($db['file_foto'], $gi, 1);
            self::norm($db, $pid, 'hasil');
            return [200, ['ok' => true]];
        });
        if ($c < 400) self::hapusFile($file);
        out($c, $body);
    }

    private static function urutan(string $pid): never
    {
        $b = readJson();
        $b = is_array($b) ? $b : [];
        $jenis = $b['jenis'] ?? 'hasil';
        if (!in_array($jenis, self::JENIS, true)) out(422, ['errors' => ['jenis' => 'Jenis harus hasil atau ref.']]);
        if (!V::isList($b['ids'] ?? null)) out(422, ['errors' => ['ids' => 'ids harus array.']]);

        [$c, $body] = Db::write(function (array &$db) use ($pid, $jenis, $b) {
            $pi = findIdx($db['pemesanan'], $pid);
            if ($pi === null) return [404, ['error' => 'Pemesanan tidak ditemukan.']];
            if ($jenis === 'hasil' && !self::boleh($db['pemesanan'][$pi]))
                return [409, ['error' => 'Urutan file hasil tidak bisa diubah pada tahap pemesanan ini.']];

            $pos = array_flip(array_filter($b['ids'], 'is_string'));
            foreach ($db['file_foto'] as $k => $g) {
                if ($g['pemesanan_id'] !== $pid || $g['jenis'] !== $jenis) continue;
                // id yang dikirim diurutkan sesuai kiriman; sisanya ditaruh di belakang
                $db['file_foto'][$k]['urutan'] = $pos[$g['id']] ?? 1000 + $g['urutan'];
            }
            self::norm($db, $pid, $jenis);
            return [200, ['ok' => true]];
        });
        out($c, $body);
    }
}