<?php
declare(strict_types=1);
/**
 * SIMASIF API - PHP native (tanpa framework). Front controller untuk semua /api/*.
 * Endpoint: /api/layanan[/{id}[/gallery[/{gid}|/order|/{gid}/image]]], /api/add-on[/{id}|/order]
 */
require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/ValidateService.php';
require_once dirname(__DIR__, 2) . '/src/ValidateAddOn.php';

const MAX_GALLERY = 8;
const MAX_BYTES = 5 * 1048576;
const IMG = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
define('UPLOADS', dirname(__DIR__) . '/uploads');

/* ---------- helper ---------- */
function out(int $code, array $body): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function readBody(int $limit): string
{
    $raw = (string) file_get_contents('php://input', false, null, 0, $limit + 1);
    if (strlen($raw) > $limit) throw new RuntimeException('Ukuran request melebihi batas.', 413);
    return $raw;
}
function readJson(): mixed
{
    $raw = readBody(1048576);
    if ($raw === '') return [];
    $d = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('JSON tidak valid', 400);
    return $d;
}
function same(string $a, string $b): bool { return V::lower(V::str($a)) === V::lower(V::str($b)); }
function newId(string $p): string { return $p . bin2hex(random_bytes(5)); }
function removeFile(string $url): void { @unlink(UPLOADS . '/' . basename($url)); }
function ctype(): string { return strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])); }

/** Validasi isi gambar sebenarnya (bukan hanya header Content-Type). */
function imageOk(string $type, string $buf): bool
{
    $info = @getimagesizefromstring($buf);
    return $info !== false && ($info['mime'] ?? '') === $type;
}
function saveImage(string $type, string $buf): string
{
    $file = newId('img_') . '.' . IMG[$type];
    if (file_put_contents(UPLOADS . '/' . $file, $buf, LOCK_EX) === false) throw new RuntimeException('Gagal menyimpan file.', 500);
    return '/uploads/' . $file;
}

/* galeri: indeks item per layanan, terurut */
function gIdx(array $db, string $lid): array
{
    $idx = array_keys(array_filter($db['gallery'], fn($g) => $g['layanan_id'] === $lid));
    usort($idx, fn($a, $b) => $db['gallery'][$a]['urutan'] <=> $db['gallery'][$b]['urutan']);
    return $idx;
}
function gNorm(array &$db, string $lid): void
{
    $idx = gIdx($db, $lid);
    $has = false;
    foreach ($idx as $i) if ($db['gallery'][$i]['cover']) $has = true;
    if (!$has && $idx) $db['gallery'][$idx[0]]['cover'] = true;
    foreach ($idx as $n => $i) $db['gallery'][$i]['urutan'] = $n;
}
function coverOf(array $db, string $lid): ?string
{
    foreach (gIdx($db, $lid) as $i) if ($db['gallery'][$i]['cover']) return $db['gallery'][$i]['url'];
    return null;
}
function withCover(array $db, array $l): array { return $l + ['cover' => coverOf($db, $l['id'])]; }
function findIdx(array $rows, string $id): ?int
{
    foreach ($rows as $i => $r) if ($r['id'] === $id) return $i;
    return null;
}

/* ---------- simpan layanan (POST / PUT) ---------- */
function saveLayanan(?string $id): never
{
    $b = readJson();
    [$code, $body] = Db::write(function (array &$db) use ($b, $id) {
        $errors = ValidateService::validate($b, function (string $n) use ($db, $id) {
            foreach ($db['layanan'] as $l) if ($l['id'] !== $id && same($l['nama'], $n)) return true;
            return false;
        });
        if ($errors) return [422, ['errors' => $errors]];

        $tg = $b['jenis_harga'] === 'tunggal';
        $data = [
            'nama' => V::str($b['nama']),
            'jenis_harga' => $b['jenis_harga'],
            'harga_tunggal' => $tg ? $b['harga_tunggal'] : null,
            'harga_pilihan' => $tg ? [] : array_map(fn($g) => [
                'nama' => V::str($g['nama']),
                'pilihan' => array_map(fn($p) => ['nama' => V::str($p['nama']), 'harga' => $p['harga']], $g['pilihan']),
            ], $b['harga_pilihan']),
            'keterangan_harga' => $b['keterangan_harga'],
            'durasi' => ['nilai' => $b['durasi']['nilai'], 'satuan' => $b['durasi']['satuan']],
            'estimasi_hasil' => ($b['estimasi_hasil'] ?? null) === null ? null
                : ['nilai' => $b['estimasi_hasil']['nilai'], 'satuan' => $b['estimasi_hasil']['satuan']],
            'maks_orang' => $b['maks_orang'] ?? null,
            'deskripsi' => ($b['deskripsi'] ?? null) === null ? null : V::str($b['deskripsi']),
            'output' => array_map(fn($o) => [
                'nama' => V::str($o['nama']),
                'tipe' => $o['tipe'],
                'sub_pilihan' => $o['tipe'] === 'pilihan' ? array_map([V::class, 'str'], $o['sub_pilihan']) : [],
            ], $b['output']),
            'kebutuhan_jadwal' => $b['kebutuhan_jadwal'],
            'tampil_publik' => $b['tampil_publik'],
        ];
        if ($id !== null) {
            $i = findIdx($db['layanan'], $id);
            if ($i === null) return [404, ['error' => 'Layanan tidak ditemukan.']];
            $db['layanan'][$i] = array_merge($db['layanan'][$i], $data, ['aktif' => $data['tampil_publik']]);
            return [200, ['ok' => true, 'data' => $db['layanan'][$i]]];
        }
        $row = ['id' => newId('s'), 'aktif' => $data['tampil_publik'], 'sesi' => 0, 'dibuat' => date('c')] + $data;
        $db['layanan'][] = $row;
        return [201, ['ok' => true, 'data' => $row]];
    });
    out($code, $body);
}

/* ---------- simpan add-on (POST / PUT) ---------- */
function saveAddOn(?string $id): never
{
    $b = readJson();
    [$code, $body] = Db::write(function (array &$db) use ($b, $id) {
        $ei = $id !== null ? findIdx($db['addon'], $id) : null;
        $lid = $ei !== null ? $db['addon'][$ei]['layanan_id'] : (is_array($b) ? ($b['layanan_id'] ?? null) : null);
        if (!is_string($lid) || findIdx($db['layanan'], $lid) === null)
            return [422, ['errors' => ['layanan_id' => 'Layanan tidak valid.']]];

        // nama add on unik per layanan (case-insensitive)
        $errors = ValidateAddOn::validate($b, function (string $n) use ($db, $id, $lid) {
            foreach ($db['addon'] as $a) if ($a['id'] !== $id && $a['layanan_id'] === $lid && same($a['nama'], $n)) return true;
            return false;
        });
        if ($errors) return [422, ['errors' => $errors]];

        $tg = $b['jenis_harga'] === 'tunggal';
        $data = [
            'nama' => V::str($b['nama']),
            'jenis_harga' => $b['jenis_harga'],
            'harga_tunggal' => $tg ? $b['harga_tunggal'] : null,
            'keterangan_harga' => $tg ? $b['keterangan_harga'] : null,
            'pilihan_harga' => $tg ? [] : array_map(fn($p) => [
                'nama' => V::str($p['nama']), 'harga' => $p['harga'], 'keterangan' => $p['keterangan'],
            ], $b['pilihan_harga']),
            'deskripsi' => ($b['deskripsi'] ?? null) === null ? null : V::str($b['deskripsi']),
            'durasi' => ($b['durasi'] ?? null) === null ? null : ['nilai' => $b['durasi']['nilai'], 'satuan' => $b['durasi']['satuan']],
        ];
        if ($ei !== null) {
            $db['addon'][$ei] = array_merge($db['addon'][$ei], $data);
            return [200, ['ok' => true, 'data' => $db['addon'][$ei]]];
        }
        $n = count(array_filter($db['addon'], fn($a) => $a['layanan_id'] === $lid));
        $row = ['id' => newId('a'), 'layanan_id' => $lid, 'aktif' => true, 'urutan' => $n] + $data;
        $db['addon'][] = $row;
        return [201, ['ok' => true, 'data' => $row]];
    });
    out($code, $body);
}

/* ---------- router ---------- */
set_exception_handler(function (Throwable $t) {
    $code = in_array($t->getCode(), [400, 413, 415, 500], true) ? $t->getCode() : 500;
    if ($code === 500 && !($t instanceof RuntimeException)) error_log((string) $t);
    out($code, ['error' => $code === 500 && !($t instanceof RuntimeException) ? 'Kesalahan server.' : $t->getMessage()]);
});

$m = $_SERVER['REQUEST_METHOD'];
$p = rtrim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$p = preg_replace('#^.*?/api(?=/)#', '/api', $p);   // toleran bila aplikasi ada di subfolder
$r = [];

/* --- layanan --- */
if ($p === '/api/layanan') {
    if ($m === 'GET') { $db = Db::read(); out(200, ['data' => array_map(fn($l) => withCover($db, $l), $db['layanan'])]); }
    if ($m === 'POST') saveLayanan(null);
}
if (preg_match('#^/api/layanan/([\w-]+)$#', $p, $r)) {
    $id = $r[1];
    $db = Db::read();
    $i = findIdx($db['layanan'], $id);
    if ($i === null) out(404, ['error' => 'Layanan tidak ditemukan.']);
    if ($m === 'GET') out(200, ['data' => withCover($db, $db['layanan'][$i])]);
    if ($m === 'PUT') saveLayanan($id);
    if ($m === 'PATCH') {
        $b = readJson();
        $b = is_array($b) ? $b : [];
        foreach (['tampil_publik', 'aktif'] as $k)
            if (array_key_exists($k, $b) && !is_bool($b[$k])) out(422, ['errors' => ['status' => 'tampil_publik/aktif harus boolean.']]);
        [$c, $body] = Db::write(function (array &$db) use ($id, $b) {
            $i = findIdx($db['layanan'], $id);
            if ($i === null) return [404, ['error' => 'Layanan tidak ditemukan.']];
            foreach (['tampil_publik', 'aktif'] as $k) if (array_key_exists($k, $b)) $db['layanan'][$i][$k] = $b[$k];
            return [200, ['ok' => true, 'data' => $db['layanan'][$i]]];
        });
        out($c, $body);
    }
    if ($m === 'DELETE') {
        [$c, $body] = Db::write(function (array &$db) use ($id) {
            foreach ($db['gallery'] as $g) if ($g['layanan_id'] === $id) removeFile($g['url']);
            $db['layanan'] = array_values(array_filter($db['layanan'], fn($l) => $l['id'] !== $id));
            $db['addon'] = array_values(array_filter($db['addon'], fn($a) => $a['layanan_id'] !== $id));
            $db['gallery'] = array_values(array_filter($db['gallery'], fn($g) => $g['layanan_id'] !== $id));
            return [200, ['ok' => true]];
        });
        out($c, $body);
    }
}

/* --- add-on --- */
if ($p === '/api/add-on') {
    if ($m === 'GET') {
        $db = Db::read();
        $lid = $_GET['layanan_id'] ?? null;
        $rows = array_values(array_filter($db['addon'], fn($a) => !$lid || $a['layanan_id'] === $lid));
        usort($rows, fn($a, $b) => $a['urutan'] <=> $b['urutan']);
        out(200, ['data' => $rows]);
    }
    if ($m === 'POST') saveAddOn(null);
}
if ($p === '/api/add-on/order' && $m === 'PUT') {
    $b = readJson();
    if (!is_array($b) || !V::isList($b['ids'] ?? null)) out(422, ['errors' => ['ids' => 'ids harus array.']]);
    [$c, $body] = Db::write(function (array &$db) use ($b) {
        foreach ($b['ids'] as $n => $id) {
            $i = is_string($id) ? findIdx($db['addon'], $id) : null;
            if ($i !== null) $db['addon'][$i]['urutan'] = $n;
        }
        return [200, ['ok' => true]];
    });
    out($c, $body);
}
if (preg_match('#^/api/add-on/([\w-]+)$#', $p, $r)) {
    $id = $r[1];
    $db = Db::read();
    $i = findIdx($db['addon'], $id);
    if ($i === null) out(404, ['error' => 'Add on tidak ditemukan.']);
    if ($m === 'GET') out(200, ['data' => $db['addon'][$i]]);
    if ($m === 'PUT') saveAddOn($id);
    if ($m === 'PATCH') {
        $b = readJson();
        if (!is_array($b) || !is_bool($b['aktif'] ?? null)) out(422, ['errors' => ['aktif' => 'aktif harus boolean.']]);
        [$c, $body] = Db::write(function (array &$db) use ($id, $b) {
            $i = findIdx($db['addon'], $id);
            if ($i === null) return [404, ['error' => 'Add on tidak ditemukan.']];
            $db['addon'][$i]['aktif'] = $b['aktif'];
            return [200, ['ok' => true, 'data' => $db['addon'][$i]]];
        });
        out($c, $body);
    }
    if ($m === 'DELETE') {
        [$c, $body] = Db::write(function (array &$db) use ($id) {
            $db['addon'] = array_values(array_filter($db['addon'], fn($a) => $a['id'] !== $id));
            return [200, ['ok' => true]];
        });
        out($c, $body);
    }
}

/* --- galeri --- */
if (preg_match('#^/api/layanan/([\w-]+)/gallery(?:/([\w-]+))?(/image)?$#', $p, $r)) {
    $lid = $r[1];
    $gid = $r[2] ?? null;
    $isImg = !empty($r[3]);
    $db = Db::read();
    if (findIdx($db['layanan'], $lid) === null) out(404, ['error' => 'Layanan tidak ditemukan.']);

    if ($gid === null && $m === 'GET')
        out(200, ['data' => array_map(fn($i) => $db['gallery'][$i], gIdx($db, $lid))]);

    if ($gid === null && $m === 'POST') {                 // body = biner gambar
        $type = ctype();
        if (!isset(IMG[$type])) out(415, ['error' => 'Hanya JPG/PNG/WebP.']);
        try { $buf = readBody(MAX_BYTES); } catch (RuntimeException $e) { out(413, ['error' => 'Ukuran melebihi 5 MB.']); }
        if (!imageOk($type, $buf)) out(415, ['error' => 'Isi file bukan gambar valid.']);
        [$c, $body] = Db::write(function (array &$db) use ($lid, $type, $buf) {
            $n = count(gIdx($db, $lid));
            if ($n >= MAX_GALLERY) return [422, ['error' => 'Maksimal ' . MAX_GALLERY . ' gambar.']];
            $g = ['id' => newId('g'), 'layanan_id' => $lid, 'url' => saveImage($type, $buf), 'caption' => '', 'cover' => $n === 0, 'urutan' => $n];
            $db['gallery'][] = $g;
            return [201, ['ok' => true, 'data' => $g]];
        });
        out($c, $body);
    }

    if ($gid === 'order' && $m === 'PUT') {
        $b = readJson();
        if (!is_array($b) || !V::isList($b['ids'] ?? null)) out(422, ['errors' => ['ids' => 'ids harus array.']]);
        [$c, $body] = Db::write(function (array &$db) use ($lid, $b) {
            foreach ($b['ids'] as $n => $id) {
                foreach ($db['gallery'] as $i => $g)
                    if ($g['id'] === $id && $g['layanan_id'] === $lid) $db['gallery'][$i]['urutan'] = $n;
            }
            gNorm($db, $lid);
            return [200, ['ok' => true]];
        });
        out($c, $body);
    }

    if ($gid !== null) {
        $gi = findIdx($db['gallery'], $gid);
        if ($gi === null || $db['gallery'][$gi]['layanan_id'] !== $lid) out(404, ['error' => 'Gambar tidak ditemukan.']);

        if ($isImg && $m === 'PUT') {                     // ganti isi gambar (hasil crop)
            $type = ctype();
            if (!isset(IMG[$type])) out(415, ['error' => 'Hanya JPG/PNG/WebP.']);
            try { $buf = readBody(MAX_BYTES); } catch (RuntimeException $e) { out(413, ['error' => 'Ukuran melebihi 5 MB.']); }
            if (!imageOk($type, $buf)) out(415, ['error' => 'Isi file bukan gambar valid.']);
            [$c, $body] = Db::write(function (array &$db) use ($gid, $type, $buf) {
                $i = findIdx($db['gallery'], $gid);
                $old = $db['gallery'][$i]['url'];
                $db['gallery'][$i]['url'] = saveImage($type, $buf);
                removeFile($old);
                return [200, ['ok' => true, 'data' => $db['gallery'][$i]]];
            });
            out($c, $body);
        }
        if ($m === 'PUT') {
            $b = readJson();
            $b = is_array($b) ? $b : [];
            if (($b['caption'] ?? null) !== null && (!is_string($b['caption']) || V::len($b['caption']) > 120))
                out(422, ['errors' => ['caption' => 'Keterangan maksimal 120 karakter.']]);
            [$c, $body] = Db::write(function (array &$db) use ($gid, $lid, $b) {
                $i = findIdx($db['gallery'], $gid);
                if (($b['caption'] ?? null) !== null) $db['gallery'][$i]['caption'] = V::str($b['caption']);
                if (($b['cover'] ?? null) === true)
                    foreach ($db['gallery'] as $k => $g) if ($g['layanan_id'] === $lid) $db['gallery'][$k]['cover'] = ($k === $i);
                gNorm($db, $lid);
                return [200, ['ok' => true, 'data' => $db['gallery'][$i]]];
            });
            out($c, $body);
        }
        if ($m === 'DELETE') {
            [$c, $body] = Db::write(function (array &$db) use ($gid, $lid) {
                $i = findIdx($db['gallery'], $gid);
                removeFile($db['gallery'][$i]['url']);
                array_splice($db['gallery'], $i, 1);
                gNorm($db, $lid);
                return [200, ['ok' => true]];
            });
            out($c, $body);
        }
    }
}

out(404, ['error' => 'Endpoint tidak ditemukan.']);
