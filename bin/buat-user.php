<?php
declare(strict_types=1);
/**
 * Buat akun baru, atau atur ulang password akun yang sudah ada (email sama).
 *   php bin/buat-user.php owner@studio.com "Nama Owner" owner
 *   php bin/buat-user.php pegawai@studio.com "Nama Pegawai" pegawai
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ValidateAuth.php';

[, $email, $nama, $role] = array_pad($argv, 4, '');
$email = ValidateAuth::email($email);
$nama = V::str($nama);
if (ValidateAuth::emailError($email) !== '' || $nama === '' || !in_array($role, ['owner', 'pegawai'], true)) {
    fwrite(STDERR, "Pemakaian: php bin/buat-user.php <email> \"<nama>\" <owner|pegawai>\n");
    exit(1);
}

fwrite(STDOUT, 'Password: ');
$win = PHP_OS_FAMILY === 'Windows';          // di Windows input terlihat saat diketik
if (!$win) system('stty -echo');
$pw = rtrim((string) fgets(STDIN), "\r\n");
if (!$win) { system('stty echo'); echo PHP_EOL; }
if (($err = ValidateAuth::passwordError($pw)) !== '') { fwrite(STDERR, $err . "\n"); exit(1); }

[, $res] = Db::write(function (array &$db) use ($email, $nama, $role, $pw) {
    $hash = password_hash($pw, PASSWORD_DEFAULT);
    foreach ($db['users'] as $i => $u) {
        if ($u['email'] !== $email) continue;
        $db['users'][$i] = array_merge($u, ['nama' => $nama, 'role' => $role, 'password_hash' => $hash, 'pw_changed' => time(), 'aktif' => true]);
        return [200, ['msg' => 'Akun sudah ada: data dan password diperbarui.']];
    }
    $db['users'][] = [
        'id' => 'u' . bin2hex(random_bytes(5)), 'email' => $email, 'nama' => $nama, 'role' => $role,
        'password_hash' => $hash, 'aktif' => true, 'pw_changed' => 0, 'dibuat' => date('c'),
    ];
    return [201, ['msg' => 'Akun dibuat.']];
});
echo $res['msg'], PHP_EOL;