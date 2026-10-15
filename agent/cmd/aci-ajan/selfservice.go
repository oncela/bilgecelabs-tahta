//go:build windows

package main

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
)

// Self servis (BilgeceLabs Destek): kullanıcı oturumunda saatin yanında duran
// pencere. Kurulum programı onu Program Files'a koyar; yeni sürümünü ajan
// panelden (sha256 doğrulamalı) indirir ve etkin oturumda kullanıcı olarak
// başlatır. Haberleşme port açmadan,
// ProgramData\BilgeceLabs\Tahta\destek klasöründeki dosyalarla olur:
//   - durum.json   ajan yazar (panelin gönderdiği liste ve talepler)
//   - istek-*.json destek penceresi yazar; ajan panele iletip siler
// İstekler güvenilmez veri sayılır: boyut/sayı sınırlı, panel ayrıca doğrular.

const destekExeName = "bilgece-destek.exe"

var (
	userenv                    = windows.NewLazySystemDLL("userenv.dll")
	procCreateEnvironmentBlock = userenv.NewProc("CreateEnvironmentBlock")
	procDestroyEnvironmentBlk  = userenv.NewProc("DestroyEnvironmentBlock")
)

type selfService struct {
	mu         sync.Mutex
	syncMu     sync.Mutex
	prepared   bool
	lastState  []byte
	lastLaunch time.Time
	pending    []string // panele gönderilen isteklerin dosyaları (başarıda silinir)
}

func destekDir() string { return filepath.Join(dataDir(), "destek") }

// prepare: klasör izinleri ve yapılandırma dosyasının korunması (açılışta bir kez).
func (s *selfService) prepare() {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.prepared {
		return
	}
	s.prepared = true

	// Ajan klasörü (config.json'da cihaz kimliği var, günlükler): yalnız SYSTEM ve
	// Yöneticiler. Yeni dosyalar bu izni miras alır.
	runHidden("icacls.exe", dataDir(), "/inheritance:r", "/grant:r",
		"*S-1-5-18:(OI)(CI)F", "*S-1-5-32-544:(OI)(CI)F", "/Q")
	// destek alt klasörü: kullanıcılar (S-1-5-32-545) istek bırakıp durumu okuyabilsin.
	d := destekDir()
	os.MkdirAll(d, 0755)
	runHidden("icacls.exe", d, "/grant", "*S-1-5-32-545:(OI)(CI)M", "/Q")
}

// sync: panelin bildirdiği destek programı özeti kurulu dosyadan farklıysa
// yenisini indirir, doğrular, çalışanı kapatıp yerine koyar.
func (s *selfService) sync(a *agent, wantSha string) {
	if wantSha == "" || !s.syncMu.TryLock() {
		return
	}
	defer s.syncMu.Unlock()
	dst := filepath.Join(installDir(), destekExeName)
	if cur, err := os.ReadFile(dst); err == nil {
		sum := sha256.Sum256(cur)
		if strings.EqualFold(hex.EncodeToString(sum[:]), wantSha) {
			return
		}
	}
	tmp := dst + ".yeni"
	if err := a.downloadVerified(strings.TrimRight(a.cfg.Server, "/")+"/api/agent.php?dosya=destek", tmp); err != nil {
		log.Printf("destek programı indirilemedi: %v", err)
		os.Remove(tmp)
		return
	}
	killProcesses(destekExeName)
	time.Sleep(500 * time.Millisecond)
	os.Remove(dst + ".eski")
	os.Rename(dst, dst+".eski")
	if err := os.Rename(tmp, dst); err != nil {
		log.Printf("destek programı yerine konamadı: %v", err)
		os.Rename(dst+".eski", dst)
		return
	}
	os.Remove(dst + ".eski")
	log.Printf("destek programı güncellendi")
}

// takeRequests destek penceresinin bıraktığı istekleri okur (en çok 10).
func (s *selfService) takeRequests() []json.RawMessage {
	files, _ := filepath.Glob(filepath.Join(destekDir(), "istek-*.json"))
	sort.Strings(files)
	var out []json.RawMessage
	var used []string
	for _, f := range files {
		if len(out) >= 10 {
			break
		}
		used = append(used, f)
		st, err := os.Stat(f)
		if err != nil || st.Size() > 16<<10 {
			continue // bozuk/büyük: yalnız sil
		}
		b, err := os.ReadFile(f)
		if err != nil || !json.Valid(b) {
			continue
		}
		out = append(out, json.RawMessage(b))
	}
	s.mu.Lock()
	s.pending = used
	s.mu.Unlock()
	return out
}

// requestsDelivered: panel isteği aldı, dosyaları sil.
func (s *selfService) requestsDelivered() {
	s.mu.Lock()
	files := s.pending
	s.pending = nil
	s.mu.Unlock()
	for _, f := range files {
		os.Remove(f)
	}
}

// writeState panelden gelen durumu pencerenin okuyacağı dosyaya yazar (değiştiyse).
func (s *selfService) writeState(raw json.RawMessage) {
	if len(raw) == 0 || !json.Valid(raw) {
		return
	}
	s.mu.Lock()
	same := bytes.Equal(s.lastState, raw)
	s.mu.Unlock()
	if same {
		return
	}
	p := filepath.Join(destekDir(), "durum.json")
	if os.WriteFile(p+".tmp", raw, 0644) == nil && os.Rename(p+".tmp", p) == nil {
		s.mu.Lock()
		s.lastState = append([]byte(nil), raw...)
		s.mu.Unlock()
	}
}

// ensureTray etkin oturumda destek penceresi çalışmıyorsa başlatır.
func (s *selfService) ensureTray() {
	sid := activeSession()
	if sid == noSession || sid == 0 || activeUserName() == "" {
		return // oturum açmış kullanıcı yok (giriş ekranı)
	}
	if processInSession(destekExeName, sid) {
		return
	}
	s.mu.Lock()
	if time.Since(s.lastLaunch) < time.Minute {
		s.mu.Unlock()
		return
	}
	s.lastLaunch = time.Now()
	s.mu.Unlock()
	exe := filepath.Join(installDir(), destekExeName)
	if _, err := os.Stat(exe); err != nil {
		return
	}
	if err := launchInSession(sid, exe); err != nil {
		log.Printf("destek penceresi başlatılamadı: %v", err)
	}
}

// hideTray: panelde self servis kapalı; açık simge varsa kapat.
func (s *selfService) hideTray() {
	if sid := activeSession(); sid != noSession && processInSession(destekExeName, sid) {
		killProcesses(destekExeName)
		log.Printf("self servis panelden kapatıldı, simge gizlendi")
	}
}

func runHidden(name string, args ...string) {
	cmd := exec.Command(name, args...)
	cmd.SysProcAttr = &windows.SysProcAttr{HideWindow: true, CreationFlags: windows.CREATE_NO_WINDOW}
	cmd.Run()
}

func eachProcess(fn func(pe *windows.ProcessEntry32)) {
	snap, err := windows.CreateToolhelp32Snapshot(windows.TH32CS_SNAPPROCESS, 0)
	if err != nil {
		return
	}
	defer windows.CloseHandle(snap)
	var pe windows.ProcessEntry32
	pe.Size = uint32(unsafe.Sizeof(pe))
	for err = windows.Process32First(snap, &pe); err == nil; err = windows.Process32Next(snap, &pe) {
		fn(&pe)
	}
}

func processInSession(name string, sid uint32) bool {
	found := false
	eachProcess(func(pe *windows.ProcessEntry32) {
		if found || !strings.EqualFold(windows.UTF16ToString(pe.ExeFile[:]), name) {
			return
		}
		var s uint32
		if windows.ProcessIdToSessionId(pe.ProcessID, &s) == nil && s == sid {
			found = true
		}
	})
	return found
}

func killProcesses(name string) {
	eachProcess(func(pe *windows.ProcessEntry32) {
		if !strings.EqualFold(windows.UTF16ToString(pe.ExeFile[:]), name) {
			return
		}
		if h, err := windows.OpenProcess(windows.PROCESS_TERMINATE, false, pe.ProcessID); err == nil {
			windows.TerminateProcess(h, 0)
			windows.CloseHandle(h)
		}
	})
}

// launchInSession programı oturumdaki kullanıcının kimliğiyle (yönetici değil) başlatır.
func launchInSession(sid uint32, exe string) error {
	var tok windows.Token
	if err := windows.WTSQueryUserToken(sid, &tok); err != nil {
		return err
	}
	defer tok.Close()
	var env *uint16 // kullanıcının ortam değişkenleri (APPDATA vb.)
	procCreateEnvironmentBlock.Call(uintptr(unsafe.Pointer(&env)), uintptr(tok), 0)
	if env != nil {
		defer procDestroyEnvironmentBlk.Call(uintptr(unsafe.Pointer(env)))
	}
	si := windows.StartupInfo{Desktop: windows.StringToUTF16Ptr(`winsta0\default`)}
	si.Cb = uint32(unsafe.Sizeof(si))
	var pi windows.ProcessInformation
	cmdLine := windows.StringToUTF16Ptr(`"` + exe + `"`)
	err := windows.CreateProcessAsUser(tok, windows.StringToUTF16Ptr(exe), cmdLine, nil, nil, false,
		windows.CREATE_UNICODE_ENVIRONMENT, env, windows.StringToUTF16Ptr(installDir()), &si, &pi)
	if err != nil {
		return err
	}
	windows.CloseHandle(pi.Thread)
	windows.CloseHandle(pi.Process)
	return nil
}
