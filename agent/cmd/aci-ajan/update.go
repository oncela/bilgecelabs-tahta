//go:build windows

package main

import (
	"crypto/sha256"
	"encoding/hex"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

// Uzaktan güncelleme. Panel yeni sürümü bildirir (pollResponse.Update); ajan
// yeni programı indirir, bütünlüğünü sha256 ile doğrular, çalışan programın
// yerine koyar ve çıkar. Servis yöneticisi (kurtarma ayarı) birkaç saniye
// sonra yeni sürümü başlatır.

type updateInfo struct {
	Version string `json:"version"`
	URL     string `json:"url"`
}

var updateOnce sync.Mutex

// cleanupOldBinary önceki güncellemeden kalan .eski dosyasını siler (başlangıçta).
func cleanupOldBinary() {
	os.Remove(filepath.Join(installDir(), exeName+".eski"))
}

func (a *agent) maybeUpdate(info *updateInfo) {
	if info == nil || info.Version == "" || info.URL == "" {
		return
	}
	if info.Version == version {
		return
	}
	if !updateOnce.TryLock() {
		return
	}
	defer updateOnce.Unlock()

	dir := installDir()
	cur := filepath.Join(dir, exeName)
	// Yalnız kurulu program güncellenir (debug/geçici çalıştırmada değil).
	self, err := os.Executable()
	if err != nil || !samePath(self, cur) {
		return
	}

	log.Printf("güncelleme: %s -> %s indiriliyor", version, info.Version)
	newPath := filepath.Join(dir, exeName+".yeni")
	if err := a.downloadVerified(strings.TrimRight(a.cfg.Server, "/")+info.URL, newPath); err != nil {
		log.Printf("güncelleme indirilemedi: %v", err)
		os.Remove(newPath)
		return
	}

	oldPath := cur + ".eski"
	os.Remove(oldPath)
	// Çalışan program yeniden adlandırılabilir (Windows); sonra yenisi yerine konur.
	if err := os.Rename(cur, oldPath); err != nil {
		log.Printf("güncelleme: eski program taşınamadı: %v", err)
		os.Remove(newPath)
		return
	}
	if err := os.Rename(newPath, cur); err != nil {
		log.Printf("güncelleme: yeni program yerine konamadı: %v", err)
		os.Rename(oldPath, cur) // geri al
		return
	}
	log.Printf("güncelleme hazır, servis yeni sürümle yeniden başlatılıyor")
	// Çıkış: servis kurtarma ayarı yeni programı başlatır.
	os.Exit(1)
}

func (a *agent) downloadVerified(url, dest string) error {
	req, err := http.NewRequest("GET", url, nil)
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
		return &httpError{res.StatusCode}
	}
	f, err := os.OpenFile(dest, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0755)
	if err != nil {
		return err
	}
	h := sha256.New()
	_, err = io.Copy(io.MultiWriter(f, h), io.LimitReader(res.Body, 64<<20))
	f.Close()
	if err != nil {
		return err
	}
	// Panelin bildirdiği özetle doğrula.
	if want := res.Header.Get("X-Sha256"); want != "" && !strings.EqualFold(want, hex.EncodeToString(h.Sum(nil))) {
		return &verifyError{}
	}
	return nil
}

type httpError struct{ code int }

func (e *httpError) Error() string { return "HTTP " + itoa(e.code) }

type verifyError struct{}

func (e *verifyError) Error() string { return "indirilen program bozuk (sha256 uyuşmuyor)" }

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	var b [12]byte
	i := len(b)
	for n > 0 {
		i--
		b[i] = byte('0' + n%10)
		n /= 10
	}
	return string(b[i:])
}
