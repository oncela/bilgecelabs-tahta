# BilgeceLabs Tahta — Özellikler ve Yol Haritası

MeshCentral kaynak kodu incelendi (14.10.2026). Aşağıdaki özellikler ya hazır,
ya kendi ajanımızla, ya da MeshCentral'ın komut kanalıyla eklenebilir.

## Şu an çalışan (tamam)
- Panel: Genel bakış (SVG grafikler), Sınıflar, Tahtalar, Ağ, Geçmiş, Ayarlar, sağlık kontrolü
- Ajan: kayıt, durum, Defender durumu, mesaj, kapat/başlat, PowerShell, ağ tarama, tek .exe kurulum, kaldırma
- Uzaktan erişim: panel içinde ekran/komut satırı/dosya, kendi kontrol çubuğu, soru sormadan bağlanma
- Toplu: mesaj, kapat, başlat, uzaktan aç (WoL), web adresi aç, bildirim
- Markalama: BilgeceLabs (panel, ajan, uzaktan erişim, logo, favicon)
- Yayın: Cloudflare Tunnel (tahta / uzak.bilgecelabs.com), tek komutla kurulum (install.sh)

## MeshCentral'da hazır bulunan, henüz kullanmadığımız özellikler
| Özellik | MeshCentral karşılığı | Okulda kullanımı |
|---|---|---|
| Çoklu ekran izleme (32 tahtaya kadar) | Multi-Desktop görünümü | "Ekranlar" sayfası: sınıfın tüm tahtaları canlı ızgarada |
| Donanım/yazılım envanteri | getSysInfo | Tahta modeli, RAM, disk, kurulu programlar |
| Oturum kaydı | sessionRecording | Uzaktan bağlantıların video kaydı (KVKK'ya dikkat — varsayılan kapalı) |
| Ağ keşfi | localDiscovery | Ağdaki cihazları MeshCentral de bulabilir (bizde ajan tarama var) |
| Web yönlendirme | webRelay | Tahtadaki bir arayüze (ör. yazıcı paneli) panel üzerinden erişim |
| Eklenti sistemi | plugins | İleride özel modüller |
| Intel AMT | amtManager | OPS donanımı destekliyorsa BIOS seviyesinde güç/ekran (çoğu OPS'de yok) |

## Kendi ajanımızla eklenecekler (Windows Pro'da güvenli — araştırma raporuna göre)
| Özellik | Yöntem |
|---|---|
| Toplu dosya gönderme (Ortak alan) | Panele yüklenir, tahtalar paralel indirir (sha256) → C:\Ortak |
| Toplu uygulama çalıştırma | Ekranda: MeshCentral "kullanıcı olarak çalıştır"; arka planda: PowerShell/MSI |
| Ders/teneffüs çizelgesine göre kilit | Zil saatleri, tahtada yerel çalışır (internetsiz de) |
| İnternetsiz PIN ile açma | TOTP + okul acil PIN'i |
| Teneffüste internet kesme | Windows Güvenlik Duvarı izin listesi |
| Akşam otomatik kapatma/uyku | Tahtada yerel çizelge |
| USB koruması, kenar kaydırma kapatma, Görev Yöneticisi kapatma | Kayıt defteri/politika |
| Uygulama engelleme | AppLocker (önce izleme modu) |
| Öğrenci hesabını sıfırlama | Standart hesap + oturum sıfırlama |
| Uzaktan güncelleme | Yeni .exe panelden → MeshCentral dosya+komut kanalıyla dağıtım |

## Yol haritası (öncelik sırası)

### Aşama A — yönetim derinliği (hemen)
1. **Ekranlar sayfası** — çoklu ekran canlı izleme (MeshCentral Multi-Desktop)
2. **Ortak alan** — tüm sınıfa/okula dosya gönderme
3. **Toplu uygulama/web açma** — ders materyalini aynı anda açma
4. **Kurulum sihirbazı** — BilgeceLabs logolu, siyah pencere yok, Programlar'da görünür
5. **Bildirim alanı simgesi** — logo + "Bağlı · sürüm" durumu
6. **Uzaktan güncelleme**

### Aşama B — sınıf disiplini (okulun en çok istediği)
7. **Zamanlama** — ders/teneffüs çizelgesi
8. **Kilit ekranı + çevrimdışı PIN** — duyuru panosu olarak da
9. **Teneffüste internet kesme, akşam kapatma**
10. **Envanter** — getSysInfo ile donanım/yazılım listesi

### Aşama C — sertleştirme (dikkatli, aşamalı)
11. **USB, kenar kaydırma, Görev Yöneticisi, uygulama engelleme** (AppLocker izleme modu)
12. **Öğrenci hesabı sıfırlama**
13. **DNS filtresi** (AdGuard Home)

### Aşama D — ileri
14. **Öğretmen ekranını tahtalara yansıtma** (yayın)
15. **e-Yoklama, raporlar**
16. **Kod imzalama** (SmartScreen/Defender uyarılarını bitirir)
17. **QR/NFC ile öğretmen girişi, mobil uygulama**

## Riskler (araştırma raporundan)
- Enterprise/Education gerektirenler: Keyboard Filter (Ctrl+Alt+Del engelleme), Shell Launcher — Pro'da yok
- WDAC/AppLocker önce izleme modunda; güvenlik duvarını varsayılan engelle açmak ajanı koparır
- KVKK: ekran kaydı ve izleme varsayılan kapalı, bildirim çubuğu açık, saklama süresi tanımlı
- İmzasız ajan: SmartScreen/Defender uyarısı — kod imzalama kalıcı çözüm

## Güvenlik notları (14.10.2026 incelemesi)
- Tahtadaki ajanımız HİÇBİR port açmıyor (sadece dışa bağlantı) → dışarıdan tahtaya bağlanılamaz
- Sunucuda düzeltilecek: MeshCentral *:4430 yerine 127.0.0.1; ufw kapalı; SSH tüm arayüzlerde
- Test paneli (8099) iş bitince kapatılacak

## Sonradan eklenenler
- Kurulumda cihaz türü (tahta/sınıf PC/idare/danışma) ve konum otomatik belirlenir, panelde gruplanır
  - Yöntem önerisi: kurulum dosyası adında tür+konum (ör. _7B, _danisma) veya kurulumda tek soru
- Güvenlik sertleştirme (Aşama C başı): sunucu (MeshCentral localhost, ufw, fail2ban, SSH),
  kayıt güvenliği (IP/zaman sınırı, anahtar rotasyonu), kod imzalama

## Açık kaynak araştırması ve ürün yönü (15.10.2026)
- Lisans: MeshCentral Apache-2.0 (güvenli omurga). AGPL bileşen (Snipe-IT, Zabbix, RustDesk-server)
  GÖMME — ürünü açık kaynağa zorlar. Tactical RMM: sadece mimari ilham (özel lisans).
- Çoklu kiracılık: "tek firma, çok şube" = basit branch_id/department_id kapsamlama. Laravel paketi (stancl/spatie) GEREKSİZ.
- Pixven seviyesi build sırası:
  1. Hiyerarşi (lokasyon/departman) + yetki + audit log  ← ŞİMDİ
  2. Alarm/bildirim motoru (eşik + e-posta/Telegram/webhook)
  3. Detaylı envanter (seri no, kurulu yazılım) + CSV/PDF rapor
  4. Güvenlik durumu (Defender ✅ var, firewall, BitLocker)
  5. Zamanlama + script kütüphanesi + yazılım/patch dağıtımı (winget/choco)
  6. Dashboard cilası + PWA mobil + WoL/ağ keşfi
- Kendin yaz: hiyerarşi, alarm motoru, zamanlayıcı, rapor. Entegre: MeshCentral (bitti), gerekirse osquery (Apache).
