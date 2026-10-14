<?php // Komut ve sonuç pencereleri (Tahtalar ve Sınıflar sayfaları ortak kullanır) ?>
<dialog id="cmd-dialog" class="aci-dialog">
  <form method="dialog" id="cmd-form" class="card">
    <div class="card-header"><h3 class="card-title" id="cmd-title"></h3></div>
    <div class="card-body">
      <p class="text-secondary" id="cmd-target"></p>
      <div id="cmd-fields"></div>
      <div class="alert alert-danger mt-3 mb-0" id="cmd-error" hidden></div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
      <button type="button" class="btn" data-close>Vazgeç</button>
      <button type="submit" class="btn btn-primary" id="cmd-submit">Gönder</button>
    </div>
  </form>
</dialog>

<dialog id="result-dialog" class="aci-dialog wide">
  <div class="card">
    <div class="card-header"><h3 class="card-title">Sonuçlar</h3></div>
    <div class="card-body">
      <p class="text-secondary" id="result-summary"></p>
      <div id="result-list" class="d-grid gap-2"></div>
    </div>
    <div class="card-footer d-flex justify-content-end"><button type="button" class="btn" data-close>Kapat</button></div>
  </div>
</dialog>

