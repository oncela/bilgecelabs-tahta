# PUA (istenmeyen yazılım) koruması: crack, reklam yazılımı, paket yükleyicileri engeller.
# $Mod panelden başa eklenir: Enabled | AuditMode | Disabled
$ErrorActionPreference = 'Stop'
try {
  Set-MpPreference -PUAProtection $Mod
} catch {
  "HATA: PUA ayarı değiştirilemedi: $($_.Exception.Message)"
  "Not: Bilgisayarda başka bir antivirüs (ör. Bitdefender) etkinse Defender pasif moddadır; PUA ayarını o antivirüsün konsolundan yapın."
  exit 1
}
$v = (Get-MpPreference).PUAProtection
$ad = @{ 0 = 'Kapalı'; 1 = 'Açık'; 2 = 'İzleme modu' }[[int]$v]
"PUA koruması: $ad"
if ([int]$v -eq 2) { "İzleme modunda engellenmez, yalnız kaydedilir. Panelde Tehditler sayfasından izleyin." }
