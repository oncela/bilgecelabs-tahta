# Acı Okulları — Akıllı Tahta Yönetim Sistemi (Plan)

## Ortam
- ~30 adet HNC akıllı tahta (65"/75"/86"), kızılötesi dokunmatik
- Her tahtada **iki sistem** var:
  - Dahili **Android** (Android 14 / bazı modellerde Android 9)
  - **OPS** modülde **Windows Pro**
- Okulda MikroTik yok. Yerel sunucu kullanılamaz (muhasebe sunucusu ayrı ağda, dokunulmayacak).
- Tahtaların ön panelinde NFC kart okuyucu ve tek tuşla kurtarma tuşu var.

## Seçilen BT yazılımları
| Alan | Yazılım | Neden |
|---|---|---|
| Uzaktan erişim | **MeshCentral** (açık kaynak) | HTTPS üzerinden çalışır, Cloudflare Tunnel'dan geçer. RustDesk elendi: OSS sunucusu ayrı TCP/UDP portları istiyor ve web konsolu yok |
| Tahta yönetimi | **Acı Ajan + Panel** (bizim) | Okula özel: çizelge, komutlar, Defender, yazılım dağıtımı |
| İnternet filtresi | **AdGuard Home** (açık kaynak, bu sunucuda) | Tahtalar: DoH + cihaz kimliği ile tahta bazında filtre ve log. Tüm ağ (telefonlar dahil): okul modeminin DNS'i |
| Ağdaki cihazlar | Ajanın **ağ tarama** özelliği | Okulda sunucu yok; çevrimiçi bir tahta ağı tarar (ARP), panel yeni cihazda uyarır |
| İleride | GLPI (envanter + arıza bildirimi), SNMP ile modem/switch izleme | |

## Kurulum
- Tahtaya USB gerekmez: panelden **tek .exe** indirilir (kayıt anahtarı içine gömülü), çift tıklayınca yönetici izni ister ve kurar.
- Kurulum: Acı Ajan servisi + MeshCentral ajanı + (ileride) Defender/USB politikaları, Öğrenci hesabı.

## Mimari
```
[Tahta - Windows OPS]                       [Merkez - bulut sunucu]
  ├ Acı Ajan (Go, Windows servisi, SYSTEM) ─HTTPS─► Okul Paneli (PHP + MySQL)
  └ MeshCentral ajanı ─────────────────────WSS───► MeshCentral (uzak masaüstü)
```
- Bağlantıyı her zaman tahta başlatır, okulda port açmak gerekmez.
- Ajan kuralları (çizelge, yasaklar) tahtada da saklar. İnternet giderse de çalışmaya devam eder.

## Özellikler → Uygulama
| Özellik | Nasıl |
|---|---|
| Uzaktan bağlantı / ekran | MeshCentral |
| Kilit (teneffüs, toplu) | Ajanın tam ekran kilidi. Öğretmen PIN / QR / **NFC kart** ile açar |
| İnternet kesme (teneffüs/akşam) | Ajan → Windows Güvenlik Duvarı kuralları (panel adresi hariç) |
| Virüs | Defender: durumu bildirme, toplu tarama ve güncelleme, kapatılmasını engelleme. USB autorun kapalı, istenirse USB depolama kapalı. Aile filtreli DNS |
| Uygulama kontrolü | Yasaklı süreç listesi (ajan) + App Control for Business (Pro'da çalışır) |
| Yazılım yükleme / çalıştırma | winget / msi / exe / PowerShell, tek tahta-sınıf-okul hedefli, sonuç raporlu |
| Ortak klasör | Panele yüklenen dosyalar → ajan `C:\Ortak\<sınıf>` klasörüne indirir |
| Akşam kapatma | Yerel çizelge, 5 dk uyarı, sonra kapatma |
| Bozulanı geri alma | Standart "Öğrenci" hesabı + her oturum açılışında profili sıfırlama. Alternatif: Reboot Restore Rx |

## ⚠ Android tarafı (kritik açık)
Öğrenci giriş kaynağını OPS'ten Android'e geçirirse Windows'taki bütün kurallar devre dışı kalır.
Yapılacaklar (tahta üzerinde kontrol edilecek):
1. Tahta ayarlarında **açılış kaynağı = OPS** olarak sabitlenebiliyor mu?
2. Ayar menüsüne **şifre** konabiliyor mu?
3. Android'in **Wi‑Fi/Ethernet'i kapatılabiliyor mu**? (internetsiz Android = zararsız)
4. Gerekirse: Android'e ADB ile uygulama kısıtlama veya kendi Android ajanımız (ileri aşama)

## Kurallar
- Öğrenciler **asla admin olmayacak**.
- Ajan imzalı olmalı (kod imzalama sertifikası) → Defender/SmartScreen sorunu çıkmasın.
- KVKK: ekran izleme ve log yetkileri sınırlı tutulacak, aydınlatma metni hazırlanacak.

## Aşamalar
1. **Hafta 1–2:** Panel iskeleti, ajan (kayıt, durum, kilit, kapatma, mesaj), MeshCentral kurulumu
2. **Hafta 3–4:** Çizelge, internet kesme, akşam kapatma, Defender paneli, USB koruması
3. **Hafta 5–6:** Yazılım dağıtımı, ortak klasör, yasaklı uygulamalar, öğrenci profili sıfırlama
4. **Sonra:** NFC/QR öğretmen girişi, mobil uygulama, raporlar, Android tarafı

## Açık sorular
- Panel hangi sunucuda / alan adında çalışacak?
- Test için bir tahtaya erişim (Windows sürümü, Android ayarları kontrolü)
