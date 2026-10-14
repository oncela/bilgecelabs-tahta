# Uzaktan açmaya (Wake-on-LAN) hazırlık: Windows tarafındaki ayarlar.
# BIOS ayarı (Wake on LAN / Power on by PCI-E açık, ErP kapalı) yazılımla
# değiştirilemez; o her tahtada bir kez elle yapılır.
$sonuc = @()
$kartlar = Get-NetAdapter -Physical -ErrorAction SilentlyContinue | Where-Object { $_.Status -eq 'Up' }
foreach ($k in $kartlar) {
    $notlar = @()
    # 1) Standart sürücü anahtarı: Magic Packet ile uyan
    try {
        Set-NetAdapterAdvancedProperty -Name $k.Name -RegistryKeyword '*WakeOnMagicPacket' -RegistryValue 1 -NoRestart -ErrorAction Stop
        $notlar += 'magic packet: açıldı'
    } catch { $notlar += 'magic packet: sürücü desteklemiyor' }
    # 2) Güç yönetimi: kart bilgisayarı uyandırabilsin
    try {
        Set-NetAdapterPowerManagement -Name $k.Name -WakeOnMagicPacket Enabled -NoRestart -ErrorAction Stop
    } catch { }
    & powercfg.exe /deviceenablewake "$($k.InterfaceDescription)" 2>$null | Out-Null
    $pm = Get-NetAdapterPowerManagement -Name $k.Name -ErrorAction SilentlyContinue
    $sonuc += [pscustomobject]@{
        kart   = $k.InterfaceDescription
        mac    = $k.MacAddress
        hazir  = ($pm -and $pm.WakeOnMagicPacket -eq 'Enabled')
        notlar = ($notlar -join ', ')
    }
}
# 3) Hızlı başlatma kapalı: kapalı durumdan uyanmayı engelleyebiliyor
& reg.exe add 'HKLM\SYSTEM\CurrentControlSet\Control\Session Manager\Power' /v HiberbootEnabled /t REG_DWORD /d 0 /f | Out-Null

$uyandirabilen = (& powercfg.exe /devicequery wake_armed) -join '; '
[pscustomobject]@{
    kartlar          = $sonuc
    hizli_baslatma   = 'kapatıldı'
    uyandirabilenler = $uyandirabilen
    not              = 'BIOS: Wake on LAN açık, ErP/EuP kapalı olmalı (elle, bir kez).'
} | ConvertTo-Json -Depth 4
