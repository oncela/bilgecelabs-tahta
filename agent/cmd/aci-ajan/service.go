//go:build windows

package main

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

type agentService struct{}

func (agentService) Execute(args []string, req <-chan svc.ChangeRequest, st chan<- svc.Status) (bool, uint32) {
	st <- svc.Status{State: svc.StartPending}
	stop := make(chan struct{})
	done := make(chan struct{})
	go func() { runAgent(stop); close(done) }()
	st <- svc.Status{State: svc.Running, Accepts: svc.AcceptStop | svc.AcceptShutdown}
	for c := range req {
		switch c.Cmd {
		case svc.Interrogate:
			st <- c.CurrentStatus
		case svc.Stop, svc.Shutdown:
			st <- svc.Status{State: svc.StopPending}
			close(stop)
			select {
			case <-done:
			case <-time.After(10 * time.Second):
			}
			return false, 0
		}
	}
	return false, 0
}

func runService() { svc.Run(serviceName, agentService{}) }

func installDir() string {
	pf := os.Getenv("ProgramFiles")
	if pf == "" {
		pf = `C:\Program Files`
	}
	return filepath.Join(pf, "BilgeceLabs", "Tahta")
}

func install(server, key, devType, room, name string) error {
	if err := os.MkdirAll(installDir(), 0755); err != nil {
		return err
	}
	if err := os.MkdirAll(dataDir(), 0700); err != nil {
		return err
	}
	// Veri klasörünü sadece SYSTEM ve yöneticiler okuyabilsin (token burada).
	exec.Command("icacls", dataDir(), "/inheritance:r",
		"/grant:r", "*S-1-5-18:(OI)(CI)F", "*S-1-5-32-544:(OI)(CI)F").Run()

	self, err := os.Executable()
	if err != nil {
		return err
	}
	dst := filepath.Join(installDir(), exeName)

	m, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("servis yöneticisi (yönetici olarak çalıştırın): %w", err)
	}
	defer m.Disconnect()

	// Eski kurulum varsa durdur (exe üzerine yazabilmek için).
	if s, err := m.OpenService(serviceName); err == nil {
		s.Control(svc.Stop)
		time.Sleep(2 * time.Second)
		s.Close()
	}
	if !samePath(self, dst) {
		if err := copyFile(self, dst); err != nil {
			return err
		}
	}

	// Kayıtlı bir cihazı yeniden kuruyorsak kimliğini koru.
	cfg, err := loadConfig()
	if err != nil {
		cfg = &Config{}
	}
	// Eski adla kurulmuş ajan varsa: kimliğini devral, kendisini ve eski
	// uzaktan erişim ajanını kaldır (aynı tahta panelde iki kez görünmesin).
	if old := migrateLegacy(m); old != nil && cfg.Token == "" {
		cfg.DeviceID, cfg.Token = old.DeviceID, old.Token
	}
	cfg.Server, cfg.EnrollKey = server, key
	if devType != "" {
		cfg.DevType = devType
	}
	if room != "" {
		cfg.Room = room
	}
	if name != "" {
		cfg.DevName = name
	}
	if err := cfg.save(); err != nil {
		return err
	}

	s, err := m.OpenService(serviceName)
	if err != nil {
		s, err = m.CreateService(serviceName, dst, mgr.Config{
			DisplayName: displayName,
			Description: "BilgeceLabs Tahta: akıllı tahta yönetimi",
			StartType:   mgr.StartAutomatic,
		})
		if err != nil {
			return err
		}
	}
	defer s.Close()
	// Durdurulur veya çökerse 5 sn sonra yeniden başlat.
	s.SetRecoveryActions([]mgr.RecoveryAction{
		{Type: mgr.ServiceRestart, Delay: 5 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 5 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 30 * time.Second},
	}, 86400)
	return s.Start()
}

func uninstall() error {
	m, err := mgr.Connect()
	if err != nil {
		return err
	}
	defer m.Disconnect()
	s, err := m.OpenService(serviceName)
	if err != nil {
		return err
	}
	defer s.Close()
	s.Control(svc.Stop)
	err = s.Delete()
	// Uzaktan erişim ajanını da kaldır (bizim kurduğumuz servis adı).
	uninstallMesh(meshService)
	removeFiles()
	return err
}

// removeFiles tahtadaki ajan dosyalarını siler: veri klasörü (bağlantı anahtarı,
// kayıtlar) hemen; program dosyası çalışıyorsa Windows yeniden başlarken silinir.
func removeFiles() {
	os.RemoveAll(dataDir())
	dir := installDir()
	exe := filepath.Join(dir, exeName)
	if err := os.Remove(exe); err != nil && !os.IsNotExist(err) {
		if p, err := windows.UTF16PtrFromString(exe); err == nil {
			windows.MoveFileEx(p, nil, windows.MOVEFILE_DELAY_UNTIL_REBOOT)
		}
		if p, err := windows.UTF16PtrFromString(dir); err == nil {
			windows.MoveFileEx(p, nil, windows.MOVEFILE_DELAY_UNTIL_REBOOT)
		}
		return
	}
	os.Remove(dir)
}

func samePath(a, b string) bool {
	x, _ := filepath.Abs(a)
	y, _ := filepath.Abs(b)
	return filepath.Clean(x) == filepath.Clean(y)
}

func copyFile(src, dst string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := os.Create(dst)
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, in); err != nil {
		out.Close()
		return err
	}
	return out.Close()
}

// uninstallSelf panelden gelen kaldırma komutu: servis içinden çalışır.
// Sıra önemli: önce uzaktan erişim ajanı ve dosyalar, en son servis kaydı
// silinir ve süreç biter (silinmek üzere işaretli servis yeniden başlatılmaz).
func uninstallSelf() {
	log.Printf("panelden kaldırma isteği: kaldırılıyor")
	uninstallMesh(meshService)
	removeFiles()
	if m, err := mgr.Connect(); err == nil {
		if s, err := m.OpenService(serviceName); err == nil {
			s.Delete()
			s.Close()
		}
		m.Disconnect()
	}
	os.Exit(0)
}

// migrateLegacy eski markalamayla (servis "AciAjan") kurulmuş ajanı kaldırır ve
// varsa eski bağlantı kimliğini döner.
func migrateLegacy(m *mgr.Mgr) *Config {
	s, err := m.OpenService(legacyServiceName)
	if err != nil {
		return nil // eski kurulum yok
	}
	s.Control(svc.Stop)
	time.Sleep(2 * time.Second)
	s.Delete()
	s.Close()

	pd := os.Getenv("ProgramData")
	if pd == "" {
		pd = `C:\ProgramData`
	}
	oldData := filepath.Join(pd, "AciAjan")
	var old *Config
	if b, err := os.ReadFile(filepath.Join(oldData, "config.json")); err == nil {
		var c Config
		if json.Unmarshal(b, &c) == nil && c.Token != "" {
			old = &c
		}
	}
	uninstallMesh(legacyMeshService)
	os.RemoveAll(oldData)
	pf := os.Getenv("ProgramFiles")
	if pf == "" {
		pf = `C:\Program Files`
	}
	os.RemoveAll(filepath.Join(pf, "AciAjan"))
	return old
}
