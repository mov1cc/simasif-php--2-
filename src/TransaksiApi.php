<?php
declare(strict_types=1);
require_once __DIR__ . '/ValidatePemesanan.php';

/**
 * Endpoint Transaksi Pembayaran (dipanggil dari public/api/index.php):
 *   GET   /api/transaksi?q=&status=&metode=&sort=   daftar + statistik
 *   GET   /api/transaksi/{id}                        satu transaksi
 *   GET   /api/transaksi/{id}/bukti                  gambar bukti (butuh login)
 *   POST  /api/transaksi/{id}/bukti                  unggah bukti (body = biner gambar)
 *   PUT   /api/transaksi/{id}/metode                 {metode: Transfer|Langsung}
 *   PATCH /api/transaksi/{id}/verifikasi             bayar -> Lunas
 *   PATCH /api/transaksi/{id}/tolak                  {alasan?} bayar -> Ditolak
 * Memakai fungsi/konstanta global index.php: out(), readJson(), readBody(), ctype(), qs(),
 * findIdx(), newId(), nowIso(), imageOk(), IMG, MAX_BYTES.
 */
final class TransaksiApi
{
    private const METODE = ['Transfer', 'Langsung'];
    private const AWAL = ['Pemesanan Dibuat', 'Menunggu Pembayaran'];   // tahap yang masih boleh diubah pembayarannya

    public static function handle(string $m, string $p): void
    {
        if ($p === '/api/transaksi' && $m === 'GET') self::index();
        if (!preg_match('#^/api/transaksi/([\w-]+)(/bukti|/metode|/verifikasi|/tolak)?$#', $p, $r)) return;
        $id = $r[1];
        $sub = $r[2] ?? '';
        if ($sub === '' && $m === 'GET') self::show($id);
        if ($sub === '/bukti' && $m === 'GET') self::bukti($id);
        if ($sub === '/bukti' && $m === 'POST') self::upload($id);
        if ($sub === '/metode' && $m === 'PUT') self::metode($id);
        if ($sub === '/verifikasi' && $m === 'PATCH') self::verifikasi($id);
        if ($sub === '/tolak' && $m === 'PATCH') self::tolak($id);
    }

    /* ---------- bentuk data ---------- */
    public static function metodeOf(array $o): string
    {
        if (in_array($o['metode'] ?? null, self::METODE, true)) return $o['metode'];
        // data lama: sudah lunas tanpa bukti = dibayar langsung; selain itu default Transfer
        return (empty($o['bukti_file']) && ($o['bayar'] ?? '') === 'Lunas') ? 'Langsung' : 'Transfer';
    }

    private static function buktiOf(array $o): string
    {
        if (self::metodeOf($o) === 'Langsung') return 'Tidak Perlu';
        return empty($o['bukti_file']) ? 'Belum Ada' : 'Tersedia';
    }

    /** [waktu, pegawai] aksi verifikasi/tolak; untuk data lama diambil dari riwayat. */
    private static function aksi(array $o): array
    {
        if (!empty($o['bayar_waktu'])) return [$o['bayar_waktu'], $o['bayar_pegawai'] ?? '-'];
        if (($o['bayar'] ?? '') === 'Lunas')
            foreach ($o['history'] ?? [] as $h)
                if ($h['status'] === 'Pembayaran Diverifikasi') return [$h['waktu'], $h['aktor']];
        return [null, '-'];
    }

    private static function row(array $o): array
    {
        [$waktu, $pegawai] = self::aksi($o);
        return [
            'id' => $o['id'], 'kode' => $o['kode'], 'nama' => $o['nama'], 'nomor' => $o['nomor'], 'layanan' => $o['layanan'],
            'metode' => self::metodeOf($o), 'jumlah' => (int) $o['total'], 'status' => $o['bayar'],
            'bukti' => self::buktiOf($o), 'waktu' => $waktu, 'alasan' => $o['bayar_alasan'] ?? '', 'pegawai' => $pegawai,
            'status_pemesanan' => $o['status'], 'dibuat' => $o['dibuat'],
        ];
    }

    private static function stats(array $rows): array
    {
        $n = fn(callable $f) => count(array_filter($rows, $f));
        return [
            'lunas' => $n(fn($r) => $r['status'] === 'Lunas'),
            'menunggu_verifikasi' => $n(fn($r) => $r['status'] === 'Menunggu Verifikasi'),
            'transfer' => $n(fn($r) => $r['metode'] === 'Transfer'),
            'langsung' => $n(fn($r) => $r['metode'] === 'Langsung'),
            'ditolak' => $n(fn($r) => $r['status'] === 'Ditolak'),
            'tanpa_bukti' => $n(fn($r) => $r['bukti'] === 'Belum Ada'),
        ];
    }

    /* ---------- baca ---------- */
    private static function index(): never
    {
        $db = Db::read();
        $term = V::lower(V::str(qs('q')));
        $digits = preg_replace('/\D/', '', $term);
        $phone = $digits !== '' && preg_match('/^[\d\s+\-()]+$/', $term);
        $st = qs('status'); $mt = qs('metode'); $sort = qs('sort');

        $all = array_map([self::class, 'row'], $db['pemesanan']);
        $rows = array_values(array_filter($all, function ($r) use ($term, $digits, $phone, $st, $mt) {
            if ($st !== '' && $st !== 'semua' && $r['status'] !== $st) return false;
            if ($mt !== '' && $mt !== 'semua' && $r['metode'] !== $mt) return false;
            return $term === '' || str_contains(V::lower($r['nama']), $term) || str_contains(V::lower($r['kode']), $term)
                || ($phone && str_contains($r['nomor'], $digits));
        }));

        if ($sort === 'az' || $sort === 'za')
            usort($rows, fn($a, $b) => strcmp(V::lower($a['nama']), V::lower($b['nama'])) * ($sort === 'az' ? 1 : -1));
        else   // antrean verifikasi di atas, lalu terbaru
            usort($rows, fn($a, $b) => [$a['status'] === 'Menunggu Verifikasi' ? 0 : 1, -strtotime($a['dibuat'])]
                                   <=> [$b['status'] === 'Menunggu Verifikasi' ? 0 : 1, -strtotime($b['dibuat'])]);

        out(200, ['data' => $rows, 'stats' => self::stats($all)]);
    }

    private static function show(string $id): never
    {
        $db = Db::read();
        $i = findIdx($db['pemesanan'], $id);
        if ($i === null) out(404, ['error' => 'Transaksi tidak ditemukan.']);
        out(200, ['data' => self::row($db['pemesanan'][$i])]);
    }

    /** Bukti disimpan di data/ (tidak publik) dan hanya bisa dilihat lewat endpoint ini (wajib login). */
    private static function bukti(string $id): never
    {
        $db = Db::read();
        $i = findIdx($db['pemesanan'], $id);
        $file = $i === null ? '' : (string) ($db['pemesanan'][$i]['bukti_file'] ?? '');
        $path = $file === '' ? '' : self::dir() . '/' . basename($file);
        $info = ($path !== '' && is_file($path)) ? @getimagesize($path) : false;
        if (!$info) out(404, ['error' => 'Bukti pembayaran tidak ditemukan.']);
        header('Content-Type: ' . $info['mime']);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    /* ---------- ubah ---------- */
    private static function dir(): string { return dirname(__DIR__) . '/data/bukti'; }

    private static function simpan(string $type, string $buf): string
    {
        $d = self::dir();
        if (!is_dir($d) && !@mkdir($d, 0750, true) && !is_dir($d)) throw new RuntimeException('Gagal menyiapkan folder bukti.', 500);
        $f = newId('bkt_') . '.' . IMG[$type];
        if (file_put_contents($d . '/' . $f, $buf, LOCK_EX) === false) throw new RuntimeException('Gagal menyimpan file.', 500);
        return $f;
    }

    private static function hapus(?string $f): void
    {
        if ($f) @unlink(self::dir() . '/' . basename($f));
    }

    private static function aktor(): string
    {
        return Auth::user(Db::read())['nama'] ?? 'Anonimus';
    }

    /** Pembayaran masih boleh diubah: belum lunas dan pemesanan masih di tahap awal. */
    private static function bisaBayar(array $o): bool
    {
        return $o['bayar'] !== 'Lunas' && in_array($o['status'], self::AWAL, true);
    }

    /** Majukan status pemesanan (satu langkah per langkah, tiap langkah dicatat di riwayat) sampai $target. */
    private static function advanceTo(array &$o, string $target, string $aktor, string $teks, string $catatan): void
    {
        $F = ValidatePemesanan::FLOW;
        $i = array_search($o['status'], $F, true);
        $t = array_search($target, $F, true);
        while ($i !== false && $t !== false && $i < $t) {
            $o['status'] = $F[++$i];
            $o['history'][] = ['status' => $o['status'], 'waktu' => nowIso(), 'aktor' => $aktor, 'teks' => $teks, 'catatan' => $catatan];
        }
    }

    private static function upload(string $id): never
    {
        $type = ctype();
        if (!isset(IMG[$type])) out(415, ['error' => 'Hanya JPG/PNG/WebP.']);
        try { $buf = readBody(MAX_BYTES); } catch (RuntimeException $e) { out(413, ['error' => 'Ukuran melebihi 5 MB.']); }
        if (!imageOk($type, $buf)) out(415, ['error' => 'Isi file bukan gambar valid.']);
        $aktor = self::aktor();

        [$c, $body] = Db::write(function (array &$db) use ($id, $type, $buf, $aktor) {
            $i = findIdx($db['pemesanan'], $id);
            if ($i === null) return [404, ['error' => 'Transaksi tidak ditemukan.']];
            $o = $db['pemesanan'][$i];
            if (!self::bisaBayar($o)) return [409, ['error' => 'Pembayaran sudah diverifikasi atau pemesanan sudah melewati tahap pembayaran.']];

            $file = self::simpan($type, $buf);
            self::hapus($o['bukti_file'] ?? null);
            $o['metode'] = 'Transfer';
            $o['bukti_file'] = $file;
            $o['bukti_waktu'] = nowIso();
            $o['bayar'] = 'Menunggu Verifikasi';
            $o['bayar_alasan'] = ''; $o['bayar_waktu'] = null; $o['bayar_pegawai'] = null;
            self::advanceTo($o, 'Menunggu Pembayaran', $aktor, 'Bukti Pembayaran Diunggah', '');
            $db['pemesanan'][$i] = $o;
            return [200, ['ok' => true, 'data' => self::row($o)]];
        });
        out($c, $body);
    }

    private static function metode(string $id): never
    {
        $b = readJson();
        $m = is_array($b) ? ($b['metode'] ?? null) : null;
        if (!in_array($m, self::METODE, true)) out(422, ['errors' => ['metode' => 'Metode harus Transfer atau Langsung.']]);
        $aktor = self::aktor();

        [$c, $body] = Db::write(function (array &$db) use ($id, $m, $aktor) {
            $i = findIdx($db['pemesanan'], $id);
            if ($i === null) return [404, ['error' => 'Transaksi tidak ditemukan.']];
            $o = $db['pemesanan'][$i];
            if (!self::bisaBayar($o)) return [409, ['error' => 'Metode tidak bisa diubah: pembayaran sudah diverifikasi atau pemesanan sudah berjalan.']];

            if ($m === 'Langsung') {       // bayar di tempat: tidak perlu bukti, langsung antre verifikasi
                self::hapus($o['bukti_file'] ?? null);
                $o['bukti_file'] = null;
                $o['bayar'] = 'Menunggu Verifikasi';
                self::advanceTo($o, 'Menunggu Pembayaran', $aktor, 'Metode Pembayaran Langsung Dipilih', '');
            } else {
                $o['bayar'] = empty($o['bukti_file']) ? 'Belum Dibayar' : 'Menunggu Verifikasi';
            }
            $o['metode'] = $m;
            $o['bayar_alasan'] = ''; $o['bayar_waktu'] = null; $o['bayar_pegawai'] = null;
            $db['pemesanan'][$i] = $o;
            return [200, ['ok' => true, 'data' => self::row($o)]];
        });
        out($c, $body);
    }

    private static function verifikasi(string $id): never
    {
        $aktor = self::aktor();
        [$c, $body] = Db::write(function (array &$db) use ($id, $aktor) {
            $i = findIdx($db['pemesanan'], $id);
            if ($i === null) return [404, ['error' => 'Transaksi tidak ditemukan.']];
            $o = $db['pemesanan'][$i];
            if ($o['bayar'] !== 'Menunggu Verifikasi') return [409, ['error' => 'Hanya pembayaran berstatus Menunggu Verifikasi yang bisa diverifikasi. Muat ulang halaman.']];
            if (self::metodeOf($o) === 'Transfer' && empty($o['bukti_file'])) return [409, ['error' => 'Bukti pembayaran belum diunggah.']];
            if (!in_array($o['status'], self::AWAL, true)) return [409, ['error' => 'Status pemesanan sudah berubah. Muat ulang halaman.']];

            $o['bayar'] = 'Lunas';
            $o['bayar_waktu'] = nowIso(); $o['bayar_pegawai'] = $aktor; $o['bayar_alasan'] = '';
            self::advanceTo($o, 'Pembayaran Diverifikasi', $aktor, 'Pemesanan Diperbarui Pegawai', '');
            $db['pemesanan'][$i] = $o;
            return [200, ['ok' => true, 'data' => self::row($o)]];
        });
        out($c, $body);
    }

    private static function tolak(string $id): never
    {
        $b = readJson();
        $b = is_array($b) ? $b : [];
        $err = ValidatePemesanan::catatan($b['alasan'] ?? null);   // aturan sama: maks 255, ASCII, tanpa HTML
        if ($err !== '') out(422, ['errors' => ['alasan' => $err]]);
        $alasan = V::str($b['alasan'] ?? null);
        $aktor = self::aktor();

        [$c, $body] = Db::write(function (array &$db) use ($id, $alasan, $aktor) {
            $i = findIdx($db['pemesanan'], $id);
            if ($i === null) return [404, ['error' => 'Transaksi tidak ditemukan.']];
            $o = $db['pemesanan'][$i];
            if ($o['bayar'] !== 'Menunggu Verifikasi') return [409, ['error' => 'Hanya pembayaran berstatus Menunggu Verifikasi yang bisa ditolak. Muat ulang halaman.']];
            if (!in_array($o['status'], self::AWAL, true)) return [409, ['error' => 'Status pemesanan sudah berubah. Muat ulang halaman.']];

            $o['bayar'] = 'Ditolak';
            $o['bayar_waktu'] = nowIso(); $o['bayar_pegawai'] = $aktor; $o['bayar_alasan'] = $alasan;
            self::advanceTo($o, 'Menunggu Pembayaran', $aktor, 'Pemesanan Diperbarui Pegawai', '');
            $o['history'][] = ['status' => $o['status'], 'waktu' => nowIso(), 'aktor' => $aktor,
                               'teks' => 'Pembayaran Ditolak Pegawai', 'catatan' => $alasan];
            $db['pemesanan'][$i] = $o;   // pelanggan bisa unggah ulang: bisaBayar() masih true
            return [200, ['ok' => true, 'data' => self::row($o)]];
        });
        out($c, $body);
    }
}