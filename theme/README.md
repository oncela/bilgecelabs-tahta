# Acı Tahta teması

Tek tema, tüm ekranlar için: panel, MeshCentral (uzaktan erişim) ve ileride
kilit ekranı. Koyu mod yok. Renkler yalnız `theme/src/tokens.css` içinde değiştirilir; `make theme`
panel ve MeshCentral temalarını bu dosyadan üretir.

| Ad | Değer | Kullanım |
|---|---|---|
| Zemin | `#f4f6fa` | Sayfa arka planı |
| Yüzey | `#ffffff` | Kartlar, pencereler |
| Yazı | `#18202b` | Ana metin |
| Soluk yazı | `#5b6678` | Açıklamalar |
| Çizgi | `#dde3ec` | Kenarlıklar |
| Vurgu (mavi) | `#1f63d6` | Düğmeler, bağlantılar, seçili öğe |
| Vurgu koyu | `#184fae` | Üzerine gelince |
| Vurgu açık | `#e6eefb` | Seçili satır, rozet zemini |
| Yan menü | `#102a4c` | Lacivert yan menü |
| Grafik | `#1f63d6`, `#3fa7d6`, `#8fb3ef`, `#c3d3ea` | Grafik serileri |
| Tamam / Uyarı / Hata | `#1f7a4a` / `#b77a00` / `#b1372b` | Durum rozetleri |
| Yazı tipi | Segoe UI, system-ui | Tahtalarda (Windows) kurulu, internet gerekmez |

MeshCentral tarafı: `theme/meshcentral` klasörü sunucuda
`/opt/meshcentral/meshcentral-data/theme-pack/aci` olarak kopyalanır;
config.json'da `"themepack": "aci"`, `"siteStyle": 3`, `"nightMode": 2`.
