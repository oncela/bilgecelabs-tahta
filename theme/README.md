# Acı Tahta teması

Tek tema, tüm ekranlar için: panel, MeshCentral (uzaktan erişim) ve ileride
kilit ekranı. Koyu mod yok. Renk değiştirilecekse önce bu tablo, sonra
`panel/public/assets/app.css` ve `theme/meshcentral/public/styles/theme.css`
güncellenir.

| Ad | Değer | Kullanım |
|---|---|---|
| Zemin | `#f4f6f4` | Sayfa arka planı |
| Yüzey | `#ffffff` | Kartlar, pencereler |
| Yazı | `#1c2420` | Ana metin |
| Soluk yazı | `#5d6a63` | Açıklamalar |
| Çizgi | `#dce3df` | Kenarlıklar |
| Vurgu (kara tahta yeşili) | `#2c6b55` | Düğmeler, bağlantılar, seçili öğe |
| Vurgu koyu | `#24594a` | Üzerine gelince |
| Vurgu açık | `#e2efe8` | Seçili satır, rozet zemini |
| Tamam | `#1f7a4a` / `#e1f3e8` | Çevrimiçi, korumalı |
| Uyarı | `#8a5a00` / `#fbf0d8` | Bekliyor, imza eski |
| Hata | `#b1372b` / `#f9e3e0` | Kapalı koruma, tehdit |
| Yazı tipi | Segoe UI, system-ui | Tahtalarda (Windows) kurulu, internet gerekmez |

MeshCentral tarafı: `theme/meshcentral` klasörü sunucuda
`/opt/meshcentral/meshcentral-data/theme-pack/aci` olarak kopyalanır;
config.json'da `"themepack": "aci"`, `"siteStyle": 3`, `"nightMode": 2`.
