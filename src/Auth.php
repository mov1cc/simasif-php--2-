<?php
declare(strict_types=1);

/** Sesi login dan pembatas percobaan (rate limit). */
final class Auth
{
    public const SESI_DETIK = 28800;     // sesi login maksimal 8 jam
    public const JENDELA_DETIK = 900;    // jendela hitung + lama kunci: 15 menit

    public static function env(string $k, string $default): string
    {
        $v = getenv($k);
        return ($v === false || $v === '') ? $default : $v;
    }

    /** Hanya REMOTE_ADDR; X-Forwarded-For sengaja tidak dipercaya (mudah dipalsukan). */
    public static function ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /* ---------- sesi ---------- */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) self::SESI_DETIK);
        session_name('SIMASIFSESS');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }

    public static function signIn(string $uid): void
    {
        self::startSession();
        session_regenerate_id(true);   // cegah session fixation
        $_SESSION = ['uid' => $uid, 'login_at' => time()];
    }

    public static function signOut(): void
    {
        self::startSession();
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
    }

    /** User yang sedang login, atau null. Sesi otomatis batal bila akun nonaktif / password diganti setelah login. */
    public static function user(array $db): ?array
    {
        self::startSession();
        $uid = $_SESSION['uid'] ?? null;
        if (!is_string($uid)) return null;
        $at = (int) ($_SESSION['login_at'] ?? 0);
        $i = findIdx($db['users'], $uid);
        $u = $i === null ? null : $db['users'][$i];
        if (time() - $at > self::SESI_DETIK || !$u || !$u['aktif'] || (int) ($u['pw_changed'] ?? 0) > $at) {
            self::signOut();
            return null;
        }
        return $u;
    }

    public static function publicUser(array $u): array
    {
        return ['id' => $u['id'], 'email' => $u['email'], 'nama' => $u['nama'], 'role' => $u['role']];
    }

    public static function userIdx(array $users, string $email): ?int
    {
        foreach ($users as $i => $u) if ($u['email'] === $email) return $i;
        return null;
    }

    /* ---------- pembatas percobaan (tersimpan di db.json) ---------- */
    /** Sisa detik terkunci; 0 = tidak terkunci. */
    public static function locked(array $db, string $key): int
    {
        $lock = (int) ($db['auth_limit'][$key]['lock'] ?? 0);
        return $lock > time() ? $lock - time() : 0;
    }

    /** Catat satu percobaan; kunci setelah $max percobaan dalam satu jendela. */
    public static function hit(array &$db, string $key, int $max): void
    {
        $now = time();
        $r = $db['auth_limit'][$key] ?? ['n' => 0, 't' => $now, 'lock' => 0];
        if ($now - (int) $r['t'] > self::JENDELA_DETIK && (int) $r['lock'] <= $now) $r = ['n' => 0, 't' => $now, 'lock' => 0];
        $r['n']++;
        if ($r['n'] >= $max) $r['lock'] = $now + self::JENDELA_DETIK;
        $db['auth_limit'][$key] = $r;
    }

    /** Buang data pembatas yang kedaluwarsa agar db.json tidak membengkak. */
    public static function prune(array &$db): void
    {
        $now = time();
        foreach ($db['auth_limit'] as $k => $r)
            if ((int) $r['lock'] <= $now && $now - (int) $r['t'] > self::JENDELA_DETIK) unset($db['auth_limit'][$k]);
    }
}