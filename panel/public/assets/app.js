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

function tag(state, text) {
  return el('span', 'tag ' + state, text);
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
    const q = document.getElementById('search').value.trim().toLocaleLowerCase('tr');
    return this.list.filter((d) => {
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
    const tr = el('tr');
    const td = el('td', 'empty', text);
    td.colSpan = 8;
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
    counts.append(c1, c2);

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
    const tr = el('tr', 'clickable' + (this.selected.has(d.id) ? ' sel' : ''));
    tr.dataset.id = d.id;

    const tdChk = el('td', 'chk');
    const chk = document.createElement('input');
    chk.type = 'checkbox';
    chk.checked = this.selected.has(d.id);
    chk.setAttribute('aria-label', d.label + ' seç');
    chk.addEventListener('click', (e) => {
      e.stopPropagation();
      if (chk.checked) this.selected.add(d.id);
      else this.selected.delete(d.id);
      tr.classList.toggle('sel', chk.checked);
      this.updateSelection();
    });
    tdChk.append(chk);

    const tdName = el('td');
    tdName.append(el('div', 'dev-name', d.label));
    if (d.label !== d.hostname) tdName.append(el('div', 'dev-sub', d.hostname));

    const tdState = el('td');
    tdState.append(d.online ? tag('ok', 'Çevrimiçi') : tag('off', 'Kapalı · ' + fmtTime(d.last_seen)));
    if (d.busy) tdState.append(' ', tag('warn', d.busy + ' işlem'));

    const tdDef = el('td');
    tdDef.append(tag(d.defender.state === 'unknown' ? 'off' : d.defender.state, d.defender.text));

    tr.append(
      tdChk,
      tdName,
      el('td', '', d.room || '—'),
      tdState,
      el('td', '', d.user || '—'),
      tdDef,
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

  init() {
    document.getElementById('room-filter').addEventListener('change', () => this.render());
    document.getElementById('search').addEventListener('input', () => this.render());
    document.getElementById('select-all').addEventListener('change', (e) => {
      for (const d of this.visible()) {
        if (e.target.checked) this.selected.add(d.id);
        else this.selected.delete(d.id);
      }
      this.render();
    });
    document.querySelectorAll('[data-cmd]').forEach((b) => {
      b.addEventListener('click', () => CommandDialog.open(b.dataset.cmd));
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

  open(type) {
    const def = COMMANDS[type];
    if (!def) return;
    this.type = type;
    const n = Devices.selected.size;
    document.getElementById('cmd-title').textContent = def.title;
    document.getElementById('cmd-target').textContent =
      n + ' tahtaya gönderilecek.' + (def.note ? ' ' + def.note : '');
    const box = document.getElementById('cmd-fields');
    box.replaceChildren();
    for (const f of def.fields) {
      const id = 'cmd-' + f.k;
      const label = el('label', '', f.label);
      label.htmlFor = id;
      const input = document.createElement(f.type === 'textarea' ? 'textarea' : 'input');
      if (f.type !== 'textarea') input.type = f.type;
      input.id = id;
      input.name = f.k;
      if (f.value) input.value = f.value;
      if (f.required) input.required = true;
      if (f.type === 'number') input.min = '0';
      box.append(label, input);
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
      const res = await api('send', { type: this.type, device_ids: [...Devices.selected], args });
      document.getElementById('cmd-dialog').close();
      ResultDialog.open(res.batch_id, COMMANDS[this.type].title);
      Devices.load();
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

  open(batchId, title) {
    const dlg = document.getElementById('result-dialog');
    dlg.querySelector('h2').textContent = title + ' · sonuçlar';
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
  const box = el('div', 'result');
  const head = el('div', 'rhead');
  head.append(el('span', '', title), tag(state, text));
  box.append(head);
  if (output) box.append(el('pre', '', output));
  return box;
}

// ---------------- Tahta ayrıntısı ----------------

const DeviceDialog = {
  device: null,

  async open(d) {
    this.device = d;
    document.getElementById('dev-title').textContent = d.label;
    document.getElementById('dev-name').value = d.label === d.hostname ? '' : d.label;
    document.getElementById('dev-room').value = d.room || '';
    const facts = document.getElementById('dev-facts');
    facts.replaceChildren();
    const add = (k, v) => facts.append(el('dt', '', k), el('dd', '', v || '—'));
    add('Bilgisayar adı', d.hostname);
    add('Durum', d.online ? 'Çevrimiçi' : 'Kapalı (son bağlantı ' + fmtTime(d.last_seen) + ')');
    add('Windows', d.os);
    add('Oturum', d.user);
    add('IP', d.ip);
    add('Virüs koruması', d.defender.text);
    add('Ajan sürümü', d.version);
    const hist = document.getElementById('dev-history');
    hist.replaceChildren(el('p', 'muted', 'Yükleniyor…'));
    document.getElementById('device-dialog').showModal();
    try {
      const data = await api('device_commands', undefined, '&id=' + d.id);
      const names = Object.fromEntries(Object.entries(COMMANDS).map(([k, v]) => [k, v.title]));
      names.netscan = 'Ağ taraması';
      hist.replaceChildren(...(data.commands.length
        ? data.commands.map((c) => resultItem(
            (names[c.type] || c.type) + ' · ' + fmtTime(c.created_at) + (c.username ? ' · ' + c.username : ''),
            c.status, c.output))
        : [el('p', 'muted', 'Bu tahtaya henüz işlem gönderilmedi.')]));
    } catch (err) {
      hist.replaceChildren(el('p', 'alert', err.message));
    }
  },

  async save(e) {
    e.preventDefault();
    try {
      await api('update_device', {
        id: this.device.id,
        name: document.getElementById('dev-name').value,
        room: document.getElementById('dev-room').value,
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
    counts.append(a, b);

    const tbody = document.getElementById('net-rows');
    if (!rows.length) {
      const tr = el('tr');
      const td = el('td', 'empty', this.list.length ? 'Yeni cihaz yok.' : 'Cihaz listesi boş. "Ağı şimdi tara" ile başlayın.');
      td.colSpan = 7;
      tr.append(td);
      tbody.replaceChildren(tr);
      return;
    }
    tbody.replaceChildren(...rows.map((n) => {
      const tr = el('tr');
      const name = el('td');
      name.append(el('div', 'dev-name', n.label || n.hostname || 'Adsız cihaz'));
      if (n.label && n.hostname) name.append(el('div', 'dev-sub', n.hostname));
      const kind = el('td');
      if (n.is_board) kind.append(tag('ok', 'Tahta'));
      else if (n.new) kind.append(tag('new', 'Yeni'));
      else if (n.known) kind.append(tag('ok', 'Tanıdık'));
      else kind.append(tag('off', 'Bilinmiyor'));
      const act = el('td');
      const btn = el('button', 'btn', 'Düzenle');
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

// ---------------- Başlatma ----------------

const page = document.querySelector('[data-page]')?.dataset.page;
if (page === 'devices') {
  Devices.init();
  CommandDialog.init();
  DeviceDialog.init();
} else if (page === 'network') {
  Network.init();
}
