# Alınan kararlar

| Tarih | Karar | Neden |
|---|---|---|
| 2026-10-13 | Panel bu sunucuda, `tahta.bilgecelabs.com`, Cloudflare Tunnel ile | Okulda sunucu yok; port açmadan HTTPS |
| 2026-10-13 | Ajan Go ile, Windows servisi olarak | Tek dosya, bağımlılık yok, SYSTEM yetkisi |
| 2026-10-14 | Uzaktan erişim MeshCentral | HTTPS üzerinden çalışır (tünelden geçer). RustDesk OSS ayrı port istiyor, web konsolu yok |
| 2026-10-14 | Kurulum internetten tek .exe | USB gerekmez; anahtar dosyaya gömülü |
| 2026-10-14 | Kilit ekranı: çevrimdışı PIN (TOTP) + okul acil PIN'i | İnternet koparsa tahta açılabilsin |
| 2026-10-14 | Tek sistem: MeshCentral panelin içine gömülür, kısıtlı MeshCentral kullanıcıları | Tek giriş; anahtar sızarsa erişim sadece tahta grubuyla sınırlı |
| 2026-10-14 | Tema: kara tahta yeşili | Kullanıcı tercihi |
| 2026-10-14 | Cloudflare Tunnel "tahta": tahta.bilgecelabs.com → 127.0.0.1:8082, uzak.bilgecelabs.com → https://127.0.0.1:4430 (No TLS Verify) | Port açmadan HTTPS; otel tünellerinden ayrı tünel ve servis (cloudflared-aci-tahta) |
| 2026-10-14 | MeshCentral: cert=uzak.bilgecelabs.com, aliasPort=443, ignoreAgentHashCheck, trustedProxy=127.0.0.1 | Cloudflare TLS'i sonlandırdığı için ajan web sertifikası özetini doğrulayamaz; sunucu kimliği ajan sertifikasıyla doğrulanır |
| 2026-10-14 | Tema mavi, grafikler sunucuda SVG | Kullanıcı tercihi; ApexCharts lisansı ücretli olabildiği için kullanılmadı |
