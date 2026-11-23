/* Dimuat di <head>: sembunyikan nama/peran sampai user.js mengisi nilai asli. */
(function () {
  var root = document.documentElement;
  try {
    if (sessionStorage.getItem('simasifUser')) root.classList.add('u-ready');
  } catch (_) {}

  var s = document.createElement('style');
  s.textContent =
    'html:not(.u-ready) #sidebar .mt-3.text-xl.font-bold,' +
    'html:not(.u-ready) #sidebar .mt-3.text-xl.font-bold + div,' +
    'html:not(.u-ready) #topbar .text-right > div{visibility:hidden}';
  document.head.appendChild(s);

  // Pengaman: jika user.js gagal jalan, jangan sembunyikan selamanya
  setTimeout(function () { root.classList.add('u-ready'); }, 3000);
})();