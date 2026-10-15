# Tam virüs taraması (tüm diskler; tahtada saatler sürebilir, arka planda çalışır).
$ErrorActionPreference = 'SilentlyContinue'
Update-MpSignature | Out-Null
$t = Get-Date
Start-MpScan -ScanType FullScan
$sure = [int]((Get-Date) - $t).TotalMinutes
$aktif = @(Get-MpThreat | Where-Object { $_.IsActive })
"Tam tarama bitti ($sure dk)."
if ($aktif.Count) { "Etkin tehdit: " + (($aktif | ForEach-Object { $_.ThreatName }) -join ', '); exit 1 } else { "Etkin tehdit yok." }
