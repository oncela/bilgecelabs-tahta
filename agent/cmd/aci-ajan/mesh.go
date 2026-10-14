//go:build windows

package main

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

// meshInfo panelin bildirdiği MeshCentral ajanı bilgisi.
type meshInfo struct {
	Service string `json:"service"`
	URL     string `json:"url"`
}

// meshKeeper uzaktan erişim ajanının (MeshCentral) kurulu ve çalışır
// olmasını sağlar: yoksa panelden indirip kurar, durmuşsa başlatır.
type meshKeeper struct {
	mu      sync.Mutex
	busy    bool
	lastTry time.Time
	state   string
	nodeID  string
}

func (k *meshKeeper) currentNodeID() string {
	k.mu.Lock()
	defer k.mu.Unlock()
	return k.nodeID
}

func (k *meshKeeper) setState(s string) {
	k.mu.Lock()
	k.state = s
	k.mu.Unlock()
}

// currentState her bağlantıda panele gönderilir.
func (k *meshKeeper) currentState() string {
	k.mu.Lock()
	defer k.mu.Unlock()
	return k.state
}

func (k *meshKeeper) ensure(a *agent, info *meshInfo) {
	if info == nil || info.Service == "" {
		return
	}
	k.mu.Lock()
	if k.busy {
		k.mu.Unlock()
		return
	}
	k.busy = true
	k.mu.Unlock()
	defer func() { k.mu.Lock(); k.busy = false; k.mu.Unlock() }()

	state, err := serviceState(info.Service)
	if err != nil {
		k.setState("error: " + err.Error())
		return
	}
	switch state {
	case "running":
		k.setState("running")
		k.mu.Lock()
		need := k.nodeID == ""
		k.mu.Unlock()
		if need {
			if id := meshNodeID(info.Service); id != "" {
				k.mu.Lock()
				k.nodeID = id
				k.mu.Unlock()
			}
		}
		return
	case "stopped":
		k.setState("stopped")
		if err := startService(info.Service); err != nil {
			log.Printf("uzak erişim servisi başlatılamadı: %v", err)
		}
		return
	}
	// Kurulu değil (yeniden kurulursa kimliği değişir, eskisini unut).
	k.mu.Lock()
	k.nodeID = ""
	k.mu.Unlock()
	// Başarısız denemeleri 30 dakikada bir tekrarla.
	k.mu.Lock()
	wait := time.Since(k.lastTry) < 30*time.Minute
	if !wait {
		k.lastTry = time.Now()
	}
	k.mu.Unlock()
	if wait {
		return
	}
	k.setState("installing")
	if err := installMesh(a, info); err != nil {
		log.Printf("uzak erişim ajanı kurulamadı: %v", err)
		k.setState("error: " + err.Error())
		return
	}
	log.Printf("uzak erişim ajanı kuruldu")
	k.setState("running")
}

// meshNodeID kurulu MeshCentral ajanının cihaz kimliğini (96 hane hex) döner.
// Panel bu kimlikle tahtayı MeshCentral'da kesin olarak açar.
func meshNodeID(service string) string {
	m, err := mgr.Connect()
	if err != nil {
		return ""
	}
	defer m.Disconnect()
	s, err := m.OpenService(service)
	if err != nil {
		return ""
	}
	defer s.Close()
	c, err := s.Config()
	if err != nil {
		return ""
	}
	exe := strings.Trim(c.BinaryPathName, `"`)
	if i := strings.Index(strings.ToLower(exe), ".exe"); i > 0 {
		exe = exe[:i+4]
	}
	out, err := runCmd(30*time.Second, exe, "-nodeid")
	if err != nil {
		return ""
	}
	id := strings.ToUpper(strings.TrimSpace(out))
	if len(id) != 96 || strings.Trim(id, "0123456789ABCDEF") != "" {
		return ""
	}
	return id
}

// uninstallMesh tahtadan MeshCentral ajanını kaldırır (ajan kaldırılırken).
func uninstallMesh(service string) {
	m, err := mgr.Connect()
	if err != nil {
		return
	}
	defer m.Disconnect()
	s, err := m.OpenService(service)
	if err != nil {
		return
	}
	c, err := s.Config()
	s.Close()
	if err != nil {
		return
	}
	exe := strings.Trim(c.BinaryPathName, `"`)
	if i := strings.Index(strings.ToLower(exe), ".exe"); i > 0 {
		exe = exe[:i+4]
	}
	runCmd(2*time.Minute, exe, "-fulluninstall")
}

func serviceState(name string) (string, error) {
	m, err := mgr.Connect()
	if err != nil {
		return "", err
	}
	defer m.Disconnect()
	s, err := m.OpenService(name)
	if err != nil {
		return "missing", nil
	}
	defer s.Close()
	st, err := s.Query()
	if err != nil {
		return "", err
	}
	if st.State == svc.Running {
		return "running", nil
	}
	return "stopped", nil
}

func startService(name string) error {
	m, err := mgr.Connect()
	if err != nil {
		return err
	}
	defer m.Disconnect()
	s, err := m.OpenService(name)
	if err != nil {
		return err
	}
	defer s.Close()
	return s.Start()
}

func installMesh(a *agent, info *meshInfo) error {
	req, err := http.NewRequest("GET", strings.TrimRight(a.cfg.Server, "/")+info.URL, nil)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+a.cfg.Token)
	req.Header.Set("User-Agent", "BilgeceTahta/"+version)
	client := &http.Client{Timeout: 5 * time.Minute}
	res, err := client.Do(req)
	if err != nil {
		return err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return fmt.Errorf("indirme HTTP %d", res.StatusCode)
	}
	path := filepath.Join(dataDir(), "meshagent64.exe")
	f, err := os.OpenFile(path, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0600)
	if err != nil {
		return err
	}
	h := sha256.New()
	_, err = io.Copy(io.MultiWriter(f, h), io.LimitReader(res.Body, 64<<20))
	f.Close()
	defer os.Remove(path)
	if err != nil {
		return err
	}
	if want := res.Header.Get("X-Sha256"); want != "" && !strings.EqualFold(want, hex.EncodeToString(h.Sum(nil))) {
		return fmt.Errorf("indirilen dosya bozuk (sha256 uyuşmuyor)")
	}
	out, err := runCmd(3*time.Minute, path, "-fullinstall")
	if err != nil {
		return fmt.Errorf("%v: %s", err, out)
	}
	return nil
}
