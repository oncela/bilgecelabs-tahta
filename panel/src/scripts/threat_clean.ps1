# Etkin tehditleri temizler (Defender'ın önerdiği eylemle: karantina/silme), sonra hızlı tarama.
$ErrorActionPreference = 'SilentlyContinue'
$once = @(Get-MpThreat | Where-Object { $_.IsActive })
if ($once.Count -eq 0) { "Etkin tehdit yok."; exit 0 }
"Temizlenecek: " + (($once | ForEach-Object { $_.ThreatName }) -join ', ')
Update-MpSignature | Out-Null
Remove-MpThreat
Start-MpScan -ScanType QuickScan
$sonra = @(Get-MpThreat | Where-Object { $_.IsActive })
if ($sonra.Count -eq 0) {
  "Tamam: etkin tehdit kalmadı."
} else {
  "Hâlâ etkin: " + (($sonra | ForEach-Object { $_.ThreatName }) -join ', ')
  "Öneri: Tam tarama yapın; sürerse Defender çevrimdışı tarama gerekir."
  exit 1
}
