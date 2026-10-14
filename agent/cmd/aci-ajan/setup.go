//go:build windows

package main

import (
	"errors"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"golang.org/x/sys/windows"
)

// Kurulum dosyasının adı kayıt anahtarını taşır; exe'nin kendisi her okul için
// aynıdır (değiştirilmez, imzalanabilir):
//
//	AciTahtaKurulum_<32 hane anahtar>.exe         -> varsayılan sunucu
//	AciTahtaKurulum_<anahtar>@<alan adı>.exe      -> başka bir sunucu (https)
//
// Tarayıcı aynı dosyayı tekrar indirince sona " (1)" ekleyebilir; yok sayılır.
var setupNameRe = regexp.MustCompile(`(?i)_([0-9a-f]{32})(?:@([a-z0-9.-]+(?::[0-9]{1,5})?))?(?:\s*\(\d+\))?\.exe$`)

type setupConfig struct {
	Server string
	Key    string
}

func setupFromFilename() (*setupConfig, error) {
	exe, err := os.Executable()
	if err != nil {
		return nil, err
	}
	m := setupNameRe.FindStringSubmatch(filepath.Base(exe))
	if m == nil {
		return nil, errors.New("dosya adında kayıt anahtarı yok")
	}
	c := &setupConfig{Server: defaultServer, Key: strings.ToLower(m[1])}
	if m[2] != "" {
		c.Server = "https://" + strings.ToLower(m[2])
	}
	return c, nil
}

// installFromSetup çift tıklamayla kurulum. Yönetici izni manifest ile
// Windows tarafından istenir (requireAdministrator).
func installFromSetup(c *setupConfig) {
	if !windows.GetCurrentProcessToken().IsElevated() {
		msgBox("Kurulum için yönetici izni gerekiyor. Dosyaya sağ tıklayıp \"Yönetici olarak çalıştır\" deyin.", true)
		return
	}
	if err := install(c.Server, c.Key); err != nil {
		msgBox("Kurulum başarısız:\n"+err.Error(), true)
		return
	}
	msgBox("Acı Okulları tahta ajanı kuruldu.\nTahta birkaç saniye içinde panelde görünecek.", false)
}

// isUninstallTool kaldırma aracı mı? (panelden "AciTahtaKaldir.exe" adıyla indirilir)
func isUninstallTool() bool {
	exe, err := os.Executable()
	if err != nil {
		return false
	}
	return strings.HasPrefix(strings.ToLower(filepath.Base(exe)), "acitahtakaldir")
}

// runUninstallTool onay alıp ajanı, uzaktan erişim ajanını ve verileri kaldırır.
func runUninstallTool() {
	const idYes = 6
	r, _ := windows.MessageBox(0,
		windows.StringToUTF16Ptr("Acı Okulları tahta ajanı ve uzaktan erişim bu bilgisayardan kaldırılacak.\nDevam edilsin mi?"),
		windows.StringToUTF16Ptr("Acı Okulları Kaldırma"), windows.MB_YESNO|windows.MB_ICONQUESTION)
	if r != idYes {
		return
	}
	if err := uninstall(); err != nil {
		msgBox("Kaldırma tamamlanamadı:\n"+err.Error(), true)
		return
	}
	msgBox("Acı Okulları tahta ajanı kaldırıldı.\nTahtayı panelden de silebilirsiniz.", false)
}

func msgBox(text string, isErr bool) {
	style := uint32(windows.MB_OK | windows.MB_ICONINFORMATION)
	if isErr {
		style = windows.MB_OK | windows.MB_ICONERROR
	}
	windows.MessageBox(0, windows.StringToUTF16Ptr(text),
		windows.StringToUTF16Ptr("Acı Okulları Kurulum"), style)
}
