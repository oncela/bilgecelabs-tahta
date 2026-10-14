//go:build windows

// Acı Ajan: akıllı tahtalarda (Windows OPS) çalışan yönetim ajanı.
// Windows servisi olarak SYSTEM yetkisiyle çalışır, merkez panele
// HTTPS ile bağlanır ve gelen komutları uygular.
package main

import (
	"flag"
	"fmt"
	"os"
	"os/signal"

	"golang.org/x/sys/windows/svc"
)

const (
	version       = "0.1.0"
	serviceName   = "AciAjan"
	displayName   = "Acı Okulları Tahta Ajanı"
	defaultServer = "https://tahta.bilgecelabs.com"
)

func main() {
	isSvc, err := svc.IsWindowsService()
	if err == nil && isSvc {
		runService()
		return
	}
	if len(os.Args) < 2 {
		// Panelden indirilen kurulum dosyası: çift tıklayınca kur.
		if c, err := readEmbedded(); err == nil {
			installFromEmbedded(c)
			return
		}
		usage()
		os.Exit(2)
	}
	switch os.Args[1] {
	case "install":
		fs := flag.NewFlagSet("install", flag.ExitOnError)
		server := fs.String("server", defaultServer, "panel adresi")
		key := fs.String("key", "", "kayıt anahtarı (panelde Ayarlar sayfasında)")
		fs.Parse(os.Args[2:])
		if *key == "" {
			fmt.Println("HATA: --key gerekli")
			os.Exit(2)
		}
		if err := install(*server, *key); err != nil {
			fmt.Println("HATA:", err)
			os.Exit(1)
		}
		fmt.Println("Kuruldu ve başlatıldı.")
	case "install-embedded":
		c, err := readEmbedded()
		if err != nil {
			msgBox("Kurulum dosyası bozuk: "+err.Error(), true)
			os.Exit(1)
		}
		installFromEmbedded(c)
	case "uninstall":
		if err := uninstall(); err != nil {
			fmt.Println("HATA:", err)
			os.Exit(1)
		}
		fmt.Println("Kaldırıldı.")
	case "debug":
		// Konsolda çalıştırma (test için). Ctrl+C ile durur.
		stop := make(chan struct{})
		go func() {
			c := make(chan os.Signal, 1)
			signal.Notify(c, os.Interrupt)
			<-c
			close(stop)
		}()
		logToConsole = true
		runAgent(stop)
	case "version":
		fmt.Println(version)
	default:
		usage()
		os.Exit(2)
	}
}

func usage() {
	fmt.Println(`Kullanım:
  aci-ajan.exe install --key ANAHTAR [--server https://tahta.bilgecelabs.com]
  aci-ajan.exe uninstall
  aci-ajan.exe debug
  aci-ajan.exe version`)
}
