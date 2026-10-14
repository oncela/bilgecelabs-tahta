/* BilgeceLabs Tahta: uzaktan erişim köprüsü (MeshCentral tarafı).
 *
 * Panel (tahta.*) uzaktan erişim sayfasını (uzak.*) bir çerçevenin içinde açar.
 * Bu betik, tema paketiyle MeshCentral'a yüklenir; MeshCentral kodu değiştirilmez.
 *  - Çerçevedeyken MeshCentral'ın kendi araç çubuklarını gizler (panel kendi
 *    araç çubuğunu gösterir).
 *  - Soru sormadan bağlanır (ekranda oturum seçme listesi çıkmaz).
 *  - Bağlantı durumunu panele bildirir, panelden gelen komutları uygular.
 * Mesajlar yalnız aynı ana alan adındaki (ör. *.bilgecelabs.com) panelle alınıp verilir.
 */
(function () {
  'use strict';
  if (window.top === window.self) return; // yalnız panelin içinde

  var VIEWS = { 11: 'desktop', 12: 'terminal', 13: 'files' };
  var view = VIEWS[new URLSearchParams(location.search).get('viewmode')] || 'desktop';
  document.documentElement.classList.add('bl-embedded', 'bl-view-' + view);

  // Panelin adresi: çerçeveyi açan sayfa, bizimle aynı ana alan adında olmalı.
  function baseDomain(host) { return host.split('.').slice(-2).join('.'); }
  var panelOrigin = null;
  var candidates = [];
  if (location.ancestorOrigins && location.ancestorOrigins.length) candidates.push(location.ancestorOrigins[0]);
  if (document.referrer) candidates.push(document.referrer);
  for (var i = 0; i < candidates.length && !panelOrigin; i++) {
    try {
      var u = new URL(candidates[i]);
      if (u.protocol === 'https:' && baseDomain(u.hostname) === baseDomain(location.hostname)) panelOrigin = u.origin;
    } catch (e) { /* geçersiz adres */ }
  }
  if (!panelOrigin) return; // tanınmayan çerçeve: hiçbir şey yapma

  function send(msg) {
    msg.src = 'bilgece-remote';
    msg.view = view;
    window.parent.postMessage(msg, panelOrigin);
  }

  // MeshCentral içindeki bağlantı nesnesinin durumu: 0 kapalı, 1-2 bağlanıyor, 3 bağlı
  function state() {
    try {
      if (view === 'desktop') return (typeof desktop !== 'undefined' && desktop) ? desktop.State : 0;
      if (view === 'terminal') return (typeof terminal !== 'undefined' && terminal) ? terminal.State : 0;
      if (view === 'files') return (typeof files !== 'undefined' && files) ? files.State : 0;
    } catch (e) { /* henüz yüklenmedi */ }
    return 0;
  }

  function ready() {
    // Cihaz sayfası açıldı mı? (bağlan düğmesi DOM'da ve etkin)
    var id = { desktop: 'connectbutton1', terminal: 'connectbutton2', files: 'p13Connect' }[view];
    var el = document.getElementById(id);
    return el && !el.disabled && typeof currentNode !== 'undefined' && currentNode;
  }

  function connect() {
    if (view === 'desktop' && typeof connectDesktop === 'function') connectDesktop(null, 1); // 1: doğrudan konsol, soru yok
    else if (view === 'terminal' && typeof connectTerminal === 'function') connectTerminal(null, 1);
    else if (view === 'files' && typeof connectFiles === 'function') connectFiles(null, 1);
  }

  function disconnect() {
    // MeshCentral'da aynı işlev açık bağlantıyı kapatır
    if (state() === 0) return;
    if (view === 'desktop') connectDesktop(null);
    else if (view === 'terminal') connectTerminal(null);
    else if (view === 'files') connectFiles(null);
  }

  var commands = {
    connect: function () { if (state() === 0) connect(); },
    disconnect: disconnect,
    reconnect: function () { disconnect(); setTimeout(connect, 800); },
    cad: function () { if (typeof sendCAD === 'function') sendCAD(); },
    screenshot: function () { if (typeof deskSaveImage === 'function') deskSaveImage(); },
    control: function (on) {
      var cb = document.getElementById('DeskControl');
      if (cb && cb.checked !== !!on) cb.click();
    },
  };

  window.addEventListener('message', function (ev) {
    if (ev.origin !== panelOrigin || !ev.data || ev.data.src !== 'bilgece-panel') return;
    var fn = commands[ev.data.cmd];
    if (fn) fn(ev.data.value);
  });

  // MeshCentral'ın kendi araç çubuklarını gizle (panel kendi çubuğunu gösterir).
  function hideToolbars() {
    var ids = { desktop: ['connectbutton1'], terminal: ['connectbutton2'], files: [] }[view];
    ids.forEach(function (id) {
      var el = document.getElementById(id);
      var bar = el && el.closest('.areaHead');
      if (bar) bar.classList.add('bl-hidden');
    });
  }

  // Hazır olunca bir kez bağlan, sonra durum değişikliklerini bildir.
  var autoDone = false;
  var last = -1;
  setInterval(function () {
    if (!autoDone && ready()) { autoDone = true; hideToolbars(); connect(); }
    var s = state();
    if (s !== last) {
      last = s;
      var ctl = document.getElementById('DeskControl');
      send({ type: 'state', state: s, control: ctl ? ctl.checked : true });
    }
  }, 400);
  send({ type: 'hello' });
})();
