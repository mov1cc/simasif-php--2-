/* Menampilkan nama & peran user yang sedang login pada sidebar dan topbar. */
(function () {
  // Jangan jalan di halaman publik (mencegah loop redirect ke Login.html)
  const HALAMAN_PUBLIK = ['login.html', 'lupapassword.html'];
  const halaman = location.pathname.split('/').pop().toLowerCase();
  if (HALAMAN_PUBLIK.includes(halaman)) return;

  const KEY = 'simasifUser';
  // ... sisa kode tetap sama
  const ROLE = { owner: 'Owner', pegawai: 'Pegawai' };

  function apply(u) {
    document.querySelectorAll('#sidebar div, #topbar div').forEach(el => {
      if (el.children.length) return;
      // elemen nama: awalnya "Anonimus", lalu ditandai agar bisa diperbarui lagi
      if (el.textContent.trim() === 'Anonimus' || el.dataset.userName !== undefined) {
        el.dataset.userName = '';
        el.textContent = u.nama;
        const role = el.nextElementSibling;   // baris di bawah nama = peran
        if (role) role.textContent = ROLE[u.role] || u.role;
      }
    });
  }

  function cached() {
    try { return JSON.parse(sessionStorage.getItem(KEY)); } catch (_) { return null; }
  }

 async function load() {
  const c = cached();
  if (c) apply(c);
  try {
    const res = await fetch('/api/auth/me', { cache: 'no-store' });
    if (res.status === 401) {
sessionStorage.removeItem(KEY);
  if (!/login\.html$/i.test(location.pathname)) location.replace('Login.html');
  return;
}
    if (!res.ok) return;
    const u = (await res.json()).data;
    sessionStorage.setItem(KEY, JSON.stringify(u));
    apply(u);
  } catch (_) { }
}

  // Logout sungguhan: akhiri sesi di server, baru pindah ke halaman login
  document.querySelectorAll('#sidebar a[href="Login.html"]').forEach(a =>
    a.addEventListener('click', async e => {
      e.preventDefault();
      try {
        await fetch('/api/auth/logout', { method: 'POST', headers: { 'Content-Type': 'application/json' } });
      } catch (_) { }
      sessionStorage.removeItem(KEY);
      location.href = 'Login.html';
    }));

document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });

  load();
})();