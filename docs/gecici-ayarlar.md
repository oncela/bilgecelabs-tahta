# Geçici ayarlar (Cloudflare Tunnel kurulunca geri alınacak)

2026-10-14: Gerçek panel Cloudflare kurulana kadar yerel ağdan erişilebilir yapıldı.

| Ayar | Geçici değer | Kalıcı değer |
|---|---|---|
| nginx `/etc/nginx/sites-available/aci-tahta` | `listen 192.168.1.105:8082;` eklendi | Sadece `127.0.0.1:8082` |
| `/var/www/aci-tahta/.env` `ACI_COOKIE_SECURE` | `0` | `1` |
| `/var/www/aci-tahta/.env` `ACI_BASE_URL` | `http://192.168.1.105:8082` | `https://tahta.bilgecelabs.com` |

Yedek: `/var/www/aci-tahta/.env.yedek-cloudflare-oncesi`

Geri alma: `deploy/install.sh` yeniden çalıştırılınca nginx ayarı depodaki hâline döner;
`.env` içindeki iki satır elle düzeltilir. Bu süre içinde kurulan tahtalar panele
yerel adresle bağlanır; Cloudflare'e geçince bu tahtalara kurulum dosyası yeniden
kurulmalı (ajan eski kaydını MAC adresinden tanır, yeni kayıt açılmaz).
