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

(function () {
  var daftar = document.getElementById('daftar-layanan');
  if (!daftar) return;

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function rp(n) { return 'Rp' + Number(n).toLocaleString('id-ID'); }
  function satuan(d) { return d ? d.nilai + ' ' + String(d.satuan).toLowerCase() : '-'; }
  function hargaTeks(l) {
    return (l.mulai_dari ? 'Mulai ' : '') + rp(l.harga) + (l.keterangan_harga === 'foto' ? '/foto' : '');
  }
  function ket(k) { return k === 'foto' ? '/foto' : k === 'pcs' ? '/pcs' : ''; }

  function kartu(l) {
    var img = l.cover
      ? '<img src="' + esc(l.cover) + '" alt="Foto layanan ' + esc(l.nama) + '" width="640" height="305" loading="lazy" class="block w-full aspect-[2.1/1] object-cover">'
      : '<div class="w-full aspect-[2.1/1] bg-[#7f7f7f]" aria-hidden="true"></div>';
    var output = l.output.slice(0, 3).map(function (o) {
  return '<li class="bg-[#f5f5f5] px-1 py-0.5 mb-0.5 text-xs">' + esc(o) + '</li>';
}).join('');
    return '<li class="flex flex-col overflow-hidden rounded-2xl bg-white shadow-[0_3px_5px_rgba(0,0,0,.3)]">' + img +
      '<div class="flex flex-1 flex-col px-3 pt-4 pb-3">' +
        '<div class="flex items-baseline justify-between gap-3">' +
          '<h3 class="m-0 text-2xl md:text-[28px] font-semibold leading-tight">' + esc(l.nama) + '</h3>' +
          '<p class="m-0 text-base md:text-lg font-semibold whitespace-nowrap">' + hargaTeks(l) + '</p></div>' +
        '<ul class="list-none m-0 mt-4 p-0 flex items-center justify-between text-xs">' +
          '<li class="flex items-center gap-1"><svg class="w-3.5 h-3.5" aria-hidden="true"><use href="#i-jam"/></svg>' + esc(satuan(l.durasi)) + '</li>' +
          '<li class="flex items-center gap-1"><svg class="w-3.5 h-3.5" aria-hidden="true"><use href="#i-timer"/></svg>Hasil ' + esc(satuan(l.estimasi_hasil)) + '</li>' +
          '<li class="flex items-center gap-1"><svg class="w-3.5 h-3.5" aria-hidden="true"><use href="#i-orang"/></svg>Maks. ' + (l.maks_orang || '-') + ' orang</li></ul>' +
        '<ul class="list-none m-0 mt-2 mb-4 p-0">' + output + '</ul>' +
        '<div class="mt-auto grid grid-cols-2 gap-5">' +
          '<button type="button" data-detail="' + esc(l.id) + '" aria-label="Detail ' + esc(l.nama) + '" class="flex h-8 items-center justify-center rounded-lg bg-black text-sm font-semibold text-white hover:bg-[#333] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1a1a1a]">Detail</button>' +
          '<a href="#cek-pemesanan" aria-label="Pesan ' + esc(l.nama) + '" class="flex h-8 items-center justify-center rounded-lg bg-[#ffe100] text-sm font-semibold text-black no-underline hover:brightness-95 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1a1a1a]">Pesan</a>' +
        '</div></div></li>';
  }

  function pesan(teks) {
    daftar.innerHTML = '<li class="col-span-full text-center text-base text-[#555]">' + esc(teks) + '</li>';
  }

  fetch('/api/publik/layanan', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
    .then(function (d) {
      if (!d.data.length) return pesan('Belum ada layanan yang tersedia saat ini.');
      daftar.innerHTML = d.data.map(kartu).join('');
    })
    .catch(function () { pesan('Layanan tidak dapat dimuat. Coba muat ulang halaman.'); });

  /* ---------- Modal detail ---------- */
  var modal = document.createElement('div');
  modal.className = 'hidden fixed inset-0 z-[60] bg-black/60 items-center justify-center p-4';
  modal.setAttribute('role', 'dialog');
  modal.setAttribute('aria-modal', 'true');
  modal.innerHTML = '<div class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-full overflow-y-auto">' +
    '<div class="sticky top-0 bg-[#0045e0] text-white px-5 py-3 flex items-center justify-between">' +
    '<h3 id="dl-judul" class="m-0 text-lg font-semibold"></h3>' +
    '<button type="button" id="dl-tutup" aria-label="Tutup" class="w-8 h-8 rounded-full hover:bg-white/20 text-xl leading-none">&times;</button></div>' +
    '<div id="dl-isi" class="p-5 text-sm"></div></div>';
  document.body.appendChild(modal);

  function tutup() { modal.classList.add('hidden'); modal.classList.remove('flex'); }
  modal.addEventListener('click', function (e) { if (e.target === modal) tutup(); });
  document.getElementById('dl-tutup').addEventListener('click', tutup);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') tutup(); });

  function baris(a, b) {
    return '<div class="flex justify-between gap-3 bg-[#f5f5f5] rounded px-2 py-1 mb-1"><span>' + esc(a) + '</span><span class="font-semibold whitespace-nowrap">' + b + '</span></div>';
  }
  function isiDetail(l) {
    var h = '';
    if (l.galeri.length) {
      h += '<div class="flex gap-2 overflow-x-auto pb-2 mb-3">' + l.galeri.map(function (g) {
        return '<img src="' + esc(g.url) + '" alt="' + esc(g.caption || l.nama) + '" loading="lazy" class="h-28 w-auto rounded-lg object-cover flex-shrink-0">';
      }).join('') + '</div>';
    }
    if (l.deskripsi) h += '<p class="m-0 mb-3 leading-snug">' + esc(l.deskripsi) + '</p>';

    h += '<h4 class="m-0 mb-1 font-semibold">Harga</h4>';
    if (l.jenis_harga === 'tunggal') h += baris(l.nama, rp(l.harga_tunggal) + (l.keterangan_harga === 'foto' ? '/foto' : ''));
    else l.harga_pilihan.forEach(function (g) {
      h += '<div class="text-xs font-semibold mt-2 mb-1">' + esc(g.nama) + '</div>';
      g.pilihan.forEach(function (p) { h += baris(p.nama, rp(p.harga)); });
    });

    h += '<h4 class="m-0 mt-3 mb-1 font-semibold">Hasil yang Didapat</h4>';
    l.output_detail.forEach(function (o) {
      h += baris(o.nama, o.tipe === 'pilihan' ? esc(o.sub_pilihan.join(', ')) : '');
    });

    if (l.addons.length) {
      h += '<h4 class="m-0 mt-3 mb-1 font-semibold">Add On</h4>';
      l.addons.forEach(function (a) {
        if (a.jenis_harga === 'tunggal') h += baris(a.nama, rp(a.harga_tunggal) + ket(a.keterangan_harga));
        else {
          h += '<div class="text-xs font-semibold mt-2 mb-1">' + esc(a.nama) + '</div>';
          a.pilihan_harga.forEach(function (p) { h += baris(p.nama, rp(p.harga) + ket(p.keterangan)); });
        }
      });
    }
    return h;
  }

  daftar.addEventListener('click', function (e) {
    var b = e.target.closest('[data-detail]');
    if (!b) return;
    fetch('/api/publik/layanan/' + encodeURIComponent(b.getAttribute('data-detail')))
      .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
      .then(function (d) {
        document.getElementById('dl-judul').textContent = d.data.nama;
        document.getElementById('dl-isi').innerHTML = isiDetail(d.data);
        modal.classList.remove('hidden'); modal.classList.add('flex');
      })
      .catch(function () { alert('Detail layanan tidak dapat dimuat.'); });
  });
})();