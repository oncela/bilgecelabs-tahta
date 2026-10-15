//go:build windows

package main

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

// installSoftware katalogdan bir programı sessizce kurar.
// Komut args:
//
//	method : "winget" | "url"
//	id     : winget paket kimliği (method=winget)
//	url    : https MSI/EXE adresi (method=url)
//	sha256 : url yöntemi için beklenen özet (isteğe bağlı)
//	silent : exe için sessiz kurulum bayrakları (varsayılan yok)
//	name   : günlükte görünecek ad
func installSoftware(args map[string]string) (string, error) {
	switch args["method"] {
	case "winget":
		return installWinget(args["id"])
	case "url":
		return installFromURL(args["url"], args["sha256"], args["silent"], args["name"])
	default:
		return "", fmt.Errorf("bilinmeyen kurulum yöntemi")
	}
}

// findWinget SYSTEM bağlamında winget.exe yolunu çözer (PATH'te olmayabilir).
func findWinget() string {
	if p, err := exec.LookPath("winget.exe"); err == nil {
		return p
	}
	pf := os.Getenv("ProgramFiles")
	if pf == "" {
		pf = `C:\Program Files`
	}
	matches, _ := filepath.Glob(filepath.Join(pf, `WindowsApps\Microsoft.DesktopAppInstaller_*_x64__8wekyb3d8bbwe\winget.exe`))
	if len(matches) > 0 {
		return matches[len(matches)-1] // en yeni sürüm
	}
	return ""
}

func installWinget(id string) (string, error) {
	id = strings.TrimSpace(id)
	if id == "" {
		return "", fmt.Errorf("winget paket kimliği boş")
	}
	wg := findWinget()
	if wg == "" {
		return "", fmt.Errorf("winget bulunamadı (App Installer kurulu olmayabilir)")
	}
	return runCmd(20*time.Minute, wg, "install", "--id", id, "--exact", "--silent",
		"--accept-package-agreements", "--accept-source-agreements",
		"--disable-interactivity", "--scope", "machine")
}

func installFromURL(url, wantSha, silent, name string) (string, error) {
	if !strings.HasPrefix(strings.ToLower(url), "https://") {
		return "", fmt.Errorf("yalnız https adresinden kurulum yapılır")
	}
	ext := strings.ToLower(filepath.Ext(strings.Split(url, "?")[0]))
	if ext != ".msi" && ext != ".exe" {
		return "", fmt.Errorf("yalnız .msi veya .exe kurulur")
	}
	tmp := filepath.Join(os.TempDir(), "bilgece-kurulum"+ext)
	if err := downloadTo(url, wantSha, tmp); err != nil {
		return "", err
	}
	defer os.Remove(tmp)

	if ext == ".msi" {
		return runCmd(20*time.Minute, "msiexec.exe", "/i", tmp, "/qn", "/norestart")
	}
	// .exe: verilen sessiz bayraklar (ör. /S, /quiet)
	cargs := []string{}
	if s := strings.TrimSpace(silent); s != "" {
		cargs = append(cargs, strings.Fields(s)...)
	}
	return runCmd(20*time.Minute, tmp, cargs...)
}

func downloadTo(url, wantSha, dest string) error {
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return err
	}
	req.Header.Set("User-Agent", "BilgeceTahta/"+version)
	client := &http.Client{Timeout: 15 * time.Minute}
	res, err := client.Do(req)
	if err != nil {
		return err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return fmt.Errorf("indirme HTTP %d", res.StatusCode)
	}
	f, err := os.Create(dest)
	if err != nil {
		return err
	}
	h := sha256.New()
	_, err = io.Copy(io.MultiWriter(f, h), io.LimitReader(res.Body, 4<<30))
	f.Close()
	if err != nil {
		return err
	}
	if wantSha != "" && !strings.EqualFold(wantSha, hex.EncodeToString(h.Sum(nil))) {
		os.Remove(dest)
		return fmt.Errorf("dosya bozuk (sha256 uyuşmuyor)")
	}
	return nil
}
