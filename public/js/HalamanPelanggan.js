(function () {
  /* ---------- Menu hamburger ---------- */
  var navToggle = document.getElementById('nav-toggle');
  var menu = document.getElementById('menu');

  function setMenu(buka) {
    menu.classList.toggle('hidden', !buka);
    navToggle.setAttribute('aria-expanded', buka ? 'true' : 'false');
    navToggle.setAttribute('aria-label', buka ? 'Tutup menu' : 'Buka menu');
  }

  navToggle.addEventListener('click', function () {
    setMenu(menu.classList.contains('hidden'));
  });
  menu.addEventListener('click', function (e) {
    if (e.target.closest('a')) setMenu(false);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !menu.classList.contains('hidden') && navToggle.offsetParent !== null) {
      setMenu(false);
      navToggle.focus();
    }
  });
  window.matchMedia('(min-width: 768px)').addEventListener('change', function (e) {
    if (e.matches) setMenu(false);
  });
})();

(function () {
  var form = document.getElementById('form-cek');
  var kode = document.getElementById('kode');
  var err = document.getElementById('err-kode');
  var status = document.getElementById('status');
  var btn = document.getElementById('btn-cek');
  var label = document.getElementById('label-cek');
  var pola = /^SF-[A-Z0-9]{7}$/;

  /* Rapikan input: huruf besar, tanpa spasi, tambah tanda "-" jika terlewat */
  function rapikan(v) {
    v = v.replace(/\s+/g, '').toUpperCase();
    if (/^SF[A-Z0-9]{7}$/.test(v)) v = 'SF-' + v.slice(2);
    return v;
  }

  function setError(pesan) {
    err.textContent = pesan;
    if (pesan) kode.setAttribute('aria-invalid', 'true');
    else kode.removeAttribute('aria-invalid');
  }

  function cek() {
    var v = rapikan(kode.value);
    if (!v) { setError('Kode unik wajib diisi.'); return false; }
    if (!pola.test(v)) { setError('Format kode belum benar, contoh: SF-3WXWYZY.'); return false; }
    setError('');
    return true;
  }

  function setStatus(pesan, warna) {
    status.textContent = pesan;
    status.className = 'mt-3 mb-0 text-[13px] text-center ' + (warna || '');
  }

  kode.addEventListener('blur', function () { if (kode.value.trim()) cek(); });
  kode.addEventListener('input', function () { if (kode.hasAttribute('aria-invalid')) cek(); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    setStatus('');
    if (!cek()) { kode.focus(); return; }
    kode.value = rapikan(kode.value);

    btn.disabled = true;
    label.textContent = 'Memeriksa...';

    /* GANTI bagian ini dengan request ke backend Anda, contoh:
       fetch('/api/pemesanan/' + encodeURIComponent(kode.value))
         .then(function (r) { return r.json(); })
         .then(function (data) { ...tampilkan data.status... });
    */
    setTimeout(function () {
      btn.disabled = false;
      label.textContent = 'Cek Status';
      setStatus('Kode valid. Hubungkan form ini ke backend untuk menampilkan status pemesanan.', 'text-[#1b6e2e]');
    }, 700);
  });
})();

(function () {
  var tautan = Array.prototype.slice.call(document.querySelectorAll('#menu a[href^="#"]'));
  var bagian = ['home', 'layanan', 'panduan', 'cek-pemesanan'];

  function tandai(id) {
    tautan.forEach(function (a) {
      if (a.getAttribute('href') === '#' + id) a.setAttribute('aria-current', 'location');
      else a.removeAttribute('aria-current');
    });
  }

  if ('IntersectionObserver' in window) {
    var pengamat = new IntersectionObserver(function (entri) {
      entri.forEach(function (e) { if (e.isIntersecting) tandai(e.target.id); });
    }, { rootMargin: '-45% 0px -50% 0px' });
    bagian.forEach(function (id) { pengamat.observe(document.getElementById(id)); });
  }

  window.addEventListener('scroll', function () {
    if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) tandai('cek-pemesanan');
  }, { passive: true });
})();

(function () {
  var footer = document.querySelector('footer');
  var main = document.querySelector('main');
  function sesuaikan() {
    main.style.marginBottom = footer.offsetHeight + 'px';
  }
  sesuaikan();
  window.addEventListener('resize', sesuaikan);
  if ('ResizeObserver' in window) new ResizeObserver(sesuaikan).observe(footer);
})();

(function () {
  /* ---------- Label layanan dapat ditarik ---------- */
  var stage = document.getElementById('hero-stage');
  var draggables = document.querySelectorAll('[data-draggable]');
  if (!stage || !draggables.length) return;

  var topZ = 20;
  var dragControllers = [];
  var MARGIN_X = 28; // ruang untuk ikon panah kecil
  var MARGIN_Y = 8;

  draggables.forEach(function (el) {
    var offsetX = 0, offsetY = 0, startX = 0, startY = 0;
    var dragging = false;
    var tilt = Number(el.getAttribute('data-tilt')) || 0;

    function applyTransform() {
      el.style.transform = 'translate3d(' + offsetX + 'px, ' + offsetY + 'px, 0) rotate(' + tilt + 'deg)';
    }

    function resetPosition() {
      offsetX = 0;
      offsetY = 0;
      el.style.transform = '';
      el.style.zIndex = '';
    }

    function clampToStage(x, y) {
      var stageRect = stage.getBoundingClientRect();
      var elRect = el.getBoundingClientRect();
      var baseLeft = elRect.left - stageRect.left - offsetX;
      var baseTop = elRect.top - stageRect.top - offsetY;

      var minX = -baseLeft + MARGIN_X;
      var maxX = stageRect.width - baseLeft - elRect.width - MARGIN_X;
      var minY = -baseTop + MARGIN_Y;
      var maxY = stageRect.height - baseTop - elRect.height - MARGIN_Y;

      return {
        x: Math.min(Math.max(x, minX), Math.max(minX, maxX)),
        y: Math.min(Math.max(y, minY), Math.max(minY, maxY))
      };
    }

    el.addEventListener('pointerdown', function (e) {
      if (e.button !== 0 && e.pointerType === 'mouse') return;
      e.preventDefault();
      dragging = true;
      topZ += 1;
      el.style.zIndex = String(topZ);
      startX = e.clientX - offsetX;
      startY = e.clientY - offsetY;
      el.setPointerCapture(e.pointerId);
    });

    el.addEventListener('pointermove', function (e) {
      if (!dragging) return;
      var c = clampToStage(e.clientX - startX, e.clientY - startY);
      offsetX = c.x;
      offsetY = c.y;
      applyTransform();
    });

    function stopDrag(e) {
      if (!dragging) return;
      dragging = false;
      if (el.hasPointerCapture(e.pointerId)) el.releasePointerCapture(e.pointerId);
    }
    el.addEventListener('pointerup', stopDrag);
    el.addEventListener('pointercancel', stopDrag);

    dragControllers.push({ reset: resetPosition });
  });

  function resetAll() { dragControllers.forEach(function (c) { c.reset(); }); }
  window.matchMedia('(min-width: 768px)').addEventListener('change', resetAll);
  window.addEventListener('resize', resetAll);
})();