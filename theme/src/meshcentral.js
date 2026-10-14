/* BilgeceLabs Tahta: uzaktan erişim ekranı panelin içinde açıldığında
   otomatik bağlanır (ekran / komut satırı / dosyalar). MeshCentral'ın kendi
   kodu değiştirilmez; tema paketi (theme-pack) ile yüklenir. */
(function () {
  'use strict';
  if (window.top === window.self) return; // yalnız panelin içinde (çerçevede)

  // Her görünüm için bağlanma düğmesi; her biri sayfa başına en fazla bir kez tıklanır.
  var targets = ['connectbutton1', 'connectbutton2', 'p13Connect'];
  var clicked = {};
  var started = Date.now();

  function visible(el) {
    return el && el.offsetParent !== null && !el.disabled;
  }

  var timer = setInterval(function () {
    for (var i = 0; i < targets.length; i++) {
      var id = targets[i];
      var el = document.getElementById(id);
      // Düğme görünür ve etkinse bağlantı kurulmamış demektir.
      if (!clicked[id] && visible(el)) {
        clicked[id] = true;
        el.click();
      }
    }
    if (Date.now() - started > 30000) clearInterval(timer); // 30 sn sonra dur
  }, 500);
})();
