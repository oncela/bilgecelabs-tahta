# Değişiklikler

## 0.2.0
- Ürün adı **BilgeceLabs Tahta**: panel, ajan (dosya bilgisi, servis "BilgeceTahta",
  C:\Program Files\BilgeceLabs\Tahta), uzaktan erişim (servis "BilgeceUzak", dosya bilgisi),
  tahtadaki bildirim ve gizlilik çubuğu. uzak.bilgecelabs.com girişsiz açılınca panele yönlenir.
- Eski adla (AciAjan/AciUzak) kurulu ajanlar yeni sürüm kurulunca kimliği devredilerek kaldırılır.
- Kaldırma aracı (BilgeceTahtaKaldir.exe) ve panelden "Tahtadan kaldır".
- Yanlış virüs alarmına karşı: sürüm bilgisi, manifest, değiştirilmeyen exe (anahtar dosya adında).
- Panel: Tabler arayüzü, Genel bakış (SVG grafikler), Sınıflar, sağlık kontrolü, uzaktan açma,
  web adresi açma, bildirim, "Uzaktan açmaya hazırla", Üçüncü taraf lisansları sayfası.
- Yayın: Cloudflare Tunnel (tahta / uzak.bilgecelabs.com), deploy/install.sh.

## 0.1.0 (geliştirme)
- Windows ajanı: servis kurulumu, panele kayıt, durum bildirimi, Defender durumu,
  mesaj, kapat/yeniden başlat, PowerShell, virüs taraması, ağ taraması.
- Tek .exe kurulum: kayıt anahtarı dosyaya gömülü, çift tıklayınca kurar.
- Panel: giriş, tahta listesi, sınıf/ad verme, toplu komut, sonuçlar,
  ağdaki cihazlar, işlem geçmişi, ayarlar, kurulum dosyası indirme.
- MeshCentral uzaktan erişim sunucusu (yerel test).
