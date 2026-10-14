//go:build windows

package main

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// fetchFile panelden bir dosya indirir, sha256 doğrular ve hedef klasöre yazar.
// Ortak alan dağıtımı bunu kullanır. Komut args:
//
//	path   : panel yolu (ör. /api/shared.php?id=5) — yalnız kendi sunucumuzdan
//	name   : kaydedilecek dosya adı
//	sha256 : beklenen özet (onaltılık)
//	dir    : C:\Ortak altındaki alt klasör (boş olabilir)
func fetchFile(a *agent, args map[string]string) (string, error) {
	path := args["path"]
	if !strings.HasPrefix(path, "/") {
		return "", fmt.Errorf("geçersiz yol")
	}
	name := filepath.Base(filepath.Clean("/" + args["name"]))
	if name == "." || name == "/" || name == "" {
		return "", fmt.Errorf("geçersiz dosya adı")
	}
	// Hedef: C:\Ortak\<dir>\<name>. Alt klasör ".." içeremez.
	sub := filepath.Clean("/" + strings.ReplaceAll(args["dir"], "..", ""))
	destDir := filepath.Join(`C:\Ortak`, filepath.FromSlash(strings.TrimPrefix(sub, "/")))
	if err := os.MkdirAll(destDir, 0755); err != nil {
		return "", err
	}
	dest := filepath.Join(destDir, name)

	req, err := http.NewRequest("GET", strings.TrimRight(a.cfg.Server, "/")+path, nil)
	if err != nil {
		return "", err
	}
	req.Header.Set("Authorization", "Bearer "+a.cfg.Token)
	req.Header.Set("User-Agent", "BilgeceTahta/"+version)
	client := &http.Client{Timeout: 10 * time.Minute}
	res, err := client.Do(req)
	if err != nil {
		return "", err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return "", fmt.Errorf("indirme HTTP %d", res.StatusCode)
	}

	tmp := dest + ".indiriliyor"
	f, err := os.OpenFile(tmp, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0644)
	if err != nil {
		return "", err
	}
	h := sha256.New()
	n, err := io.Copy(io.MultiWriter(f, h), io.LimitReader(res.Body, 2<<30)) // en çok 2 GB
	f.Close()
	if err != nil {
		os.Remove(tmp)
		return "", err
	}
	if want := args["sha256"]; want != "" && !strings.EqualFold(want, hex.EncodeToString(h.Sum(nil))) {
		os.Remove(tmp)
		return "", fmt.Errorf("dosya bozuk (sha256 uyuşmuyor)")
	}
	if err := os.Rename(tmp, dest); err != nil {
		os.Remove(tmp)
		return "", err
	}
	return fmt.Sprintf("%s indirildi (%d KB) → %s", name, n/1024, dest), nil
}
