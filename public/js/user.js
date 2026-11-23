/* Menampilkan nama & peran user yang sedang login pada sidebar dan topbar. */
(function () {
  const HALAMAN_PUBLIK = ['login.html', 'lupapassword.html'];
  const halaman = location.pathname.split('/').pop().toLowerCase();
  if (HALAMAN_PUBLIK.includes(halaman)) return;

  const KEY = 'simasifUser';
  const ROLE = { owner: 'Owner', pegawai: 'Pegawai' };
  const root = document.documentElement;

  function apply(u) {
    document.querySelectorAll('#sidebar div, #topbar div').forEach(el => {
      if (el.children.length) return;
      if (el.textContent.trim() === 'Anonimus' || el.dataset.userName !== undefined) {
        el.dataset.userName = '';
        el.textContent = u.nama;
        const role = el.nextElementSibling;
        if (role) role.textContent = ROLE[u.role] || u.role;
      }
    });
    root.classList.add('u-ready');
  }

  function cached() {
    try { return JSON.parse(sessionStorage.getItem(KEY)); } catch (_) { return null; }
  }

  // Pasang dari cache secepat mungkin (script ini dimuat di akhir body, sidebar sudah ada)
  const c = cached();
  if (c) apply(c);

  async function load() {
    try {
      const res = await fetch('/api/auth/me', { cache: 'no-store' });
      if (res.status === 401) {
        sessionStorage.removeItem(KEY);
        if (!/login\.html$/i.test(location.pathname)) location.replace('Login.html');
        return;
      }
      if (!res.ok) { root.classList.add('u-ready'); return; }
      const u = (await res.json()).data;
      sessionStorage.setItem(KEY, JSON.stringify(u));
      apply(u);
    } catch (_) { root.classList.add('u-ready'); }   // jangan biarkan nama tersembunyi selamanya
  }

  // Logout sungguhan
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