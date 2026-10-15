// Acı Tahta paneli: sayfa davranışları.
'use strict';

const CSRF = document.querySelector('meta[name=csrf]')?.content || '';

async function api(action, body, params = '') {
  const opts = { headers: { Accept: 'application/json' } };
  if (body !== undefined) {
    opts.method = 'POST';
    opts.headers['Content-Type'] = 'application/json';
    opts.headers['X-CSRF'] = CSRF;
    opts.body = JSON.stringify(body);
  }
  const res = await fetch('admin_api.php?action=' + action + params, opts);
  if (res.status === 401) {
    location.href = 'login.php';
    throw new Error('Oturum kapandı');
  }
  const data = await res.json().catch(() => ({ error: 'Sunucudan geçersiz yanıt geldi.' }));
  if (!res.ok || data.error) throw new Error(data.error || 'Hata (' + res.status + ')');
  return data;
}

// Küçük DOM yardımcısı: metinler her zaman textContent ile eklenir.
function el(tagName, className, text) {
  const e = document.createElement(tagName);
  if (className) e.className = className;
  if (text !== undefined && text !== null) e.textContent = String(text);
  return e;
}

// Durum rozeti (Tabler badge). state: ok / warn / bad / off / new
const BADGE = { ok: 'bg-success-lt', warn: 'bg-warning-lt', bad: 'bg-danger-lt', off: 'bg-secondary-lt', new: 'bg-warning-lt' };
function tag(state, text) {
  return el('span', 'badge ' + (BADGE[state] || BADGE.off), text);
}

function fmtUptime(s) {
  if (!s) return '—';
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  if (d) return d + ' gün ' + h + ' sa';
  if (h) return h + ' sa ' + m + ' dk';
  return m + ' dk';
}

function fmtTime(iso) {
  if (!iso) return 'hiç';
  const t = new Date(iso.replace(' ', 'T') + '+03:00');
  const s = Math.max(0, (Date.now() - t.getTime()) / 1000);
  if (s < 60) return 'az önce';
  if (s < 3600) return Math.floor(s / 60) + ' dk önce';
  if (s < 86400) return Math.floor(s / 3600) + ' sa önce';
  return t.toLocaleString('tr-TR', { dateStyle: 'short', timeStyle: 'short' });
}

// data-close özniteliği olan düğmeler bulunduğu pencereyi kapatır.
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-close]');
  if (btn) btn.closest('dialog').close();
});

const STATUS = {
  pending: ['off', 'Bekliyor'],
  sent: ['warn', 'Çalışıyor'],
  done: ['ok', 'Tamam'],
  failed: ['bad', 'Hata'],
  expired: ['off', 'Ulaşılamadı'],
};

// ---------------- Tahtalar sayfası: liste ----------------

const Devices = {
  list: [],
  selected: new Set(),

  visible() {
    const room = document.getElementById('room-filter').value;
    const type = document.getElementById('type-filter').value;
    const loc = document.getElementById('loc-filter').value;
    const q = document.getElementById('search').value.trim().toLocaleLowerCase('tr');
    return this.list.filter((d) => {
      if (loc && String(d.location_id || '') !== loc) return false;
      if (type && d.type !== type) return false;
      if (room && d.room !== room) return false;
      if (!q) return true;
      return [d.label, d.hostname, d.user, d.ip, d.room].join(' ').toLocaleLowerCase('tr').includes(q);
    });
  },

  async load() {
    try {
      const data = await api('devices');
      this.list = data.devices;
      // Silinmiş tahtaları seçimden çıkar.
      const ids = new Set(this.list.map((d) => d.id));
      for (const id of this.selected) if (!ids.has(id)) this.selected.delete(id);
      this.renderRooms();
      this.render();
    } catch (err) {
      this.showEmpty(err.message);
    }
  },

  showEmpty(text) {
    const tbody = document.getElementById('rows');
    tbody.replaceChildren();
    const tr = el('tr', 'empty-row');
    const td = el('td', '', text);
    td.colSpan = 9;
    tr.append(td);
    tbody.append(tr);
  },

  renderRooms() {
    const sel = document.getElementById('room-filter');
    const current = sel.value;
    const rooms = [...new Set(this.list.map((d) => d.room).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'tr'));
    sel.replaceChildren(new Option('Tüm sınıflar', ''));
    for (const r of rooms) sel.append(new Option(r, r));
    sel.value = rooms.includes(current) ? current : '';
    const dl = document.getElementById('room-list');
    dl.replaceChildren(...rooms.map((r) => new Option(r)));
    // Lokasyon filtresi ve pencere seçimi, cihazlarda görünen lokasyonlardan
    const locs = new Map();
    for (const d of this.list) if (d.location_id) locs.set(d.location_id, d.location);
    const lf = document.getElementById('loc-filter');
    const lcur = lf.value;
    lf.replaceChildren(new Option('Tüm lokasyonlar', ''));
    for (const [idv, nm] of locs) lf.append(new Option(nm, idv));
    lf.value = [...locs.keys()].map(String).includes(lcur) ? lcur : '';
  },

  render() {
    const rows = this.visible();
    const online = this.list.filter((d) => d.online).length;
    const counts = document.getElementById('counts');
    counts.replaceChildren();
    const c1 = el('span');
    c1.append(el('b', '', online), ' çevrimiçi');
    const c2 = el('span');
    c2.append(el('b', '', this.list.length), ' toplam');
    counts.append(c1, ' · ', c2);

    if (!this.list.length) {
      this.showEmpty('Henüz kayıtlı tahta yok. Ayarlar sayfasından kurulum dosyasını indirip bir tahtaya kurun.');
    } else if (!rows.length) {
      this.showEmpty('Filtreye uyan tahta yok.');
    } else {
      document.getElementById('rows').replaceChildren(...rows.map((d) => this.row(d)));
    }
    this.updateSelection();
  },

  row(d) {
    const tr = el('tr', 'clickable' + (this.selected.has(d.id) ? ' selected' : ''));
    tr.dataset.id = d.id;

    const tdChk = el('td');
    const chk = document.createElement('input');
    chk.type = 'checkbox';
    chk.className = 'form-check-input';
    chk.checked = this.selected.has(d.id);
    chk.setAttribute('aria-label', d.label + ' seç');
    chk.addEventListener('click', (e) => {
      e.stopPropagation();
      if (chk.checked) this.selected.add(d.id);
      else this.selected.delete(d.id);
      tr.classList.toggle('selected', chk.checked);
      this.updateSelection();
    });
    tdChk.append(chk);

    const tdName = el('td');
    tdName.append(el('div', 'fw-medium', d.label));
    if (d.label !== d.hostname) tdName.append(el('div', 'text-secondary small', d.hostname));

    const tdState = el('td');
    tdState.append(d.online ? tag('ok', 'Çevrimiçi') : tag('off', 'Kapalı · ' + fmtTime(d.last_seen)));
    if (d.busy) tdState.append(' ', tag('warn', d.busy + ' işlem'));

    const tdDef = el('td');
    tdDef.append(tag(d.defender.state === 'unknown' ? 'off' : d.defender.state, d.defender.text));
    const tdMesh = el('td');
    tdMesh.append(tag(d.mesh.state, d.mesh.text));

    tr.append(
      tdChk,
      tdName,
      el('td', '', d.location || '—'),
      el('td', '', d.type_label || '—'),
      el('td', '', d.room || '—'),
      tdState,
      el('td', '', d.user || '—'),
      tdDef,
      tdMesh,
      el('td', 'mono', d.ip || '—'),
      el('td', 'nowrap', d.online ? fmtUptime(d.uptime_s) : '—'),
    );
    tr.addEventListener('click', () => DeviceDialog.open(d));
    return tr;
  },

  updateSelection() {
    const n = this.selected.size;
    document.getElementById('selcount').textContent = n + ' tahta seçili';
    document.querySelectorAll('[data-cmd]').forEach((b) => { b.disabled = n === 0; });
    const vis = this.visible();
    const all = document.getElementById('select-all');
    all.checked = vis.length > 0 && vis.every((d) => this.selected.has(d.id));
  },

  async assignLocation() {
    if (!this.selected.size) { alert('Önce cihaz seçin.'); return; }
    let opts = '<option value="">— Lokasyonsuz —</option>';
    try {
      const data = await api('locations');
      opts += data.locations.map((l) => '<option value="' + l.id + '">' + l.name.replace(/[<>&]/g, '') + '</option>').join('');
    } catch (e) {}
    const dlg = document.getElementById('assign-dialog');
    document.getElementById('assign-select').innerHTML = opts;
    document.getElementById('assign-count').textContent = this.selected.size + ' cihaz seçili';
    dlg.showModal();
  },

  init() {
    document.getElementById('room-filter').addEventListener('change', () => this.render());
    document.getElementById('type-filter').addEventListener('change', () => this.render());
    document.getElementById('loc-filter').addEventListener('change', () => this.render());
    document.getElementById('assign-loc-btn').addEventListener('click', () => this.assignLocation());
    document.getElementById('assign-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        await api('assign_location', { location_id: document.getElementById('assign-select').value, device_ids: [...this.selected] });
        document.getElementById('assign-dialog').close();
        this.load();
      } catch (err) { alert(err.message); }
    });
    document.getElementById('search').addEventListener('input', () => this.render());
    document.getElementById('select-all').addEventListener('change', (e) => {
      for (const d of this.visible()) {
        if (e.target.checked) this.selected.add(d.id);
        else this.selected.delete(d.id);
      }
      this.render();
    });
    document.querySelectorAll('[data-cmd]').forEach((b) => {
      b.addEventListener('click', () => CommandDialog.open(b.dataset.cmd, [...this.selected], () => this.load()));
    });
    this.load();
    setInterval(() => { if (!document.hidden) this.load(); }, 10000);
  },
};

// ---------------- Komut gönderme ----------------

// Her işlem için pencerede sorulacak alanlar.
const COMMANDS = {
  message: {
    title: 'Mesaj gönder',
    fields: [
      { k: 'text', label: 'Mesaj', type: 'textarea', required: true },
      { k: 'title', label: 'Başlık (isteğe bağlı)', type: 'text' },
    ],
  },
  restart: {
    title: 'Yeniden başlat',
    fields: [{ k: 'delay', label: 'Kaç saniye sonra', type: 'number', value: '60' }],
  },
  shutdown: {
    title: 'Kapat',
    fields: [{ k: 'delay', label: 'Kaç saniye sonra', type: 'number', value: '60' }],
  },
  cancel_shutdown: { title: 'Kapatmayı iptal et', fields: [] },
  uninstall: {
    title: 'Tahtadan kaldır',
    note: 'Ajan ve uzaktan erişim bu tahtadan kaldırılır; tahta artık panele bağlanmaz. Geri almak için kurulum dosyasını yeniden çalıştırmak gerekir. Kaldırma tamamlanınca tahtayı panelden de silebilirsiniz.',
    fields: [],
  },
  run_app: {
    title: 'Uygulama veya adres aç',
    note: 'Seçili tahtaların ekranında açılır. Program yolu (C:\\...\\program.exe), belge ya da web adresi girebilirsiniz.',
    fields: [
      { k: 'path', label: 'Program / belge / adres', type: 'text', required: true },
      { k: 'args', label: 'Ek parametre (isteğe bağlı)', type: 'text' },
    ],
  },
  wol_prepare: {
    title: 'Uzaktan açmaya hazırla',
    note: 'Ağ kartında Wake-on-LAN açılır, Windows hızlı başlatma kapatılır ve her tahta için hazır olup olmadığı raporlanır. BIOS ayarı ayrıca elle yapılmalıdır.',
    fields: [],
  },
  // mesh: true olanlar MeshCentral üzerinden gider (tahta kapalıyken de çalışabilir)
  wake: {
    title: 'Uzaktan aç',
    mesh: true,
    note: 'Aynı ağdaki açık bir tahta üzerinden uyandırma sinyali gönderilir. Tahtanın BIOS ayarlarında Wake-on-LAN açık olmalı.',
    fields: [],
  },
  open_url: {
    title: 'Web adresi aç',
    mesh: true,
    fields: [{ k: 'url', label: 'Adres', type: 'url', required: true }],
  },
  toast: {
    title: 'Bildirim göster',
    mesh: true,
    fields: [
      { k: 'text', label: 'Bildirim metni', type: 'text', required: true },
      { k: 'title', label: 'Başlık (isteğe bağlı)', type: 'text' },
    ],
  },
  defender_scan: { title: 'Virüs taraması başlat', fields: [] },
  defender_update: { title: 'Virüs imzalarını güncelle', fields: [] },
  powershell: {
    title: 'PowerShell komutu çalıştır',
    note: 'Komut tahtada yönetici yetkisiyle çalışır. Göndermeden önce kontrol edin.',
    fields: [
      { k: 'script', label: 'Komut', type: 'textarea', required: true },
      { k: 'timeout', label: 'Zaman aşımı (saniye)', type: 'number', value: '300' },
    ],
  },
};

const CommandDialog = {
  type: null,
  ids: [],
  onDone: null,

  // ids: hedef tahtalar; onDone: gönderimden sonra sayfayı yenilemek için
  open(type, ids, onDone) {
    const def = COMMANDS[type];
    if (!def || !ids.length) return;
    this.type = type;
    this.ids = ids;
    this.onDone = onDone || null;
    const n = ids.length;
    document.getElementById('cmd-title').textContent = def.title;
    document.getElementById('cmd-target').textContent =
      n + ' tahtaya gönderilecek.' + (def.note ? ' ' + def.note : '');
    const box = document.getElementById('cmd-fields');
    box.replaceChildren();
    for (const f of def.fields) {
      const id = 'cmd-' + f.k;
      const wrap = el('div', 'mb-3');
      const label = el('label', 'form-label', f.label);
      label.htmlFor = id;
      const input = document.createElement(f.type === 'textarea' ? 'textarea' : 'input');
      input.className = 'form-control' + (f.type === 'textarea' ? ' mono' : '');
      if (f.type === 'textarea') input.rows = 6;
      if (f.type !== 'textarea') input.type = f.type;
      input.id = id;
      input.name = f.k;
      if (f.value) input.value = f.value;
      if (f.required) input.required = true;
      if (f.type === 'number') input.min = '0';
      wrap.append(label, input);
      box.append(wrap);
    }
    document.getElementById('cmd-error').hidden = true;
    document.getElementById('cmd-submit').disabled = false;
    document.getElementById('cmd-dialog').showModal();
  },

  async submit(e) {
    e.preventDefault();
    const args = {};
    for (const f of COMMANDS[this.type].fields) {
      args[f.k] = document.getElementById('cmd-' + f.k).value;
    }
    const btn = document.getElementById('cmd-submit');
    const errBox = document.getElementById('cmd-error');
    btn.disabled = true;
    try {
      if (COMMANDS[this.type].mesh) {
        const r = await api('mesh_action', { action: this.type, device_ids: this.ids, args });
        if (!r.ok) throw new Error(r.output || 'İşlem tamamlanamadı.');
        document.getElementById('cmd-dialog').close();
        ResultDialog.show(COMMANDS[this.type].title, r.sent + ' tahtaya gönderildi.'
          + (r.missing ? ' ' + r.missing + ' tahtada uzaktan erişim kimliği olmadığı için gönderilemedi.' : ''));
        if (this.onDone) this.onDone();
        return;
      }
      const res = await api('send', { type: this.type, device_ids: this.ids, args });
      document.getElementById('cmd-dialog').close();
      ResultDialog.open(res.batch_id, COMMANDS[this.type].title);
      if (this.onDone) this.onDone();
    } catch (err) {
      errBox.textContent = err.message;
      errBox.hidden = false;
      btn.disabled = false;
    }
  },

  init() {
    document.getElementById('cmd-form').addEventListener('submit', (e) => this.submit(e));
  },
};

// ---------------- Sonuçlar ----------------

const ResultDialog = {
  timer: null,

  // Anında sonuçlanan işlemler (MeshCentral) için kısa özet.
  show(title, text) {
    clearInterval(this.timer);
    const dlg = document.getElementById('result-dialog');
    dlg.querySelector('h3').textContent = title;
    document.getElementById('result-summary').textContent = text;
    document.getElementById('result-list').replaceChildren();
    dlg.showModal();
  },

  open(batchId, title) {
    const dlg = document.getElementById('result-dialog');
    dlg.querySelector('h3').textContent = title + ' · sonuçlar';
    document.getElementById('result-list').replaceChildren();
    document.getElementById('result-summary').textContent = 'Tahtalar komutu alıyor…';
    dlg.showModal();
    clearInterval(this.timer);
    const tick = () => this.refresh(batchId);
    tick();
    this.timer = setInterval(tick, 3000);
    dlg.addEventListener('close', () => clearInterval(this.timer), { once: true });
  },

  async refresh(batchId) {
    let data;
    try {
      data = await api('batch', undefined, '&id=' + encodeURIComponent(batchId));
    } catch (err) {
      document.getElementById('result-summary').textContent = err.message;
      return;
    }
    const counts = { done: 0, failed: 0, waiting: 0 };
    const list = document.getElementById('result-list');
    list.replaceChildren(...data.results.map((r) => {
      if (r.status === 'done') counts.done++;
      else if (r.status === 'failed' || r.status === 'expired') counts.failed++;
      else counts.waiting++;
      return resultItem(r.device, r.status, r.output);
    }));
    document.getElementById('result-summary').textContent =
      counts.done + ' tamam, ' + counts.failed + ' hata, ' + counts.waiting + ' bekliyor.';
    if (counts.waiting === 0) clearInterval(this.timer);
  },
};

function resultItem(title, status, output) {
  const [state, text] = STATUS[status] || ['off', status];
  const box = el('div', 'border rounded p-2');
  const head = el('div', 'd-flex justify-content-between align-items-center gap-2');
  head.append(el('span', '', title), tag(state, text));
  box.append(head);
  if (output) box.append(el('pre', 'result-output', output));
  return box;
}

// ---------------- Tahta ayrıntısı ----------------

const DeviceDialog = {
  device: null,

  async open(d) {
    this.device = d;
    document.getElementById('dev-title').textContent = d.label;
    document.getElementById('dev-name').value = d.label === d.hostname ? '' : d.label;
    document.querySelectorAll('#dev-remote [data-view]').forEach((a) => {
      a.href = 'remote.php?id=' + d.id + '&view=' + a.dataset.view;
    });
    document.getElementById('dev-remote').hidden = d.mesh.state !== 'ok';
    document.getElementById('dev-room').value = d.room || '';
    const facts = document.getElementById('dev-facts');
    facts.replaceChildren();
    const add = (k, v) => facts.append(el('dt', 'col-sm-4 text-secondary fw-normal', k), el('dd', 'col-sm-8 mb-1', v || '—'));
    add('Bilgisayar adı', d.hostname);
    add('Durum', d.online ? 'Çevrimiçi' : 'Kapalı (son bağlantı ' + fmtTime(d.last_seen) + ')');
    add('Windows', d.os);
    add('Oturum', d.user);
    add('IP', d.ip);
    add('Virüs koruması', d.defender.text);
    add('Uzaktan erişim', d.mesh.text);
    if (d.cpu !== null) add('İşlemci (CPU)', d.cpu + '%');
    if (d.mem_used !== null) add('Bellek (RAM)', d.mem_used + '%' + (d.mem_mb ? ' / ' + (d.mem_mb / 1024).toFixed(1) + ' GB' : ''));
    if (d.disk_used !== null) add('Disk (C:)', d.disk_used + '% dolu' + (d.disk_gb ? ' · ' + d.disk_free + ' / ' + d.disk_gb + ' GB boş' : ''));
    this.loadInventory(d.id, add);
    add('Ajan sürümü', d.version);
    const hist = document.getElementById('dev-history');
    hist.replaceChildren(el('p', 'text-secondary', 'Yükleniyor…'));
    document.getElementById('device-dialog').showModal();
    try {
      const data = await api('device_commands', undefined, '&id=' + d.id);
      const names = Object.fromEntries(Object.entries(COMMANDS).map(([k, v]) => [k, v.title]));
      names.netscan = 'Ağ taraması';
      hist.replaceChildren(...(data.commands.length
        ? data.commands.map((c) => resultItem(
            (names[c.type] || c.type) + ' · ' + fmtTime(c.created_at) + (c.username ? ' · ' + c.username : ''),
            c.status, c.output))
        : [el('p', 'text-secondary', 'Bu tahtaya henüz işlem gönderilmedi.')]));
    } catch (err) {
      hist.replaceChildren(el('div', 'alert alert-danger', err.message));
    }
  },

  async loadInventory(id, add) {
    try {
      const r = await api('device_inventory', undefined, '&id=' + id);
      const inv = r.inventory;
      if (!inv) return;
      if (inv.serial) add('Seri no', inv.serial);
      if (inv.manufacturer || inv.model) add('Üretici / model', [inv.manufacturer, inv.model].filter(Boolean).join(' '));
      if (inv.bios) add('BIOS', inv.bios);
      if (Array.isArray(inv.disks) && inv.disks.length) add('Diskler', inv.disks.map((x) => (x.model || '') + (x.size_gb ? ' (' + x.size_gb + ' GB)' : '')).join(', '));
      if (inv.software_count) {
        const dd = el('dd', 'col-sm-8 mb-1');
        const a = el('a', ''); a.href = '#'; a.textContent = inv.software_count + ' yazılım — listele';
        a.addEventListener('click', (ev) => { ev.preventDefault(); this.showSoftware(inv.software || []); });
        dd.append(a);
        document.getElementById('dev-facts').append(el('dt', 'col-sm-4 text-secondary fw-normal', 'Kurulu yazılım'), dd);
      }
    } catch (e) {}
  },

  showSoftware(list) {
    const body = list.length
      ? list.map((x) => (x.name || '') + (x.version ? ' — ' + x.version : '')).join('\n')
      : 'Liste boş.';
    const dlg = document.getElementById('result-dialog');
    dlg.querySelector('h3').textContent = 'Kurulu yazılımlar (' + list.length + ')';
    document.getElementById('result-summary').textContent = '';
    const pre = el('pre', 'result-output'); pre.textContent = body; pre.style.maxHeight = '60vh';
    document.getElementById('result-list').replaceChildren(pre);
    dlg.showModal();
  },

  async save(e) {
    e.preventDefault();
    try {
      await api('update_device', {
        id: this.device.id,
        name: document.getElementById('dev-name').value,
        room: document.getElementById('dev-room').value,
        type: document.getElementById('dev-type').value,
        location_id: document.getElementById('dev-location').value,
      });
      document.getElementById('device-dialog').close();
      Devices.load();
    } catch (err) {
      window.alert(err.message);
    }
  },

  async remove() {
    const btn = document.getElementById('dev-delete');
    // İki aşamalı onay: ilk tıklamada düğme metni değişir.
    if (!btn.dataset.confirm) {
      btn.dataset.confirm = '1';
      btn.textContent = 'Emin misiniz? Tekrar tıklayın';
      return;
    }
    try {
      await api('delete_device', { id: this.device.id });
      document.getElementById('device-dialog').close();
      Devices.load();
    } catch (err) {
      window.alert(err.message);
    }
  },

  init() {
    document.getElementById('device-form').addEventListener('submit', (e) => this.save(e));
    document.getElementById('dev-delete').addEventListener('click', () => this.remove());
    document.getElementById('dev-uninstall').addEventListener('click', () => {
      document.getElementById('device-dialog').close();
      CommandDialog.open('uninstall', [this.device.id], () => Devices.load());
    });
    document.getElementById('device-dialog').addEventListener('close', () => {
      const btn = document.getElementById('dev-delete');
      delete btn.dataset.confirm;
      btn.textContent = 'Panelden sil';
    });
  },
};

// ---------------- Ağ sayfası ----------------

const Network = {
  list: [],
  current: null,

  async load() {
    try {
      const data = await api('net');
      this.list = data.devices;
      document.getElementById('last-scan').textContent = data.last_scan
        ? 'Son tarama: ' + fmtTime(data.last_scan)
        : 'Henüz tarama yapılmadı.';
      this.render();
    } catch (err) {
      document.getElementById('last-scan').textContent = err.message;
    }
  },

  render() {
    const onlyNew = document.getElementById('only-new').checked;
    const rows = this.list.filter((n) => !onlyNew || n.new);
    const newCount = this.list.filter((n) => n.new).length;
    const counts = document.getElementById('net-counts');
    counts.replaceChildren();
    const a = el('span');
    a.append(el('b', '', this.list.length), ' cihaz');
    const b = el('span');
    b.append(el('b', '', newCount), ' yeni');
    counts.append(a, ' · ', b);

    const tbody = document.getElementById('net-rows');
    if (!rows.length) {
      const tr = el('tr', 'empty-row');
      const td = el('td', '', this.list.length ? 'Yeni cihaz yok.' : 'Cihaz listesi boş. "Ağı şimdi tara" ile başlayın.');
      td.colSpan = 7;
      tr.append(td);
      tbody.replaceChildren(tr);
      return;
    }
    tbody.replaceChildren(...rows.map((n) => {
      const tr = el('tr');
      const name = el('td');
      name.append(el('div', 'fw-medium', n.label || n.hostname || 'Adsız cihaz'));
      if (n.label && n.hostname) name.append(el('div', 'text-secondary small', n.hostname));
      const kind = el('td');
      if (n.is_board) kind.append(tag('ok', 'Tahta'));
      else if (n.new) kind.append(tag('new', 'Yeni'));
      else if (n.known) kind.append(tag('ok', 'Tanıdık'));
      else kind.append(tag('off', 'Bilinmiyor'));
      const act = el('td');
      const btn = el('button', 'btn btn-sm', 'Düzenle');
      btn.type = 'button';
      btn.addEventListener('click', () => this.edit(n));
      act.append(btn);
      tr.append(name, el('td', 'mono', n.ip), el('td', 'mono', n.mac), kind,
        el('td', 'nowrap', fmtTime(n.first_seen)), el('td', 'nowrap', fmtTime(n.last_seen)), act);
      return tr;
    }));
  },

  edit(n) {
    this.current = n;
    document.getElementById('net-info').textContent = n.ip + ' · ' + n.mac + (n.hostname ? ' · ' + n.hostname : '');
    document.getElementById('net-label').value = n.label;
    document.getElementById('net-known').checked = n.known;
    document.getElementById('net-dialog').showModal();
  },

  async save(e) {
    e.preventDefault();
    try {
      await api('net_update', {
        id: this.current.id,
        label: document.getElementById('net-label').value,
        known: document.getElementById('net-known').checked,
      });
      document.getElementById('net-dialog').close();
      this.load();
    } catch (err) {
      window.alert(err.message);
    }
  },

  async scan() {
    const btn = document.getElementById('scan-btn');
    btn.disabled = true;
    btn.textContent = 'Taranıyor…';
    try {
      await api('net_scan', {});
      // Tarama tahtada yaklaşık 10-30 saniye sürer.
      setTimeout(() => this.load(), 15000);
      setTimeout(() => this.load(), 35000);
    } catch (err) {
      window.alert(err.message);
    } finally {
      setTimeout(() => { btn.disabled = false; btn.textContent = 'Ağı şimdi tara'; }, 35000);
    }
  },

  init() {
    document.getElementById('only-new').addEventListener('change', () => this.render());
    document.getElementById('scan-btn').addEventListener('click', () => this.scan());
    document.getElementById('net-form').addEventListener('submit', (e) => this.save(e));
    this.load();
  },
};

// ---------------- Sınıflar sayfası ----------------

const Rooms = {
  list: [],

  async load() {
    try {
      const data = await api('devices');
      this.list = data.devices;
      this.render();
    } catch (err) {
      const grid = document.getElementById('room-grid');
      grid.replaceChildren(el('div', 'col-12 alert alert-danger', err.message));
    }
  },

  groups() {
    const map = new Map();
    for (const d of this.list) {
      const key = d.room || 'Sınıfı atanmamış';
      if (!map.has(key)) map.set(key, []);
      map.get(key).push(d);
    }
    return [...map.entries()].sort((a, b) => a[0].localeCompare(b[0], 'tr', { numeric: true }));
  },

  render() {
    const grid = document.getElementById('room-grid');
    const groups = this.groups();
    const online = this.list.filter((d) => d.online).length;
    document.getElementById('room-counts').textContent =
      groups.length + ' sınıf · ' + online + ' / ' + this.list.length + ' tahta açık';
    if (!groups.length) {
      grid.replaceChildren(el('div', 'col-12 text-secondary', 'Henüz tahta yok. Ayarlar sayfasından kurulum dosyasını indirip bir tahtaya kurun.'));
      return;
    }
    grid.replaceChildren(...groups.map(([room, boards]) => this.card(room, boards)));
    if (location.hash) {
      const target = document.getElementById('room-' + decodeURIComponent(location.hash.slice(1)));
      if (target) target.scrollIntoView({ block: 'center' });
    }
  },

  card(room, boards) {
    const col = el('div', 'col-sm-6 col-xl-4');
    col.id = 'room-' + room;
    const card = el('div', 'card h-100');
    const head = el('div', 'card-header');
    const on = boards.filter((d) => d.online).length;
    head.append(el('h3', 'card-title', room));
    const badge = tag(on ? 'ok' : 'off', on + ' / ' + boards.length + ' açık');
    badge.classList.add('ms-auto');
    head.append(badge);

    const list = el('div', 'list-group list-group-flush flex-fill');
    for (const d of boards) {
      const item = el('div', 'list-group-item d-flex align-items-center gap-2');
      const info = el('div', 'flex-fill');
      info.append(el('div', 'fw-medium', d.label));
      const sub = el('div', 'd-flex flex-wrap gap-1 mt-1');
      sub.append(d.online ? tag('ok', 'Çevrimiçi') : tag('off', 'Kapalı'));
      if (d.defender.state === 'bad' || d.defender.state === 'warn') sub.append(tag(d.defender.state, d.defender.text));
      info.append(sub);
      item.append(info);
      if (d.online && d.mesh.state === 'ok') {
        const a = el('a', 'btn btn-sm btn-primary', 'Ekran');
        a.href = 'remote.php?id=' + d.id + '&view=desktop';
        item.append(a);
      }
      item.addEventListener('dblclick', () => { location.href = 'devices.php?q=' + encodeURIComponent(d.hostname); });
      list.append(item);
    }

    const foot = el('div', 'card-footer d-flex flex-wrap gap-2');
    const ids = boards.map((d) => d.id);
    const offline = boards.filter((d) => !d.online).map((d) => d.id);
    if (offline.length) {
      const w = el('button', 'btn btn-sm');
      w.type = 'button';
      w.append(el('i', 'ti ti-player-play me-1'), 'Uzaktan aç');
      w.addEventListener('click', () => CommandDialog.open('wake', offline, () => this.load()));
      foot.append(w);
    }
    for (const [type, label, icon] of [['message', 'Mesaj', 'message'], ['restart', 'Yeniden başlat', 'refresh'], ['shutdown', 'Kapat', 'power']]) {
      const b = el('button', 'btn btn-sm');
      b.type = 'button';
      const i = el('i', 'ti ti-' + icon + ' me-1');
      b.append(i, label);
      b.addEventListener('click', () => CommandDialog.open(type, ids, () => this.load()));
      foot.append(b);
    }
    card.append(head, list, foot);
    col.append(card);
    return col;
  },

  init() {
    this.load();
    setInterval(() => { if (!document.hidden) this.load(); }, 15000);
  },
};

// ---------------- Uzaktan erişim köprüsü (panel tarafı) ----------------
// Çerçevedeki uzaktan erişim sayfasıyla postMessage üzerinden konuşur;
// yalnız kendi uzaktan erişim adresimizden gelen mesajlar kabul edilir.

const Remote = {
  origin: '',
  frame: null,
  state: -1,
  since: 0,
  timer: null,
  heard: false,

  send(cmd, value) {
    if (this.frame && this.frame.contentWindow) {
      this.frame.contentWindow.postMessage({ src: 'bilgece-panel', cmd, value }, this.origin);
    }
  },

  setState(s, control) {
    const labels = { 0: ['Bağlantı yok', 'off'], 1: ['Bağlanıyor…', 'warn'], 2: ['Bağlanıyor…', 'warn'], 3: ['Bağlı', 'ok'] };
    const [text, cls] = labels[s] || labels[0];
    document.getElementById('remote-state').textContent = text;
    document.getElementById('remote-dot').className = 'remote-dot ' + cls;
    if (s === 3 && this.state !== 3) this.since = Date.now();
    this.state = s;
    document.querySelectorAll('[data-remote="cad"],[data-remote="screenshot"],[data-remote="control"],[data-remote="disconnect"]')
      .forEach((b) => { b.disabled = s !== 3; });
    const ctl = document.getElementById('remote-control');
    if (ctl && control !== undefined) {
      ctl.dataset.on = control ? '1' : '0';
      ctl.querySelector('span').textContent = control ? 'Kontrol açık' : 'Sadece izleme';
      ctl.classList.toggle('btn-primary', !!control);
    }
    this.tick();
  },

  tick() {
    const el = document.getElementById('remote-timer');
    if (this.state !== 3) { el.textContent = ''; return; }
    const sec = Math.floor((Date.now() - this.since) / 1000);
    el.textContent = '· ' + String(Math.floor(sec / 60)).padStart(2, '0') + ':' + String(sec % 60).padStart(2, '0');
  },

  init() {
    const root = document.querySelector('[data-page="remote"]');
    this.origin = root.dataset.meshOrigin;
    this.frame = document.getElementById('remote-frame');
    window.addEventListener('message', (ev) => {
      if (ev.origin !== this.origin || !ev.data || ev.data.src !== 'bilgece-remote') return;
      this.heard = true;
      if (ev.data.type === 'state') this.setState(ev.data.state, ev.data.control);
    });
    document.querySelectorAll('[data-remote]').forEach((b) => {
      b.addEventListener('click', () => {
        const cmd = b.dataset.remote;
        if (cmd === 'fullscreen') {
          const card = document.getElementById('remote-card');
          if (document.fullscreenElement) document.exitFullscreen();
          else if (card.requestFullscreen) card.requestFullscreen().catch(() => {});
        } else if (cmd === 'control') {
          this.send('control', b.dataset.on !== '1');
        } else {
          this.send(cmd);
        }
      });
    });
    this.setState(1);
    this.timer = setInterval(() => this.tick(), 1000);
    // Köprü yanıt vermezse kullanıcıya yol göster.
    setTimeout(() => {
      if (!this.heard) {
        document.getElementById('remote-state').textContent = 'Yanıt yok';
        document.getElementById('remote-note').textContent =
          'Uzaktan erişim yanıt vermedi. Sayfayı yenileyin; sorun sürerse tahtanın açık ve internete bağlı olduğunu kontrol edin.';
      }
    }, 20000);
  },
};

// ---------------- Ortak alan ----------------

const Shared = {
  files: [],
  current: null,
  devices: [],

  fmtSize(b) {
    if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
    if (b >= 1024) return Math.round(b / 1024) + ' KB';
    return b + ' B';
  },

  async load() {
    try {
      const data = await api('shared_list');
      this.files = data.files;
      this.render();
    } catch (err) {
      document.getElementById('shared-rows').replaceChildren(
        (() => { const tr = el('tr', 'empty-row'); const td = el('td', '', err.message); td.colSpan = 5; tr.append(td); return tr; })());
    }
    try { this.devices = (await api('devices')).devices; this.renderRooms(); } catch (e) {}
  },

  renderRooms() {
    const rooms = [...new Set(this.devices.map((d) => d.room).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'tr', { numeric: true }));
    document.getElementById('subdir-list').replaceChildren(...rooms.map((r) => new Option(r)));
    const sel = document.getElementById('send-room');
    sel.replaceChildren(new Option('Tüm tahtalar', ''));
    for (const r of rooms) sel.append(new Option(r, r));
  },

  render() {
    const tbody = document.getElementById('shared-rows');
    if (!this.files.length) {
      const tr = el('tr', 'empty-row'); const td = el('td', '', 'Henüz dosya yok.'); td.colSpan = 5; tr.append(td);
      tbody.replaceChildren(tr); return;
    }
    tbody.replaceChildren(...this.files.map((f) => {
      const tr = el('tr');
      tr.append(
        el('td', 'fw-medium', f.orig_name),
        el('td', 'text-nowrap', this.fmtSize(f.size)),
        el('td', '', f.subdir || '—'),
        el('td', 'text-nowrap', fmtTime(f.created_at)));
      const act = el('td', 'text-nowrap');
      const send = el('button', 'btn btn-sm btn-primary me-1'); send.type = 'button';
      send.append(el('i', 'ti ti-send me-1'), 'Gönder');
      send.addEventListener('click', () => this.openSend(f));
      const del = el('button', 'btn btn-sm btn-ghost-danger'); del.type = 'button';
      del.append(el('i', 'ti ti-trash'));
      del.addEventListener('click', async () => {
        if (del.dataset.c) { try { await api('shared_delete', { id: f.id }); this.load(); } catch (e) { alert(e.message); } }
        else { del.dataset.c = '1'; del.title = 'Tekrar tıklayın'; }
      });
      act.append(send, del);
      tr.append(act);
      return tr;
    }));
  },

  openSend(f) {
    this.current = f;
    document.getElementById('send-info').textContent = f.orig_name + ' · ' + this.fmtSize(f.size);
    document.getElementById('send-room').value = f.subdir && this.devices.some((d) => d.room === f.subdir) ? f.subdir : '';
    document.getElementById('send-error').hidden = true;
    this.updateCount();
    document.getElementById('send-dialog').showModal();
  },

  targetIds() {
    const room = document.getElementById('send-room').value;
    return this.devices.filter((d) => !room || d.room === room).map((d) => d.id);
  },

  updateCount() {
    const n = this.targetIds().length;
    document.getElementById('send-count').textContent = n + ' tahtaya gönderilecek. Tahta açıldığında dosyayı indirir.';
  },

  async submitSend(e) {
    e.preventDefault();
    const ids = this.targetIds();
    const err = document.getElementById('send-error');
    if (!ids.length) { err.textContent = 'Seçime uyan tahta yok.'; err.hidden = false; return; }
    try {
      const r = await api('shared_send', { file_id: this.current.id, device_ids: ids });
      document.getElementById('send-dialog').close();
      ResultDialog.open(r.batch_id, 'Ortak alan: ' + this.current.orig_name);
    } catch (ex) { err.textContent = ex.message; err.hidden = false; }
  },

  async submitUpload(e) {
    e.preventDefault();
    const file = document.getElementById('up-file').files[0];
    const err = document.getElementById('up-error'); err.hidden = true;
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    fd.append('subdir', document.getElementById('up-subdir').value);
    fd.append('csrf', CSRF);
    const btn = document.getElementById('up-btn');
    const prog = document.getElementById('up-progress');
    const bar = document.getElementById('up-bar');
    btn.disabled = true; prog.hidden = false;
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'admin_api.php?action=shared_upload');
    xhr.upload.onprogress = (ev) => { if (ev.lengthComputable) bar.style.width = Math.round(ev.loaded / ev.total * 100) + '%'; };
    xhr.onload = () => {
      btn.disabled = false; prog.hidden = true; bar.style.width = '0%';
      let d = {}; try { d = JSON.parse(xhr.responseText); } catch (e) {}
      if (xhr.status === 200 && d.ok) {
        document.getElementById('upload-form').reset();
        this.load();
      } else { err.textContent = d.error || ('Yükleme başarısız (' + xhr.status + ').'); err.hidden = false; }
    };
    xhr.onerror = () => { btn.disabled = false; prog.hidden = true; err.textContent = 'Bağlantı hatası.'; err.hidden = false; };
    xhr.send(fd);
  },

  init() {
    document.getElementById('upload-form').addEventListener('submit', (e) => this.submitUpload(e));
    document.getElementById('send-form').addEventListener('submit', (e) => this.submitSend(e));
    document.getElementById('send-room').addEventListener('change', () => this.updateCount());
    this.load();
  },
};

// ---------------- Başlatma ----------------

const page = document.querySelector('[data-page]')?.dataset.page;
const Locations = {
  async load() {
    const tbody = document.getElementById('loc-rows');
    try {
      const data = await api('locations');
      if (!data.locations.length) {
        const tr = el('tr', 'empty-row'); const td = el('td', '', 'Henuz lokasyon yok.'); td.colSpan = 3; tr.append(td);
        tbody.replaceChildren(tr); return;
      }
      tbody.replaceChildren(...data.locations.map((l) => {
        const tr = el('tr');
        tr.append(el('td', 'fw-medium', l.name), el('td', '', String(l.devices)));
        const act = el('td', 'text-nowrap');
        const ren = el('button', 'btn btn-sm me-1'); ren.type = 'button'; ren.textContent = 'Yeniden adlandir';
        ren.addEventListener('click', async () => {
          const nm = prompt('Yeni ad:', l.name); if (nm === null) return;
          try { await api('location_save', { id: l.id, name: nm }); this.load(); } catch (e) { alert(e.message); }
        });
        const del = el('button', 'btn btn-sm btn-ghost-danger'); del.type = 'button';
        del.append(el('i', 'ti ti-trash'));
        del.addEventListener('click', async () => {
          if (del.dataset.c) { try { await api('location_delete', { id: l.id }); this.load(); } catch (e) { alert(e.message); } }
          else { del.dataset.c = '1'; del.title = 'Tekrar tiklayin'; }
        });
        act.append(ren, del);
        tr.append(act);
        return tr;
      }));
    } catch (err) {
      const tr = el('tr', 'empty-row'); const td = el('td', '', err.message); td.colSpan = 3; tr.append(td); tbody.replaceChildren(tr);
    }
  },
  init() {
    document.getElementById('loc-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const inp = document.getElementById('loc-name');
      try { await api('location_save', { name: inp.value }); inp.value = ''; this.load(); } catch (err) { alert(err.message); }
    });
    this.load();
  },
};

const SEV = { critical: ['Kritik', 'bg-danger-lt'], warning: ['Uyarı', 'bg-warning-lt'] };

const Alerts = {
  async load() {
    try {
      const d = await api('alerts');
      document.getElementById('open-count').textContent = d.open_count + ' açık';
      const ob = document.getElementById('open-rows');
      if (!d.open.length) {
        const tr = el('tr', 'empty-row'); const td = el('td', '', 'Açık uyarı yok.'); td.colSpan = 5; tr.append(td); ob.replaceChildren(tr);
      } else {
        ob.replaceChildren(...d.open.map((a) => {
          const tr = el('tr');
          const [lbl, cls] = SEV[a.severity] || SEV.warning;
          tr.append(tag2(cls, lbl), el('td', '', a.name || a.hostname || '—'), el('td', '', a.message),
            el('td', 'text-nowrap', fmtTime(a.created_at)));
          const act = el('td', 'text-nowrap');
          const btn = el('button', 'btn btn-sm', 'Çözüldü'); btn.type = 'button';
          btn.addEventListener('click', async () => { try { await api('alert_resolve', { id: a.id }); this.load(); } catch (e) { alert(e.message); } });
          act.append(btn); tr.append(act); return tr;
        }));
      }
      const rb = document.getElementById('resolved-rows');
      rb.replaceChildren(...(d.resolved.length ? d.resolved.map((a) => {
        const tr = el('tr'); const [lbl, cls] = SEV[a.severity] || SEV.warning;
        tr.append(tag2(cls, lbl), el('td', '', a.name || a.hostname || '—'), el('td', '', a.message),
          el('td', 'text-nowrap', fmtTime(a.resolved_at))); return tr;
      }) : [(() => { const tr = el('tr', 'empty-row'); const td = el('td', '', '—'); td.colSpan = 4; tr.append(td); return tr; })()]));
    } catch (err) {
      document.getElementById('open-rows').replaceChildren((() => { const tr = el('tr', 'empty-row'); const td = el('td', '', err.message); td.colSpan = 5; tr.append(td); return tr; })());
    }
  },
  init() { this.load(); setInterval(() => { if (!document.hidden) this.load(); }, 20000); },
};

function tag2(cls, text) { const td = el('td'); td.append(el('span', 'badge ' + cls, text)); return td; }

// Menüde açık uyarı rozeti (her sayfada)
async function updateAlertBadge() {
  try {
    const d = await api('alerts_count');
    const link = document.querySelector('.aci-sidebar a[href="alerts.php"]');
    if (!link) return;
    let b = link.querySelector('.alert-badge');
    if (d.open > 0) {
      if (!b) { b = el('span', 'badge bg-danger ms-auto alert-badge'); link.append(b); }
      b.textContent = d.open;
    } else if (b) { b.remove(); }
  } catch (e) {}
}
updateAlertBadge();
setInterval(updateAlertBadge, 30000);

const Inventory = {
  list: [],
  async load() {
    try { this.list = (await api('inventory')).devices; this.render(); }
    catch (err) {
      document.getElementById('inv-rows').replaceChildren((() => { const tr = el('tr','empty-row'); const td = el('td','',err.message); td.colSpan=8; tr.append(td); return tr; })());
    }
  },
  render() {
    const q = document.getElementById('inv-search').value.trim().toLocaleLowerCase('tr');
    const rows = this.list.filter((d) => !q || [d.name, d.hostname, d.serial, d.model, d.cpu, d.os].join(' ').toLocaleLowerCase('tr').includes(q));
    document.getElementById('inv-count').textContent = rows.length + ' cihaz';
    const tb = document.getElementById('inv-rows');
    if (!rows.length) { const tr = el('tr','empty-row'); const td = el('td','','Kayıt yok.'); td.colSpan=8; tr.append(td); tb.replaceChildren(tr); return; }
    tb.replaceChildren(...rows.map((d) => {
      const tr = el('tr');
      const name = el('td'); name.append(el('div','fw-medium', d.name || d.hostname));
      if (d.updated_at) name.append(el('div','text-secondary small', 'güncelleme ' + fmtTime(d.updated_at)));
      tr.append(name, el('td','', d.location || '—'), el('td','mono', d.serial || '—'),
        el('td','', d.model || '—'), el('td','small', d.cpu || '—'),
        el('td','text-nowrap', d.ram_gb ? d.ram_gb + ' GB' : '—'), el('td','small', d.os || '—'),
        el('td','', d.software_count !== null ? String(d.software_count) : '—'));
      return tr;
    }));
  },
  init() {
    document.getElementById('inv-search').addEventListener('input', () => this.render());
    this.load();
  },
};

if (page === 'devices') {
  Devices.init();
  CommandDialog.init();
  DeviceDialog.init();
} else if (page === 'rooms') {
  Rooms.init();
  CommandDialog.init();
} else if (page === 'network') {
  Network.init();
} else if (page === 'remote') {
  Remote.init();
} else if (page === 'shared') {
  Shared.init();
} else if (page === 'locations') {
  Locations.init();
} else if (page === 'alerts') {
  Alerts.init();
} else if (page === 'inventory') {
  Inventory.init();
}
