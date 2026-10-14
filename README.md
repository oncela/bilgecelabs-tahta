# Acı Tahta

Acı Okulları'ndaki akıllı tahtaları (HNC, Windows OPS) tek bir web panelinden
yönetmek için sistem: uzaktan ekran ve kontrol, toplu komut, virüs durumu,
ağdaki cihazlar, ders çizelgesine göre kilit ve internet kontrolü.

## Yapı
| Klasör | İçerik |
|---|---|
| `agent/` | Tahtalara kurulan Windows ajanı (Go). Giriş noktası `cmd/aci-ajan`. |
| `panel/` | Web paneli (PHP 8.1 + MariaDB). `public/` web kökü, `src/` ortak kod, `views/` şablonlar, `migrations/` veritabanı güncellemeleri. |
| `deploy/` | Sunucu ayarları: nginx, systemd, Cloudflare Tunnel, MeshCentral. |
| `docs/` | Plan, geliştirme raporu, kararlar. |

## Sık kullanılan komutlar
```bash
make lint       # sözdizimi kontrolü
make agent      # Windows ajanını derle
make release    # ajanı panelin indirme klasörüne koy
make migrate    # veritabanını güncelle
```

## İlk kurulum (geliştirme)
1. `panel/.env.example` dosyasını `panel/.env` olarak kopyalayıp doldurun.
2. `make migrate`
3. `php panel/tools/create_admin.php KULLANICI`
4. `make release`

Gizli bilgiler (`.env`, şifre dosyaları) depoya eklenmez.
