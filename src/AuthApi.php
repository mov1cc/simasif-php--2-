<?php
declare(strict_types=1);
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/ValidateAuth.php';

/**
 * Endpoint auth (dipanggil dari public/api/index.php):
 *   POST /api/auth/login    {email, password}
 *   POST /api/auth/logout
 *   GET  /api/auth/me
 * Memakai fungsi global index.php: out(), readJson(), ctype(), findIdx(), nowIso().
 */
final class AuthApi
{
    public static function handle(string $m, string $p): void
    {
        if (!str_starts_with($p, '/api/auth/')) return;
        header('Cache-Control: no-store');
        if ($m === 'POST' && $p === '/api/auth/login') self::login();
        if ($m === 'POST' && $p === '/api/auth/logout') self::logout();
        if ($m === 'GET' && $p === '/api/auth/me') self::me();
    }

    /** Wajib JSON: form lintas-situs tidak bisa mengirim application/json tanpa preflight (mitigasi CSRF). */
    private static function needJson(): void
    {
        if (ctype() !== 'application/json') out(415, ['error' => 'Content-Type harus application/json.']);
    }

    private static function login(): never
    {
        self::needJson();
        $b = readJson();
        if ($err = ValidateAuth::login($b)) out(422, ['errors' => $err]);

        $email = ValidateAuth::email($b['email']);
        $pw = $b['password'];
        $ip = Auth::ip();
        $kE = 'le:' . hash('sha256', $email . '|' . $ip);
        $kI = 'li:' . hash('sha256', $ip);

        $db = Db::read();
        $wait = max(Auth::locked($db, $kE), Auth::locked($db, $kI));
        if ($wait > 0) out(429, ['error' => 'Terlalu banyak percobaan masuk. Coba lagi dalam ' . (int) ceil($wait / 60) . ' menit.']);

        // verifikasi di luar kunci berkas; akun tak dikenal tetap menjalankan bcrypt agar waktunya sama
        $i = Auth::userIdx($db['users'], $email);
        $u = $i === null ? null : $db['users'][$i];
        if ($u === null) { password_hash($pw, PASSWORD_DEFAULT); $ok = false; }
        else $ok = password_verify($pw, $u['password_hash']) && $u['aktif'];

        Db::write(function (array &$db) use ($ok, $u, $pw, $kE, $kI) {
            Auth::prune($db);
            if (!$ok) { Auth::hit($db, $kE, 5); Auth::hit($db, $kI, 20); return [200, []]; }
            unset($db['auth_limit'][$kE]);
            $i = findIdx($db['users'], $u['id']);
            if ($i !== null) {
                $db['users'][$i]['terakhir_masuk'] = nowIso();
                if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT))
                    $db['users'][$i]['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
            }
            return [200, []];
        });

        if (!$ok) out(401, ['error' => 'Email atau password salah.']);
        Auth::signIn($u['id']);
        out(200, ['ok' => true, 'data' => Auth::publicUser($u)]);
    }

    private static function logout(): never
    {
        self::needJson();
        Auth::signOut();
        header('Clear-Site-Data: "cache"');
        out(200, ['ok' => true]);
    }

    private static function me(): never
    {
        $u = Auth::user(Db::read());
        if (!$u) out(401, ['error' => 'Belum masuk.']);
        out(200, ['data' => Auth::publicUser($u)]);
    }
}