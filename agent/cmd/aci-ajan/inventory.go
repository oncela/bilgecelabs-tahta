//go:build windows

package main

import (
	"encoding/json"
	"fmt"
	"strings"
	"time"
)

// Envanter: donanım kimliği ve kurulu yazılımlar. 12 saatte bir toplanır
// (ağır olduğu için her bağlantıda değil). PowerShell/CIM ile okunur.

func (a *agent) inventoryDue() bool {
	a.invMu.Lock()
	defer a.invMu.Unlock()
	if !a.invAt.IsZero() && time.Since(a.invAt) < 12*time.Hour {
		return false
	}
	a.invAt = time.Now()
	return true
}

func (a *agent) refreshInventory() {
	out, err := runPowerShell(inventoryScript, 3*time.Minute)
	out = strings.TrimSpace(out)
	if err != nil || !json.Valid([]byte(out)) {
		out = fmt.Sprintf(`{"error":%q}`, strings.TrimSpace(out+" "+fmt.Sprint(err)))
	}
	a.invMu.Lock()
	a.inventory = json.RawMessage(out)
	a.invMu.Unlock()
}

const inventoryScript = `
$ErrorActionPreference = 'SilentlyContinue'
$cs  = Get-CimInstance Win32_ComputerSystem
$bios= Get-CimInstance Win32_BIOS
$os  = Get-CimInstance Win32_OperatingSystem
$cpu = Get-CimInstance Win32_Processor | Select-Object -First 1
$disks = Get-CimInstance Win32_DiskDrive | ForEach-Object { [pscustomobject]@{ model=$_.Model; size_gb=[math]::Round($_.Size/1GB) } }
$net = Get-CimInstance Win32_NetworkAdapterConfiguration | Where-Object { $_.IPEnabled } |
  ForEach-Object { [pscustomobject]@{ mac=$_.MACAddress; ip=($_.IPAddress | Select-Object -First 1) } }
# Kurulu yazılımlar (32 ve 64 bit kayıt defteri)
$paths = @(
  'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
  'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
)
$apps = Get-ItemProperty $paths |
  Where-Object { $_.DisplayName -and -not $_.SystemComponent } |
  Select-Object @{n='name';e={$_.DisplayName}}, @{n='version';e={$_.DisplayVersion}}, @{n='publisher';e={$_.Publisher}} |
  Sort-Object name -Unique
[pscustomobject]@{
  serial       = $bios.SerialNumber
  manufacturer = $cs.Manufacturer
  model        = $cs.Model
  bios         = $bios.SMBIOSBIOSVersion
  cpu          = ($cpu.Name -replace '\s+',' ').Trim()
  cores        = [int]$cpu.NumberOfCores
  ram_gb       = [math]::Round($cs.TotalPhysicalMemory/1GB)
  os           = $os.Caption
  os_version   = $os.Version
  os_install   = ($os.InstallDate)
  last_boot    = ($os.LastBootUpTime)
  disks        = @($disks)
  net          = @($net)
  software      = @($apps)
  software_count= @($apps).Count
  collected    = (Get-Date).ToString('s')
} | ConvertTo-Json -Depth 5 -Compress
`
