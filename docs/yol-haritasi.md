# BilgeceLabs Tahta — Yol Haritası

Son güncelleme: 15.10.2026 (sürüm 0.3.8). Öncelik sırası yukarıdan aşağıya.
Her fazın sonunda: testler (`make test`), canlıda deneme, GitHub'a kayıt.

## Bugüne kadar biten (0.3.8)
- **Panel:** yeni tasarım (tahta duvarı, yüzen seçim çubuğu, yeni giriş ekranı), lokasyon/departman,
  uyarılar + Telegram/webhook/e-posta, envanter + CSV, güvenlik karnesi, yazılım kataloğu,
  ortak alan, ağ taraması, destek talepleri, geçmiş, Bitdefender kısa linki
- **Ajan:** port açmaz; uzaktan güncelleme (5+ kez sahada denendi), Defender/güvenlik/envanter/metrik,
  sessiz program kurma (winget/MSI), veri klasörü yalnız SYSTEM/Yöneticiler
- **Kurulum:** penceresiz (logo + yükleme çubuğu), siyah pencere yok, /S ile sessiz toplu kurulum
- **Self servis:** WebView2 ile modern pencere (talep, programlar, taleplerim); simge Ayarlar'dan aç/kapa
  — **şu an kapalı**, sahada denendikten sonra açılacak
- **Uzaktan erişim:** MeshCentral, üst bant yerine sağ altta bildirim
- **Kararlılık:** gece veritabanı yedeği (14 gün, geri yükleme denendi), eski kayıt temizliği, tünel HTTP/2

## Faz 1 — Sağlamlaştırma (hemen)
1. **Kod imzalama sertifikası** — SmartScreen ve antivirüs uyarılarını kalıcı bitirir. Derleme hattı hazır,
   yalnız sertifika gerekli (yıllık ücretli; OV/EV seçenekleri araştırılacak).
2. **Self servis sahada deneme** — tek tahtada simgeyi aç, %300 ölçekli 4K tahtada pencereyi doğrula, sonra herkese aç.
3. **Eski 0.2.0 tahtaları** — kendini güncelleyemeyenlere yeni kurulum bir kez (DESKTOP-BIU7SOQ).
4. **Sunucu güvenliği** — şifresiz sudo'yu kapat, Cloudflare tünel anahtarını yenile, SSH yalnız yerel ağ,
   ufw + fail2ban, MeshCentral yalnız 127.0.0.1.
5. **Sunucu izleme** — dış izleme (UptimeRobot vb.) sunucu düşünce Telegram'a haber versin.
6. **GitHub sürümleri** — `v0.3.x` etiketi atınca GitHub derlesin, sürüm notu yayınlansın.

## Faz 2 — Zararlı yazılım koruması (malware)
Sahadaki bilgisayarlarda Defender geçmişinde 5–12 tespit ve RDP Wrapper görüldü; okul bilgisayarlarında
USB ve "crack" kaynaklı bulaşma yaygın. Katmanlı plan (ucuzdan pahalıya, hepsi Windows Pro'da çalışır):

**2.1 Görünürlük — "Tehditler" sayfası**
- Ajan her cihazın Defender tespit listesini gönderir: tehdit adı, dosya yolu, tarih, durum
  (temizlendi / karantinada / etkin), kaynağı (USB, indirme, e-posta).
- Panelde tüm okulun tehditleri tek listede; aynı tehdit birden çok cihazda varsa "salgın" uyarısı.
- Etkin tehdit kalırsa kritik uyarı + Telegram (mevcut motor).

**2.2 Tarama — panelden ve zamanlanmış**
- Tek tık: hızlı / tam tarama, imza güncelleme (hızlı tarama ve imza bugün de var).
- **Çevrimdışı tarama** (Microsoft Defender Offline): Defender'ın temizleyemediği inatçı zararlılar için;
  cihaz yeniden başlar, Windows açılmadan tarar. Panelde onaylı (öğretmene bildirimle) tetiklenir.
- **İkinci görüş:** Microsoft Safety Scanner (ücretsiz, her seferinde güncel indirilir) uzaktan çalıştırılır.
- Gece otomatik hızlı tarama (Zamanlama fazıyla birleşir).

**2.3 Önleme — Defender ayarları panelden**
- **PUA koruması** (istenmeyen/crack/reklam yazılımlarını engeller) — okul için en etkili tek ayar.
- **ASR kuralları** (Saldırı Yüzeyi Azaltma): Office makroları, USB'den çalışan imzasız programlar,
  e-postadan gelen çalıştırılabilirler, kimlik bilgisi hırsızlığı. Önce "denetim" modunda, sonra "engelle".
- **Kontrollü klasör erişimi** (fidye yazılımına karşı belgeler/masaüstü).
- USB otomatik çalıştırmayı kapatma, SmartScreen'i zorunlu açma.
- Cihazların bu ayarlara uyumu güvenlik karnesinde görünür.

**2.4 Derin inceleme — şüpheli programlar**
- Açılışta çalışan programlar listesi (Sysinternals Autoruns mantığı) envantere eklenir.
- İmzasız/şüpheli dosyaların özeti **MalwareBazaar / VirusTotal** ile karşılaştırılır (yalnız özet
  gönderilir, dosya gönderilmez; KVKK uyumlu).
- "Okulda yalnız bu cihazda çalışan imzasız program" gibi anormallikler işaretlenir.

**2.5 Ağ katmanı**
- Zararlı alan adı engelleyen DNS (Quad9 veya sunucuda AdGuard Home) — tek ayarla tüm okul.
- İsteğe bağlı: teneffüs/akşam internet kısıtı ile birleşir (Faz 4).

**2.6 Bitdefender GravityZone ile birleştirme** (lisans varsa)
- GravityZone API'den cihaz koruma durumu ve olaylar panele çekilir; Defender olmayan cihazlarda
  "korumalı" sayılır. Tarama görevleri panelden GravityZone'a iletilir.

**2.7 Raporlama**
- Haftalık güvenlik raporu (e-posta/PDF): tehditler, temizlenenler, güncel olmayan imzalar, uyumsuz cihazlar.

## Faz 3 — Windows güncellemeleri ve donanım sağlığı
- Bekleyen Windows güncellemelerini listeleme, panelden kurma, gece otomatik + yeniden başlatma penceresi.
- Disk sağlığı (SMART: yeniden atanmış sektör, sıcaklık, kalan ömür) → arıza öncesi uyarı.
- Pil/ekran/dokunmatik durumu (tahta modeline göre), sürücü sürümleri.

## Faz 4 — Sınıf düzeni (okulun en çok istediği)
- **Zamanlama:** ders/teneffüs çizelgesi; akşam otomatik kapatma, sabah uzaktan açma (WoL).
- **Kilit ekranı + internetsiz PIN** (duyuru panosu olarak da kullanılır).
- Teneffüste internet kesme, USB kısıtı, öğrenci hesabını sıfırlama.
- Ekranlar sayfası: bir sınıfın/okulun tahtalarını canlı ızgarada izleme (MeshCentral çoklu ekran).

## Faz 5 — Çok şube, yetki, raporlar
- Kullanıcı rolleri: şube yöneticisi yalnız kendi lokasyonunu görür; öğretmen yalnız kendi sınıfını.
- Rapor merkezi: kullanım, arıza, envanter, güvenlik — PDF/Excel, e-postayla düzenli gönderim.
- Mobil uyumlu panel (PWA) ve anlık bildirimler.

## Faz 6 — İleri
- Öğretmen ekranını tahtalara yansıtma, QR ile öğretmen girişi.
- Android tarafı (tahtaların dahili Android'i).
- Yapay zekâ destekli talep sınıflandırma ve çözüm önerisi.

## Kurallar ve riskler
- **Lisans:** MeshCentral Apache-2.0 (güvenli). AGPL bileşen gömülmez (Snipe-IT, Zabbix, RustDesk-server).
  go-webview2 (MIT) çatalı `agent/third_party` altında; WebView2Loader.dll Microsoft lisanslı.
- **Antivirüs uyumu:** exe içine exe/DLL gömülmez, bellekten yükleme yapılmaz; yardımcı dosyalar ayrı ve
  özetle doğrulanarak dağıtılır. Kalıcı çözüm kod imzalama.
- **Windows Pro sınırı:** Keyboard Filter, Shell Launcher yok (Enterprise/Education gerekir).
  AppLocker/WDAC ve ASR önce denetim modunda.
- **KVKK:** ekran kaydı ve izleme varsayılan kapalı; uzaktan bağlanınca kullanıcıya bildirim; saklama süreleri tanımlı.
- **Ajan port açmaz;** tüm bağlantılar dışa doğru ve HTTPS.
