# Defender koruma ayarları. $Islem panelden başa eklenir (bkz. preset_script):
#   asr_audit|asr_on|asr_off, net_audit|net_on|net_off, cfa_audit|cfa_on|cfa_off,
#   autorun_off|autorun_default, scan_daily|scan_daily_off, paket_izleme, paket_engelle
$ErrorActionPreference = 'Stop'

# Okul için seçilmiş ASR kuralları. Bilerek dışarıda: "yaygın olmayan exe" (01443614) ve
# gelişmiş fidye koruması (c1db55ab) — imzasız ajan güncellemelerini engelleyebilir.
$AsrKurallar = @(
  'be9ba2d9-53ea-4cdc-84e5-9b1eeee46550', # E-posta/webmail'den gelen çalıştırılabilir içerik
  'd4f940ab-401b-4efc-aadc-ad5f3c50688a', # Office uygulamalarının alt işlem başlatması
  '3b576869-a4ec-4529-8536-b80a7769e899', # Office'in çalıştırılabilir içerik üretmesi
  '75668c1f-73b5-4cf0-bb93-3ecf5cb7cc84', # Office'in başka işlemlere kod enjekte etmesi
  'd3e037e1-3eb8-44c8-a917-57927947596d', # JS/VBS'nin indirilen programı başlatması
  '5beb7efe-fd9a-4556-801d-275e5ffc04cc', # Gizlenmiş (karartılmış) betikler
  '92e97fa1-2edf-4476-bdd6-9dd0b4dddc7b', # Office makrolarından Win32 API çağrıları
  'b2b3f03d-6a65-4f7b-a9c7-1c7ef74a9ba4', # USB'den çalışan güvenilmeyen/imzasız programlar
  '9e6c4e1f-7d60-472f-ba1a-a39ef669e4b2', # LSASS'tan parola hırsızlığı
  'e6db77e5-3df2-4cf1-b95a-636979351e5b'  # WMI ile kalıcılık
)

function Asr($eylem) {
  Add-MpPreference -AttackSurfaceReductionRules_Ids $AsrKurallar -AttackSurfaceReductionRules_Actions (@($eylem) * $AsrKurallar.Count)
  "ASR kuralları ($($AsrKurallar.Count)): $eylem"
}
function Ag($m)     { Set-MpPreference -EnableNetworkProtection $m; "Ağ koruması: $m" }
function Klasor($m) { Set-MpPreference -EnableControlledFolderAccess $m; "Fidye yazılımı klasör koruması: $m" }
function Pua($m)    { Set-MpPreference -PUAProtection $m; "PUA koruması: $m" }
function Autorun($kapat) {
  $k = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\Explorer'
  if (-not (Test-Path $k)) { New-Item $k -Force | Out-Null }
  if ($kapat) { Set-ItemProperty $k -Name NoDriveTypeAutoRun -Value 255 -Type DWord; "USB otomatik çalıştırma: kapalı" }
  else { Remove-ItemProperty $k -Name NoDriveTypeAutoRun -ErrorAction SilentlyContinue; "USB otomatik çalıştırma: Windows varsayılanı" }
}
function Tarama($ac) {
  if ($ac) { Set-MpPreference -ScanScheduleQuickScanTime 12:30:00; "Günlük hızlı tarama: her gün 12:30" }
  else { Set-MpPreference -ScanScheduleQuickScanTime 00:00:00; "Günlük hızlı tarama: kapalı" }
}

try {
  if ((Get-MpComputerStatus).AMRunningMode -match 'Passive|EDR') {
    throw "Defender pasif modda (başka antivirüs etkin). Bu ayarları o antivirüsün konsolundan yapın."
  }
  switch ($Islem) {
    'asr_audit'       { Asr 'AuditMode' }
    'asr_on'          { Asr 'Enabled' }
    'asr_off'         { Asr 'Disabled' }
    'net_audit'       { Ag 'AuditMode' }
    'net_on'          { Ag 'Enabled' }
    'net_off'         { Ag 'Disabled' }
    'cfa_audit'       { Klasor 'AuditMode' }
    'cfa_on'          { Klasor 'Enabled' }
    'cfa_off'         { Klasor 'Disabled' }
    'autorun_off'     { Autorun $true }
    'autorun_default' { Autorun $false }
    'scan_daily'      { Tarama $true }
    'scan_daily_off'  { Tarama $false }
    'paket_izleme'  { Pua 'AuditMode'; Asr 'AuditMode'; Ag 'AuditMode'; Klasor 'AuditMode'; Autorun $true; Tarama $true }
    'paket_engelle' { Pua 'Enabled'; Asr 'Enabled'; Ag 'Enabled'; Autorun $true; Tarama $true }
    default { throw "Bilinmeyen işlem: $Islem" }
  }
} catch {
  "HATA: $($_.Exception.Message)"
  exit 1
}
