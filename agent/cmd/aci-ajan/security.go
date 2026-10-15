//go:build windows

package main

import (
	"encoding/json"
	"fmt"
	"strings"
	"time"
)

// Güvenlik karnesi: güvenlik duvarı, BitLocker, bekleyen yeniden başlatma,
// son güncelleme, UAC, Defender kurcalama koruması. Saatte bir toplanır.

func (a *agent) securityDue() bool {
	a.secMu.Lock()
	defer a.secMu.Unlock()
	if !a.secAt.IsZero() && time.Since(a.secAt) < time.Hour {
		return false
	}
	a.secAt = time.Now()
	return true
}

func (a *agent) refreshSecurity() {
	out, err := runPowerShell(securityScript, 90*time.Second)
	out = strings.TrimSpace(out)
	if err != nil || !json.Valid([]byte(out)) {
		out = fmt.Sprintf(`{"error":%q}`, strings.TrimSpace(out+" "+fmt.Sprint(err)))
	}
	a.secMu.Lock()
	a.security = json.RawMessage(out)
	a.secMu.Unlock()
}

const securityScript = `
$ErrorActionPreference = 'SilentlyContinue'
# Güvenlik duvarı (üç profil)
$fw = Get-NetFirewallProfile | Select-Object Name, Enabled
$fwOn = -not ($fw | Where-Object { -not $_.Enabled })
# BitLocker C: (yoksa/desteklenmezse null)
$bl = $null
try { $bl = (Get-BitLockerVolume -MountPoint 'C:' -ErrorAction Stop).ProtectionStatus.ToString() } catch {}
# UAC
$uac = (Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System' -Name EnableLUA).EnableLUA -eq 1
# Bekleyen yeniden başlatma
$reboot = $false
if (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending') { $reboot = $true }
if (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired') { $reboot = $true }
# Son güncelleme (en yeni hotfix)
$lastUpd = $null
try { $lastUpd = (Get-HotFix | Sort-Object InstalledOn -Descending | Select-Object -First 1).InstalledOn.ToString('yyyy-MM-dd') } catch {}
# Defender kurcalama koruması
$tamper = $null
try { $tamper = [bool](Get-MpComputerStatus).IsTamperProtected } catch {}
[pscustomobject]@{
  firewall_on   = [bool]$fwOn
  firewall      = @($fw | ForEach-Object { [pscustomobject]@{ name=$_.Name; on=[bool]$_.Enabled } })
  bitlocker     = $bl
  uac           = [bool]$uac
  reboot_pending= [bool]$reboot
  last_update   = $lastUpd
  tamper_protect= $tamper
  collected     = (Get-Date).ToString('s')
} | ConvertTo-Json -Depth 4 -Compress
`
