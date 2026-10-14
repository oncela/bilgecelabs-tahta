//go:build windows

package main

import (
	"context"
	"fmt"
	"os/exec"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// execute panelden gelen bir komutu çalıştırır ve çıktısını döner.
func (a *agent) execute(c command) (string, error) {
	switch c.Type {
	case "message":
		title := c.Args["title"]
		if title == "" {
			title = productName
		}
		return "", sendMessage(title, c.Args["text"], atoiDefault(c.Args["timeout"], 0))
	case "shutdown", "restart":
		delay := atoiDefault(c.Args["delay"], 60)
		flag := "/s"
		if c.Type == "restart" {
			flag = "/r"
		}
		msg := c.Args["text"]
		if msg == "" {
			msg = "Tahta yönetici tarafından kapatılıyor."
		}
		return runCmd(30*time.Second, "shutdown.exe", flag, "/f", "/t", strconv.Itoa(delay), "/c", msg)
	case "cancel_shutdown":
		return runCmd(30*time.Second, "shutdown.exe", "/a")
	case "powershell":
		timeout := time.Duration(atoiDefault(c.Args["timeout"], 300)) * time.Second
		return runPowerShell(c.Args["script"], timeout)
	case "fetch_file":
		return fetchFile(a, c.Args)
	case "uninstall":
		// Sonuç bir sonraki bağlantıda panele gitsin diye kısa bir süre sonra kaldır.
		go func() {
			time.Sleep(25 * time.Second)
			uninstallSelf()
		}()
		return "Kaldırma başlatıldı; ajan 25 saniye içinde kendini kaldıracak.", nil
	case "netscan":
		return netScan()
	case "defender_scan":
		return runPowerShell("Update-MpSignature; Start-MpScan -ScanType QuickScan; 'Tarama bitti'", 60*time.Minute)
	case "defender_update":
		return runPowerShell("Update-MpSignature; 'Güncellendi'", 10*time.Minute)
	default:
		return "", fmt.Errorf("bilinmeyen komut: %s", c.Type)
	}
}

func runPowerShell(script string, timeout time.Duration) (string, error) {
	// Türkçe karakterler bozulmasın diye çıktıyı UTF-8 yap.
	full := "[Console]::OutputEncoding=[Text.Encoding]::UTF8; $ProgressPreference='SilentlyContinue'; " + script
	return runCmd(timeout, "powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", full)
}

func runCmd(timeout time.Duration, name string, args ...string) (string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, name, args...)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
	out, err := cmd.CombinedOutput()
	if ctx.Err() == context.DeadlineExceeded {
		err = fmt.Errorf("zaman aşımı (%s)", timeout)
	}
	return strings.TrimSpace(string(out)), err
}

func atoiDefault(s string, d int) int {
	if n, err := strconv.Atoi(s); err == nil {
		return n
	}
	return d
}
